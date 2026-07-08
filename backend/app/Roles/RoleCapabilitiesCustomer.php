<?php

namespace App\Roles;

/**
 * Quyền module Khách hàng (core CRM).
 *
 * `customer_view_all` cho phép xem khách của TOÀN sàn; không có thì chỉ thấy khách mình
 * phụ trách (`assigned_user_id`) — data-scope làm ở tầng query trong controller.
 * `customer_transfer` cho phép bàn giao/thu hồi khách giữa các nhân viên.
 * Đăng ký vào màn Phân quyền qua filter 'role_capabilities_groups' (xem register.php).
 */
class RoleCapabilitiesCustomer
{
    /**
     * @return array [capKey => 'Nhãn tiếng Việt']
     */
    public static function all(): array
    {
        return [
            'customer_view'     => 'Xem khách hàng',
            'customer_add'      => 'Thêm khách hàng',
            'customer_edit'     => 'Sửa khách hàng',
            'customer_delete'   => 'Xóa khách hàng',
            'customer_view_all' => 'Xem khách hàng toàn sàn',
            'customer_transfer' => 'Bàn giao / thu hồi khách',
        ];
    }
}
