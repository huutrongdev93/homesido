<?php

/**
 * Đăng ký các nhóm quyền (capabilities) bổ sung vào màn Phân quyền.
 *
 * File này được require ở đầu routes/api.php (điểm boot của app) nên filter được
 * gắn trước khi RoleGroup::groups() chạy. Module mới chỉ cần thêm 1 nhánh ở đây
 * (kèm 1 class RoleCapabilities* tương ứng), không phải sửa lõi RoleGroup.
 *
 * Ví dụ thêm nhóm quyền cho một module "Bài viết":
 *
 *   use App\Roles\RoleCapabilitiesPost;
 *   $groups['post'] = [
 *       'label'        => 'Bài viết',
 *       'capabilities' => RoleCapabilitiesPost::all(),
 *   ];
 */

use App\Roles\RoleCapabilitiesAppointment;
use App\Roles\RoleCapabilitiesAuth;
use App\Roles\RoleCapabilitiesCustomer;
use App\Roles\RoleCapabilitiesMatching;
use App\Roles\RoleCapabilitiesProperty;

add_filter('role_capabilities_groups', function (array $groups) {

    $groups['auth'] = [
        'label'        => 'Hệ thống',
        'capabilities' => RoleCapabilitiesAuth::all(),
    ];

    $groups['customer'] = [
        'label'        => 'Khách hàng',
        'capabilities' => RoleCapabilitiesCustomer::all(),
    ];

    $groups['property'] = [
        'label'        => 'Bất động sản',
        'capabilities' => RoleCapabilitiesProperty::all(),
    ];

    $groups['matching'] = [
        'label'        => 'Khớp lệnh (Matching)',
        'capabilities' => RoleCapabilitiesMatching::all(),
    ];

    $groups['appointment'] = [
        'label'        => 'Lịch hẹn dẫn khách',
        'capabilities' => RoleCapabilitiesAppointment::all(),
    ];

    return $groups;
});
