<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * Thông báo in-app của 1 user (tiến trình nền xong, cảnh báo hệ thống...).
 * Cột tự nạp từ schema bảng `notifications`. Ghi qua Services\Notification\Notifier.
 */
class Notification extends Model
{
    protected string $table = 'notifications';
}
