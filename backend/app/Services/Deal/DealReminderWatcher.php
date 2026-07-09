<?php

namespace App\Services\Deal;

use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\DealReminder;
use App\Services\Notification\Notifier;

/**
 * Tick nhắc hẹn giao dịch (mỗi phút). Hai nguồn độc lập, đều đánh dấu `reminded_at` để chỉ bắn 1 lần:
 *
 *  A. Đợt thu DỰ KIẾN đến/quá hạn — `deal_payments` status=planned, chưa nhắc, `due_date <= now + lead`
 *     (env DEAL_PAYMENT_REMIND_LEAD_DAYS, mặc định 1 ngày → nhắc trước 1 ngày + bắt cả quá hạn). Gom
 *     DIGEST theo sales phụ trách giao dịch ("Bạn có N khoản thu tới/quá hạn").
 *  B. Nhắc hẹn tự do đến giờ — `deal_reminders` status=pending, chưa nhắc, `remind_at <= now`. Gửi
 *     TỪNG lời nhắc cho người phụ trách (mỗi lời nhắc có nội dung riêng).
 *
 * Bảng chưa migrate / thiếu cột → thoát êm.
 *
 * @see App\Console\schedule.php (đăng ký tick 'deal-reminder-tick')
 */
class DealReminderWatcher
{
    const BATCH = 500;

    /** @return array{payments:int, reminders:int, users:int} */
    public function tick(): array
    {
        $result = ['payments' => 0, 'reminders' => 0, 'users' => 0];

        if (!schema()->hasTable('deals'))
        {
            return $result;
        }

        $users = [];

        // ── A. Đợt thu dự kiến đến/quá hạn ─────────────────────────────────────────────
        if (schema()->hasColumn('deal_payments', 'status') && schema()->hasColumn('deal_payments', 'reminded_at'))
        {
            $leadDays = max(0, (int) env('DEAL_PAYMENT_REMIND_LEAD_DAYS', 1));
            $now      = time();
            $due      = date('Y-m-d H:i:s', $now + $leadDays * 86400);

            $payments = DealPayment::where('status', 'planned')
                ->whereNull('reminded_at')
                ->whereNotNull('due_date')
                ->where('due_date', '<=', $due)
                ->orderBy('due_date')
                ->limit(self::BATCH)
                ->get();

            if (count($payments) > 0)
            {
                // Nạp giao dịch để biết sales phụ trách + mã giao dịch.
                $dealIds = [];
                foreach ($payments as $p)
                {
                    $dealIds[(int) $p->deal_id] = true;
                }

                $owners = [];
                foreach (Deal::whereIn('id', array_keys($dealIds))->get() as $d)
                {
                    $owners[(int) $d->id] = (int) $d->assigned_user_id;
                }

                // Gom số khoản thu đến hạn theo sales.
                $counts = [];
                $ids    = [];
                foreach ($payments as $p)
                {
                    $ids[] = (int) $p->id;
                    $uid = $owners[(int) $p->deal_id] ?? 0;
                    if ($uid <= 0)
                    {
                        continue;
                    }
                    $counts[$uid] = ($counts[$uid] ?? 0) + 1;
                }

                foreach ($counts as $uid => $n)
                {
                    Notifier::send($uid, 'warning', 'Khoản thu đến hạn',
                        'Bạn có ' . $n . ' khoản thu đang tới/quá hạn cần theo dõi.', '/deals');
                    $users[$uid] = true;
                }

                // Đánh dấu đã nhắc (kể cả đợt không có sales để không quét lại mãi).
                DealPayment::whereIn('id', $ids)->update(['reminded_at' => date('Y-m-d H:i:s', $now)]);

                $result['payments'] = count($ids);
            }
        }

        // ── B. Nhắc hẹn tự do đến giờ ───────────────────────────────────────────────────
        if (schema()->hasTable('deal_reminders'))
        {
            $now = time();
            $to  = date('Y-m-d H:i:s', $now);

            $reminders = DealReminder::where('status', 'pending')
                ->whereNull('reminded_at')
                ->whereNotNull('remind_at')
                ->where('remind_at', '<=', $to)
                ->orderBy('remind_at')
                ->limit(self::BATCH)
                ->get();

            if (count($reminders) > 0)
            {
                $ids = [];
                foreach ($reminders as $r)
                {
                    $ids[] = (int) $r->id;
                    $uid = (int) $r->assigned_user_id;
                    if ($uid <= 0)
                    {
                        continue;
                    }

                    Notifier::send($uid, 'info', 'Nhắc hẹn giao dịch', (string) $r->title, '/deals');
                    $users[$uid] = true;
                }

                DealReminder::whereIn('id', $ids)->update(['reminded_at' => date('Y-m-d H:i:s', $now)]);

                $result['reminders'] = count($ids);
            }
        }

        $result['users'] = count($users);

        return $result;
    }
}
