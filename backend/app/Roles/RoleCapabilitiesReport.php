<?php

namespace App\Roles;

/**
 * Quyền module Báo cáo (GĐ2).
 *
 * `report_view` xem báo cáo của MÌNH (data-scope theo assigned_user_id); `report_view_all` xem báo cáo
 * TOÀN sàn + hiệu suất theo từng nhân viên (cấp quản lý). Đăng ký qua filter 'role_capabilities_groups'.
 */
class RoleCapabilitiesReport
{
    /**
     * @return array [capKey => 'Nhãn tiếng Việt']
     */
    public static function all(): array
    {
        return [
            'report_view'     => 'Xem báo cáo',
            'report_view_all' => 'Xem báo cáo toàn sàn',
        ];
    }
}
