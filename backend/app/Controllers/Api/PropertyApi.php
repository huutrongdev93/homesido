<?php

namespace App\Controllers\Api;

use App\Models\Property;
use App\Models\PropertyMedia;
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
        foreach (PropertyMedia::where('property_id', $property->id)->orderBy('sort_order')->get() as $m)
        {
            $media[] = [
                'id'         => (int) $m->id,
                'type'       => (string) $m->type,
                'path'       => (string) $m->path,
                'sort_order' => (int) $m->sort_order,
            ];
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
     * DELETE api/property/{id} — xóa mềm (trash = 1).
     */
    public function destroy(Request $request, $id): void
    {
        $this->requireCap('property_delete', 'Bạn không có quyền xóa bất động sản.');

        $property = $this->findOwned((int) $id);

        Property::where('id', $property->id)->trash();

        response()->success('Đã xóa bất động sản', ['id' => (int) $property->id]);
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
