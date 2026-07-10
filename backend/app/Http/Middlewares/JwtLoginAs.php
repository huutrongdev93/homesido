<?php

namespace App\Http\Middlewares;

use Closure;
use SkillDo\Api\Repository\TokenRepository;
use SkillDo\Cms\Models\User;
use SkillDo\Cms\Support\UserRole;
use SkillDo\Http\Request;

/**
 * Middleware JWT có hỗ trợ "đăng nhập vào tài khoản khác" (login as / impersonation).
 *
 * Thay thế alias 'jwt' mặc định của framework (xem re-alias ở đầu routes/api.php) để
 * GIỮ NGUYÊN logic xác thực gốc (JwtAuthenticate) nhưng thêm 1 lớp mạo danh:
 *
 *  - Header `Authorization: Bearer <token>` LUÔN là tài khoản GỐC (người thật đăng nhập).
 *    Nhờ vậy quyền "đăng nhập tài khoản khác / chuyển đổi" luôn được xét trên tài khoản gốc,
 *    cho phép quay lại hoặc nhảy sang user khác mà không mất quyền.
 *  - Header `loginAsToken: <token>` (tuỳ chọn) là token của tài khoản ĐANG MẠO DANH.
 *    Chỉ áp dụng khi tài khoản gốc có quyền (`root` hoặc cap `login_as`) và token hợp lệ.
 *
 * Bind vào container:
 *  - `user`          → tài khoản hiệu lực (mạo danh nếu hợp lệ, ngược lại = gốc) → Auth::user().
 *  - `original_user` → luôn là tài khoản gốc (Authorization).
 *  - `is_login_as`   → true khi đang mạo danh.
 *
 * MULTI-TENANT (GĐ4): JWT ký bằng secret DÙNG CHUNG toàn cụm nên token sàn A vẫn hợp lệ CHỮ KÝ ở
 * sàn B. Việc cô lập KHÔNG dựa vào claim mà dựa vào KIẾN TRÚC prefix: `TokenRepository::decode()`
 * gọi `find()` → truy vấn bảng `{prefix}oauth_access_tokens` của tenant ĐANG resolve (index.php đã
 * set env DB_PREFIX) và cache dò token nằm trong THƯ MỤC CACHE RIÊNG của tenant (index.php rebind
 * path.cache). ⇒ token sàn A đưa sang sàn B: cache-miss + không có dòng trong bảng của B → decode
 * ném lỗi → 401. TUYỆT ĐỐI không thêm cache token dùng chung giữa các tenant, sẽ phá cô lập này.
 * Xem docs/features/multi-tenant.md §"Cô lập token & cache".
 */
class JwtLoginAs
{
    public function handle(Request $request, Closure $next)
    {
        $authorization = $request->header('Authorization', '');

        if (empty($authorization))
        {
            response()
                ->setStatusCode(401)
                ->setApiStatus(401)
                ->error('Unauthorized - Missing Authorization header');
        }

        try
        {
            $decoded = TokenRepository::getInstance()->decode($authorization);

            if (empty($decoded->id))
            {
                response()
                    ->setStatusCode(401)
                    ->setApiStatus(401)
                    ->error('Invalid token - Missing user id');
            }

            $original = User::find($decoded->id);

            if (!hasItems($original))
            {
                response()
                    ->setStatusCode(403)
                    ->setApiStatus(403)
                    ->error('You don\'t have the required permissions to access the API');
            }

            if ($this->isInactive($original))
            {
                response()
                    ->setStatusCode(403)
                    ->setApiStatus(403)
                    ->error('Your account has been suspended');
            }

            app()->instance('original_user', $original);

            // Mặc định: tài khoản hiệu lực = tài khoản gốc.
            $effective = $original;

            $loginAsToken = (string) $request->header('loginAsToken', '');

            if ($loginAsToken !== '' && $loginAsToken !== 'null' && $loginAsToken !== 'undefined')
            {
                $target = $this->resolveLoginAs($original, $loginAsToken);

                if (hasItems($target))
                {
                    $effective = $target;

                    app()->instance('is_login_as', true);
                }
                else
                {
                    // Token mạo danh hỏng/hết hạn: KHÔNG âm thầm rơi về tài khoản gốc —
                    // nếu im lặng, mọi thao tác tiếp theo chạy dưới danh nghĩa tài khoản gốc
                    // (thường là root) trong khi UI vẫn hiển thị "đang đăng nhập vào X".
                    // Trả mã riêng để FE bắt được → tự thoát mạo danh rồi reload (phiên gốc còn).
                    response()
                        ->setStatusCode(409)
                        ->setApiStatus(409)
                        ->error('LOGIN_AS_EXPIRED');
                }
            }

            app()->instance('user', $effective);

            return $next($request);
        }
        catch (\Exception $e)
        {
            // Không trả message exception thô cho client (có thể lộ chi tiết DB/cache).
            error_log('[JwtLoginAs] ' . $e->getMessage());

            response()
                ->setStatusCode(401)
                ->setApiStatus(401)
                ->error('Invalid token');
        }
    }

    /**
     * Giải mã loginAsToken thành user mạo danh nếu hợp lệ.
     *
     * Trả null khi token hỏng/hết hạn/target không hợp lệ → handle() trả 409
     * LOGIN_AS_EXPIRED để FE tự thoát mạo danh (phiên gốc vẫn còn, không bị đá ra login).
     */
    protected function resolveLoginAs(User $original, string $loginAsToken): ?User
    {
        // Chỉ tài khoản gốc có quyền mới được mạo danh.
        if (!$this->canLoginAs($original))
        {
            return null;
        }

        try
        {
            $decoded = TokenRepository::getInstance()->decode($loginAsToken);
        }
        catch (\Exception $e)
        {
            return null;
        }

        if (empty($decoded->id))
        {
            return null;
        }

        $target = User::find($decoded->id);

        if (!hasItems($target))
        {
            return null;
        }

        if ($this->isInactive($target))
        {
            return null;
        }

        // Không cho mạo danh tài khoản siêu quản trị (role hoặc cap từ meta) —
        // đồng nhất với check chặn ở AuthController::loginAs.
        if (in_array($target->role, ['administrator', 'root'], true)
            || UserRole::hasCap((int) $target->id, 'administrator')
            || UserRole::hasCap((int) $target->id, 'root'))
        {
            return null;
        }

        return $target;
    }

    /**
     * Tài khoản đang bị vô hiệu hoá (khóa / chờ duyệt / đã xóa)?
     *
     * LƯU Ý: các tài khoản nghiệp vụ của dự án được tạo với status 'publish' (khác mặc định
     * 'public' của model). Vì vậy KHÔNG kiểm tra `!== 'public'` (sẽ chặn nhầm mọi tài khoản
     * publish), mà chỉ chặn các trạng thái vô hiệu — đồng nhất với luồng đăng nhập.
     */
    protected function isInactive($user): bool
    {
        // Đồng nhất với AuthController::INACTIVE_STATUSES (gồm cả 'suspended' — trước đây bỏ sót).
        return isset($user->status) && in_array($user->status, \App\Controllers\Api\AuthController::INACTIVE_STATUSES, true);
    }

    /**
     * Tài khoản gốc có được phép đăng nhập vào tài khoản khác không (root hoặc cap login_as).
     */
    protected function canLoginAs(User $original): bool
    {
        $id = (int) $original->id;

        return UserRole::hasCap($id, 'administrator') || UserRole::hasCap($id, 'root') || UserRole::hasCap($id, 'login_as');
    }
}
