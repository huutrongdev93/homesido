<?php

namespace App\Controllers\Api;

use App\Models\Property;
use App\Models\PropertyMedia;
use App\Services\Storage\PropertyMediaService;
use SkillDo\Cms\Location\Location2;
use SkillDo\Cms\Models\User;
use SkillDo\Http\Request;
use SkillDo\Routing\Controller\Controller;

/**
 * API CÔNG KHAI cho 1 bất động sản — phục vụ trang giới thiệu gửi khách xem KHÔNG cần đăng nhập
 * (route `api/public/property/{code}`, ngoài middleware jwt). Xem [docs/features/public-listing.md].
 *
 * Chỉ trả field tiếp thị + liên hệ nhân viên phụ trách; TUYỆT ĐỐI không lộ dữ liệu nội bộ
 * (chủ nhà, assigned_user_id thô, visibility, ghi chú). Resolve theo `code` (không unique cứng ở
 * DB → lấy bản ghi mới nhất khớp mã, chưa xóa mềm, không `inactive`).
 */
class PublicPropertyApi extends Controller
{
    /** BĐS liên quan hiển thị ở cuối trang. */
    const RELATED_LIMIT = 6;

    // ─── Nhãn enum (tiếng Việt) ───────────────────────────────────────────────────────
    // ⚠️ SAO CHÉP từ UtilsApi::index phần `property`. Trang công khai không có JWT nên không gọi
    //    được api/utils → phải tự map ở đây. Đổi enum ở UtilsApi thì ĐỒNG BỘ lại các mảng này.
    const PROPERTY_TYPE_LABELS = [
        'apartment' => 'Căn hộ', 'house' => 'Nhà phố', 'villa' => 'Biệt thự', 'land' => 'Đất nền',
        'shophouse' => 'Shophouse', 'farmland' => 'Đất nông nghiệp', 'warehouse' => 'Kho xưởng', 'office' => 'Văn phòng',
    ];
    const TRANSACTION_LABELS = ['sale' => 'Bán', 'rent' => 'Cho thuê'];
    const STATUS_LABELS = [
        'available' => 'Đang bán', 'deposited' => 'Đang cọc', 'sold' => 'Đã bán', 'rented' => 'Đã cho thuê', 'inactive' => 'Ngừng',
    ];
    const DIRECTION_LABELS = [
        'east' => 'Đông', 'west' => 'Tây', 'south' => 'Nam', 'north' => 'Bắc',
        'southeast' => 'Đông Nam', 'southwest' => 'Tây Nam', 'northeast' => 'Đông Bắc', 'northwest' => 'Tây Bắc',
    ];
    const ROAD_TYPE_LABELS = [
        'frontage' => 'Mặt tiền đường', 'car_alley' => 'Hẻm xe hơi', 'bike_alley' => 'Hẻm xe máy', 'walk_alley' => 'Hẻm bộ',
    ];
    const LEGAL_LABELS = [
        'red_book' => 'Sổ đỏ', 'pink_book' => 'Sổ hồng', 'sale_contract' => 'HĐ mua bán', 'waiting' => 'Đang chờ sổ', 'other' => 'Khác',
    ];
    const FURNITURE_LABELS = ['none' => 'Bàn giao thô', 'basic' => 'Cơ bản', 'full' => 'Đầy đủ'];

    /**
     * GET api/public/property/{code} — chi tiết công khai 1 BĐS + media + liên hệ NV + BĐS liên quan.
     */
    public function detail(Request $request, $code): void
    {
        $code = trim((string) $code);

        $property = Property::where('code', $code)
            ->where('trash', 0)
            ->where('status', '!=', 'inactive')
            ->orderByDesc('id')
            ->first();

        if (!hasItems($property))
        {
            response()->setStatusCode(404)->setApiStatus(404)->error('Bất động sản không tồn tại hoặc đã ngừng hiển thị.');
        }

        // Media (ảnh/video/tài liệu) theo thứ tự hiển thị; giải ảnh đại diện (cover chọn else ảnh đầu).
        $mediaRows = PropertyMedia::where('property_id', $property->id)->orderBy('sort_order')->orderBy('id')->get();
        [$media, $thumbnail] = $this->buildMedia($mediaRows, (int) $property->cover_media_id);

        // Tên tỉnh/phường (Location2 — 2 cấp).
        $provinceName = $this->provinceName((int) $property->province_code);
        $wardName     = $this->wardName((int) $property->province_code, (int) $property->ward_code);

        $data = [
            'code'                  => (string) $property->code,
            'title'                 => (string) $property->title,
            'transaction_type'      => (string) $property->transaction_type,
            'transaction_label'     => self::TRANSACTION_LABELS[(string) $property->transaction_type] ?? '',
            'property_type'         => (string) $property->property_type,
            'property_type_label'   => self::PROPERTY_TYPE_LABELS[(string) $property->property_type] ?? '',
            'status'                => (string) $property->status,
            'status_label'          => self::STATUS_LABELS[(string) $property->status] ?? '',
            'price'                 => (float) $property->price,
            'price_per_m2'          => (float) $property->price_per_m2,
            'area_land'             => (float) $property->area_land,
            'area_usable'           => (float) $property->area_usable,
            'bedrooms'              => (int) $property->bedrooms,
            'bathrooms'             => (int) $property->bathrooms,
            'floors'                => (int) $property->floors,
            'direction'             => (string) ($property->direction ?? ''),
            'direction_label'       => self::DIRECTION_LABELS[(string) ($property->direction ?? '')] ?? '',
            'road_type'             => (string) ($property->road_type ?? ''),
            'road_type_label'       => self::ROAD_TYPE_LABELS[(string) ($property->road_type ?? '')] ?? '',
            'legal_status'          => (string) ($property->legal_status ?? ''),
            'legal_label'           => self::LEGAL_LABELS[(string) ($property->legal_status ?? '')] ?? '',
            'furniture'             => (string) ($property->furniture ?? ''),
            'furniture_label'       => self::FURNITURE_LABELS[(string) ($property->furniture ?? '')] ?? '',
            'address'               => (string) ($property->address ?? ''),
            'province_name'         => $provinceName,
            'ward_name'             => $wardName,
            'latitude'              => (float) $property->latitude,
            'longitude'             => (float) $property->longitude,
            'description'           => (string) ($property->description ?? ''),
            'thumbnail'             => $thumbnail,
            'media'                 => $media,
            'contact'               => $this->contact((int) $property->assigned_user_id),
            'related'               => $this->related($property),
        ];

        response()->success('success', $data);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────────────

    /** Media công khai + URL ảnh đại diện. Trả `[array $media, ?string $thumbnail]`. */
    protected function buildMedia($mediaRows, int $coverMediaId): array
    {
        // Ảnh đại diện hiệu lực: cover đã chọn (nếu là ảnh) else ảnh đầu tiên.
        $firstImagePath = null;
        $coverPath      = null;
        foreach ($mediaRows as $m)
        {
            if ((string) $m->type !== 'image')
            {
                continue;
            }
            if ($firstImagePath === null)
            {
                $firstImagePath = (string) $m->path;
            }
            if ($coverMediaId > 0 && (int) $m->id === $coverMediaId)
            {
                $coverPath = (string) $m->path;
            }
        }
        $thumbPath = $coverPath ?? $firstImagePath;
        $thumbnail = $thumbPath !== null ? PropertyMediaService::url($thumbPath) : null;

        $media = [];
        foreach ($mediaRows as $m)
        {
            $media[] = [
                'type'          => (string) $m->type,
                'url'           => PropertyMediaService::url((string) $m->path),
                'original_name' => (string) ($m->original_name ?? ''),
            ];
        }

        return [$media, $thumbnail];
    }

    /** Thông tin liên hệ NV phụ trách (tên + SĐT + Zalo). Null nếu không có SĐT. */
    protected function contact(int $userId): ?array
    {
        if ($userId <= 0)
        {
            return null;
        }

        $user = User::where('id', $userId)->first();
        if (!hasItems($user))
        {
            return null;
        }

        $phone = trim((string) $user->phone);
        if ($phone === '')
        {
            return null;   // không có SĐT thì ẩn khối liên hệ (theo thiết kế)
        }

        $name = trim((string) $user->lastname . ' ' . (string) $user->firstname);

        return [
            'name'  => $name !== '' ? $name : (string) $user->username,
            'phone' => $phone,
        ];
    }

    /**
     * BĐS liên quan (khối "BĐS dành cho bạn"): chỉ hàng kho chung, còn giao dịch được, cùng hình thức;
     * ưu tiên cùng tỉnh rồi bù thêm khác tỉnh nếu thiếu. Không lộ dữ liệu nội bộ.
     */
    protected function related($property): array
    {
        $base = fn () => Property::where('visibility', 'shared')
            ->where('trash', 0)
            ->whereIn('status', ['available', 'deposited'])
            ->where('transaction_type', (string) $property->transaction_type)
            ->where('id', '!=', (int) $property->id);

        // Ưu tiên cùng tỉnh.
        $rows = $base()->where('province_code', (int) $property->province_code)
            ->orderByDesc('id')->limit(self::RELATED_LIMIT)->get();

        // Bù thêm BĐS khác tỉnh nếu chưa đủ.
        if (count($rows) < self::RELATED_LIMIT)
        {
            $exclude = [(int) $property->id];
            foreach ($rows as $r)
            {
                $exclude[] = (int) $r->id;
            }
            $more = $base()->whereNotIn('id', $exclude)
                ->orderByDesc('id')->limit(self::RELATED_LIMIT - count($rows))->get();
            $rows = $rows->merge($more);
        }

        $thumbs = PropertyMediaService::thumbnails($rows);

        $items = [];
        foreach ($rows as $r)
        {
            $items[] = [
                'code'             => (string) $r->code,
                'title'            => (string) $r->title,
                'transaction_type' => (string) $r->transaction_type,
                'price'            => (float) $r->price,
                'area'             => (float) ($r->area_usable ?: $r->area_land),
                'province_name'    => $this->provinceName((int) $r->province_code),
                'thumbnail'        => $thumbs[(int) $r->id] ?? null,
            ];
        }

        return $items;
    }

    /** Tên tỉnh theo code (cache tĩnh trong request). */
    protected function provinceName(int $provinceCode): string
    {
        if ($provinceCode <= 0)
        {
            return '';
        }

        static $provinces = null;
        if ($provinces === null)
        {
            $provinces = Location2::provincesOptions();
        }

        return (string) ($provinces[$provinceCode] ?? '');
    }

    /** Tên phường theo tỉnh+code. */
    protected function wardName(int $provinceCode, int $wardCode): string
    {
        if ($provinceCode <= 0 || $wardCode <= 0)
        {
            return '';
        }

        $wards = Location2::wardsOptions($provinceCode);

        return (string) ($wards[$wardCode] ?? '');
    }
}
