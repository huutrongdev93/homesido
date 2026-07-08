<?php

namespace App\Roles;

/**
 * Quyền hệ thống liên quan tới xác thực.
 *
 * `login_as` cho phép một tài khoản "đăng nhập vào tài khoản khác" (impersonation):
 * giữ nguyên phiên gốc rồi thao tác dưới danh nghĩa user khác. `root` mặc định có sẵn.
 * Đăng ký vào màn Phân quyền qua filter 'role_capabilities_groups' (xem register.php).
 */
class RoleCapabilitiesAuth
{
    /**
     * @return array [capKey => 'Nhãn tiếng Việt']
     */
    public static function all(): array
    {
        return [
            'login_as' => 'Đăng nhập vào tài khoản khác',
        ];
    }
}
