# Trang công khai BĐS (Public Listing — link gửi khách)

Mỗi BĐS có 1 **URL công khai theo mã** (`/p/{code}`) render trang giới thiệu **không cần đăng nhập**
để nhân viên gửi link cho khách xem. Trang gồm: thư viện ảnh, đặc điểm, mô tả, bản đồ, **liên hệ NV
phụ trách** (Gọi/Zalo) và vài BĐS liên quan. Chỉ trả **field tiếp thị + liên hệ**; không lộ dữ liệu
nội bộ (chủ nhà, `assigned_user_id` thô, `visibility`). Xem thêm [property.md](property.md).

## Bản đồ file (front-to-back)

```
Trang công khai BĐS (Public Listing)
├─ FE  src/features/PublicProperty/pages/PublicProperty.js       # trang /p/:code: gallery (antd Image.PreviewGroup + thumbnail + đếm n/N) · header badge/tiêu đề/địa chỉ/giá · ĐẶC ĐIỂM (spec động) · MÔ TẢ · BẢN ĐỒ (iframe Google Maps) · BĐS DÀNH CHO BẠN (RelatedCard) · thanh LIÊN HỆ (Gọi tel:/Zalo). Loading + 404. Đổi document.title theo BĐS
│  ├─ src/features/PublicProperty/style/PublicProperty.module.scss # layout marketing full-width, độc lập AppShell; thanh liên hệ = fixed bottom (mobile) → card nổi góc phải (desktop); responsive
│  ├─ src/reduxs/api/publicPropertyApiSlice.js                   # RTK Query getPublicProperty(code) → url `public/property/{code}`; KHÔNG token (không tag)
│  └─ src/routes/PublicRoutes.js                                  # route công khai { path:'/p/:code', layout:null } — App.js map publicRoutes KHÔNG bọc PrivateRoutes
├─ Điểm vào (copy link)
│  └─ src/features/Property/components/PropertyDetailPanel.js     # nút "Copy link công khai" (navigator.clipboard) + "Xem trang" (mở /p/{code}) trong .dActions
└─ BE  routes/api.php  (prefix api/public, KHÔNG middleware jwt)
       └─ app/Controllers/Api/PublicPropertyApi.php               # detail($code): resolve theo code (trash=0, status!=inactive, bản mới nhất); media+cover; nhãn enum (const nội bộ); tên tỉnh/phường (Location2); contact NV (User theo assigned_user_id); related (kho chung, cùng hình thức)
          ├─ tái dùng: App\Services\Storage\PropertyMediaService::url()/thumbnails()
          ├─ App\Models\Property + PropertyMedia
          └─ SkillDo\Cms\Models\User (tên = lastname+firstname; phone) · SkillDo\Cms\Location\Location2
```

## Route / Data

- **Route** (PUBLIC, ngoài `jwt`): `GET api/public/property/{code}` → `PublicPropertyApi@detail`.
- **Resolve theo `code`**: `code` giờ **DUY NHẤT** (app enforce + UNIQUE index
  `properties_code_unique`, xem gotcha property.md) nên tối đa 1 bản active khớp mã. Query lấy bản
  `trash=0`, `status != 'inactive'` (kèm `orderByDesc('id')` phòng hờ). Không có → **404**.
- **Response `data`**: field tiếp thị + mỗi enum kèm `*_label` (đã dịch VN server-side) +
  `province_name`/`ward_name` + `thumbnail` + `media[]` (`type/url/original_name`) +
  `contact` (`{name,phone}` hoặc `null`) + `related[]` (`code/title/transaction_type/price/area/province_name/thumbnail`).
- **KHÔNG có cột/migration/cap mới**: mọi BĐS đều có link sẵn; kiểm soát chỉ bằng `trash`/`status`.

## Gotcha

- ⚠️ **Nhãn enum bị TRÙNG LẶP**: `PublicPropertyApi` tự khai `const *_LABELS` (property_type,
  transaction, status, direction, road_type, legal, furniture) **sao chép** từ `UtilsApi::index` phần
  `property`. Lý do: trang công khai không có JWT nên **không gọi được `api/utils`** (cũng không có
  appData ở FE). **Đổi enum ở `UtilsApi` thì PHẢI đồng bộ lại các const này.**
- **Liên hệ ẩn khi NV không có SĐT**: `contact` = `null` nếu `assigned_user_id=0` hoặc user không có
  `phone` → FE ẩn cả thanh liên hệ. (Tài khoản `admin` seed không có phone → test thấy null là đúng.)
  Zalo link = `https://zalo.me/{chỉ-số}` (strip ký tự không phải số); Gọi = `tel:{phone}`.
- **Bản đồ Google Maps (iframe keyless)**: `src=https://maps.google.com/maps?q={lat,lng | địa chỉ}&z=15&output=embed`
  (ưu tiên tọa độ, else địa chỉ). Không cần API key. **Có thể hiển thị TRỐNG trong trình duyệt
  headless/bot** (Google chặn/consent) nhưng render bình thường cho người dùng thật; luôn có nút
  "Xem trên Google Maps" (`www.google.com/maps?q=...`) làm fallback chắc chắn.
- **Gallery lightbox**: `Image.PreviewGroup` gồm ảnh lớn (theo `active`) + các ảnh còn lại (ẩn, **bỏ
  ảnh đang hiển thị** để không trùng). Video render `<video controls>` riêng (không vào PreviewGroup).
- **Không token vẫn an toàn**: interceptor `utils/http.js` chỉ gắn Bearer khi có token và chỉ refresh
  401 khi đang có token → khách vãng lai gọi endpoint public không bị đá về `/login`.
- **Link tuyệt đối khi share**: nút copy ở panel dựng `window.location.origin + REACT_APP_HOMEPAGE + '/p/' + code`
  (route đã có basename `REACT_APP_HOMEPAGE`; chỗ dùng full URL phải tự prepend — xem App.js).
- **Related không lộ kho riêng**: chỉ `visibility='shared'` + `status IN (available,deposited)`, cùng
  `transaction_type`; ưu tiên cùng tỉnh, bù thêm khác tỉnh nếu thiếu; giới hạn 6.
