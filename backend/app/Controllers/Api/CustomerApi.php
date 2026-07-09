<?php

namespace App\Controllers\Api;

use App\Models\Customer;
use App\Models\CustomerDemand;
use App\Models\CustomerInteraction;
use App\Models\CustomerTransfer;
use App\Models\Property;
use App\Models\PropertyCustomerMatch;
use App\Services\Care\CareSequence;
use App\Services\Customer\CustomerSheet;
use App\Services\Customer\LeadScorer;
use App\Services\Matching\MatchEngine;
use App\Services\Notification\Notifier;
use App\Services\Storage\PropertyMediaService;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SkillDo\Cms\Models\User;
use SkillDo\Cms\Support\UserRole;
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

    /** Enum hợp lệ cho nhu cầu / tiêu chí khách (customer_demands). */
    const DEMAND_TYPES          = ['buy', 'rent', 'sell', 'consign'];
    const DEMAND_PROPERTY_TYPES = ['apartment', 'house', 'villa', 'land', 'shophouse', 'farmland', 'warehouse', 'office'];
    const DEMAND_PURPOSES       = ['live', 'invest'];
    const DEMAND_DIRECTIONS     = ['east', 'west', 'south', 'north', 'southeast', 'southwest', 'northeast', 'northwest'];

    /** Chức vụ KHÔNG cho phép nhận bàn giao khách (siêu quản trị / master framework). */
    const NON_ASSIGNABLE_ROLES = ['administrator', 'root'];

    /** Trạng thái hợp lệ cho 1 lần "gửi SP cho khách" (property_customer_matches). */
    const MATCH_STATUSES = ['sent', 'interested', 'rejected'];

    /** Trần số gợi ý trả về (Matching) — đủ dùng, tránh trả cả kho. */
    const MATCH_LIMIT = 50;

    /**
     * GET api/customer — danh sách có filter + phân trang, áp data-scope.
     * Query: page, pageSize, keyword (tên/SĐT), pipeline_stage, temperature, assigned_user_id.
     */
    public function index(Request $request): void
    {
        $this->requireCap('customer_view', 'Bạn không có quyền xem khách hàng.');

        [$page, $pageSize, $offset] = $this->paging($request);

        $query = $this->buildListQuery($request);

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

        $data = $this->transform($customer);
        $data['demands'] = $this->listDemands((int) $customer->id);

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

        // Nhận khách → khóa cho sales phụ trách (X ngày) để người khác không nhận trùng.
        $data['locked_until'] = Customer::lockExpiry();

        $id = Customer::create($data);

        if (!is_numeric($id))
        {
            response()->error('Thêm khách hàng thất bại.');
        }

        LeadScorer::recompute((int) $id);

        // AUTO CARE SEQUENCE (GĐ3): khách mới → tự sinh chuỗi lịch chăm từ kịch bản mặc định
        // (care_templates auto_apply=1). Fire-and-forget: lỗi KHÔNG làm hỏng việc tạo khách.
        try
        {
            CareSequence::applyAuto((int) $id);
        }
        catch (\Throwable $e)
        {
            // Bỏ qua: chuỗi chăm sóc chỉ là tiện ích tự động.
        }

        response()->success('Thêm khách hàng thành công', ['id' => (int) $id]);
    }

    /**
     * POST api/customer/{id}/apply-care-sequence — áp CHUỖI kịch bản mặc định (care_templates
     * auto_apply=1) cho 1 khách hiện có; tạo lịch chăm tính từ hôm nay. Cho phép áp cho khách cũ
     * hoặc khi kịch bản thay đổi. Trả về số lịch đã tạo.
     */
    public function applyCareSequence(Request $request, $id): void
    {
        $this->requireCap('customer_edit', 'Bạn không có quyền cập nhật khách hàng.');

        $customer = $this->findOwned((int) $id);

        $created = CareSequence::applyAuto((int) $customer->id);

        response()->success('Đã áp kịch bản chăm sóc', ['created' => $created]);
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

        // Chỉ người xem-toàn-sàn được đổi người phụ trách (bàn giao có endpoint riêng).
        if ($this->canViewAll('customer_view_all'))
        {
            $assigned = (int) $request->input('assigned_user_id');
            if ($assigned > 0)
            {
                $data['assigned_user_id'] = $assigned;
            }
        }
        // Sales chạm vào khách kho chung → tự nhận + khóa (nhận khách từ kho chung).
        elseif ((int) $customer->assigned_user_id === 0)
        {
            $data['assigned_user_id'] = $this->userId();
            $data['locked_until']     = Customer::lockExpiry();
        }

        Customer::where('id', $customer->id)->update($data);

        // Giai đoạn/nhiệt độ có thể đổi → chấm lại điểm tiềm năng.
        LeadScorer::recompute((int) $customer->id);

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
     * GET api/customer/export — xuất khách hàng ra Excel theo filter + scope hiện tại.
     * Query giống index (keyword/pipeline_stage/temperature/assigned_user_id). Tải file .xlsx.
     */
    public function export(Request $request): void
    {
        $this->requireCap('customer_view', 'Bạn không có quyền xem khách hàng.');

        $rows = $this->buildListQuery($request)
            ->orderByDesc('id')
            ->limit(CustomerSheet::MAX_EXPORT)
            ->get();

        $spreadsheet = CustomerSheet::buildExport($rows);

        $this->streamXlsx($spreadsheet, 'khach-hang-' . date('Ymd-His') . '.xlsx');
    }

    /**
     * GET api/customer/import-template — tải file mẫu (header + 1 dòng ví dụ) để nhập.
     */
    public function importTemplate(Request $request): void
    {
        $this->requireCap('customer_add', 'Bạn không có quyền thêm khách hàng.');

        $this->streamXlsx(CustomerSheet::buildTemplate(), 'mau-nhap-khach-hang.xlsx');
    }

    /**
     * POST api/customer/import — nhập khách từ Excel/CSV (field `file`). Chống trùng SĐT theo
     * lô + báo từng dòng lỗi. Khách nhập vào tự gán cho người nhập (assigned_user_id = mình).
     */
    public function import(Request $request): void
    {
        $this->requireCap('customer_add', 'Bạn không có quyền thêm khách hàng.');

        $file = $request->file('file');
        if (empty($file))
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Vui lòng chọn tệp Excel/CSV để nhập.');
        }
        if (is_array($file))
        {
            $file = $file[0];
        }

        try
        {
            $summary = CustomerSheet::import($file, $this->userId());
        }
        catch (\Exception $e)
        {
            // Message tiếng Việt đã set sẵn trong service; truyền $e để được auto-log.
            response()->setStatusCode(422)->setApiStatus(422)->error($e);
        }

        response()->success(
            'Đã nhập ' . $summary['created'] . '/' . $summary['total'] . ' khách hàng'
                . ($summary['skipped'] > 0 ? ', bỏ qua ' . $summary['skipped'] . ' dòng lỗi' : ''),
            $summary
        );
    }

    /** Xuất Spreadsheet ra output dạng tải file .xlsx (send-and-exit). */
    protected function streamXlsx(Spreadsheet $spreadsheet, string $filename): void
    {
        // Ghi thẳng ra output với header tải file — bỏ qua vòng response() JSON.
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0, no-store');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');

        // Giải phóng bộ nhớ + thoát ngay (giống mọi endpoint gọi response()->...; die).
        $spreadsheet->disconnectWorksheets();
        exit;
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

        // Sales tương tác với khách kho chung → tự nhận (nhận khách từ kho chung).
        if (!$this->canViewAll('customer_view_all') && (int) $customer->assigned_user_id === 0)
        {
            Customer::where('id', $customer->id)->update(['assigned_user_id' => $this->userId()]);
        }

        // Cập nhật mốc tương tác + gia hạn khóa (đang chăm tích cực → không bị auto-release).
        Customer::touch((int) $customer->id);

        // Tần suất/độ mới tương tác đổi → chấm lại điểm tiềm năng.
        LeadScorer::recompute((int) $customer->id);

        response()->success('Đã ghi tương tác', ['id' => (int) $intId]);
    }

    /**
     * GET api/customer/{id}/demands — danh sách nhu cầu / tiêu chí của khách.
     */
    public function demands(Request $request, $id): void
    {
        $this->requireCap('customer_view', 'Bạn không có quyền xem khách hàng.');

        $customer = $this->findOwned((int) $id);

        response()->success('success', $this->listDemands((int) $customer->id));
    }

    /**
     * POST api/customer/{id}/demands — thêm 1 nhu cầu cho khách.
     */
    public function addDemand(Request $request, $id): void
    {
        $this->requireCap('customer_edit', 'Bạn không có quyền cập nhật khách hàng.');

        $customer = $this->findOwned((int) $id);

        $data = $this->collectDemandInput($request);
        $data['customer_id'] = (int) $customer->id;
        // Nhu cầu mới → chưa quét → tick match-scan-tick gợi ý BĐS khớp trong kho (auto-matching).
        $data['match_scanned'] = 0;

        $demandId = CustomerDemand::create($data);

        if (!is_numeric($demandId))
        {
            response()->error('Thêm nhu cầu thất bại.');
        }

        response()->success('Đã thêm nhu cầu', ['id' => (int) $demandId]);
    }

    /**
     * PUT api/customer/{id}/demands/{demandId} — cập nhật 1 nhu cầu.
     */
    public function updateDemand(Request $request, $id, $demandId): void
    {
        $this->requireCap('customer_edit', 'Bạn không có quyền cập nhật khách hàng.');

        $customer = $this->findOwned((int) $id);
        $demand   = $this->findDemand((int) $customer->id, (int) $demandId);

        $data = $this->collectDemandInput($request);
        // Đổi tiêu chí → quét lại để gợi ý BĐS khớp theo tiêu chí mới (auto-matching).
        $data['match_scanned'] = 0;
        CustomerDemand::where('id', $demand->id)->update($data);

        response()->success('Đã cập nhật nhu cầu', ['id' => (int) $demand->id]);
    }

    /**
     * DELETE api/customer/{id}/demands/{demandId} — xóa CỨNG 1 nhu cầu (bảng không có xóa mềm).
     */
    public function destroyDemand(Request $request, $id, $demandId): void
    {
        $this->requireCap('customer_edit', 'Bạn không có quyền cập nhật khách hàng.');

        $customer = $this->findOwned((int) $id);
        $demand   = $this->findDemand((int) $customer->id, (int) $demandId);

        CustomerDemand::where('id', $demand->id)->delete();

        response()->success('Đã xóa nhu cầu', ['id' => (int) $demand->id]);
    }

    // ─── Matching (gợi ý BĐS cho khách + gửi SP) ─────────────────────────────────────

    /**
     * GET api/customer/{id}/match-properties?demand_id= — gợi ý BĐS khớp nhu cầu khách.
     * Duyệt nhu cầu is_active (hoặc 1 nhu cầu nếu truyền demand_id), so với kho qua MatchEngine,
     * gộp BĐS theo id giữ điểm cao nhất. Áp data-scope BĐS (của tôi + kho chung) khi không
     * có property_view_all. Trả kèm cờ `already_sent`.
     */
    public function matchProperties(Request $request, $id): void
    {
        $this->requireCap('matching_view', 'Bạn không có quyền xem gợi ý khớp.');

        $customer = $this->findOwned((int) $id);

        $demands = $this->demandsToMatch((int) $customer->id, (int) $request->input('demand_id'));

        // Gộp BĐS theo id: giữ điểm cao nhất + nhu cầu tạo ra điểm đó.
        $best = [];
        foreach ($demands as $demand)
        {
            $query = MatchEngine::matchQueryForDemand($demand);
            if ($query === null)
            {
                continue;
            }

            // Data-scope BĐS: không có quyền toàn sàn → chỉ hàng của mình + kho chung (shared).
            if (!$this->canViewAll('property_view_all'))
            {
                $me = $this->userId();
                $query->where(function ($q) use ($me) {
                    $q->where('assigned_user_id', $me)->orWhere('visibility', 'shared');
                });
            }

            foreach ($query->orderByDesc('id')->limit(self::MATCH_LIMIT)->get() as $row)
            {
                $pid   = (int) $row->id;
                $score = MatchEngine::score($demand, $row);

                if (!isset($best[$pid]) || $score > $best[$pid]['score'])
                {
                    $best[$pid] = [
                        'property'  => $row,
                        'score'     => $score,
                        'demand_id' => (int) $demand->id,
                        'reasons'   => MatchEngine::reasons($demand, $row),
                    ];
                }
            }
        }

        // Đánh dấu BĐS đã gửi cho khách này.
        $sent = [];
        foreach (PropertyCustomerMatch::where('customer_id', $customer->id)->get() as $m)
        {
            $sent[(int) $m->property_id] = true;
        }

        // Ảnh đại diện cho cả lô BĐS gợi ý (1 truy vấn) — dùng chung logic với danh sách BĐS.
        $thumbs = PropertyMediaService::thumbnails(array_column($best, 'property'));

        $items = [];
        foreach ($best as $pid => $b)
        {
            $items[] = $this->transformMatchProperty($b, isset($sent[$pid]), $thumbs[$pid] ?? null);
        }

        usort($items, fn ($a, $b) => $b['score'] <=> $a['score']);

        response()->success('success', array_slice($items, 0, self::MATCH_LIMIT));
    }

    /**
     * GET api/customer/{id}/matches — lịch sử SP đã gửi cho khách (kèm mã/tiêu đề BĐS).
     */
    public function matches(Request $request, $id): void
    {
        $this->requireCap('matching_view', 'Bạn không có quyền xem gợi ý khớp.');

        $customer = $this->findOwned((int) $id);

        $rows = PropertyCustomerMatch::where('customer_id', $customer->id)->orderByDesc('id')->get();

        // Nạp BĐS theo lô để hiện mã/tiêu đề (tránh N+1).
        $propIds = [];
        foreach ($rows as $r)
        {
            $propIds[] = (int) $r->property_id;
        }

        $props = [];
        if (!empty($propIds))
        {
            foreach (Property::whereIn('id', array_values(array_unique($propIds)))->get() as $p)
            {
                $props[(int) $p->id] = $p;
            }
        }

        $thumbs = PropertyMediaService::thumbnails(array_values($props));

        $items = [];
        foreach ($rows as $r)
        {
            $p = $props[(int) $r->property_id] ?? null;
            $items[] = [
                'id'          => (int) $r->id,
                'property_id' => (int) $r->property_id,
                'code'        => $p ? (string) $p->code : '',
                'title'       => $p ? (string) $p->title : '(BĐS đã xóa)',
                'thumbnail'   => $p ? ($thumbs[(int) $p->id] ?? null) : null,
                'price'       => $p ? (float) $p->price : 0,
                'score'       => (int) $r->score,
                'status'      => (string) $r->status,
                'note'        => (string) ($r->note ?? ''),
                'created'     => $r->created,
            ];
        }

        response()->success('success', $items);
    }

    /**
     * POST api/customer/{id}/matches — "gửi sản phẩm cho khách". Ghi/ cập nhật 1 dòng
     * property_customer_matches (1 cặp khách–BĐS) + ghi 1 tương tác vào timeline + touch khách.
     * Body: property_id (bắt buộc), demand_id (tuỳ chọn), note (tuỳ chọn).
     */
    public function sendMatch(Request $request, $id): void
    {
        $this->requireCap('matching_send', 'Bạn không có quyền gửi sản phẩm cho khách.');

        $customer = $this->findOwned((int) $id);

        $propertyId = (int) $request->input('property_id');
        if ($propertyId <= 0)
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Vui lòng chọn bất động sản để gửi.');
        }

        $property = Property::where('id', $propertyId)->first();
        if (!hasItems($property))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Bất động sản không tồn tại.');
        }

        $note = Str::clear((string) $request->input('note'));

        // Điểm khớp + nhu cầu khớp tốt nhất (ưu tiên demand_id nếu truyền).
        $matchedDemandId = 0;
        $score = $this->bestScoreFor((int) $customer->id, $property, (int) $request->input('demand_id'), $matchedDemandId);

        // Upsert 1 dòng/cặp (gửi lại thì cập nhật thay vì tạo trùng).
        $existing = PropertyCustomerMatch::where('customer_id', $customer->id)->where('property_id', $propertyId)->first();

        if (hasItems($existing))
        {
            PropertyCustomerMatch::where('id', $existing->id)->update([
                'demand_id' => $matchedDemandId,
                'user_id'   => $this->userId(),
                'score'     => $score,
                'status'    => 'sent',
                'note'      => $note,
            ]);
            $matchId = (int) $existing->id;
        }
        else
        {
            $matchId = (int) PropertyCustomerMatch::create([
                'customer_id' => (int) $customer->id,
                'property_id' => $propertyId,
                'demand_id'   => $matchedDemandId,
                'user_id'     => $this->userId(),
                'score'       => $score,
                'status'      => 'sent',
                'note'        => $note,
            ]);
        }

        // Ghi 1 tương tác vào timeline khách (đây là "nhịp chăm sóc" — như addInteraction).
        $content = 'Đã gửi SP ' . trim((string) $property->code . ' — ' . (string) $property->title);
        if ($note !== '')
        {
            $content .= ' | Ghi chú: ' . $note;
        }

        CustomerInteraction::create([
            'customer_id'   => (int) $customer->id,
            'user_id'       => $this->userId(),
            'type'          => 'note',
            'content'       => $content,
            'direction'     => 'out',
            'interacted_at' => date('Y-m-d H:i:s'),
        ]);

        // Sales gửi SP cho khách kho chung → tự nhận (auto-claim, giống addInteraction).
        if (!$this->canViewAll('customer_view_all') && (int) $customer->assigned_user_id === 0)
        {
            Customer::where('id', $customer->id)->update(['assigned_user_id' => $this->userId()]);
        }

        Customer::touch((int) $customer->id);
        LeadScorer::recompute((int) $customer->id);

        response()->success('Đã gửi sản phẩm cho khách', ['id' => $matchId]);
    }

    /**
     * PUT api/customer/{id}/matches/{matchId} — cập nhật phản hồi khách (status) + ghi chú.
     */
    public function updateMatchStatus(Request $request, $id, $matchId): void
    {
        $this->requireCap('matching_send', 'Bạn không có quyền cập nhật gợi ý.');

        $customer = $this->findOwned((int) $id);

        $match = PropertyCustomerMatch::where('id', (int) $matchId)->where('customer_id', $customer->id)->first();
        if (!hasItems($match))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Bản ghi gửi SP không tồn tại.');
        }

        $status = (string) $request->input('status');

        $data = ['note' => Str::clear((string) $request->input('note'))];
        if (in_array($status, self::MATCH_STATUSES, true))
        {
            $data['status'] = $status;
        }

        PropertyCustomerMatch::where('id', $match->id)->update($data);

        response()->success('Đã cập nhật trạng thái', ['id' => (int) $match->id]);
    }

    /** Nhu cầu đem so khớp: 1 nhu cầu nếu truyền demandId, ngược lại các nhu cầu đang bật. */
    protected function demandsToMatch(int $customerId, int $demandId)
    {
        $query = CustomerDemand::where('customer_id', $customerId);

        if ($demandId > 0)
        {
            $query->where('id', $demandId);
        }
        else
        {
            $query->where('is_active', 1);
        }

        return $query->get();
    }

    /**
     * Điểm khớp tốt nhất giữa 1 BĐS và các nhu cầu của khách (ưu tiên preferDemandId nếu truyền).
     * Gán $matchedDemandId = nhu cầu cho điểm cao nhất (0 nếu không nhu cầu nào thỏa hard filter).
     */
    protected function bestScoreFor(int $customerId, $property, int $preferDemandId, ?int &$matchedDemandId): int
    {
        $matchedDemandId = 0;
        $best = 0;

        foreach ($this->demandsToMatch($customerId, $preferDemandId) as $demand)
        {
            if (!MatchEngine::matchesProperty($demand, $property))
            {
                continue;
            }

            $s = MatchEngine::score($demand, $property);
            if ($s >= $best)
            {
                $best = $s;
                $matchedDemandId = (int) $demand->id;
            }
        }

        // Chỉ định nhu cầu nhưng BĐS không thỏa hard filter → vẫn cho gửi thủ công gắn nhu cầu đó.
        if ($preferDemandId > 0 && $matchedDemandId === 0)
        {
            $matchedDemandId = $preferDemandId;
        }

        return $best;
    }

    /** Map 1 BĐS gợi ý (kèm điểm/nhu cầu/lý do) → mảng cho FE. */
    protected function transformMatchProperty(array $b, bool $alreadySent, ?string $thumbnail = null): array
    {
        $p = $b['property'];

        return [
            'id'               => (int) $p->id,
            'code'             => (string) $p->code,
            'title'            => (string) $p->title,
            'thumbnail'        => $thumbnail,
            'property_type'    => (string) $p->property_type,
            'transaction_type' => (string) $p->transaction_type,
            'price'            => (float) $p->price,
            'area_usable'      => (float) $p->area_usable,
            'area_land'        => (float) $p->area_land,
            'bedrooms'         => (int) $p->bedrooms,
            'province_code'    => (int) $p->province_code,
            'ward_code'        => (int) $p->ward_code,
            'status'           => (string) $p->status,
            'score'            => (int) $b['score'],
            'demand_id'        => (int) $b['demand_id'],
            'reasons'          => $b['reasons'],
            'already_sent'     => $alreadySent,
        ];
    }

    /**
     * POST api/customer/{id}/transfer — bàn giao khách cho nhân viên khác.
     * Body: to_user_id (bắt buộc), reason (tuỳ chọn). Đổi assigned_user_id, ghi lịch sử
     * customer_transfers, reset khóa cho người nhận, báo cả 2 bên.
     */
    public function transfer(Request $request, $id): void
    {
        $this->requireCap('customer_transfer', 'Bạn không có quyền bàn giao khách hàng.');

        $customer = $this->findOwned((int) $id);

        $toUserId = (int) $request->input('to_user_id');
        if ($toUserId <= 0)
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Vui lòng chọn nhân viên nhận bàn giao.');
        }

        $fromUserId = (int) $customer->assigned_user_id;
        if ($toUserId === $fromUserId)
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Nhân viên này đang phụ trách khách hàng.');
        }

        $target = $this->assertAssignable($toUserId);

        $reason = Str::clear((string) $request->input('reason'));

        Customer::where('id', $customer->id)->update([
            'assigned_user_id' => $toUserId,
            'locked_until'     => Customer::lockExpiry(),
            'is_cold_flagged'  => 0,
        ]);

        CustomerTransfer::create([
            'customer_id'    => (int) $customer->id,
            'from_user_id'   => $fromUserId,
            'to_user_id'     => $toUserId,
            'transferred_by' => $this->userId(),
            'reason'         => $reason,
        ]);

        // Báo người nhận + (nếu có) người giao cũ.
        Notifier::send($toUserId, 'info', 'Bạn được bàn giao khách',
            'Khách "' . $customer->full_name . '" vừa được bàn giao cho bạn.', '/customers');

        if ($fromUserId > 0 && $fromUserId !== $this->userId())
        {
            Notifier::send($fromUserId, 'info', 'Khách đã được bàn giao',
                'Khách "' . $customer->full_name . '" đã được chuyển cho ' . $this->userLabel($target) . '.', '/customers');
        }

        response()->success('Đã bàn giao khách hàng', ['id' => (int) $customer->id, 'to_user_id' => $toUserId]);
    }

    /**
     * GET api/customer/users — danh sách nhân viên có thể nhận bàn giao (cho select ở FE).
     * Cần cap customer_transfer. Loại tài khoản siêu quản trị / master và tài khoản bị khóa.
     */
    public function assignableUsers(Request $request): void
    {
        $this->requireCap('customer_transfer', 'Bạn không có quyền bàn giao khách hàng.');

        $query = User::whereNotIn('role', self::NON_ASSIGNABLE_ROLES)
            ->where('status', '!=', 'trash')
            ->where('status', '!=', 'block')
            ->orderByDesc('id');

        $keyword = Str::clear((string) $request->input('keyword'));
        if (!empty($keyword))
        {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'like', "%$keyword%")
                    ->orWhere('firstname', 'like', "%$keyword%")
                    ->orWhere('lastname', 'like', "%$keyword%")
                    ->orWhere('phone', 'like', "%$keyword%");
            });
        }

        $items = [];
        foreach ($query->get() as $user)
        {
            // Loại tài khoản siêu quản trị theo cap (đồng nhất với chặn ở loginAs / transfer).
            if (UserRole::hasCap((int) $user->id, 'administrator') || UserRole::hasCap((int) $user->id, 'root'))
            {
                continue;
            }

            $items[] = [
                'id'       => (int) $user->id,
                'fullname' => $this->userLabel($user),
                'role'     => (string) $user->role,
            ];
        }

        response()->success('success', $items);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────────

    /** Kiểm tra user đích hợp lệ để nhận bàn giao (tồn tại, hoạt động, không phải siêu quản trị). */
    protected function assertAssignable(int $userId)
    {
        $user = User::find($userId);

        if (!hasItems($user) || in_array($user->role, self::NON_ASSIGNABLE_ROLES, true)
            || UserRole::hasCap($userId, 'administrator') || UserRole::hasCap($userId, 'root'))
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Nhân viên nhận bàn giao không hợp lệ.');
        }

        if (in_array($user->status, ['trash', 'block'], true))
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Nhân viên nhận bàn giao đang bị vô hiệu hóa.');
        }

        return $user;
    }

    /** Tên hiển thị của user (họ tên; rỗng thì dùng username). */
    protected function userLabel($user): string
    {
        $name = trim((string) $user->lastname . ' ' . (string) $user->firstname);

        return $name !== '' ? $name : (string) $user->username;
    }

    /** Tìm khách theo id trong phạm vi được phép; 404/403 nếu không thấy/không thuộc mình. */
    protected function findOwned(int $id)
    {
        $customer = Customer::where('id', $id)->first();

        if (!hasItems($customer))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Khách hàng không tồn tại.');
        }

        // Không có quyền toàn sàn → chỉ được với khách của mình hoặc khách kho chung chưa khóa.
        if (!$this->canViewAll('customer_view_all')
            && (int) $customer->assigned_user_id !== $this->userId()
            && !$this->isSharedUnlocked($customer))
        {
            response()->setStatusCode(403)->setApiStatus(403)->error('Bạn không phụ trách khách hàng này.');
        }

        return $customer;
    }

    /** Nhu cầu của 1 khách (mới nhất trước) → mảng cho FE. */
    protected function listDemands(int $customerId): array
    {
        $items = [];
        foreach (CustomerDemand::where('customer_id', $customerId)->orderByDesc('id')->get() as $demand)
        {
            $items[] = $this->transformDemand($demand);
        }

        return $items;
    }

    /** Map 1 bản ghi nhu cầu → mảng cho FE. */
    protected function transformDemand($demand): array
    {
        return [
            'id'            => (int) $demand->id,
            'demand_type'   => (string) $demand->demand_type,
            'property_type' => (string) $demand->property_type,
            'purpose'       => (string) ($demand->purpose ?? ''),
            'province_code' => (int) $demand->province_code,
            'ward_code'     => (int) $demand->ward_code,
            'budget_min'    => $demand->budget_min,
            'budget_max'    => $demand->budget_max,
            'area_min'      => $demand->area_min,
            'area_max'      => $demand->area_max,
            'bedrooms_min'  => (int) $demand->bedrooms_min,
            'direction'     => (string) ($demand->direction ?? ''),
            'is_active'     => (int) $demand->is_active === 1,
        ];
    }

    /** Tìm nhu cầu thuộc đúng khách; 404 nếu không thấy hoặc không thuộc khách này. */
    protected function findDemand(int $customerId, int $demandId)
    {
        $demand = CustomerDemand::where('id', $demandId)->where('customer_id', $customerId)->first();

        if (!hasItems($demand))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Nhu cầu không tồn tại.');
        }

        return $demand;
    }

    /** Gom + whitelist input cho tạo/sửa nhu cầu (không gồm customer_id — xử lý riêng). */
    protected function collectDemandInput(Request $request): array
    {
        $demandType   = (string) $request->input('demand_type');
        $propertyType = (string) $request->input('property_type');
        $purpose      = (string) $request->input('purpose');
        $direction    = (string) $request->input('direction');

        return [
            'demand_type'   => in_array($demandType, self::DEMAND_TYPES, true) ? $demandType : 'buy',
            'property_type' => in_array($propertyType, self::DEMAND_PROPERTY_TYPES, true) ? $propertyType : '',
            'purpose'       => in_array($purpose, self::DEMAND_PURPOSES, true) ? $purpose : '',
            'province_code' => max(0, (int) $request->input('province_code')),
            'ward_code'     => max(0, (int) $request->input('ward_code')),
            'budget_min'    => max(0, (float) $request->input('budget_min')),
            'budget_max'    => max(0, (float) $request->input('budget_max')),
            'area_min'      => max(0, (float) $request->input('area_min')),
            'area_max'      => max(0, (float) $request->input('area_max')),
            'bedrooms_min'  => max(0, (int) $request->input('bedrooms_min')),
            'direction'     => in_array($direction, self::DEMAND_DIRECTIONS, true) ? $direction : '',
            'is_active'     => (int) $request->input('is_active') === 1 ? 1 : 0,
        ];
    }

    /**
     * Dựng query danh sách khách theo filter + data-scope (dùng chung index + export).
     * Query: keyword (tên/SĐT), pipeline_stage, temperature, assigned_user_id.
     */
    protected function buildListQuery(Request $request)
    {
        $query = Customer::query();

        // Data-scope: không có quyền xem toàn sàn → chỉ thấy khách của mình + khách "kho chung"
        // (chưa ai phụ trách) đang KHÔNG bị khóa (khách bị khóa của người khác coi như đã có chủ).
        if (!$this->canViewAll('customer_view_all'))
        {
            $this->applyScope($query);
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

        return $query;
    }

    /**
     * Áp data-scope cho query list của sales thường (không có customer_view_all): thấy khách của
     * mình HOẶC khách kho chung (assigned_user_id = 0) đang không bị khóa.
     */
    protected function applyScope($query): void
    {
        $now = date('Y-m-d H:i:s');

        $query->where(function ($q) use ($now) {
            $q->where('assigned_user_id', $this->userId())
                ->orWhere(function ($shared) use ($now) {
                    $shared->where('assigned_user_id', 0)
                        ->where(function ($lock) use ($now) {
                            $lock->whereNull('locked_until')->orWhere('locked_until', '<=', $now);
                        });
                });
        });
    }

    /** Khách thuộc kho chung (chưa ai phụ trách) và không còn bị khóa. */
    protected function isSharedUnlocked($customer): bool
    {
        if ((int) $customer->assigned_user_id !== 0)
        {
            return false;
        }

        return empty($customer->locked_until) || $customer->locked_until <= date('Y-m-d H:i:s');
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
