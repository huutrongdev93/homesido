<?php

namespace App\Controllers\Api;

use App\Models\Project;
use Illuminate\Support\Str;
use SkillDo\Http\Request;
use SkillDo\Validate\Rule;

/**
 * API danh mục Dự án (projects) — dùng cho select "dự án" ở form Bất động sản.
 * Đọc mở cho `property_view` (nạp dropdown); ghi chỉ admin (`permission`). Xóa CỨNG.
 */
class ProjectApi extends ApiController
{
    public function index(Request $request): void
    {
        $this->requireCap('property_view', 'Bạn không có quyền xem dự án.');

        $items = [];
        foreach (Project::orderByDesc('id')->get() as $row)
        {
            $items[] = $this->transform($row);
        }

        response()->success('success', $items);
    }

    public function add(Request $request): void
    {
        $this->requireCap('permission', 'Bạn không có quyền cấu hình danh mục.');

        $this->validateName($request);

        $id = Project::create($this->collectInput($request));

        response()->success('Đã thêm dự án', ['id' => (int) $id]);
    }

    public function update(Request $request, $id): void
    {
        $this->requireCap('permission', 'Bạn không có quyền cấu hình danh mục.');

        $row = $this->findOrFail((int) $id);

        $this->validateName($request);

        Project::where('id', $row->id)->update($this->collectInput($request));

        response()->success('Đã cập nhật dự án', ['id' => (int) $row->id]);
    }

    public function destroy(Request $request, $id): void
    {
        $this->requireCap('permission', 'Bạn không có quyền cấu hình danh mục.');

        $row = $this->findOrFail((int) $id);

        Project::where('id', $row->id)->delete();

        response()->success('Đã xóa dự án', ['id' => (int) $row->id]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────────

    protected function findOrFail(int $id)
    {
        $row = Project::where('id', $id)->first();

        if (!hasItems($row))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Dự án không tồn tại.');
        }

        return $row;
    }

    protected function validateName(Request $request): void
    {
        $validate = $request->validate(['name' => Rule::make('Tên dự án')->notEmpty()]);

        if ($validate->fails())
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($validate->errors(), $validate->errors()->errors());
        }
    }

    protected function collectInput(Request $request): array
    {
        return [
            'name'          => Str::clear((string) $request->input('name')),
            'developer'     => Str::clear((string) $request->input('developer')),
            'province_code' => max(0, (int) $request->input('province_code')),
            'ward_code'     => max(0, (int) $request->input('ward_code')),
            'address'       => Str::clear((string) $request->input('address')),
            'description'   => Str::clear((string) $request->input('description')),
        ];
    }

    protected function transform($row): array
    {
        return [
            'id'            => (int) $row->id,
            'name'          => (string) $row->name,
            'developer'     => (string) ($row->developer ?? ''),
            'province_code' => (int) $row->province_code,
            'ward_code'     => (int) $row->ward_code,
            'address'       => (string) ($row->address ?? ''),
            'description'   => (string) ($row->description ?? ''),
        ];
    }
}
