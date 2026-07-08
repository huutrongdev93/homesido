<?php

namespace App\Controllers\Api;

use App\Models\Customer;
use App\Models\CustomerDemand;
use App\Models\Property;
use App\Models\PropertyCustomerMatch;
use App\Models\PropertyMedia;
use App\Services\Matching\MatchEngine;
use App\Services\Storage\PropertyMediaService;
use Illuminate\Support\Str;
use SkillDo\Http\Request;
use SkillDo\Validate\Rule;

/**
 * API Bất động sản (kho hàng / giỏ hàng).
 *
 * Data-scope khác Khách hàng: ngoài hàng của mình (`assigned_user_id = userId()`), nhân viên
 * còn thấy **kho chung** (`visibility = 'shared'`). Sửa/xóa chỉ hàng của mình (hoặc có
 * `property_view_all`). Media (ảnh/tài liệu) quản lý qua PropertyMedia — upload để bước sau.
 */
class PropertyApi extends ApiController
{
    const TRANSACTION_TYPES = ['sale', 'rent'];
    const STATUSES          = ['available', 'deposited', 'sold', 'rented', 'inactive'];
    const VISIBILITIES      = ['private', 'shared'];
    const LEGAL_STATUSES    = ['red_book', 'pink_book', 'sale_contract', 'waiting', 'other'];
    const FURNITURES        = ['none', 'basic', 'full'];

    /** Trần số khách gợi ý trả về (Matching). */
    const MATCH_LIMIT = 50;

    /**
     * GET api/property — danh sách có filter + phân trang, áp data-scope (của tôi + kho chung).
     * Query: page, pageSize, keyword (tiêu đề/mã), property_type, transaction_type, status.
     */
    public function index(Request $request): void
    {
        $this->requireCap('property_view', 'Bạn không có quyền xem bất động sản.');

        [$page, $pageSize, $offset] = $this->paging($request);

        $query = Property::query();

        // Không có quyền xem toàn sàn → chỉ thấy hàng của mình + kho chung (shared).
        if (!$this->canViewAll('property_view_all'))
        {
            $me = $this->userId();
            $query->where(function ($q) use ($me) {
                $q->where('assigned_user_id', $me)->orWhere('visibility', 'shared');
            });
        }

        $keyword = trim((string) $request->input('keyword'));
        if ($keyword !== '')
        {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)->orWhere('code', 'like', $like);
            });
        }

        $propertyType = trim((string) $request->input('property_type'));
        if ($propertyType !== '')
        {
            $query->where('property_type', $propertyType);
        }

        $transaction = (string) $request->input('transaction_type');
        if (in_array($transaction, self::TRANSACTION_TYPES, true))
        {
            $query->where('transaction_type', $transaction);
        }

        $status = (string) $request->input('status');
        if (in_array($status, self::STATUSES, true))
        {
            $query->where('status', $status);
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
     * GET api/property/{id} — chi tiết + danh sách media.
     */
    public function detail(Request $request, $id): void
    {
        $this->requireCap('property_view', 'Bạn không có quyền xem bất động sản.');

        $property = $this->findViewable((int) $id);

        $media = [];
        foreach (PropertyMedia::where('property_id', $property->id)->orderBy('sort_order')->orderBy('id')->get() as $m)
        {
            $media[] = $this->transformMedia($m);
        }

        $data = $this->transform($property);
        $data['media'] = $media;

        response()->success('success', $data);
    }

    /**
     * POST api/property — thêm BĐS. Tự sinh mã nếu bỏ trống.
     */
    public function add(Request $request): void
    {
        $this->requireCap('property_add', 'Bạn không có quyền thêm bất động sản.');

        $validate = $request->validate([
            'title' => Rule::make('Tiêu đề')->notEmpty(),
        ]);

        if ($validate->fails())
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($validate->errors(), $validate->errors()->errors());
        }

        $data = $this->collectInput($request);

        $code = Str::clear((string) $request->input('code'));
        $data['code'] = ($code !== '') ? $code : ('BDS' . strtoupper(Str::random(7)));

        // Người tạo phụ trách; chỉ người xem-toàn-sàn được chỉ định người khác.
        $assigned = (int) $request->input('assigned_user_id');
        $data['assigned_user_id'] = ($assigned > 0 && $this->canViewAll('property_view_all')) ? $assigned : $this->userId();

        $id = Property::create($data);

        if (!is_numeric($id))
        {
            response()->error('Thêm bất động sản thất bại.');
        }

        response()->success('Thêm bất động sản thành công', ['id' => (int) $id]);
    }

    /**
     * PUT api/property/{id} — cập nhật (chỉ hàng của mình hoặc có quyền toàn sàn).
     */
    public function update(Request $request, $id): void
    {
        $this->requireCap('property_edit', 'Bạn không có quyền sửa bất động sản.');

        $property = $this->findOwned((int) $id);

        $validate = $request->validate([
            'title' => Rule::make('Tiêu đề')->notEmpty(),
        ]);

        if ($validate->fails())
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($validate->errors(), $validate->errors()->errors());
        }

        $data = $this->collectInput($request);

        $code = Str::clear((string) $request->input('code'));
        if ($code !== '')
        {
            $data['code'] = $code;
        }

        if ($this->canViewAll('property_view_all'))
        {
            $assigned = (int) $request->input('assigned_user_id');
            if ($assigned > 0)
            {
                $data['assigned_user_id'] = $assigned;
            }
        }

        Property::where('id', $property->id)->update($data);

        response()->success('Cập nhật bất động sản thành công', ['id' => (int) $property->id]);
    }

    /**
     * DELETE api/property/{id} — xóa mềm (trash = 1); GIỮ media (xem lựa chọn nghiệp vụ).
     * `?force=1` = xóa HẲN: purge media (xóa file + hoàn dung lượng người upload) + xóa cứng record.
     */
    public function destroy(Request $request, $id): void
    {
        $this->requireCap('property_delete', 'Bạn không có quyền xóa bất động sản.');

        $property = $this->findOwned((int) $id);

        if (filter_var($request->input('force'), FILTER_VALIDATE_BOOLEAN))
        {
            PropertyMediaService::purgeProperty((int) $property->id);
            Property::where('id', $property->id)->delete();   // xóa CỨNG record

            response()->success('Đã xóa hẳn bất động sản', ['id' => (int) $property->id]);
        }

        Property::where('id', $property->id)->trash();

        response()->success('Đã xóa bất động sản', ['id' => (int) $property->id]);
    }

    // ─── Media (ảnh / video) ─────────────────────────────────────────────────────────

    /** GET api/property/{id}/media — danh sách media của BĐS (theo scope xem). */
    public function mediaIndex(Request $request, $id): void
    {
        $this->requireCap('property_view', 'Bạn không có quyền xem bất động sản.');

        $property = $this->findViewable((int) $id);

        $items = [];
        foreach (PropertyMedia::where('property_id', $property->id)->orderBy('sort_order')->orderBy('id')->get() as $m)
        {
            $items[] = $this->transformMedia($m);
        }

        response()->success('success', $items);
    }

    /** POST api/property/{id}/media — upload nhiều file (field `files[]`). Gom lỗi từng file. */
    public function mediaUpload(Request $request, $id): void
    {
        $this->requireCap('property_edit', 'Bạn không có quyền sửa bất động sản.');

        $property = $this->findOwned((int) $id);

        $files = $request->file('files');
        if (empty($files))
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Chưa chọn tệp nào để tải lên.');
        }
        if (!is_array($files))
        {
            $files = [$files];
        }

        $userId   = $this->userId();
        $maxOrder = (int) PropertyMedia::where('property_id', $property->id)->max('sort_order');

        $saved  = 0;
        $errors = [];
        foreach ($files as $file)
        {
            $maxOrder++;
            $res = PropertyMediaService::store($file, (int) $property->id, $userId, $maxOrder);

            if ($res['ok'])
            {
                $saved++;
            }
            else
            {
                $errors[] = $res['error'];
            }
        }

        if ($saved === 0)
        {
            response()->setStatusCode(422)->setApiStatus(422)->error($errors[0] ?? 'Tải lên thất bại.');
        }

        response()->success('Đã tải lên ' . $saved . ' tệp', ['saved' => $saved, 'errors' => $errors]);
    }

    /** DELETE api/property/{id}/media/{mediaId} — xóa 1 media (xóa file + hoàn dung lượng). */
    public function mediaDelete(Request $request, $id, $mediaId): void
    {
        $this->requireCap('property_edit', 'Bạn không có quyền sửa bất động sản.');

        $property = $this->findOwned((int) $id);

        $row = PropertyMedia::where('id', (int) $mediaId)->where('property_id', $property->id)->first();
        if (!hasItems($row))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Tệp không tồn tại.');
        }

        PropertyMediaService::delete($row);

        response()->success('Đã xóa tệp', ['id' => (int) $mediaId]);
    }

    /** PUT api/property/{id}/media/reorder — cập nhật thứ tự hiển thị (body `order`: mảng id). */
    public function mediaReorder(Request $request, $id): void
    {
        $this->requireCap('property_edit', 'Bạn không có quyền sửa bất động sản.');

        $property = $this->findOwned((int) $id);

        $order = $request->input('order');
        if (!is_array($order))
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Thứ tự không hợp lệ.');
        }

        $i = 0;
        foreach ($order as $mediaId)
        {
            PropertyMedia::where('id', (int) $mediaId)->where('property_id', $property->id)->update(['sort_order' => $i]);
            $i++;
        }

        response()->success('Đã cập nhật thứ tự');
    }

    // ─── Matching (gợi ý khách cho BĐS) ──────────────────────────────────────────────

    /**
     * GET api/property/{id}/match-customers — gợi ý khách phù hợp 1 BĐS.
     * Duyệt nhu cầu active (đã lọc sơ theo loại/khu vực để giảm tập), kiểm khớp qua MatchEngine,
     * gộp theo khách (điểm cao nhất). Áp data-scope khách (của tôi + kho chung chưa khóa) khi
     * không có customer_view_all. Trả kèm cờ `already_sent`.
     */
    public function matchCustomers(Request $request, $id): void
    {
        $this->requireCap('matching_view', 'Bạn không có quyền xem gợi ý khớp.');

        $property = $this->findViewable((int) $id);

        // BĐS không còn bán thì không có gì để khớp (MatchEngine cũng loại) → trả rỗng sớm.
        if ((string) $property->status !== 'available')
        {
            response()->success('success', []);
        }

        // Lọc sơ nhu cầu ở SQL (loại giao dịch + loại hình + tỉnh) để giảm tập; khớp CHÍNH XÁC
        // (gồm cả khoảng giá) verify bằng MatchEngine::matchesProperty ở PHP — 1 nguồn logic.
        $demandType = ((string) $property->transaction_type === 'rent') ? 'rent' : 'buy';

        $demandsQuery = CustomerDemand::where('is_active', 1)
            ->where('demand_type', $demandType)
            ->where(function ($q) use ($property) {
                $q->where('property_type', '')->orWhere('property_type', (string) $property->property_type);
            })
            ->where(function ($q) use ($property) {
                $q->where('province_code', 0)->orWhere('province_code', (int) $property->province_code);
            });

        // Gộp theo khách: giữ điểm cao nhất + nhu cầu tạo ra điểm.
        $byCustomer = [];
        foreach ($demandsQuery->get() as $demand)
        {
            if (!MatchEngine::matchesProperty($demand, $property))
            {
                continue;
            }

            $cid = (int) $demand->customer_id;
            $s   = MatchEngine::score($demand, $property);

            if (!isset($byCustomer[$cid]) || $s > $byCustomer[$cid]['score'])
            {
                $byCustomer[$cid] = [
                    'score'     => $s,
                    'demand_id' => (int) $demand->id,
                    'reasons'   => MatchEngine::reasons($demand, $property),
                ];
            }
        }

        if (empty($byCustomer))
        {
            response()->success('success', []);
        }

        // Nạp khách trong data-scope (của mình / kho chung chưa khóa) nếu không xem toàn sàn.
        $custQuery = Customer::whereIn('id', array_keys($byCustomer));

        if (!$this->canViewAll('customer_view_all'))
        {
            $me  = $this->userId();
            $now = date('Y-m-d H:i:s');
            $custQuery->where(function ($q) use ($me, $now) {
                $q->where('assigned_user_id', $me)
                    ->orWhere(function ($shared) use ($now) {
                        $shared->where('assigned_user_id', 0)
                            ->where(function ($lock) use ($now) {
                                $lock->whereNull('locked_until')->orWhere('locked_until', '<=', $now);
                            });
                    });
            });
        }

        // Khách đã được gửi BĐS này.
        $sent = [];
        foreach (PropertyCustomerMatch::where('property_id', $property->id)->get() as $m)
        {
            $sent[(int) $m->customer_id] = true;
        }

        $items = [];
        foreach ($custQuery->get() as $c)
        {
            $cid = (int) $c->id;
            $m   = $byCustomer[$cid];

            $items[] = [
                'id'               => $cid,
                'full_name'        => (string) $c->full_name,
                'phone'            => (string) $c->phone,
                'pipeline_stage'   => (string) $c->pipeline_stage,
                'temperature'      => (string) $c->temperature,
                'lead_score'       => (int) $c->lead_score,
                'assigned_user_id' => (int) $c->assigned_user_id,
                'score'            => (int) $m['score'],
                'demand_id'        => (int) $m['demand_id'],
                'reasons'          => $m['reasons'],
                'already_sent'     => isset($sent[$cid]),
            ];
        }

        usort($items, fn ($a, $b) => $b['score'] <=> $a['score']);

        response()->success('success', array_slice($items, 0, self::MATCH_LIMIT));
    }

    /** Map 1 media → mảng cho FE (kèm URL công khai). */
    protected function transformMedia($m): array
    {
        return [
            'id'            => (int) $m->id,
            'type'          => (string) $m->type,
            'url'           => PropertyMediaService::url((string) $m->path),
            'size'          => (int) $m->size,
            'original_name' => (string) ($m->original_name ?? ''),
            'sort_order'    => (int) $m->sort_order,
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────────

    /** BĐS xem được: của mình / kho chung / có quyền toàn sàn. 404 nếu không tồn tại. */
    protected function findViewable(int $id)
    {
        $property = Property::where('id', $id)->first();

        if (!hasItems($property))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Bất động sản không tồn tại.');
        }

        $mine   = (int) $property->assigned_user_id === $this->userId();
        $shared = (string) $property->visibility === 'shared';

        if (!$this->canViewAll('property_view_all') && !$mine && !$shared)
        {
            response()->setStatusCode(403)->setApiStatus(403)->error('Bạn không có quyền xem bất động sản này.');
        }

        return $property;
    }

    /** BĐS được sửa/xóa: chỉ của mình hoặc có quyền toàn sàn (kho chung của người khác không được sửa). */
    protected function findOwned(int $id)
    {
        $property = Property::where('id', $id)->first();

        if (!hasItems($property))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Bất động sản không tồn tại.');
        }

        if (!$this->canViewAll('property_view_all') && (int) $property->assigned_user_id !== $this->userId())
        {
            response()->setStatusCode(403)->setApiStatus(403)->error('Bạn không phụ trách bất động sản này.');
        }

        return $property;
    }

    /** Gom + làm sạch input cho tạo/sửa (không gồm code/assigned_user_id — xử lý riêng). */
    protected function collectInput(Request $request): array
    {
        $transaction = (string) $request->input('transaction_type');
        $status      = (string) $request->input('status');
        $visibility  = (string) $request->input('visibility');
        $legal       = (string) $request->input('legal_status');
        $furniture   = (string) $request->input('furniture');

        return [
            'title'            => Str::clear((string) $request->input('title')),
            'project_id'       => max(0, (int) $request->input('project_id')),
            'owner_id'         => max(0, (int) $request->input('owner_id')),
            'property_type'    => Str::clear((string) $request->input('property_type')),
            'transaction_type' => in_array($transaction, self::TRANSACTION_TYPES, true) ? $transaction : 'sale',
            'price'            => (float) $request->input('price'),
            'price_per_m2'     => (float) $request->input('price_per_m2'),
            'area_land'        => (float) $request->input('area_land'),
            'area_usable'      => (float) $request->input('area_usable'),
            'bedrooms'         => max(0, (int) $request->input('bedrooms')),
            'bathrooms'        => max(0, (int) $request->input('bathrooms')),
            'floors'           => max(0, (int) $request->input('floors')),
            'direction'        => Str::clear((string) $request->input('direction')),
            'road_type'        => Str::clear((string) $request->input('road_type')),
            'legal_status'     => in_array($legal, self::LEGAL_STATUSES, true) ? $legal : '',
            'furniture'        => in_array($furniture, self::FURNITURES, true) ? $furniture : '',
            'province_code'    => max(0, (int) $request->input('province_code')),
            'ward_code'        => max(0, (int) $request->input('ward_code')),
            'address'          => Str::clear((string) $request->input('address')),
            'latitude'         => (float) $request->input('latitude'),
            'longitude'        => (float) $request->input('longitude'),
            'description'      => Str::clear((string) $request->input('description')),
            'visibility'       => in_array($visibility, self::VISIBILITIES, true) ? $visibility : 'shared',
            'status'           => in_array($status, self::STATUSES, true) ? $status : 'available',
        ];
    }

    /** Map 1 bản ghi BĐS → mảng cho FE. */
    protected function transform($row): array
    {
        return [
            'id'               => (int) $row->id,
            'code'             => (string) $row->code,
            'title'            => (string) $row->title,
            'project_id'       => (int) $row->project_id,
            'owner_id'         => (int) $row->owner_id,
            'property_type'    => (string) $row->property_type,
            'transaction_type' => (string) $row->transaction_type,
            'price'            => (float) $row->price,
            'price_per_m2'     => (float) $row->price_per_m2,
            'area_land'        => (float) $row->area_land,
            'area_usable'      => (float) $row->area_usable,
            'bedrooms'         => (int) $row->bedrooms,
            'bathrooms'        => (int) $row->bathrooms,
            'floors'           => (int) $row->floors,
            'direction'        => (string) ($row->direction ?? ''),
            'road_type'        => (string) ($row->road_type ?? ''),
            'legal_status'     => (string) ($row->legal_status ?? ''),
            'furniture'        => (string) ($row->furniture ?? ''),
            'province_code'    => (int) $row->province_code,
            'ward_code'        => (int) $row->ward_code,
            'address'          => (string) ($row->address ?? ''),
            'latitude'         => (float) $row->latitude,
            'longitude'        => (float) $row->longitude,
            'description'      => (string) ($row->description ?? ''),
            'visibility'       => (string) $row->visibility,
            'status'           => (string) $row->status,
            'assigned_user_id' => (int) $row->assigned_user_id,
            'created'          => $row->created,
        ];
    }
}
