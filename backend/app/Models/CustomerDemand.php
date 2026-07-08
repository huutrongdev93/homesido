<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * Nhu cầu / tiêu chí tìm kiếm của khách (1 khách nhiều nhu cầu). Bảng `customer_demands`.
 */
class CustomerDemand extends Model
{
    protected string $table = 'customer_demands';
}
