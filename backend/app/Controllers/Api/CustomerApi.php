<?php

namespace App\Controllers\Api;

use App\Models\Customer;
use App\Models\CustomerDemand;
use App\Models\CustomerInteraction;
use Illuminate\Support\Str;
use SkillDo\Http\Request;
use SkillDo\Support\Auth;
use SkillDo\Validate\Rule;

/**
 * API Khách hàng (core CRM).
 *
 * Data-scope: không có cap `customer_view_all` → chỉ thấy/sửa khách mình phụ trách
 * (`assigned_user_id = userId()`). Chống trùng SĐT trong toàn sàn (kể cả khách của
 * người khác) — cảnh báo, không cho tạo trùng.
 */
class CustomerApi extends ApiController
{
    /** Giá trị hợp lệ cho các cột enum (whitelist trước khi ghi). */
    const PIPELINE_STAGES = ['new', 'contacting', 'potential', 'negotiating', 'won', 'lost'];
    const TEMPERATURES    = ['hot', 'warm', 'cold'];
    const GENDERS         = ['male', 'female', 'other'];
    const INTERACTION_TYPES = ['call', 'sms', 'zalo', 'email', 'meeting', 'note', 'viewing'];
    const DIRECTIONS        = ['in', 'out'];

    /**
     * GET api/customer — danh sách có filter + phân trang, áp data-scope.
     * Query: page, pageSize, keyword (tên/SĐT), pipeline_stage, temperature, assigned_user_id.
     */
    public function index(Request $request): void
    {
        $this->requireCap('customer_view', 'Bạn không có quyền xem khách hàng.');

        [$page, $pageSize, $offset] = $this->paging($request);

        $query = Customer::query();

        // Data-scope: chỉ xem khách của mình nếu không có quyền xem toàn sàn.
        if (!$this->canViewAll('customer_view_all'))
        {
            $query->where('assigned_user_id', $this->userId());
        }

        $keyword = trim((string) $request->input('keyword'));
        if ($keyword !== '')
        {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('full_name', 'like', $like)->orWhere('phone', 'like', $like);
            });
        }

        $stage = (string) $request->input('pipeline_stage');
        if (in_array($stage, self::PIPELINE_STAGES, true))
        {
            $query->where('pipeline_stage', $stage);
        }

        $temperature = (string) $request->input('temperature');
        if (in_array($temperature, self::TEMPERATURES, true))
        {
            $query->where('temperature', $temperature);
        }

        $assigned = (int) $request->input('assigned_user_id');
        if ($assigned > 0 && $this->canViewAll('customer_view_all'))
        {
            $query->where('assigned_user_id', $assigned);
        }

        $total = (int) $query->count();

        $rows = $query->orderByDesc('id')->offset($offset)->limit($pageSize)->get();

        $items = [];
        foreach ($rows as $row)
        {
            $items[] = $this->transform($row);
        }

        $this->respondList($items, $total, $page, $pageSize);
    }

    /**
     * GET api/customer/{id} — chi tiết 1 khách + danh sách nhu cầu.
     */
    public function detail(Request $request, $id): void
    {
        $this->requireCap('customer_view', 'Bạn không có quyền xem khách hàng.');

        $customer = $this->findOwned((int) $id);

        $demands = [];
        foreach (CustomerDemand::where('customer_id', $customer->id)->orderByDesc('id')->get() as $demand)
        {
            $demands[] = [
                'id'            => (int) $demand->id,
                'demand_type'   => (string) $demand->demand_type,
                'property_type' => (string) $demand->property_type,
                'purpose'       => $demand->purpose,
                'province_code' => (int) $demand->province_code,
                'ward_code'     => (int) $demand->ward_code,
                'budget_min'    => $demand->budget_min,
                'budget_max'    => $demand->budget_max,
                'area_min'      => $demand->area_min,
                'area_max'      => $demand->area_max,
                'bedrooms_min'  => $demand->bedrooms_min,
                'direction'     => $demand->direction,
                'is_active'     => (int) $demand->is_active === 1,
            ];
        }

        $data = $this->transform($customer);
        $data['demands'] = $demands;

        response()->success('success', $data);
    }

    /**
     * POST api/customer — thêm khách. Chống trùng SĐT trong toàn sàn.
     */
    public function add(Request $request): void
    {
        $this->requireCap('customer_add', 'Bạn không có quyền thêm khách hàng.');

        $validate = $request->validate([
            'full_name' => Rule::make('Họ tên')->notEmpty(),
            'phone'     => Rule::make('Số điện thoại')->notEmpty(),
        ]);

        if ($validate->fails())
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($validate->errors(), $validate->errors()->errors());
        }

        $phone = Str::clear($request->input('phone'));

        $this->assertPhoneUnique($phone);

        $data = $this->collectInput($request);
        $data['phone'] = $phone;

        // Sales tạo khách → tự phụ trách. Chỉ người xem-toàn-sàn mới được chỉ định sales khác.
        $assigned = (int) $request->input('assigned_user_id');
        $data['assigned_user_id'] = ($assigned > 0 && $this->canViewAll('customer_view_all')) ? $assigned : $this->userId();

        $id = Customer::create($data);

        if (!is_numeric($id))
        {
            response()->error('Thêm khách hàng thất bại.');
        }

        response()->success('Thêm khách hàng thành công', ['id' => (int) $id]);
    }

    /**
     * PUT api/customer/{id} — cập nhật khách (trong phạm vi được phép).
     */
    public function update(Request $request, $id): void
    {
        $this->requireCap('customer_edit', 'Bạn không có quyền sửa khách hàng.');

        $customer = $this->findOwned((int) $id);

        $validate = $request->validate([
            'full_name' => Rule::make('Họ tên')->notEmpty(),
            'phone'     => Rule::make('Số điện thoại')->notEmpty(),
        ]);

        if ($validate->fails())
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($validate->errors(), $validate->errors()->errors());
        }

        $phone = Str::clear($request->input('phone'));

        $this->assertPhoneUnique($phone, (int) $customer->id);

        $data = $this->collectInput($request);
        $data['phone'] = $phone;

        // Chỉ người xem-toàn-sàn được đổi người phụ trách (bàn giao có màn riêng ở bước sau).
        if ($this->canViewAll('customer_view_all'))
        {
            $assigned = (int) $request->input('assigned_user_id');
            if ($assigned > 0)
            {
                $data['assigned_user_id'] = $assigned;
            }
        }

        Customer::where('id', $customer->id)->update($data);

        response()->success('Cập nhật khách hàng thành công', ['id' => (int) $customer->id]);
    }

    /**
     * DELETE api/customer/{id} — xóa mềm (set trash = 1).
     */
    public function destroy(Request $request, $id): void
    {
        $this->requireCap('customer_delete', 'Bạn không có quyền xóa khách hàng.');

        $customer = $this->findOwned((int) $id);

        Customer::where('id', $customer->id)->trash();

        response()->success('Đã xóa khách hàng', ['id' => (int) $customer->id]);
    }

    /**
     * GET api/customer/{id}/interactions — timeline tương tác của khách.
     */
    public function interactions(Request $request, $id): void
    {
        $this->requireCap('customer_view', 'Bạn không có quyền xem khách hàng.');

        $customer = $this->findOwned((int) $id);

        $items = [];
        foreach (CustomerInteraction::where('customer_id', $customer->id)->orderByDesc('id')->limit(100)->get() as $row)
        {
            $items[] = [
                'id'            => (int) $row->id,
                'user_id'       => (int) $row->user_id,
                'type'          => (string) $row->type,
                'content'       => (string) ($row->content ?? ''),
                'direction'     => (string) ($row->direction ?? ''),
                'interacted_at' => $row->interacted_at,
                'created'       => $row->created,
            ];
        }

        response()->success('success', $items);
    }

    /**
     * POST api/customer/{id}/interactions — ghi 1 tương tác vào timeline + cập nhật
     * last_interaction_at (gỡ cờ nguội). Đây là "nhịp chăm sóc" cơ bản.
     */
    public function addInteraction(Request $request, $id): void
    {
        $this->requireCap('customer_edit', 'Bạn không có quyền cập nhật khách hàng.');

        $customer = $this->findOwned((int) $id);

        $validate = $request->validate([
            'content' => Rule::make('Nội dung')->notEmpty(),
        ]);

        if ($validate->fails())
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($validate->errors(), $validate->errors()->errors());
        }

        $type = (string) $request->input('type');
        $direction = (string) $request->input('direction');

        $intId = CustomerInteraction::create([
            'customer_id'   => (int) $customer->id,
            'user_id'       => $this->userId(),
            'type'          => in_array($type, self::INTERACTION_TYPES, true) ? $type : 'note',
            'content'       => Str::clear((string) $request->input('content')),
            'direction'     => in_array($direction, self::DIRECTIONS, true) ? $direction : '',
            'interacted_at' => date('Y-m-d H:i:s'),
        ]);

        $this->touchInteraction((int) $customer->id);

        response()->success('Đã ghi tương tác', ['id' => (int) $intId]);
    }

    /** Cập nhật mốc tương tác gần nhất + gỡ cờ nguội (gọi khi có tương tác/chăm sóc). */
    protected function touchInteraction(int $customerId): void
    {
        Customer::where('id', $customerId)->update([
            'last_interaction_at' => date('Y-m-d H:i:s'),
            'is_cold_flagged'     => 0,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────────

    /** Tìm khách theo id trong phạm vi được phép; 404/403 nếu không thấy/không thuộc mình. */
    protected function findOwned(int $id)
    {
        $customer = Customer::where('id', $id)->first();

        if (!hasItems($customer))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Khách hàng không tồn tại.');
        }

        if (!$this->canViewAll('customer_view_all') && (int) $customer->assigned_user_id !== $this->userId())
        {
            response()->setStatusCode(403)->setApiStatus(403)->error('Bạn không phụ trách khách hàng này.');
        }

        return $customer;
    }

    /** Chống trùng SĐT trong toàn sàn (loại trừ chính khách đang sửa). */
    protected function assertPhoneUnique(string $phone, int $exceptId = 0): void
    {
        $query = Customer::where('phone', $phone);

        if ($exceptId > 0)
        {
            $query->where('id', '!=', $exceptId);
        }

        $existing = $query->first();

        if (hasItems($existing))
        {
            response()->setStatusCode(422)->setApiStatus(422)
                ->error('Số điện thoại này đã tồn tại trong hệ thống (khách "' . $existing->full_name . '").');
        }
    }

    /** Gom + làm sạch input cho tạo/sửa (không gồm phone/assigned_user_id — xử lý riêng). */
    protected function collectInput(Request $request): array
    {
        $stage = (string) $request->input('pipeline_stage');
        $temperature = (string) $request->input('temperature');
        $gender = (string) $request->input('gender');
        $leadSource = (int) $request->input('lead_source_id');
        $birthYear = (int) $request->input('birth_year');

        return [
            'full_name'      => Str::clear($request->input('full_name')),
            'phone_alt'      => Str::clear($request->input('phone_alt')),
            'email'          => Str::clear($request->input('email')),
            'gender'         => in_array($gender, self::GENDERS, true) ? $gender : '',
            'birth_year'     => $birthYear > 0 ? $birthYear : 0,
            'address'        => Str::clear($request->input('address')),
            'occupation'     => Str::clear($request->input('occupation')),
            'pipeline_stage' => in_array($stage, self::PIPELINE_STAGES, true) ? $stage : 'new',
            'temperature'    => in_array($temperature, self::TEMPERATURES, true) ? $temperature : 'warm',
            'lead_source_id' => $leadSource > 0 ? $leadSource : 0,
            'note'           => Str::clear($request->input('note')),
        ];
    }

    /** Map 1 bản ghi khách → mảng cho FE. */
    protected function transform($row): array
    {
        return [
            'id'                  => (int) $row->id,
            'full_name'           => (string) $row->full_name,
            'phone'               => (string) $row->phone,
            'phone_alt'           => (string) ($row->phone_alt ?? ''),
            'email'               => (string) ($row->email ?? ''),
            'gender'              => $row->gender,
            'birth_year'          => $row->birth_year ? (int) $row->birth_year : null,
            'address'             => (string) ($row->address ?? ''),
            'occupation'          => (string) ($row->occupation ?? ''),
            'pipeline_stage'      => (string) $row->pipeline_stage,
            'temperature'         => (string) $row->temperature,
            'lead_score'          => (int) $row->lead_score,
            'lead_source_id'      => (int) $row->lead_source_id,
            'assigned_user_id'    => (int) $row->assigned_user_id,
            'locked_until'        => $row->locked_until,
            'last_interaction_at' => $row->last_interaction_at,
            'is_cold_flagged'     => (int) $row->is_cold_flagged === 1,
            'note'                => (string) ($row->note ?? ''),
            'created'             => $row->created,
        ];
    }
}
