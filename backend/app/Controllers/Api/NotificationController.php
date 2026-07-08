<?php

namespace App\Controllers\Api;

use App\Models\Notification;
use SkillDo\Http\Request;
use SkillDo\Routing\Controller\Controller;
use SkillDo\Support\Auth;

/**
 * API Thông báo in-app — chuông ở sidebar. Chỉ thao tác trên thông báo của
 * CHÍNH user đang đăng nhập (không cần cap riêng; ai đăng nhập cũng có thông báo).
 */
class NotificationController extends Controller
{
    /** Số thông báo tối đa trả về cho dropdown chuông. */
    const LIST_LIMIT = 30;

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
     * GET api/notifications — danh sách mới nhất + số chưa đọc (FE poll để cập nhật badge).
     */
    public function index(Request $request): void
    {
        $userId = $this->userId();

        if (!schema()->hasTable('notifications'))
        {
            // Chưa chạy api/utils/database — trả rỗng để FE không lỗi.
            response()->success('success', ['items' => [], 'unread' => 0]);
        }

        $items = [];

        foreach (Notification::where('user_id', $userId)->orderByDesc('id')->limit(self::LIST_LIMIT)->get() as $row)
        {
            $items[] = [
                'id'      => (int) $row->id,
                'type'    => (string) $row->type,
                'title'   => (string) $row->title,
                'message' => (string) ($row->message ?? ''),
                'link'    => (string) $row->link,
                'is_read' => (int) $row->is_read === 1,
                'created' => $row->created,
            ];
        }

        $unread = (int) Notification::where('user_id', $userId)->where('is_read', 0)->count();

        response()->success('success', ['items' => $items, 'unread' => $unread]);
    }

    /**
     * POST api/notifications/read — đánh dấu đã đọc. Body {id} = 1 thông báo;
     * không có id = đọc tất cả.
     */
    public function markRead(Request $request): void
    {
        $userId = $this->userId();

        if (!schema()->hasTable('notifications'))
        {
            response()->success('success', ['unread' => 0]);
        }

        $id = (int) $request->input('id');

        $query = Notification::where('user_id', $userId)->where('is_read', 0);

        if ($id > 0)
        {
            $query->where('id', $id);
        }

        $query->update(['is_read' => 1]);

        $unread = (int) Notification::where('user_id', $userId)->where('is_read', 0)->count();

        response()->success('success', ['unread' => $unread]);
    }
}
