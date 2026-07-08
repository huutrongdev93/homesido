<?php

namespace App\Controllers\Api;

use App\Models\PropertyOwner;
use Illuminate\Support\Str;
use SkillDo\Http\Request;
use SkillDo\Validate\Rule;

/**
 * API danh mục Chủ nhà (property_owners) — dùng cho select "chủ nhà" ở form Bất động sản.
 * Đọc mở cho `property_view`; ghi chỉ admin (`permission`). Xóa CỨNG.
 */
class PropertyOwnerApi extends ApiController
{
    public function index(Request $request): void
    {
        $this->requireCap('property_view', 'Bạn không có quyền xem chủ nhà.');

        $items = [];
        foreach (PropertyOwner::orderByDesc('id')->get() as $row)
        {
            $items[] = $this->transform($row);
        }

        response()->success('success', $items);
    }

    public function add(Request $request): void
    {
        $this->requireCap('permission', 'Bạn không có quyền cấu hình danh mục.');

        $this->validateName($request);

        $id = PropertyOwner::create($this->collectInput($request));

        response()->success('Đã thêm chủ nhà', ['id' => (int) $id]);
    }

    public function update(Request $request, $id): void
    {
        $this->requireCap('permission', 'Bạn không có quyền cấu hình danh mục.');

        $row = $this->findOrFail((int) $id);

        $this->validateName($request);

        PropertyOwner::where('id', $row->id)->update($this->collectInput($request));

        response()->success('Đã cập nhật chủ nhà', ['id' => (int) $row->id]);
    }

    public function destroy(Request $request, $id): void
    {
        $this->requireCap('permission', 'Bạn không có quyền cấu hình danh mục.');

        $row = $this->findOrFail((int) $id);

        PropertyOwner::where('id', $row->id)->delete();

        response()->success('Đã xóa chủ nhà', ['id' => (int) $row->id]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────────

    protected function findOrFail(int $id)
    {
        $row = PropertyOwner::where('id', $id)->first();

        if (!hasItems($row))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Chủ nhà không tồn tại.');
        }

        return $row;
    }

    protected function validateName(Request $request): void
    {
        $validate = $request->validate(['full_name' => Rule::make('Tên chủ nhà')->notEmpty()]);

        if ($validate->fails())
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($validate->errors(), $validate->errors()->errors());
        }
    }

    protected function collectInput(Request $request): array
    {
        return [
            'full_name' => Str::clear((string) $request->input('full_name')),
            'phone'     => Str::clear((string) $request->input('phone')),
            'email'     => Str::clear((string) $request->input('email')),
            'note'      => Str::clear((string) $request->input('note')),
        ];
    }

    protected function transform($row): array
    {
        return [
            'id'        => (int) $row->id,
            'full_name' => (string) $row->full_name,
            'phone'     => (string) ($row->phone ?? ''),
            'email'     => (string) ($row->email ?? ''),
            'note'      => (string) ($row->note ?? ''),
        ];
    }
}
