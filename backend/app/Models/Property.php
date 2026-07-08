<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;
use SkillDo\Traits\Eloquent\SoftDeletes;

/**
 * Bất động sản (kho hàng / giỏ hàng). Soft delete qua cột `trash`. Bảng `properties`.
 * Kho chung/riêng = `visibility`; trạng thái = `status`; người phụ trách = `assigned_user_id`.
 */
class Property extends Model
{
    use SoftDeletes;

    protected string $table = 'properties';
}
