<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * Kịch bản chăm sóc — mẫu nội dung theo giai đoạn, có biến {{ten_khach}}. Bảng `care_templates`.
 */
class CareTemplate extends Model
{
    protected string $table = 'care_templates';
}
