<?php

namespace App\Services\Notification;

use App\Models\Notification;
use SkillDo\Database\DB;

/**
 * Ghi thông báo in-app cho user — "đầu ra" của mọi tiến trình nền (#26).
 *
 * FIRE-AND-FORGET: mọi lỗi đều bị nuốt (log-less) — thông báo là phụ trợ, tuyệt đối
 * không được làm hỏng luồng chính của tiến trình gọi nó (tick nền, xử lý nghiệp vụ...).
 * Bảng chưa tồn tại (chưa chạy api/utils/database) → bỏ qua êm.
 */
class Notifier
{
    /** Mỗi user chỉ giữ tối đa chừng này thông báo — vượt là tỉa bớt cái cũ nhất. */
    const MAX_PER_USER = 100;

    /**
     * Ghi 1 thông báo.
     *
     * @param int    $userId  chủ thông báo (0 → bỏ qua).
     * @param string $type    loại sự kiện (info | success | warning | error | ...).
     * @param string $title   tiêu đề ngắn hiển thị đậm.
     * @param string $message mô tả thêm (tuỳ chọn).
     * @param string $link    đường dẫn FE mở khi bấm (tuỳ chọn, vd /account).
     */
    public static function send(int $userId, string $type, string $title, string $message = '', string $link = ''): void
    {
        if ($userId <= 0 || $title === '' || !self::ready())
        {
            return;
        }

        try
        {
            Notification::insert([
                'user_id' => $userId,
                'type'    => mb_substr($type, 0, 40),
                'title'   => mb_substr($title, 0, 255),
                'message' => $message !== '' ? mb_substr($message, 0, 2000) : null,
                'link'    => mb_substr($link, 0, 255),
            ]);

            self::prune($userId);

            // Xếp vào hàng đợi Web Push (1 job/thiết bị đã bật) — tick push-tick
            // gửi lần lượt. Tự nuốt lỗi, user chưa bật push thì không có job nào.
            PushQueue::enqueue($userId, $type, $title, $message, $link);
        }
        catch (\Throwable $e)
        {
            // Nuốt lỗi — xem ghi chú đầu class.
        }
    }

    /**
     * Như send() nhưng CHỐNG LẶP: bỏ qua nếu user đã có 1 thông báo CHƯA ĐỌC cùng
     * type + link (vd "token sắp cạn" chỉ nhắc 1 lần cho tới khi user đọc).
     */
    public static function sendUnique(int $userId, string $type, string $title, string $message = '', string $link = ''): void
    {
        if ($userId <= 0 || !self::ready())
        {
            return;
        }

        try
        {
            $exists = Notification::where('user_id', $userId)
                ->where('type', $type)
                ->where('link', mb_substr($link, 0, 255))
                ->where('is_read', 0)
                ->first();

            if (hasItems($exists))
            {
                return;
            }
        }
        catch (\Throwable $e)
        {
            return;
        }

        self::send($userId, $type, $title, $message, $link);
    }

    /** Bảng đã sẵn sàng chưa (chưa chạy migration thì thôi, không nổ). */
    protected static function ready(): bool
    {
        try
        {
            return schema()->hasTable('notifications');
        }
        catch (\Throwable $e)
        {
            return false;
        }
    }

    /** Tỉa thông báo cũ nhất khi vượt trần MAX_PER_USER (giữ bảng không phình). */
    protected static function prune(int $userId): void
    {
        try
        {
            $count = (int) DB::table('notifications')->where('user_id', $userId)->count();

            if ($count <= self::MAX_PER_USER)
            {
                return;
            }

            $ids = [];

            foreach (DB::table('notifications')->where('user_id', $userId)
                         ->orderBy('id')->limit($count - self::MAX_PER_USER)->get(['id']) as $r)
            {
                $ids[] = (int) $r->id;
            }

            if (!empty($ids))
            {
                DB::table('notifications')->whereIn('id', $ids)->delete();
            }
        }
        catch (\Throwable $e)
        {
            // Nuốt lỗi.
        }
    }
}
