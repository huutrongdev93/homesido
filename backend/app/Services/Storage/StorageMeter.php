<?php

namespace App\Services\Storage;

use SkillDo\Cms\Models\User;

/**
 * Kế toán dung lượng lưu trữ THEO TỪNG NHÂN VIÊN (phục vụ bán gói theo dung lượng).
 *
 * Tổng byte đã dùng của mỗi user lưu ở user meta `storage_used_bytes`. Mọi file upload cộng vào,
 * mọi file bị xóa (kể cả khi xóa hẳn dữ liệu chứa media) trừ ra — luôn theo NGƯỜI UPLOAD
 * (`property_media.user_id`). getMeta trả '' khi chưa có key nên luôn ép `(int)`.
 */
class StorageMeter
{
    const META_KEY = 'storage_used_bytes';

    /** Dung lượng đã dùng của 1 user (byte). */
    public static function used(int $userId): int
    {
        if ($userId <= 0)
        {
            return 0;
        }

        return max(0, (int) User::getMeta($userId, self::META_KEY));
    }

    /** Cộng dung lượng khi upload. */
    public static function add(int $userId, int $bytes): void
    {
        if ($userId <= 0 || $bytes <= 0)
        {
            return;
        }

        User::updateMeta($userId, self::META_KEY, self::used($userId) + $bytes);
    }

    /** Trừ dung lượng khi xóa file (không âm). */
    public static function subtract(int $userId, int $bytes): void
    {
        if ($userId <= 0 || $bytes <= 0)
        {
            return;
        }

        User::updateMeta($userId, self::META_KEY, max(0, self::used($userId) - $bytes));
    }

    /** Hạn mức mỗi user (byte); 0 = không giới hạn. Env `STORAGE_QUOTA_MB_PER_USER`. */
    public static function quota(): int
    {
        $mb = (int) env('STORAGE_QUOTA_MB_PER_USER', 0);

        return $mb > 0 ? $mb * 1024 * 1024 : 0;
    }

    /** True nếu cộng thêm $bytes sẽ vượt hạn mức của user (khi có hạn mức). */
    public static function wouldExceed(int $userId, int $bytes): bool
    {
        $quota = self::quota();

        return $quota > 0 && (self::used($userId) + $bytes) > $quota;
    }
}
