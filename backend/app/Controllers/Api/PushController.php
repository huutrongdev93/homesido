<?php

namespace App\Controllers\Api;

use App\Models\PushSubscription;
use App\Services\Notification\WebPushClient;
use SkillDo\Http\Request;
use SkillDo\Routing\Controller\Controller;
use SkillDo\Support\Auth;

/**
 * API đăng ký thông báo đẩy (Web Push) — mở rộng của Thông báo in-app (#26).
 *
 * FE (utils/pushNotifications.js) đăng ký service worker + PushManager.subscribe với
 * khoá VAPID public rồi gửi subscription lên đây. Mỗi dòng push_subscriptions = 1
 * thiết bị/trình duyệt; 1 user có thể bật trên nhiều thiết bị. Không cần cap —
 * thao tác trên subscription của CHÍNH user.
 */
class PushController extends Controller
{
    /** Mỗi user giữ tối đa chừng này thiết bị — vượt là tỉa cái đăng ký cũ nhất. */
    const MAX_DEVICES_PER_USER = 10;

    protected function userId(): int
    {
        $user = Auth::user();

        if (!hasItems($user))
        {
            response()
                ->setStatusCode(401)
                ->setApiStatus(401)
                ->error('Chưa đăng nhập.');
        }

        return (int) $user->id;
    }

    /**
     * GET api/notifications/push/config — FE cần biết push đã bật trên server chưa
     * (khoá VAPID + bảng đã sẵn sàng) và khoá public để gọi PushManager.subscribe.
     */
    public function config(Request $request): void
    {
        $this->userId();

        $enabled = WebPushClient::configured() && schema()->hasTable('push_subscriptions');

        response()->success('success', [
            'enabled'   => $enabled,
            'publicKey' => $enabled ? WebPushClient::publicKey() : '',
        ]);
    }

    /**
     * POST api/notifications/push/subscribe — lưu subscription của thiết bị hiện tại.
     * Body (nguyên object PushSubscription.toJSON() của trình duyệt):
     *   {endpoint, keys: {p256dh, auth}}
     * Upsert theo md5(endpoint): trình duyệt đăng ký lại / user khác đăng nhập trên
     * cùng trình duyệt → dòng cũ được gán lại, không nhân bản.
     */
    public function subscribe(Request $request): void
    {
        $userId = $this->userId();

        if (!WebPushClient::configured() || !schema()->hasTable('push_subscriptions'))
        {
            response()->setStatusCode(422)->error('Máy chủ chưa cấu hình thông báo đẩy.');
        }

        $endpoint = trim((string) $request->input('endpoint'));

        $keys = $request->input('keys');

        $p256dh = trim((string) ($keys['p256dh'] ?? ''));
        $auth   = trim((string) ($keys['auth'] ?? ''));

        // Khoá hợp lệ: p256dh 65 byte (điểm P-256), auth 16 byte — decode thử để chặn rác.
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)
            || strlen(WebPushClient::b64Decode($p256dh)) !== 65
            || strlen(WebPushClient::b64Decode($auth)) !== 16)
        {
            response()->setStatusCode(422)->error('Subscription không hợp lệ.');
        }

        // Chống blind-SSRF: endpoint là URL dịch vụ push công khai (FCM/Mozilla/WNS).
        // Chặn endpoint trỏ host nội bộ (WebPushClient::send sẽ POST tới URL này).
        if (!\App\Services\Support\SsrfGuard::isSafeUrl($endpoint))
        {
            response()->setStatusCode(422)->error('Endpoint không hợp lệ.');
        }

        $hash = md5($endpoint);

        $data = [
            'user_id'    => $userId,
            'endpoint'   => $endpoint,
            'p256dh'     => $p256dh,
            'auth'       => $auth,
            'user_agent' => mb_substr((string) $request->header('User-Agent'), 0, 255),
        ];

        $existing = PushSubscription::where('endpoint_hash', $hash)->first();

        if (hasItems($existing))
        {
            PushSubscription::where('id', (int) $existing->id)->update($data);
        }
        else
        {
            $data['endpoint_hash'] = $hash;

            PushSubscription::insert($data);

            $this->pruneDevices($userId);
        }

        response()->success('Đã bật thông báo trên thiết bị này.');
    }

    /**
     * POST api/notifications/push/unsubscribe — tắt thông báo trên thiết bị hiện tại.
     * Body: {endpoint}. Chỉ xoá được subscription của chính mình.
     */
    public function unsubscribe(Request $request): void
    {
        $userId = $this->userId();

        if (!schema()->hasTable('push_subscriptions'))
        {
            response()->success('Đã tắt thông báo trên thiết bị này.');
        }

        $endpoint = trim((string) $request->input('endpoint'));

        if ($endpoint !== '')
        {
            PushSubscription::where('endpoint_hash', md5($endpoint))
                ->where('user_id', $userId)
                ->delete();
        }

        response()->success('Đã tắt thông báo trên thiết bị này.');
    }

    /** Tỉa thiết bị đăng ký cũ nhất khi user vượt trần MAX_DEVICES_PER_USER. */
    protected function pruneDevices(int $userId): void
    {
        try
        {
            $count = (int) PushSubscription::where('user_id', $userId)->count();

            if ($count <= self::MAX_DEVICES_PER_USER)
            {
                return;
            }

            foreach (PushSubscription::where('user_id', $userId)
                         ->orderBy('id')->limit($count - self::MAX_DEVICES_PER_USER)->get(['id']) as $row)
            {
                PushSubscription::where('id', (int) $row->id)->delete();
            }
        }
        catch (\Throwable $e)
        {
            // Tỉa lỗi cũng không sao — không phá luồng đăng ký.
        }
    }
}
