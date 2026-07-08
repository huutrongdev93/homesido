<?php

namespace App\Roles;

/**
 * Quyền module Matching (khớp Khách ↔ BĐS — GĐ2).
 *
 * `matching_view` cho phép xem gợi ý khớp (BĐS phù hợp khách / khách phù hợp BĐS) ở trang
 * /matching và trong drawer khách / panel BĐS. `matching_send` cho phép "gửi sản phẩm cho
 * khách" (ghi lịch sử + tương tác vào timeline). Data-scope khách/BĐS vẫn áp như thường ở
 * tầng query. Đăng ký vào màn Phân quyền qua filter 'role_capabilities_groups' (xem register.php).
 */
class RoleCapabilitiesMatching
{
    /**
     * @return array [capKey => 'Nhãn tiếng Việt']
     */
    public static function all(): array
    {
        return [
            'matching_view' => 'Xem gợi ý khớp',
            'matching_send' => 'Gửi sản phẩm cho khách',
        ];
    }
}
