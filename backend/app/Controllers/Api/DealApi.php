<?php

namespace App\Controllers\Api;

use App\Models\Commission;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\DealPayment;
use App\Models\DealReminder;
use App\Models\Property;
use Illuminate\Support\Str;
use SkillDo\Http\Request;
use SkillDo\Validate\Rule;

/**
 * API Giao dịch (deals) — GĐ2.
 *
 * 1 giao dịch = 1 khách mua/thuê 1 BĐS. Vòng đời: deposit(cọc) → contract(hợp đồng) → completed(hoàn tất)
 * / canceled(hủy). Chuyển giai đoạn **tự đổi `properties.status`** (deposit/contract → deposited;
 * completed → sold|rented theo transaction_type; canceled → available). Hoa hồng lưu trên deal + đồng bộ
 * 1 dòng `commissions` cho sale phụ trách (mark-paid cần cap `commission_manage`). Đợt thu tiền = sub-resource
 * `deal_payments`. Data-scope theo `assigned_user_id` (không có `deal_view_all` → chỉ giao dịch của mình).
 */
class DealApi extends ApiController
{
    const STATUSES          = ['deposit', 'contract', 'completed', 'canceled'];
    const PAYMENT_METHODS   = ['cash', 'transfer', 'card'];
    const PAYMENT_STATUSES  = ['planned', 'paid'];

    // Mốc thời gian tương ứng mỗi giai đoạn (điền khi lần đầu chuyển tới).
    const STATUS_TS = [
        'deposit'   => 'deposit_at',
        'contract'  => 'contract_at',
        'completed' => 'completed_at',
        'canceled'  => 'canceled_at',
    ];

    // Nhãn giai đoạn (dùng cho nhật ký hoạt động).
    const STATUS_LABELS = [
        'deposit'   => 'Đặt cọc',
        'contract'  => 'Đã ký hợp đồng',
        'completed' => 'Hoàn tất',
        'canceled'  => 'Đã hủy',
    ];

    /**
     * GET api/deal — danh sách giao dịch (phân trang) + lọc.
     * ?keyword= (mã) &status= &customer_id= &property_id=
     */
    public function index(Request $request): void
    {
        $this->requireCap('deal_view', 'Bạn không có quyền xem giao dịch.');

        [$page, $pageSize, $offset] = $this->paging($request);

        $query = Deal::query();

        if (!$this->canViewAll('deal_view_all'))
        {
            $query->where('assigned_user_id', $this->userId());
        }

        $keyword = Str::clear((string) $request->input('keyword'));
        if ($keyword !== '')
        {
            $query->where('code', 'like', '%' . $keyword . '%');
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

        $total = (int) $query->count();

        $rows = $query->orderByDesc('id')->offset($offset)->limit($pageSize)->get();

        $items = $this->enrich($rows);

        $this->respondList($items, $total, $page, $pageSize);
    }

    /** GET api/deal/{id} — chi tiết (kèm khách/BĐS + đợt thanh toán + hoa hồng + đã thu/còn lại). */
    public function detail(Request $request, $id): void
    {
        $this->requireCap('deal_view', 'Bạn không có quyền xem giao dịch.');

        $deal = $this->findDeal((int) $id);

        $data = $this->enrich([$deal])[0];

        $payments = [];
        $paid = 0.0;      // tổng đã thu (status=paid)
        $planned = 0.0;   // tổng dự kiến (status=planned)
        foreach (DealPayment::where('deal_id', $deal->id)->orderByDesc('id')->get() as $p)
        {
            $payments[] = $this->transformPayment($p);
            if ((string) $p->status === 'planned')
            {
                $planned += (float) $p->amount;
            }
            else
            {
                $paid += (float) $p->amount;
            }
        }

        $data['payments']      = $payments;
        $data['paid_total']    = $paid;
        $data['planned_total'] = $planned;
        $data['remaining']     = max(0, (float) $deal->value - $paid);
        $data['commission']    = $this->commissionOf((int) $deal->id);
        $data['reminders']     = $this->remindersOf((int) $deal->id);
        $data['activities']    = $this->activitiesOf((int) $deal->id);

        response()->success('success', $data);
    }

    /** POST api/deal — tạo giao dịch (khách + BĐS + giá trị). Đặt BĐS sang "đang cọc" + sinh hoa hồng. */
    public function add(Request $request): void
    {
        $this->requireCap('deal_add', 'Bạn không có quyền tạo giao dịch.');

        $customer = $this->findCustomerInScope((int) $request->input('customer_id'));
        $property = $this->requireProperty((int) $request->input('property_id'));

        if ((string) $property->status === 'sold')
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Bất động sản này đã bán, không thể tạo giao dịch mới.');
        }

        $txnType = in_array((string) $property->transaction_type, ['sale', 'rent'], true)
            ? (string) $property->transaction_type : 'sale';

        $value = max(0, (float) $request->input('value'));
        $rate  = $this->clampRate((float) $request->input('commission_rate'));
        $amount = $this->resolveCommission($value, $rate, (float) $request->input('commission_amount'));

        $assigned = (int) $request->input('assigned_user_id');
        $assignedUserId = ($assigned > 0 && $this->canViewAll('deal_view_all')) ? $assigned : $this->userId();

        $now = date('Y-m-d H:i:s');

        $id = Deal::create([
            'code'              => 'GD' . strtoupper(Str::random(7)),
            'customer_id'       => (int) $customer->id,
            'property_id'       => (int) $property->id,
            'assigned_user_id'  => $assignedUserId,
            'transaction_type'  => $txnType,
            'value'             => $value,
            'commission_rate'   => $rate,
            'commission_amount' => $amount,
            'status'            => 'deposit',
            'deposit_at'        => $now,
            'note'              => Str::clear((string) $request->input('note')),
        ]);

        if (!is_numeric($id))
        {
            response()->error('Tạo giao dịch thất bại.');
        }

        // Đặt BĐS sang trạng thái "đang cọc".
        $this->applyPropertyStatus((int) $property->id, 'deposit', $txnType);

        // Sinh dòng hoa hồng cho sale phụ trách.
        $this->syncCommission((int) $id, $assignedUserId, $rate, $amount);

        $this->logActivity((int) $id, 'created', 'Tạo giao dịch — đặt cọc', $value);

        response()->success('Đã tạo giao dịch', ['id' => (int) $id]);
    }

    /** PUT api/deal/{id} — sửa thông tin (giá trị/hoa hồng/khách/BĐS/ghi chú). Đồng bộ lại hoa hồng. */
    public function update(Request $request, $id): void
    {
        $this->requireCap('deal_edit', 'Bạn không có quyền sửa giao dịch.');

        $deal = $this->findDeal((int) $id);

        $customer = $this->findCustomerInScope((int) $request->input('customer_id'));
        $property = $this->requireProperty((int) $request->input('property_id'));

        $txnType = in_array((string) $property->transaction_type, ['sale', 'rent'], true)
            ? (string) $property->transaction_type : (string) $deal->transaction_type;

        $value  = max(0, (float) $request->input('value'));
        $rate   = $this->clampRate((float) $request->input('commission_rate'));
        $amount = $this->resolveCommission($value, $rate, (float) $request->input('commission_amount'));

        $update = [
            'customer_id'       => (int) $customer->id,
            'property_id'       => (int) $property->id,
            'transaction_type'  => $txnType,
            'value'             => $value,
            'commission_rate'   => $rate,
            'commission_amount' => $amount,
            'note'              => Str::clear((string) $request->input('note')),
        ];

        // Chỉ đổi người phụ trách khi có quyền toàn sàn.
        if ($this->canViewAll('deal_view_all') && (int) $request->input('assigned_user_id') > 0)
        {
            $update['assigned_user_id'] = (int) $request->input('assigned_user_id');
        }

        Deal::where('id', $deal->id)->update($update);

        $ownerId = $update['assigned_user_id'] ?? (int) $deal->assigned_user_id;
        $this->syncCommission((int) $deal->id, (int) $ownerId, $rate, $amount);

        $this->logActivity((int) $deal->id, 'update', 'Cập nhật thông tin giao dịch', $value);

        response()->success('Đã cập nhật giao dịch', ['id' => (int) $deal->id]);
    }

    /**
     * PUT api/deal/{id}/status — chuyển giai đoạn (body `status`). Tự đổi `properties.status` +
     * điền mốc thời gian giai đoạn (lần đầu tới).
     */
    public function changeStatus(Request $request, $id): void
    {
        $this->requireCap('deal_edit', 'Bạn không có quyền chuyển giai đoạn giao dịch.');

        $deal = $this->findDeal((int) $id);

        $status = (string) $request->input('status');
        if (!in_array($status, self::STATUSES, true))
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Giai đoạn không hợp lệ.');
        }

        if ($status === (string) $deal->status)
        {
            response()->success('Không có thay đổi', ['id' => (int) $deal->id, 'status' => $status]);
        }

        $update = ['status' => $status];

        // Điền mốc thời gian giai đoạn nếu chưa có.
        $tsCol = self::STATUS_TS[$status] ?? '';
        if ($tsCol !== '' && !hasItems($deal->$tsCol))
        {
            $update[$tsCol] = date('Y-m-d H:i:s');
        }

        Deal::where('id', $deal->id)->update($update);

        $this->applyPropertyStatus((int) $deal->property_id, $status, (string) $deal->transaction_type);

        $this->logActivity((int) $deal->id, 'status', 'Chuyển giai đoạn: ' . (self::STATUS_LABELS[$status] ?? $status));

        response()->success('Đã cập nhật giai đoạn giao dịch', ['id' => (int) $deal->id, 'status' => $status]);
    }

    /** DELETE api/deal/{id} — xóa mềm. Nếu đang cọc/hợp đồng → trả BĐS về "đang bán". */
    public function destroy(Request $request, $id): void
    {
        $this->requireCap('deal_delete', 'Bạn không có quyền xóa giao dịch.');

        $deal = $this->findDeal((int) $id);

        // Giải phóng BĐS về kho nếu giao dịch chưa hoàn tất (cọc/hợp đồng dở).
        if (in_array((string) $deal->status, ['deposit', 'contract'], true))
        {
            $this->applyPropertyStatus((int) $deal->property_id, 'canceled', (string) $deal->transaction_type);
        }

        Deal::where('id', $deal->id)->trash();

        response()->success('Đã xóa giao dịch', ['id' => (int) $deal->id]);
    }

    // ─── Sub-resource: đợt thanh toán ─────────────────────────────────────────────────

    /**
     * POST api/deal/{id}/payments — thêm 1 đợt thu.
     * `status` = paid (đã thu, mặc định) → có paid_at; = planned (dự kiến) → bắt buộc due_date.
     */
    public function addPayment(Request $request, $id): void
    {
        $this->requireCap('deal_edit', 'Bạn không có quyền ghi thanh toán.');

        $deal = $this->findDeal((int) $id);

        $amount = max(0, (float) $request->input('amount'));
        if ($amount <= 0)
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Số tiền phải lớn hơn 0.');
        }

        $status = (string) $request->input('status');
        if (!in_array($status, self::PAYMENT_STATUSES, true))
        {
            $status = 'paid';
        }

        $method = (string) $request->input('method');
        $data = [
            'deal_id' => (int) $deal->id,
            'amount'  => $amount,
            'status'  => $status,
            'method'  => in_array($method, self::PAYMENT_METHODS, true) ? $method : '',
            'note'    => Str::clear((string) $request->input('note')),
        ];

        if ($status === 'planned')
        {
            $dueDate = trim((string) $request->input('due_date'));
            if ($dueDate === '')
            {
                response()->setStatusCode(422)->setApiStatus(422)->error('Vui lòng chọn ngày đến hạn cho đợt dự kiến.');
            }
            $data['due_date'] = $this->normalizeDatetime($dueDate);
            $data['paid_at']  = null;
        }
        else
        {
            $paidAt = trim((string) $request->input('paid_at'));
            $data['paid_at'] = ($paidAt !== '') ? $this->normalizeDatetime($paidAt) : date('Y-m-d H:i:s');
        }

        $pid = DealPayment::create($data);

        if (!is_numeric($pid))
        {
            response()->error('Ghi thanh toán thất bại.');
        }

        if ($status === 'planned')
        {
            $this->logActivity((int) $deal->id, 'payment_plan', 'Lên kế hoạch thu', $amount, 'Hạn: ' . $data['due_date']);
        }
        else
        {
            $this->logActivity((int) $deal->id, 'payment', 'Thu tiền', $amount);
        }

        response()->success('Đã ghi đợt thanh toán', ['id' => (int) $pid]);
    }

    /** PUT api/deal/{id}/payments/{paymentId}/paid — đánh dấu đợt dự kiến đã thu. */
    public function markPaymentPaid(Request $request, $id, $paymentId): void
    {
        $this->requireCap('deal_edit', 'Bạn không có quyền ghi thanh toán.');

        $deal = $this->findDeal((int) $id);

        $payment = DealPayment::where('id', (int) $paymentId)->where('deal_id', $deal->id)->first();
        if (!hasItems($payment))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Đợt thanh toán không tồn tại.');
        }

        if ((string) $payment->status === 'paid')
        {
            response()->success('Đợt này đã thu', ['id' => (int) $payment->id]);
        }

        DealPayment::where('id', $payment->id)->update([
            'status'  => 'paid',
            'paid_at' => hasItems($payment->paid_at) ? $payment->paid_at : date('Y-m-d H:i:s'),
        ]);

        $this->logActivity((int) $deal->id, 'payment_paid', 'Đã thu đợt dự kiến', (float) $payment->amount);

        response()->success('Đã đánh dấu đã thu', ['id' => (int) $payment->id]);
    }

    /** DELETE api/deal/{id}/payments/{paymentId} — xóa 1 đợt thu (xóa cứng). */
    public function deletePayment(Request $request, $id, $paymentId): void
    {
        $this->requireCap('deal_edit', 'Bạn không có quyền xóa thanh toán.');

        $deal = $this->findDeal((int) $id);

        $payment = DealPayment::where('id', (int) $paymentId)->where('deal_id', $deal->id)->first();
        if (!hasItems($payment))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Đợt thanh toán không tồn tại.');
        }

        $amount = (float) $payment->amount;

        DealPayment::where('id', $payment->id)->delete();

        $this->logActivity((int) $deal->id, 'payment_delete', 'Xóa đợt thu', $amount);

        response()->success('Đã xóa đợt thanh toán', ['id' => (int) $payment->id]);
    }

    // ─── Hoa hồng ─────────────────────────────────────────────────────────────────────

    /** PUT api/deal/{id}/commission — đánh dấu chi/chưa chi hoa hồng (cap commission_manage). */
    public function updateCommission(Request $request, $id): void
    {
        $this->requireCap('commission_manage', 'Bạn không có quyền quản lý hoa hồng.');

        $deal = $this->findDeal((int) $id);

        $commission = Commission::where('deal_id', $deal->id)->first();
        if (!hasItems($commission))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Chưa có hoa hồng cho giao dịch này.');
        }

        $status = (string) $request->input('status');
        if (!in_array($status, ['pending', 'paid'], true))
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Trạng thái hoa hồng không hợp lệ.');
        }

        Commission::where('id', $commission->id)->update([
            'status'  => $status,
            'paid_at' => ($status === 'paid') ? date('Y-m-d H:i:s') : null,
            'note'    => Str::clear((string) $request->input('note')),
        ]);

        $this->logActivity(
            (int) $deal->id,
            'commission',
            ($status === 'paid') ? 'Đánh dấu đã chi hoa hồng' : 'Hoãn chi hoa hồng',
            (float) $commission->amount
        );

        response()->success('Đã cập nhật hoa hồng', ['id' => (int) $commission->id, 'status' => $status]);
    }

    // ─── Sub-resource: nhắc hẹn ────────────────────────────────────────────────────────

    /** POST api/deal/{id}/reminders — tạo lời nhắc gắn giao dịch (title + remind_at). */
    public function addReminder(Request $request, $id): void
    {
        $this->requireCap('deal_edit', 'Bạn không có quyền tạo nhắc hẹn.');

        $deal = $this->findDeal((int) $id);

        $title = Str::clear((string) $request->input('title'));
        if ($title === '')
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Vui lòng nhập nội dung nhắc.');
        }

        $remindAt = trim((string) $request->input('remind_at'));
        if ($remindAt === '')
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Vui lòng chọn thời điểm nhắc.');
        }

        $rid = DealReminder::create([
            'deal_id'          => (int) $deal->id,
            'assigned_user_id' => (int) $deal->assigned_user_id,
            'title'            => $title,
            'remind_at'        => $this->normalizeDatetime($remindAt),
            'status'           => 'pending',
            'note'             => Str::clear((string) $request->input('note')),
        ]);

        if (!is_numeric($rid))
        {
            response()->error('Tạo nhắc hẹn thất bại.');
        }

        $this->logActivity((int) $deal->id, 'reminder', 'Tạo nhắc hẹn: ' . $title);

        response()->success('Đã tạo nhắc hẹn', ['id' => (int) $rid]);
    }

    /** PUT api/deal/{id}/reminders/{reminderId} — sửa / đánh dấu xong lời nhắc. */
    public function updateReminder(Request $request, $id, $reminderId): void
    {
        $this->requireCap('deal_edit', 'Bạn không có quyền sửa nhắc hẹn.');

        $deal = $this->findDeal((int) $id);

        $reminder = DealReminder::where('id', (int) $reminderId)->where('deal_id', $deal->id)->first();
        if (!hasItems($reminder))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Nhắc hẹn không tồn tại.');
        }

        $update = [];

        $status = (string) $request->input('status');
        if (in_array($status, ['pending', 'done'], true))
        {
            $update['status']  = $status;
            $update['done_at'] = ($status === 'done') ? date('Y-m-d H:i:s') : null;
        }

        $title = Str::clear((string) $request->input('title'));
        if ($title !== '')
        {
            $update['title'] = $title;
        }

        $remindAt = trim((string) $request->input('remind_at'));
        if ($remindAt !== '')
        {
            $update['remind_at']   = $this->normalizeDatetime($remindAt);
            $update['reminded_at'] = null; // đổi giờ nhắc → cho phép nhắc lại
        }

        if ($request->has('note'))
        {
            $update['note'] = Str::clear((string) $request->input('note'));
        }

        if (empty($update))
        {
            response()->success('Không có thay đổi', ['id' => (int) $reminder->id]);
        }

        DealReminder::where('id', $reminder->id)->update($update);

        if (($update['status'] ?? '') === 'done')
        {
            $this->logActivity((int) $deal->id, 'reminder_done', 'Hoàn thành nhắc hẹn: ' . (string) $reminder->title);
        }

        response()->success('Đã cập nhật nhắc hẹn', ['id' => (int) $reminder->id]);
    }

    /** DELETE api/deal/{id}/reminders/{reminderId} — xóa lời nhắc (xóa cứng). */
    public function deleteReminder(Request $request, $id, $reminderId): void
    {
        $this->requireCap('deal_edit', 'Bạn không có quyền xóa nhắc hẹn.');

        $deal = $this->findDeal((int) $id);

        $reminder = DealReminder::where('id', (int) $reminderId)->where('deal_id', $deal->id)->first();
        if (!hasItems($reminder))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Nhắc hẹn không tồn tại.');
        }

        DealReminder::where('id', $reminder->id)->delete();

        response()->success('Đã xóa nhắc hẹn', ['id' => (int) $reminder->id]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────────

    /** Ghi 1 dòng nhật ký hoạt động (append-only, tự nuốt lỗi để không chặn nghiệp vụ chính). */
    protected function logActivity(int $dealId, string $type, string $title, float $amount = 0, string $note = ''): void
    {
        try
        {
            DealActivity::create([
                'deal_id' => $dealId,
                'type'    => $type,
                'title'   => $title,
                'amount'  => $amount,
                'note'    => $note,
                'user_id' => $this->userId(),
            ]);
        }
        catch (\Exception $e)
        {
            // Nhật ký là phụ trợ — lỗi ghi log không được làm hỏng thao tác chính.
        }
    }

    /** Danh sách nhắc hẹn của giao dịch: pending trước done, trong mỗi nhóm theo remind_at tăng dần. */
    protected function remindersOf(int $dealId): array
    {
        $pending = [];
        $done = [];
        foreach (DealReminder::where('deal_id', $dealId)->orderBy('remind_at')->get() as $r)
        {
            if ((string) $r->status === 'done')
            {
                $done[] = $this->transformReminder($r);
            }
            else
            {
                $pending[] = $this->transformReminder($r);
            }
        }

        return array_merge($pending, $done);
    }

    /** Nhật ký hoạt động của giao dịch (mới nhất trước, tối đa 100 dòng). */
    protected function activitiesOf(int $dealId): array
    {
        $items = [];
        foreach (DealActivity::where('deal_id', $dealId)->orderByDesc('id')->limit(100)->get() as $a)
        {
            $items[] = $this->transformActivity($a);
        }
        return $items;
    }

    /** Giao dịch trong phạm vi (404/403 theo assigned_user_id). Loại bản đã xóa mềm. */
    protected function findDeal(int $id)
    {
        $deal = Deal::where('id', $id)->first();

        if (!hasItems($deal))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Giao dịch không tồn tại.');
        }

        if (!$this->canViewAll('deal_view_all') && (int) $deal->assigned_user_id !== $this->userId())
        {
            response()->setStatusCode(403)->setApiStatus(403)->error('Bạn không phụ trách giao dịch này.');
        }

        return $deal;
    }

    /** Khách trong phạm vi (404/403). */
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

    /** BĐS bắt buộc gắn (422 nếu thiếu, 404 nếu không tồn tại). */
    protected function requireProperty(int $id)
    {
        if ($id <= 0)
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Vui lòng chọn bất động sản.');
        }

        $property = Property::where('id', $id)->first();

        if (!hasItems($property))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Bất động sản không tồn tại.');
        }

        return $property;
    }

    /** Đổi trạng thái BĐS theo giai đoạn giao dịch. */
    protected function applyPropertyStatus(int $propertyId, string $dealStatus, string $txnType): void
    {
        if ($propertyId <= 0)
        {
            return;
        }

        if ($dealStatus === 'completed')
        {
            $target = ($txnType === 'rent') ? 'rented' : 'sold';
        }
        elseif ($dealStatus === 'canceled')
        {
            $target = 'available';
        }
        else
        {
            $target = 'deposited'; // deposit / contract
        }

        Property::where('id', $propertyId)->update(['status' => $target]);
    }

    /** Tạo/cập nhật dòng hoa hồng của giao dịch (giữ nguyên status khi đã có). */
    protected function syncCommission(int $dealId, int $userId, float $rate, float $amount): void
    {
        $existing = Commission::where('deal_id', $dealId)->first();

        if (hasItems($existing))
        {
            Commission::where('id', $existing->id)->update([
                'user_id' => $userId,
                'rate'    => $rate,
                'amount'  => $amount,
            ]);
            return;
        }

        Commission::create([
            'deal_id' => $dealId,
            'user_id' => $userId,
            'rate'    => $rate,
            'amount'  => $amount,
            'status'  => 'pending',
        ]);
    }

    /** Hoa hồng của giao dịch → mảng cho FE (null nếu chưa có). */
    protected function commissionOf(int $dealId): ?array
    {
        $c = Commission::where('deal_id', $dealId)->first();
        if (!hasItems($c))
        {
            return null;
        }

        return [
            'id'      => (int) $c->id,
            'user_id' => (int) $c->user_id,
            'rate'    => (float) $c->rate,
            'amount'  => (float) $c->amount,
            'status'  => (string) $c->status,
            'paid_at' => $c->paid_at,
            'note'    => (string) ($c->note ?? ''),
        ];
    }

    /** % hoa hồng hợp lệ trong [0, 100]. */
    protected function clampRate(float $rate): float
    {
        return max(0, min(100, $rate));
    }

    /** Tiền hoa hồng: ưu tiên số nhập tay (>0), else tính theo % trên giá trị. */
    protected function resolveCommission(float $value, float $rate, float $amountInput): float
    {
        if ($amountInput > 0)
        {
            return $amountInput;
        }
        if ($rate > 0)
        {
            return round($value * $rate / 100, 2);
        }
        return 0;
    }

    /** Chuẩn hóa datetime FE gửi → 'Y-m-d H:i:s' (422 nếu sai). */
    protected function normalizeDatetime($value): string
    {
        $ts = strtotime((string) $value);
        if ($ts === false)
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Thời điểm không hợp lệ.');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Nạp khách + BĐS theo lô → mảng cho FE.
     * @param iterable $rows danh sách bản ghi Deal
     */
    protected function enrich($rows): array
    {
        $customerIds = [];
        $propertyIds = [];
        foreach ($rows as $row)
        {
            $customerIds[(int) $row->customer_id] = true;
            $propertyIds[(int) $row->property_id] = true;
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

        $items = [];
        foreach ($rows as $row)
        {
            $item = $this->transform($row);

            $cid = (int) $row->customer_id;
            $item['customer'] = $customers[$cid] ?? ['full_name' => '(đã xóa)', 'phone' => ''];

            $pid = (int) $row->property_id;
            $item['property'] = $properties[$pid] ?? ['code' => '', 'title' => '(BĐS đã xóa)'];

            $items[] = $item;
        }

        return $items;
    }

    /** Map 1 giao dịch → mảng cho FE. */
    protected function transform($row): array
    {
        return [
            'id'                => (int) $row->id,
            'code'              => (string) $row->code,
            'customer_id'       => (int) $row->customer_id,
            'property_id'       => (int) $row->property_id,
            'assigned_user_id'  => (int) $row->assigned_user_id,
            'transaction_type'  => (string) $row->transaction_type,
            'value'             => (float) $row->value,
            'commission_rate'   => (float) $row->commission_rate,
            'commission_amount' => (float) $row->commission_amount,
            'status'            => (string) $row->status,
            'deposit_at'        => $row->deposit_at,
            'contract_at'       => $row->contract_at,
            'completed_at'      => $row->completed_at,
            'canceled_at'       => $row->canceled_at,
            'note'              => (string) ($row->note ?? ''),
            'created'           => $row->created,
        ];
    }

    /** Map 1 đợt thanh toán → mảng cho FE. */
    protected function transformPayment($p): array
    {
        return [
            'id'       => (int) $p->id,
            'deal_id'  => (int) $p->deal_id,
            'amount'   => (float) $p->amount,
            'status'   => (string) ($p->status ?? 'paid'),
            'paid_at'  => $p->paid_at,
            'due_date' => $p->due_date,
            'method'   => (string) ($p->method ?? ''),
            'note'     => (string) ($p->note ?? ''),
            'created'  => $p->created,
        ];
    }

    /** Map 1 nhắc hẹn → mảng cho FE. */
    protected function transformReminder($r): array
    {
        return [
            'id'               => (int) $r->id,
            'deal_id'          => (int) $r->deal_id,
            'assigned_user_id' => (int) $r->assigned_user_id,
            'title'            => (string) $r->title,
            'remind_at'        => $r->remind_at,
            'status'           => (string) ($r->status ?? 'pending'),
            'done_at'          => $r->done_at,
            'note'             => (string) ($r->note ?? ''),
        ];
    }

    /** Map 1 dòng nhật ký → mảng cho FE. */
    protected function transformActivity($a): array
    {
        return [
            'id'      => (int) $a->id,
            'type'    => (string) $a->type,
            'title'   => (string) $a->title,
            'amount'  => (float) $a->amount,
            'note'    => (string) ($a->note ?? ''),
            'user_id' => (int) $a->user_id,
            'created' => $a->created,
        ];
    }
}
