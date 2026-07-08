<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * Lịch sử bàn giao / thu hồi khách giữa các nhân viên. Bảng `customer_transfers`.
 */
class CustomerTransfer extends Model
{
    protected string $table = 'customer_transfers';
}
