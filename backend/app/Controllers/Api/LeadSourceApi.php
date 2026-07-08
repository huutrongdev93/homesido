<?php

namespace App\Controllers\Api;

use App\Models\LeadSource;
use Illuminate\Support\Str;
use SkillDo\Http\Request;
use SkillDo\Validate\Rule;

/**
 * API danh mục Nguồn khách (lead_sources) — dùng cho select "nguồn khách" ở form Khách hàng.
 *
 * Đọc (index) mở cho ai có `customer_view` để nạp dropdown; ghi (thêm/sửa/xóa) chỉ admin
 * (`permission`) vì đây là cấu hình danh mục. Xóa CỨNG (danh mục không có soft-delete);
 * khách đang tham chiếu id đã xóa chỉ mất nhãn, không lỗi.
 */
class LeadSourceApi extends ApiController
{
    public function index(Request $request): void
    {
        $this->requireCap('customer_view', 'Bạn không có quyền xem nguồn khách.');

        $items = [];
        foreach (LeadSource::orderByDesc('id')->get() as $row)
        {
            $items[] = $this->transform($row);
        }

        response()->success('success', $items);
    }

    public function add(Request $request): void
    {
        $this->requireCap('permission', 'Bạn không có quyền cấu hình danh mục.');

        $this->validateName($request);

        $id = LeadSource::create([
            'name'      => Str::clear((string) $request->input('name')),
            'is_active' => $this->boolInput($request, 'is_active'),
        ]);

        response()->success('Đã thêm nguồn khách', ['id' => (int) $id]);
    }

    public function update(Request $request, $id): void
    {
        $this->requireCap('permission', 'Bạn không có quyền cấu hình danh mục.');

        $row = $this->findOrFail((int) $id);

        $this->validateName($request);

        LeadSource::where('id', $row->id)->update([
            'name'      => Str::clear((string) $request->input('name')),
            'is_active' => $this->boolInput($request, 'is_active'),
        ]);

        response()->success('Đã cập nhật nguồn khách', ['id' => (int) $row->id]);
    }

    public function destroy(Request $request, $id): void
    {
        $this->requireCap('permission', 'Bạn không có quyền cấu hình danh mục.');

        $row = $this->findOrFail((int) $id);

        LeadSource::where('id', $row->id)->delete();

        response()->success('Đã xóa nguồn khách', ['id' => (int) $row->id]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────────

    protected function findOrFail(int $id)
    {
        $row = LeadSource::where('id', $id)->first();

        if (!hasItems($row))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Nguồn khách không tồn tại.');
        }

        return $row;
    }

    protected function validateName(Request $request): void
    {
        $validate = $request->validate(['name' => Rule::make('Tên nguồn')->notEmpty()]);

        if ($validate->fails())
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($validate->errors(), $validate->errors()->errors());
        }
    }

    /** Đọc cờ bật/tắt: thiếu → mặc định bật (1). */
    protected function boolInput(Request $request, string $key): int
    {
        $v = $request->input($key);

        return ($v === null || $v === '') ? 1 : ((int) (bool) $v);
    }

    protected function transform($row): array
    {
        return [
            'id'        => (int) $row->id,
            'name'      => (string) $row->name,
            'is_active' => (int) $row->is_active === 1,
        ];
    }
}
