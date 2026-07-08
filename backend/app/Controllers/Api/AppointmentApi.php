<?php

namespace App\Controllers\Api;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Property;
use App\Services\Customer\LeadScorer;
use Illuminate\Support\Str;
use SkillDo\Http\Request;
use SkillDo\Validate\Rule;

/**
 * API Lịch hẹn dẫn khách (appointments) — GĐ2.
 *
 * Sales tạo buổi hẹn dẫn khách đi xem BĐS → đến gần giờ tick `appointment-reminder-tick` nhắc →
 * dẫn xong ghi kết quả (`complete`): buổi hẹn `done` + kết quả (`result`) + tạo 1 tương tác
 * "Dẫn xem nhà" vào timeline khách (giống CareApi::complete: touch + rescore điểm).
 *
 * Data-scope theo `assigned_user_id` (giống Khách hàng/Care): không có appointment_view_all →
 * chỉ thấy/sửa buổi hẹn của mình. Vòng đời trạng thái (không xoá mềm): pending → done/no_show/canceled.
 */
class AppointmentApi extends ApiController
{
    const STATUSES = ['pending', 'done', 'canceled', 'no_show'];
    const RESULTS  = ['interested', 'considering', 'rejected', 'deposited'];

    // Nhãn kết quả (để dựng nội dung tương tác timeline khi hoàn thành).
    const RESULT_LABELS = [
        'interested'  => 'Quan tâm',
        'considering' => 'Đang cân nhắc',
        'rejected'    => 'Từ chối',
        'deposited'   => 'Đặt cọc',
    ];

    /**
     * GET api/appointment — danh sách buổi hẹn (phân trang) + lọc.
     * ?status= &customer_id= &property_id= &from=YYYY-MM-DD &to=YYYY-MM-DD
     * pending sắp tới xếp sớm-trước; còn lại mới-trước. Kèm khách/BĐS + cờ overdue.
     */
    public function index(Request $request): void
    {
        $this->requireCap('appointment_view', 'Bạn không có quyền xem lịch hẹn.');

        [$page, $pageSize, $offset] = $this->paging($request);

        $query = Appointment::query();

        if (!$this->canViewAll('appointment_view_all'))
        {
            $query->where('assigned_user_id', $this->userId());
        }

        $status = (string) $request->input('status');
        if (in_array($status, self::STATUSES, true))
        {
            $query->where('status', $status);
        }

        $customerId = (int) $request->input('customer_id');
        if ($customerId > 0)
        {
            $query->where('customer_id', $customerId);
        }

        $propertyId = (int) $request->input('property_id');
        if ($propertyId > 0)
        {
            $query->where('property_id', $propertyId);
        }

        $from = trim((string) $request->input('from'));
        if ($from !== '' && ($ts = strtotime($from)) !== false)
        {
            $query->where('scheduled_at', '>=', date('Y-m-d 00:00:00', $ts));
        }

        $to = trim((string) $request->input('to'));
        if ($to !== '' && ($ts = strtotime($to)) !== false)
        {
            $query->where('scheduled_at', '<=', date('Y-m-d 23:59:59', $ts));
        }

        $total = (int) $query->count();

        // pending: buổi sắp tới xếp sớm-trước; các trạng thái khác: mới-trước.
        if ($status === 'pending')
        {
            $query->orderBy('scheduled_at');
        }
        else
        {
            $query->orderByDesc('scheduled_at');
        }

        $rows = $query->orderByDesc('id')->offset($offset)->limit($pageSize)->get();

        $items = $this->enrich($rows);

        $this->respondList($items, $total, $page, $pageSize);
    }

    /** GET api/appointment/{id} — chi tiết 1 buổi hẹn (kèm khách/BĐS). */
    public function detail(Request $request, $id): void
    {
        $this->requireCap('appointment_view', 'Bạn không có quyền xem lịch hẹn.');

        $appt  = $this->findAppointment((int) $id);
        $items = $this->enrich([$appt]);

        response()->success('success', $items[0]);
    }

    /** POST api/appointment — tạo buổi hẹn (khách + tuỳ chọn BĐS + giờ hẹn). */
    public function add(Request $request): void
    {
        $this->requireCap('appointment_add', 'Bạn không có quyền tạo lịch hẹn.');

        $customer = $this->findCustomerInScope((int) $request->input('customer_id'));

        $validate = $request->validate([
            'scheduled_at' => Rule::make('Thời điểm hẹn')->notEmpty(),
        ]);

        if ($validate->fails())
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($validate->errors(), $validate->errors()->errors());
        }

        $property = $this->resolveProperty((int) $request->input('property_id'));

        $location = Str::clear((string) $request->input('location'));
        if ($location === '' && $property !== null)
        {
            $location = (string) ($property->address ?? '');
        }

        $id = Appointment::create([
            'customer_id'      => (int) $customer->id,
            'property_id'      => $property !== null ? (int) $property->id : 0,
            // Người phụ trách buổi hẹn = sales của khách (nếu có), ngược lại người tạo.
            'assigned_user_id' => ((int) $customer->assigned_user_id > 0) ? (int) $customer->assigned_user_id : $this->userId(),
            'scheduled_at'     => $this->normalizeDatetime($request->input('scheduled_at')),
            'duration_min'     => max(0, (int) $request->input('duration_min')),
            'location'         => $location,
            'note'             => Str::clear((string) $request->input('note')),
            'status'           => 'pending',
        ]);

        if (!is_numeric($id))
        {
            response()->error('Tạo lịch hẹn thất bại.');
        }

        response()->success('Đã tạo lịch hẹn', ['id' => (int) $id]);
    }

    /** PUT api/appointment/{id} — sửa buổi hẹn (chỉ khi còn `pending`). */
    public function update(Request $request, $id): void
    {
        $this->requireCap('appointment_edit', 'Bạn không có quyền sửa lịch hẹn.');

        $appt = $this->findAppointment((int) $id);

        if ((string) $appt->status !== 'pending')
        {
            response()->error('Chỉ sửa được buổi hẹn đang chờ.');
        }

        $validate = $request->validate([
            'scheduled_at' => Rule::make('Thời điểm hẹn')->notEmpty(),
        ]);

        if ($validate->fails())
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($validate->errors(), $validate->errors()->errors());
        }

        $customer = $this->findCustomerInScope((int) $request->input('customer_id'));
        $property = $this->resolveProperty((int) $request->input('property_id'));

        $location = Str::clear((string) $request->input('location'));
        if ($location === '' && $property !== null)
        {
            $location = (string) ($property->address ?? '');
        }

        Appointment::where('id', $appt->id)->update([
            'customer_id'      => (int) $customer->id,
            'property_id'      => $property !== null ? (int) $property->id : 0,
            'assigned_user_id' => ((int) $customer->assigned_user_id > 0) ? (int) $customer->assigned_user_id : $this->userId(),
            'scheduled_at'     => $this->normalizeDatetime($request->input('scheduled_at')),
            'duration_min'     => max(0, (int) $request->input('duration_min')),
            'location'         => $location,
            'note'             => Str::clear((string) $request->input('note')),
        ]);

        response()->success('Đã cập nhật lịch hẹn', ['id' => (int) $appt->id]);
    }

    /**
     * PUT api/appointment/{id}/complete — chốt buổi hẹn.
     * body: status(done|no_show), result(khi done), result_note.
     * `done` → ghi kết quả + tạo 1 tương tác "Dẫn xem nhà" vào timeline khách + touch + rescore.
     * `no_show` → chỉ đánh dấu (khách không đến, không tính là tương tác).
     */
    public function complete(Request $request, $id): void
    {
        $this->requireCap('appointment_edit', 'Bạn không có quyền cập nhật lịch hẹn.');

        $appt = $this->findAppointment((int) $id);

        if ((string) $appt->status !== 'pending')
        {
            response()->error('Buổi hẹn này đã được xử lý.');
        }

        $status = (string) $request->input('status');
        if (!in_array($status, ['done', 'no_show'], true))
        {
            $status = 'done';
        }

        $resultNote = Str::clear((string) $request->input('result_note'));

        if ($status === 'no_show')
        {
            Appointment::where('id', $appt->id)->update([
                'status'       => 'no_show',
                'completed_at' => date('Y-m-d H:i:s'),
                'result'       => '',
                'result_note'  => $resultNote,
            ]);

            response()->success('Đã đánh dấu khách không đến', ['id' => (int) $appt->id]);
        }

        $result = (string) $request->input('result');
        if (!in_array($result, self::RESULTS, true))
        {
            $result = '';
        }

        Appointment::where('id', $appt->id)->update([
            'status'       => 'done',
            'completed_at' => date('Y-m-d H:i:s'),
            'result'       => $result,
            'result_note'  => $resultNote,
        ]);

        // Ghi tương tác "Dẫn xem nhà" vào timeline khách (nếu buổi hẹn có gắn khách).
        $customerId = (int) $appt->customer_id;
        if ($customerId > 0)
        {
            CustomerInteraction::create([
                'customer_id'   => $customerId,
                'user_id'       => $this->userId(),
                'type'          => 'viewing',
                'content'       => $this->buildInteractionContent($appt, $result, $resultNote),
                'direction'     => 'out',
                'interacted_at' => date('Y-m-d H:i:s'),
            ]);

            // Cập nhật mốc chăm sóc + gỡ cờ nguội + gia hạn khóa; chấm lại điểm.
            Customer::touch($customerId);
            LeadScorer::recompute($customerId);
        }

        response()->success('Đã hoàn thành buổi hẹn', ['id' => (int) $appt->id]);
    }

    /** DELETE api/appointment/{id} — hủy buổi hẹn (status = canceled). */
    public function cancel(Request $request, $id): void
    {
        $this->requireCap('appointment_delete', 'Bạn không có quyền hủy lịch hẹn.');

        $appt = $this->findAppointment((int) $id);

        if ((string) $appt->status === 'done')
        {
            response()->error('Buổi hẹn đã hoàn thành, không thể hủy.');
        }

        Appointment::where('id', $appt->id)->update(['status' => 'canceled']);

        response()->success('Đã hủy lịch hẹn', ['id' => (int) $appt->id]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────────

    /** Buổi hẹn trong phạm vi được phép (404/403 theo assigned_user_id). */
    protected function findAppointment(int $id)
    {
        $appt = Appointment::where('id', $id)->first();

        if (!hasItems($appt))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Lịch hẹn không tồn tại.');
        }

        if (!$this->canViewAll('appointment_view_all') && (int) $appt->assigned_user_id !== $this->userId())
        {
            response()->setStatusCode(403)->setApiStatus(403)->error('Bạn không phụ trách buổi hẹn này.');
        }

        return $appt;
    }

    /** Khách trong phạm vi được phép (404/403). Buộc gắn khách hợp lệ cho buổi hẹn. */
    protected function findCustomerInScope(int $id)
    {
        if ($id <= 0)
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Vui lòng chọn khách hàng.');
        }

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

    /** BĐS gắn kèm (tuỳ chọn): trả model nếu id>0 & tồn tại, null nếu bỏ trống, 404 nếu id sai. */
    protected function resolveProperty(int $id)
    {
        if ($id <= 0)
        {
            return null;
        }

        $property = Property::where('id', $id)->first();

        if (!hasItems($property))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Bất động sản không tồn tại.');
        }

        return $property;
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

    /** Nội dung tương tác timeline khi hoàn thành buổi hẹn (kèm BĐS + kết quả). */
    protected function buildInteractionContent($appt, string $result, string $resultNote): string
    {
        $parts = ['Dẫn xem BĐS'];

        $propertyId = (int) $appt->property_id;
        if ($propertyId > 0)
        {
            $p = Property::where('id', $propertyId)->first();
            if (hasItems($p))
            {
                $parts[0] = 'Dẫn xem: ' . trim((string) $p->code . ' — ' . (string) $p->title, ' —');
            }
        }

        if ($result !== '')
        {
            $parts[] = 'Kết quả: ' . (self::RESULT_LABELS[$result] ?? $result);
        }

        if ($resultNote !== '')
        {
            $parts[] = $resultNote;
        }

        return implode(' · ', $parts);
    }

    /**
     * Nạp khách + BĐS theo lô cho danh sách buổi hẹn → mảng cho FE (kèm cờ overdue).
     * @param iterable $rows danh sách bản ghi Appointment
     */
    protected function enrich($rows): array
    {
        $customerIds = [];
        $propertyIds = [];
        foreach ($rows as $row)
        {
            $customerIds[(int) $row->customer_id] = true;
            if ((int) $row->property_id > 0)
            {
                $propertyIds[(int) $row->property_id] = true;
            }
        }

        $customers = [];
        if (!empty($customerIds))
        {
            foreach (Customer::whereIn('id', array_keys($customerIds))->get() as $c)
            {
                $customers[(int) $c->id] = ['full_name' => (string) $c->full_name, 'phone' => (string) $c->phone];
            }
        }

        $properties = [];
        if (!empty($propertyIds))
        {
            foreach (Property::whereIn('id', array_keys($propertyIds))->get() as $p)
            {
                $properties[(int) $p->id] = ['code' => (string) $p->code, 'title' => (string) $p->title];
            }
        }

        $now = date('Y-m-d H:i:s');

        $items = [];
        foreach ($rows as $row)
        {
            $item = $this->transform($row);

            $cid = (int) $row->customer_id;
            $item['customer'] = $customers[$cid] ?? ['full_name' => '(đã xóa)', 'phone' => ''];

            $pid = (int) $row->property_id;
            $item['property'] = ($pid > 0) ? ($properties[$pid] ?? ['code' => '', 'title' => '(BĐS đã xóa)']) : null;

            $item['overdue'] = ((string) $row->status === 'pending' && (string) $row->scheduled_at < $now);

            $items[] = $item;
        }

        return $items;
    }

    /** Map 1 buổi hẹn → mảng cho FE. */
    protected function transform($row): array
    {
        return [
            'id'               => (int) $row->id,
            'customer_id'      => (int) $row->customer_id,
            'property_id'      => (int) $row->property_id,
            'assigned_user_id' => (int) $row->assigned_user_id,
            'scheduled_at'     => $row->scheduled_at,
            'duration_min'     => (int) $row->duration_min,
            'location'         => (string) ($row->location ?? ''),
            'note'             => (string) ($row->note ?? ''),
            'status'           => (string) $row->status,
            'result'           => (string) ($row->result ?? ''),
            'result_note'      => (string) ($row->result_note ?? ''),
            'completed_at'     => $row->completed_at,
            'created'          => $row->created,
        ];
    }
}
