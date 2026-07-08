<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * Chủ nhà (hàng ký gửi) — liên kết với BĐS qua `properties.owner_id`. Bảng `property_owners`.
 */
class PropertyOwner extends Model
{
    protected string $table = 'property_owners';
}
