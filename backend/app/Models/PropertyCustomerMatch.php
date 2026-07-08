<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * Lịch sử "gửi sản phẩm (BĐS) cho khách" trong Matching. Bảng `property_customer_matches`.
 * Mỗi cặp (customer_id, property_id) tối đa 1 dòng — gửi lại thì cập nhật status/score/note.
 */
class PropertyCustomerMatch extends Model
{
    protected string $table = 'property_customer_matches';
}
