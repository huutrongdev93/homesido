<?php

namespace App\Controllers\Api;

use App\Models\Customer;
use App\Models\CareSchedule;
use App\Models\CustomerInteraction;
use Illuminate\Support\Str;
use SkillDo\Http\Request;
use SkillDo\Validate\Rule;

/**
 * API Chăm sóc chủ động — lịch chăm sóc / nhắc việc (care_schedules).
 *
 * Là "trái tim" của chăm sóc chủ động: sales đặt lịch cho khách → đến hạn hiện ở "Cần chăm hôm nay"
 * → làm xong ghi kết quả (tạo 1 tương tác vào timeline + cập nhật last_interaction_at) + đặt lịch tiếp.
 * Data-scope theo `assigned_user_id` (giống Khách hàng): không có customer_view_all → chỉ việc của mình.
 */
class CareApi extends ApiController
{
    const CARE_TYPES = ['call', 'sms', 'zalo', 'email', 'meeting'];

    /**
     * GET api/care?customer_id= — lịch chăm của 1 khách (mọi trạng thái, mới nhất trước).
     */
    public function index(Request $request): void
    {
        $this->requireCap('customer_view', 'Bạn không có quyền xem chăm sóc.');

        $customerId = (int) $request->input('customer_id');
        if ($customerId <= 0)
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Thiếu customer_id.');
        }

        $this->findCustomer($customerId); // kiểm tra scope

        $query = CareSchedule::where('customer_id', $customerId);
        if (!$this->canViewAll('customer_view_all'))
        {
            $query->where('assigned_user_id', $this->userId());
        }

        $items = [];
        foreach ($query->orderByDesc('id')->limit(100)->get() as $row)
        {
            $items[] = $this->transform($row);
        }

        response()->success('success', $items);
    }

    /**
     * GET api/care/today — việc cần chăm đến hạn hôm nay (gồm cả quá hạn), của tôi (hoặc all nếu view_all).
     * Kèm thông tin khách (tên/SĐT) + cờ overdue.
     */
    public function today(Request $request): void
    {
        $this->requireCap('customer_view', 'Bạn không có quyền xem chăm sóc.');

        $endToday   = date('Y-m-d 23:59:59');
        $startToday = date('Y-m-d 00:00:00');

        $query = CareSchedule::where('status', 'pending')->where('scheduled_at', '<=', $endToday);
        if (!$this->canViewAll('customer_view_all'))
        {
            $query->where('assigned_user_id', $this->userId());
        }

        $rows = $query->orderBy('scheduled_at')->limit(200)->get();

        // Nạp khách theo lô để hiển thị tên/SĐT (không có eager load).
        $customerIds = [];
        foreach ($rows as $row)
        {
            $customerIds[(int) $row->customer_id] = true;
        }

        $customers = [];
        if (!empty($customerIds))
        {
            foreach (Customer::whereIn('id', array_keys($customerIds))->get() as $c)
            {
                $customers[(int) $c->id] = ['full_name' => (string) $c->full_name, 'phone' => (string) $c->phone];
            }
        }

        $items = [];
        foreach ($rows as $row)
        {
            $item = $this->transform($row);
            $cid = (int) $row->customer_id;
            $item['customer'] = $customers[$cid] ?? ['full_name' => '(đã xóa)', 'phone' => ''];
            $item['overdue']  = ((string) $row->scheduled_at < $startToday);
            $items[] = $item;
        }

        response()->success('success', $items);
    }

    /**
     * POST api/care — đặt lịch chăm cho 1 khách.
     */
    public function add(Request $request): void
    {
        $this->requireCap('customer_edit', 'Bạn không có quyền đặt lịch chăm sóc.');

        $customerId = (int) $request->input('customer_id');
        $customer = $this->findCustomer($customerId);

        $validate = $request->validate([
            'scheduled_at' => Rule::make('Thời điểm chăm')->notEmpty(),
        ]);

        if ($validate->fails())
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($validate->errors(), $validate->errors()->errors());
        }

        $scheduledAt = $this->normalizeDatetime($request->input('scheduled_at'));

        $type = (string) $request->input('type');

        $id = CareSchedule::create([
            'customer_id'      => (int) $customer->id,
            // Người phụ trách việc = sales của khách (nếu có), ngược lại người tạo.
            'assigned_user_id' => ((int) $customer->assigned_user_id > 0) ? (int) $customer->assigned_user_id : $this->userId(),
            'care_template_id' => max(0, (int) $request->input('care_template_id')),
            'type'             => in_array($type, self::CARE_TYPES, true) ? $type : 'call',
            'scheduled_at'     => $scheduledAt,
            'content'          => Str::clear((string) $request->input('content')),
            'status'           => 'pending',
        ]);

        if (!is_numeric($id))
        {
            response()->error('Đặt lịch chăm sóc thất bại.');
        }

        response()->success('Đã đặt lịch chăm sóc', ['id' => (int) $id]);
    }

    /**
     * PUT api/care/{id}/complete — hoàn thành 1 lịch chăm: ghi kết quả → tạo tương tác vào
     * timeline + cập nhật last_interaction_at của khách. Cho phép đặt lịch tiếp (next_scheduled_at).
     */
    public function complete(Request $request, $id): void
    {
        $this->requireCap('customer_edit', 'Bạn không có quyền cập nhật chăm sóc.');

        $care = $this->findCare((int) $id);

        if ((string) $care->status !== 'pending')
        {
            response()->error('Lịch chăm này đã được xử lý.');
        }

        $resultNote = Str::clear((string) $request->input('result_note'));

        CareSchedule::where('id', $care->id)->update([
            'status'       => 'done',
            'completed_at' => date('Y-m-d H:i:s'),
            'result_note'  => $resultNote,
        ]);

        // Ghi vào timeline tương tác + cập nhật mốc chăm sóc của khách.
        CustomerInteraction::create([
            'customer_id'   => (int) $care->customer_id,
            'user_id'       => $this->userId(),
            'type'          => (string) $care->type,
            'content'       => ($resultNote !== '') ? $resultNote : (string) ($care->content ?? ''),
            'direction'     => 'out',
            'interacted_at' => date('Y-m-d H:i:s'),
        ]);

        Customer::where('id', (int) $care->customer_id)->update([
            'last_interaction_at' => date('Y-m-d H:i:s'),
            'is_cold_flagged'     => 0,
        ]);

        // Tuỳ chọn: đặt lịch chăm tiếp theo.
        $next = trim((string) $request->input('next_scheduled_at'));
        $nextId = 0;
        if ($next !== '')
        {
            $nextType = (string) $request->input('next_type');
            $nextId = CareSchedule::create([
                'customer_id'      => (int) $care->customer_id,
                'assigned_user_id' => (int) $care->assigned_user_id,
                'type'             => in_array($nextType, self::CARE_TYPES, true) ? $nextType : (string) $care->type,
                'scheduled_at'     => $this->normalizeDatetime($next),
                'content'          => Str::clear((string) $request->input('next_content')),
                'status'           => 'pending',
            ]);
        }

        response()->success('Đã hoàn thành chăm sóc', ['id' => (int) $care->id, 'next_id' => (int) $nextId]);
    }

    /**
     * DELETE api/care/{id} — hủy 1 lịch chăm (status = canceled).
     */
    public function cancel(Request $request, $id): void
    {
        $this->requireCap('customer_edit', 'Bạn không có quyền hủy chăm sóc.');

        $care = $this->findCare((int) $id);

        CareSchedule::where('id', $care->id)->update(['status' => 'canceled']);

        response()->success('Đã hủy lịch chăm sóc', ['id' => (int) $care->id]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────────

    /** Khách trong phạm vi được phép (404/403). Dùng để kiểm tra scope khi thao tác care. */
    protected function findCustomer(int $id)
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

    /** Lịch chăm trong phạm vi được phép (404/403). */
    protected function findCare(int $id)
    {
        $care = CareSchedule::where('id', $id)->first();

        if (!hasItems($care))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Lịch chăm sóc không tồn tại.');
        }

        if (!$this->canViewAll('customer_view_all') && (int) $care->assigned_user_id !== $this->userId())
        {
            response()->setStatusCode(403)->setApiStatus(403)->error('Bạn không phụ trách lịch chăm này.');
        }

        return $care;
    }

    /** Chuẩn hóa datetime FE gửi về 'Y-m-d H:i:s' (422 nếu không hợp lệ). */
    protected function normalizeDatetime($value): string
    {
        $ts = strtotime((string) $value);
        if ($ts === false)
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Thời điểm không hợp lệ.');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /** Map 1 lịch chăm → mảng cho FE. */
    protected function transform($row): array
    {
        return [
            'id'               => (int) $row->id,
            'customer_id'      => (int) $row->customer_id,
            'assigned_user_id' => (int) $row->assigned_user_id,
            'type'             => (string) $row->type,
            'scheduled_at'     => $row->scheduled_at,
            'content'          => (string) ($row->content ?? ''),
            'status'           => (string) $row->status,
            'completed_at'     => $row->completed_at,
            'result_note'      => (string) ($row->result_note ?? ''),
            'created'          => $row->created,
        ];
    }
}
