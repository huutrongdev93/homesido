<?php

namespace App\Roles;

/**
 * Danh mục quyền (capabilities) của hệ thống.
 *
 * Mỗi nhóm là 1 method tĩnh trả về mảng [capKey => 'Nhãn tiếng Việt'].
 * Nhóm gốc chỉ còn "phân quyền"; các module nghiệp vụ bổ sung nhóm quyền của mình
 * qua filter 'role_capabilities_groups' (xem app/Roles/register.php).
 */
class RoleCapabilities
{
    /**
     * Quyền quản lý phân quyền (chức vụ)
     * @return array
     */
    public static function roles(): array
    {
        return [
            'permission' => 'Quản lý phân quyền',
        ];
    }
}
