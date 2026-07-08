<?php

namespace App\Roles;

/**
 * Quyền module Lịch hẹn dẫn khách (GĐ2).
 *
 * `appointment_view_all` cho xem lịch hẹn TOÀN sàn; không có thì chỉ thấy lịch mình phụ trách
 * (`assigned_user_id`) — data-scope làm ở tầng query trong controller (giống Khách hàng/Care).
 * Đăng ký vào màn Phân quyền qua filter 'role_capabilities_groups' (xem register.php).
 */
class RoleCapabilitiesAppointment
{
    /**
     * @return array [capKey => 'Nhãn tiếng Việt']
     */
    public static function all(): array
    {
        return [
            'appointment_view'     => 'Xem lịch hẹn',
            'appointment_add'      => 'Tạo lịch hẹn',
            'appointment_edit'     => 'Sửa / cập nhật lịch hẹn',
            'appointment_delete'   => 'Hủy lịch hẹn',
            'appointment_view_all' => 'Xem lịch hẹn toàn sàn',
        ];
    }
}
