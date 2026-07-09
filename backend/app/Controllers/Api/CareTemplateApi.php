<?php

namespace App\Controllers\Api;

use App\Models\CareTemplate;
use Illuminate\Support\Str;
use SkillDo\Http\Request;
use SkillDo\Validate\Rule;

/**
 * API danh mục Kịch bản chăm sóc (care_templates) — mẫu nội dung có biến {{ten_khach}}, dùng để
 * prefill nội dung khi đặt lịch chăm. Đọc mở cho `customer_view`; ghi chỉ admin (`permission`).
 */
class CareTemplateApi extends ApiController
{
    const CHANNELS = ['call', 'sms', 'zalo', 'email'];

    public function index(Request $request): void
    {
        $this->requireCap('customer_view', 'Bạn không có quyền xem kịch bản chăm sóc.');

        $items = [];
        foreach (CareTemplate::orderByDesc('id')->get() as $row)
        {
            $items[] = $this->transform($row);
        }

        response()->success('success', $items);
    }

    public function add(Request $request): void
    {
        $this->requireCap('permission', 'Bạn không có quyền cấu hình danh mục.');

        $this->validateName($request);

        $id = CareTemplate::create($this->collectInput($request));

        response()->success('Đã thêm kịch bản', ['id' => (int) $id]);
    }

    public function update(Request $request, $id): void
    {
        $this->requireCap('permission', 'Bạn không có quyền cấu hình danh mục.');

        $row = $this->findOrFail((int) $id);

        $this->validateName($request);

        CareTemplate::where('id', $row->id)->update($this->collectInput($request));

        response()->success('Đã cập nhật kịch bản', ['id' => (int) $row->id]);
    }

    public function destroy(Request $request, $id): void
    {
        $this->requireCap('permission', 'Bạn không có quyền cấu hình danh mục.');

        $row = $this->findOrFail((int) $id);

        CareTemplate::where('id', $row->id)->delete();

        response()->success('Đã xóa kịch bản', ['id' => (int) $row->id]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────────

    protected function findOrFail(int $id)
    {
        $row = CareTemplate::where('id', $id)->first();

        if (!hasItems($row))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Kịch bản không tồn tại.');
        }

        return $row;
    }

    protected function validateName(Request $request): void
    {
        $validate = $request->validate(['name' => Rule::make('Tên kịch bản')->notEmpty()]);

        if ($validate->fails())
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($validate->errors(), $validate->errors()->errors());
        }
    }

    protected function collectInput(Request $request): array
    {
        $channel = (string) $request->input('channel');
        $active  = $request->input('is_active');

        return [
            'name'        => Str::clear((string) $request->input('name')),
            'channel'     => in_array($channel, self::CHANNELS, true) ? $channel : 'call',
            'content'     => Str::clear((string) $request->input('content')),
            'stage'       => Str::clear((string) $request->input('stage')),
            'is_active'   => ($active === null || $active === '') ? 1 : ((int) (bool) $active),
            // Chuỗi chăm sóc tự động (GĐ3): bước sau N ngày + cờ thuộc chuỗi mặc định + thứ tự.
            'offset_days' => max(0, (int) $request->input('offset_days')),
            'auto_apply'  => (int) (bool) $request->input('auto_apply'),
            'sort_order'  => max(0, (int) $request->input('sort_order')),
        ];
    }

    protected function transform($row): array
    {
        return [
            'id'        => (int) $row->id,
            'name'      => (string) $row->name,
            'channel'   => (string) $row->channel,
            'content'   => (string) ($row->content ?? ''),
            'stage'     => (string) ($row->stage ?? ''),
            'is_active' => (int) $row->is_active === 1,
            'offset_days' => (int) ($row->offset_days ?? 0),
            'auto_apply'  => (int) ($row->auto_apply ?? 0) === 1,
            'sort_order'  => (int) ($row->sort_order ?? 0),
        ];
    }
}
