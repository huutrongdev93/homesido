<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;
use SkillDo\Traits\Eloquent\SoftDeletes;

/**
 * Khách hàng (core CRM). Soft delete qua cột `trash`. Cột tự nạp từ schema bảng `customers`.
 * Sales phụ trách = `assigned_user_id`; khóa khách = `locked_until`; cảnh báo nguội = `last_interaction_at`.
 *
 * **Khóa khách**: khi sales nhận/tạo khách → `locked_until = now + lockDays()`. Trong hạn khóa,
 * khách không nằm trong "kho chung" nên sales khác không thấy/nhận. Mỗi tương tác/chăm sóc gọi
 * `touch()` để GIA HẠN khóa (đang chăm tích cực → không bị auto-release). Hết hạn mà không ai chạm
 * → tick `customer-release-tick` trả về kho chung (xem `App\Services\Care\CustomerRelease`).
 */
class Customer extends Model
{
    use SoftDeletes;

    protected string $table = 'customers';

    /** Số ngày khóa khách mặc định khi env CUSTOMER_LOCK_DAYS không đặt / không hợp lệ. */
    const LOCK_DAYS_DEFAULT = 7;

    /** Số ngày khóa khách (env `CUSTOMER_LOCK_DAYS`, mặc định 7). */
    public static function lockDays(): int
    {
        $days = (int) env('CUSTOMER_LOCK_DAYS', self::LOCK_DAYS_DEFAULT);

        return $days > 0 ? $days : self::LOCK_DAYS_DEFAULT;
    }

    /** Mốc hết hạn khóa tính từ bây giờ (`Y-m-d H:i:s`). */
    public static function lockExpiry(): string
    {
        return date('Y-m-d H:i:s', strtotime('+' . self::lockDays() . ' days'));
    }

    /**
     * "Chạm" khách khi có tương tác/chăm sóc: cập nhật mốc tương tác gần nhất, gỡ cờ nguội, và
     * GIA HẠN khóa cho sales đang phụ trách (chống bị auto-release khi đang chăm tích cực).
     * Gọi từ MỌI nơi tạo tương tác (CustomerApi::addInteraction, CareApi::complete).
     */
    public static function touch(int $id): void
    {
        self::where('id', $id)->update([
            'last_interaction_at' => date('Y-m-d H:i:s'),
            'is_cold_flagged'     => 0,
            'locked_until'        => self::lockExpiry(),
        ]);
    }
}
