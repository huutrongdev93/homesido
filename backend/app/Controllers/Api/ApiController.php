<?php

namespace App\Controllers\Api;

use SkillDo\Http\Request;
use SkillDo\Routing\Controller\Controller;
use SkillDo\Support\Auth;

/**
 * Base controller cho các module nghiệp vụ CRM. Gom lại 2 thứ base framework còn thiếu:
 *
 * - **Phân trang** (`paging`/`respondList`): framework chưa có paginate() → tự tính offset/limit
 *   + count và trả về khuôn thống nhất {items, total, page, pageSize} cho FE (RTK Query).
 * - **Gate quyền + data-scope** (`requireCap`/`canViewAll`): administrator/root bypass mọi cap.
 *   `canViewAll` quyết định user thấy TOÀN sàn hay chỉ dữ liệu của mình — controller tự áp
 *   điều kiện `assigned_user_id = userId()` khi không có cap *_view_all (KHÔNG có Global Scope
 *   như Laravel nên phải áp thủ công ở MỌI query list — luôn đi qua helper để tránh sót).
 */
abstract class ApiController extends Controller
{
    const PAGE_SIZE_DEFAULT = 20;
    const PAGE_SIZE_MAX = 100;

    /** User đang đăng nhập (theo login-as = tài khoản hiệu lực). 401 nếu chưa đăng nhập. */
    protected function currentUser()
    {
        $user = Auth::user();

        if (!hasItems($user))
        {
            response()->setStatusCode(401)->setApiStatus(401)->error('Chưa đăng nhập.');
        }

        return $user;
    }

    protected function userId(): int
    {
        return (int) $this->currentUser()->id;
    }

    /** Chặn nếu không có 1 trong các cap truyền vào (administrator/root luôn qua). */
    protected function requireCap(array|string $caps, string $message = 'Bạn không có quyền thực hiện thao tác này.'): void
    {
        if (Auth::hasCap('administrator') || Auth::hasCap('root'))
        {
            return;
        }

        foreach ((array) $caps as $cap)
        {
            if (Auth::hasCap($cap))
            {
                return;
            }
        }

        response()->setStatusCode(403)->setApiStatus(403)->error($message);
    }

    /** True nếu user được xem dữ liệu toàn sàn (administrator/root hoặc có cap *_view_all). */
    protected function canViewAll(string $viewAllCap): bool
    {
        return Auth::hasCap('administrator') || Auth::hasCap('root') || Auth::hasCap($viewAllCap);
    }

    /** [page, pageSize, offset] từ query. page >= 1; pageSize trong [1, PAGE_SIZE_MAX]. */
    protected function paging(Request $request): array
    {
        $page = (int) $request->input('page');
        if ($page < 1)
        {
            $page = 1;
        }

        $pageSize = (int) $request->input('pageSize');
        if ($pageSize < 1)
        {
            $pageSize = self::PAGE_SIZE_DEFAULT;
        }
        if ($pageSize > self::PAGE_SIZE_MAX)
        {
            $pageSize = self::PAGE_SIZE_MAX;
        }

        return [$page, $pageSize, ($page - 1) * $pageSize];
    }

    /** Trả danh sách theo khuôn thống nhất cho FE. */
    protected function respondList(array $items, int $total, int $page, int $pageSize): void
    {
        response()->success('success', [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
        ]);
    }
}
