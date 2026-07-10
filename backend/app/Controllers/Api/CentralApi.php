<?php

namespace App\Controllers\Api;

use App\Services\Tenant\TenantProvisioner;
use SkillDo\Http\Request;
use SkillDo\Routing\Controller\Controller;

/**
 * API TRUNG TÂM multi-tenant (GĐ4 Bước 0) — cấp phát & liệt kê tenant.
 *
 * Đặt ở ROOT (`api/central/*`, ngoài `/{key}`), KHÔNG qua jwt: đây là thao tác BOOTSTRAP hệ thống
 * (tạo bộ bảng cho sàn mới) giống `utils/database`, nên gate bằng biến `UTILS_API_OPEN` (dev/demo
 * mở, production tắt → 403). Bước 4 sẽ thay bằng Portal đăng ký + duyệt tay có xác thực.
 *
 * Vì chạy ở request GỐC (passthrough), connection đang dùng prefix mặc định (.env); mọi thao tác
 * theo prefix `core_`/tenant do TenantProvisioner tự set-and-restore (xem service đó).
 */
class CentralApi extends Controller
{
    protected function ensureOpen(): void
    {
        if (filter_var(env('UTILS_API_OPEN', false), FILTER_VALIDATE_BOOLEAN))
        {
            return;
        }

        response()
            ->setStatusCode(403)
            ->setApiStatus(403)
            ->error('Tiện ích hệ thống đang tắt. Bật UTILS_API_OPEN=true trong .env (chỉ môi trường demo/dev).');
    }

    /** GET api/central/tenants — danh sách sàn (kiểm chứng). */
    public function tenants(Request $request): void
    {
        $this->ensureOpen();

        response()->success('success', ['items' => TenantProvisioner::all()]);
    }

    /**
     * POST api/central/provision — tạo sàn mới.
     * Body: slug (bắt buộc), name, plan_code. Trả mật khẩu admin MỘT LẦN.
     */
    public function provision(Request $request): void
    {
        $this->ensureOpen();

        $slug = strtolower(trim((string) $request->input('slug')));
        $name = trim((string) $request->input('name'));
        $plan = trim((string) $request->input('plan_code'));

        if ($slug === '')
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Thiếu mã sàn (slug).');
        }

        try
        {
            // Slug được validate chặt (regex + reserved) trong Provisioner; name/plan đi qua
            // query builder (parameterized) nên an toàn.
            $result = TenantProvisioner::create($slug, $name, $plan);
        }
        catch (\Exception $e)
        {
            // Truyền Exception vào error() để auto-log + trả message tiếng Việt (xem CLAUDE.md).
            response()->setStatusCode(422)->setApiStatus(422)->error($e);
            return;
        }

        response()->success('Đã tạo sàn "' . $result['slug'] . '".', array_merge($result, [
            'note' => $result['admin_password']
                ? 'Lưu mật khẩu admin này lại và đổi ngay sau khi đăng nhập.'
                : 'Sàn đã tồn tại tài khoản admin từ trước.',
        ]));
    }

    /** POST api/central/rebuild-cache — ghi lại file cache map slug=>prefix từ core_tenants. */
    public function rebuildCache(Request $request): void
    {
        $this->ensureOpen();

        $map = TenantProvisioner::rebuildCache();

        response()->success('Đã cập nhật cache tenants (' . count($map) . ' sàn).', ['map' => $map]);
    }
}
