<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;
use SkillDo\Traits\Eloquent\SoftDeletes;

/**
 * Khách hàng (core CRM). Soft delete qua cột `trash`. Cột tự nạp từ schema bảng `customers`.
 * Sales phụ trách = `assigned_user_id`; khóa khách = `locked_until`; cảnh báo nguội = `last_interaction_at`.
 */
class Customer extends Model
{
    use SoftDeletes;

    protected string $table = 'customers';
}
