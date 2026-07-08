<?php

namespace App\Controllers\Api;

use SkillDo\Cms\Location\Location2;
use SkillDo\Http\Request;
use SkillDo\Routing\Controller\Controller;

/**
 * Danh mục địa giới hành chính VN (2 cấp: tỉnh → phường) qua SkillDo Location2.
 * Dùng cho select địa chỉ ở form tài khoản/đăng ký. Route công khai, không cần đăng nhập.
 */
class LocationApi extends Controller
{
    /** GET /api/location/provinces — [{value, label}] danh sách tỉnh/thành */
    public function provinces(Request $request): void
    {
        $data = [];

        foreach (Location2::provincesOptions() as $id => $name)
        {
            $data[] = ['value' => (int) $id, 'label' => $name];
        }

        response()->success('success', $data);
    }

    /** GET /api/location/wards?province_id= — [{value, label}] phường/xã theo tỉnh */
    public function wards(Request $request): void
    {
        $provinceId = (int) $request->input('province_id');

        if (empty($provinceId))
        {
            response()->success('success', []);
        }

        $data = [];

        foreach (Location2::wardsOptions($provinceId) as $id => $name)
        {
            $data[] = ['value' => (int) $id, 'label' => $name];
        }

        response()->success('success', $data);
    }
}
