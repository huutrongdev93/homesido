<?php

namespace App\Roles;

/**
 * Gom các quyền (capabilities) thành nhóm để hiển thị ở màn Phân quyền.
 *
 * Module khác có thể thêm nhóm quyền của mình bằng filter:
 *
 *   add_filter('role_capabilities_groups', function (array $groups) {
 *       $groups['post'] = [
 *           'label'        => 'Bài viết',
 *           'capabilities' => RoleCapabilitiesPost::all(),
 *       ];
 *       return $groups;
 *   });
 */
class RoleGroup
{
    /**
     * Cấu trúc nhóm gốc: [groupKey => ['label' => ..., 'capabilities' => [capKey => label]]]
     * @return array
     */
    public static function groups(): array
    {
        $groups = [
            'roles' => [
                'label'        => 'Phân quyền',
                'capabilities' => RoleCapabilities::roles(),
            ],
        ];

        return apply_filters('role_capabilities_groups', $groups);
    }

    /**
     * Cấu trúc gửi cho frontend:
     * [groupKey => ['label' => ..., 'permission' => [capKey => label]]]
     * @return array
     */
    public static function permission(): array
    {
        $result = [];

        foreach (self::groups() as $key => $group)
        {
            $result[$key] = [
                'label'      => $group['label'],
                'permission' => $group['capabilities'],
            ];
        }

        return $result;
    }

    /**
     * Bản đồ phẳng tất cả quyền: [capKey => label]
     * Dùng để validate và liệt kê toàn bộ quyền hợp lệ.
     * @return array
     */
    public static function label(): array
    {
        $labels = [];

        foreach (self::groups() as $group)
        {
            $labels = array_merge($labels, $group['capabilities']);
        }

        return $labels;
    }
}
