<?php

namespace App\Services\Notification;

use App\Models\PushJob;
use App\Models\PushSubscription;
use SkillDo\Database\DB;

/**
 * Hàng đợi gửi thông báo đẩy (Web Push) — bảng `push_queue`.
 *
 * Luồng: Notifier::send ghi thông báo in-app xong → enqueue() tạo 1 job cho TỪNG
 * thiết bị (subscription) của người nhận → tick Schedule (push-tick, mỗi phút)
 * claim một lô rồi gửi LẦN LƯỢT từng job qua WebPushClient. Trạng thái nằm hết
 * trong DB, claim atomic bằng lockForUpdate nên tick chồng lấn an toàn,
 * không CLI worker/Redis.
 *
 * FIRE-AND-FORGET như Notifier: enqueue nuốt mọi lỗi — push là phụ trợ, không được
 * phá luồng chính. Bảng chưa migrate / chưa cấu hình VAPID → bỏ qua êm.
 */
class PushQueue
{
    /** Số job gửi tối đa mỗi tick (mỗi phút) — gửi tuần tự, ~0.3-0.5s/job. */
    const BATCH = 50;

    /** Job lỗi tạm thời (mạng, 429, 5xx) được thử lại tối đa chừng này lần. */
    const MAX_ATTEMPTS = 3;

    /** Job 'sending' kẹt quá số phút này (tick chết giữa chừng) → trả về pending. */
    const STALE_MINUTES = 5;

    /** Job đã xong (sent/failed) giữ lại chừng này ngày để soi lỗi rồi dọn. */
    const KEEP_DAYS = 3;

    /* ---------------------------------------------------------------------
     | Enqueue — gọi từ Notifier sau khi ghi thông báo in-app
     * -------------------------------------------------------------------- */

    /**
     * Xếp thông báo vào hàng đợi: 1 job cho mỗi thiết bị đã bật push của user.
     * User chưa bật push trên thiết bị nào → không có job, im lặng bỏ qua.
     */
    public static function enqueue(int $userId, string $type, string $title, string $message = '', string $link = ''): void
    {
        if ($userId <= 0 || $title === '' || !self::ready())
        {
            return;
        }

        try
        {
            $jobs = [];

            foreach (PushSubscription::where('user_id', $userId)->get(['id']) as $sub)
            {
                $jobs[] = [
                    'subscription_id' => (int) $sub->id,
                    'user_id'         => $userId,
                    'type'            => mb_substr($type, 0, 40),
                    'title'           => mb_substr($title, 0, 255),
                    'message'         => mb_substr($message, 0, 500),
                    'link'            => mb_substr($link, 0, 255),
                ];
            }

            if (!empty($jobs))
            {
                DB::table('push_queue')->insert($jobs);
            }
        }
        catch (\Throwable $e)
        {
            // Nuốt lỗi — xem ghi chú đầu class.
        }
    }

    /* ---------------------------------------------------------------------
     | Tick — chạy nền mỗi phút (schedule.php, task push-tick)
     * -------------------------------------------------------------------- */

    /**
     * Gửi một lô job pending, tuần tự từng người nhận. Trả summary để log.
     *
     * @return array{sent:int, failed:int, retried:int, pruned:int}
     */
    public function tick(): array
    {
        $summary = ['sent' => 0, 'failed' => 0, 'retried' => 0, 'pruned' => 0];

        if (!self::ready())
        {
            return $summary;
        }

        $this->resetStale();

        $jobs = $this->claimBatch(self::BATCH);

        if (!empty($jobs))
        {
            $client = new WebPushClient();

            // Cache subscription theo id — nhiều job trong lô có thể cùng thiết bị.
            $subs = [];

            foreach ($jobs as $job)
            {
                $subId = (int) $job->subscription_id;

                if (!array_key_exists($subId, $subs))
                {
                    $subs[$subId] = PushSubscription::where('id', $subId)->first();
                }

                $this->process($client, $job, $subs[$subId], $summary);
            }
        }

        $summary['pruned'] = $this->prune();

        return $summary;
    }

    /** Gửi 1 job + cập nhật trạng thái theo kết quả. */
    protected function process(WebPushClient $client, object $job, ?object $sub, array &$summary): void
    {
        if (!hasItems($sub))
        {
            // Thiết bị đã hủy đăng ký trong lúc job chờ — bỏ job.
            $this->finish((int) $job->id, 'failed', 'Subscription đã bị xoá');

            $summary['failed']++;

            return;
        }

        $payload = json_encode([
            'type'    => (string) $job->type,
            'title'   => (string) $job->title,
            'message' => (string) $job->message,
            'link'    => (string) $job->link,
        ], JSON_UNESCAPED_UNICODE);

        $result = $client->send((string) $sub->endpoint, (string) $sub->p256dh, (string) $sub->auth, $payload);

        if ($result['ok'])
        {
            $this->finish((int) $job->id, 'sent');

            $summary['sent']++;

            return;
        }

        if ($result['gone'])
        {
            // Endpoint hết hạn/thu hồi: xoá thiết bị + fail mọi job còn chờ của nó.
            $this->dropSubscription((int) $sub->id, $result['message']);

            $this->finish((int) $job->id, 'failed', $result['message']);

            $summary['failed']++;

            return;
        }

        $attempts = (int) $job->attempts + 1;

        if ($result['retry'] && $attempts < self::MAX_ATTEMPTS)
        {
            // Trả về pending cho tick sau thử lại.
            DB::table('push_queue')->where('id', (int) $job->id)->update([
                'status'     => 'pending',
                'attempts'   => $attempts,
                'last_error' => mb_substr($result['message'], 0, 255),
                'claimed_at' => null,
            ]);

            $summary['retried']++;

            return;
        }

        DB::table('push_queue')->where('id', (int) $job->id)->update([
            'status'     => 'failed',
            'attempts'   => $attempts,
            'last_error' => mb_substr($result['message'], 0, 255),
        ]);

        $summary['failed']++;
    }

    /**
     * Claim một lô job pending (atomic bằng lockForUpdate — như Crawler::claimBatch).
     * FIFO theo id → thông báo đến từng người nhận theo đúng thứ tự xếp hàng.
     */
    protected function claimBatch(int $batch): array
    {
        return DB::transaction(function () use ($batch) {

            $rows = DB::table('push_queue')
                ->where('status', 'pending')
                ->orderBy('id')
                ->limit($batch)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty())
            {
                return [];
            }

            DB::table('push_queue')
                ->whereIn('id', $rows->pluck('id')->all())
                ->update(['status' => 'sending', 'claimed_at' => date('Y-m-d H:i:s')]);

            return $rows->all();
        });
    }

    /** Đánh dấu job xong (sent/failed). */
    protected function finish(int $jobId, string $status, string $error = ''): void
    {
        $data = ['status' => $status, 'last_error' => mb_substr($error, 0, 255)];

        if ($status === 'sent')
        {
            $data['sent_at'] = date('Y-m-d H:i:s');
        }

        DB::table('push_queue')->where('id', $jobId)->update($data);
    }

    /** Xoá subscription hết hạn + fail các job pending còn lại của nó. */
    protected function dropSubscription(int $subscriptionId, string $reason): void
    {
        PushSubscription::where('id', $subscriptionId)->delete();

        DB::table('push_queue')
            ->where('subscription_id', $subscriptionId)
            ->whereIn('status', ['pending', 'sending'])
            ->update(['status' => 'failed', 'last_error' => mb_substr($reason, 0, 255)]);
    }

    /** Job 'sending' kẹt quá STALE_MINUTES (tick chết giữa chừng) → trả về pending. */
    protected function resetStale(): void
    {
        DB::table('push_queue')
            ->where('status', 'sending')
            ->where('claimed_at', '<', date('Y-m-d H:i:s', time() - self::STALE_MINUTES * 60))
            ->update(['status' => 'pending', 'claimed_at' => null]);
    }

    /** Dọn job đã xong cũ hơn KEEP_DAYS — giữ bảng queue không phình. */
    protected function prune(): int
    {
        return (int) DB::table('push_queue')
            ->whereIn('status', ['sent', 'failed'])
            ->where('created', '<', date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400))
            ->delete();
    }

    /** Sẵn sàng chưa: bảng đã migrate + khoá VAPID đã cấu hình. */
    protected static function ready(): bool
    {
        try
        {
            return WebPushClient::configured()
                && schema()->hasTable('push_subscriptions')
                && schema()->hasTable('push_queue');
        }
        catch (\Throwable $e)
        {
            return false;
        }
    }
}
