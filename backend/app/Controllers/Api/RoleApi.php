<?php

namespace App\Controllers\Api;

use App\Roles\RoleGroup;
use Illuminate\Support\Str;
use SkillDo\Cms\Models\User;
use SkillDo\Cms\Support\Role;
use SkillDo\Http\Request;
use SkillDo\Routing\Controller\Controller;
use SkillDo\Support\Auth;
use SkillDo\Validate\Rule;

class RoleApi extends Controller
{
    /**
     * Chức vụ hệ thống: không cho sửa quyền / xóa qua API.
     * @var string[]
     */
    protected array $protectedRoles = ['administrator', 'subscriber'];

    /**
     * Chặn nếu không phải siêu quản trị (administrator/root) và không có quyền 'permission'.
     */
    protected function authorize(): void
    {
        if (Auth::hasCap('administrator') || Auth::hasCap('root') || Auth::hasCap('permission'))
        {
            return;
        }

        response()
            ->setStatusCode(403)
            ->setApiStatus(403)
            ->error('Bạn không có quyền truy cập chức năng phân quyền.');
    }

    /**
     * Danh sách chức vụ [key => tên] (bỏ chức vụ hệ thống administrator/root/subscriber).
     */
    public function index(Request $request): void
    {
        $this->authorize();

        $roles = Role::make()->getNames();

        unset($roles['administrator'], $roles['root'], $roles['subscriber']);

        response()->success('success', $roles);
    }

    /**
     * Danh sách toàn bộ quyền, gom theo nhóm:
     * [groupKey => ['label' => ..., 'permission' => [capKey => label]]]
     */
    public function permission(Request $request): void
    {
        $this->authorize();

        response()->success('success', RoleGroup::permission());
    }

    /**
     * Quyền hiện tại của 1 chức vụ.
     * Trả về đầy đủ các quyền đã định nghĩa, đánh dấu true/false.
     */
    public function detail(Request $request, $role): void
    {
        $this->authorize();

        $roleObject = Role::get($role);

        if (!$roleObject)
        {
            response()
                ->setStatusCode(404)
                ->setApiStatus(404)
                ->error('Chức vụ không tồn tại.');
        }

        $capabilities = $roleObject->getCapabilities();

        $data = [];

        foreach (array_keys(RoleGroup::label()) as $capKey)
        {
            $data[$capKey] = !empty($capabilities[$capKey]);
        }

        response()->success('success', [
            'role'         => $role,
            'name'         => $roleObject->getName(),
            'capabilities' => $data,
        ]);
    }

    /**
     * Thêm chức vụ mới. Sinh key từ tên (hoặc nhận 'key' truyền lên).
     */
    public function add(Request $request): void
    {
        $this->authorize();

        $validate = $request->validate([
            'name' => Rule::make('Tên chức vụ')->notEmpty(),
        ]);

        if ($validate->fails())
        {
            response()
                ->setStatusCode(422)
                ->setApiStatus(422)
                ->error($validate->errors(), $validate->errors()->errors());
        }

        $name = Str::clear($request->input('name'));

        $key = $request->input('key');

        $key = !empty($key) ? Str::clear($key) : Str::slug($name);

        if (empty($key))
        {
            response()->error('Tên chức vụ không hợp lệ.');
        }

        if (Role::has($key))
        {
            response()->error('Chức vụ này đã tồn tại.');
        }

        $role = Role::add($key, $name);

        if ($role === false)
        {
            response()->error('Thêm chức vụ thất bại.');
        }

        response()->success('Thêm chức vụ thành công', [
            'key'  => $key,
            'name' => $name,
        ]);
    }

    /**
     * Cập nhật quyền của 1 chức vụ.
     * Nhận 'capabilities' dạng [capKey => bool], chỉ lưu các quyền hợp lệ + được bật.
     */
    public function permissionUpdate(Request $request, $role): void
    {
        $this->authorize();

        if (in_array($role, $this->protectedRoles))
        {
            response()->error('Không thể chỉnh sửa quyền của chức vụ hệ thống.');
        }

        $roleObject = Role::get($role);

        if (!$roleObject)
        {
            response()
                ->setStatusCode(404)
                ->setApiStatus(404)
                ->error('Chức vụ không tồn tại.');
        }

        $input = $request->input('capabilities');

        if (!is_array($input))
        {
            $input = [];
        }

        $capabilities = [];

        foreach (array_keys(RoleGroup::label()) as $capKey)
        {
            if (!empty($input[$capKey]))
            {
                $capabilities[$capKey] = true;
            }
        }

        // Dùng setCapabilities thay cho Role::update: engine coi mảng quyền rỗng
        // là "giữ nguyên quyền cũ" (RoleCollection::update), nên không thể tắt
        // hết quyền của 1 chức vụ. setCapabilities ghi đè trực tiếp + lưu option.
        $roleObject->setCapabilities($capabilities);

        response()->success('Cập nhật phân quyền thành công', [
            'role'         => $role,
            'capabilities' => $capabilities,
        ]);
    }

    /**
     * Xóa chức vụ. Chỉ cho xóa khi chưa có user nào dùng chức vụ này
     * và không phải chức vụ hệ thống.
     */
    public function destroy(Request $request, $role): void
    {
        $this->authorize();

        if (in_array($role, $this->protectedRoles))
        {
            response()->error('Không thể xóa chức vụ hệ thống.');
        }

        if (!Role::has($role))
        {
            response()
                ->setStatusCode(404)
                ->setApiStatus(404)
                ->error('Chức vụ không tồn tại.');
        }

        $used = User::where('role', $role)->count();

        if ($used > 0)
        {
            response()->error('Không thể xóa: đang có ' . $used . ' tài khoản sử dụng chức vụ này.');
        }

        Role::remove($role);

        response()->success('Đã xóa chức vụ', ['role' => $role]);
    }
}
