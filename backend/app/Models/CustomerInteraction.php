<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * Timeline tương tác với khách (gọi/nhắn/gặp/ghi chú...). Bảng `customer_interactions`.
 */
class CustomerInteraction extends Model
{
    protected string $table = 'customer_interactions';
}
