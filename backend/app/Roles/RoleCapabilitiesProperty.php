<?php

namespace App\Roles;

/**
 * Quyền module Bất động sản (kho hàng / giỏ hàng).
 *
 * `property_view_all` cho phép xem BĐS của TOÀN sàn; không có thì chỉ thấy hàng mình phụ trách
 * (`assigned_user_id`) + hàng dùng chung (`visibility = shared`) — data-scope ở tầng query.
 * Đăng ký vào màn Phân quyền qua filter 'role_capabilities_groups' (xem register.php).
 */
class RoleCapabilitiesProperty
{
    /**
     * @return array [capKey => 'Nhãn tiếng Việt']
     */
    public static function all(): array
    {
        return [
            'property_view'     => 'Xem bất động sản',
            'property_add'      => 'Thêm bất động sản',
            'property_edit'     => 'Sửa bất động sản',
            'property_delete'   => 'Xóa bất động sản',
            'property_view_all' => 'Xem bất động sản toàn sàn',
        ];
    }
}
