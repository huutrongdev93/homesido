<?php

namespace App\Roles;

/**
 * Quyền module Giao dịch (GĐ2).
 *
 * `deal_view_all` xem giao dịch TOÀN sàn (không có → chỉ giao dịch mình phụ trách theo `assigned_user_id`).
 * `commission_manage` cho phép đánh dấu hoa hồng đã chi (thao tác cấp quản lý/kế toán).
 * Đăng ký vào màn Phân quyền qua filter 'role_capabilities_groups' (xem register.php).
 */
class RoleCapabilitiesDeal
{
    /**
     * @return array [capKey => 'Nhãn tiếng Việt']
     */
    public static function all(): array
    {
        return [
            'deal_view'         => 'Xem giao dịch',
            'deal_add'          => 'Tạo giao dịch',
            'deal_edit'         => 'Sửa / chuyển giai đoạn giao dịch',
            'deal_delete'       => 'Xóa giao dịch',
            'deal_view_all'     => 'Xem giao dịch toàn sàn',
            'commission_manage' => 'Quản lý chi hoa hồng',
        ];
    }
}
