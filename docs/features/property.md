# Bất động sản (Property — Kho hàng)

Quản lý kho sản phẩm BĐS: CRUD + filter/phân trang + **data-scope kho chung** (của tôi + hàng
`visibility='shared'`) + xóa mềm + địa chỉ tỉnh→phường. Nhân khuôn từ [Khách hàng](customer.md);
khác ở scope (thấy kho chung) và có địa giới hành chính. **Media (ảnh/video) + kế toán dung lượng
theo user: xem [media.md](media.md)** — upload/xóa/sắp xếp qua modal riêng, xóa hẳn (`?force=1`) purge file.

## Bản đồ file (front-to-back)

```
Bất động sản (Property)
├─ FE  src/features/Property/pages/Property.js              # list: antd Table + toolbar + phân trang; fmtPrice (tỷ/tr); can{add,edit,delete} + events. Expandable row (click hàng → chi tiết inline): expandedRowKeys controlled 1-hàng, expandRowByClick + showExpandColumn:false; cột actions stopPropagation
│  ├─ src/features/Property/components/PropertyDetailPanel.js # panel chi tiết inline (mô phỏng thiết kế): header (badge+tiêu đề+giá cam+nút Sửa/Ảnh) · THÔNG SỐ (spec cards động) · THƯ VIỆN (grid ảnh/video/tài liệu read-only, click mở tab mới; dùng lại .mediaGrid + formatBytes của PropertyMediaModal) · VỊ TRÍ (địa chỉ + tag phường/tỉnh). Tự fetch useGetPropertyQuery (media+field), tên tỉnh/phường tra locationApiSlice
│  ├─ src/features/Property/components/PropertyFormModal.js # form thêm/sửa: ModalForm + RHF + yup; cascade tỉnh→phường; select "Dự án" (project_id) + "Chủ nhà" (owner_id) — danh mục ở [catalog.md](catalog.md). Giá nhập theo TRIỆU (InputPriceField, quy đổi ×/÷1e6 sang VNĐ); Hướng + Đường vào (road_type) = SelectField (enum directions/road_types); các bộ 3 field (Hình thức/Trạng thái/Phạm vi · Giá/DT đất/DT sử dụng · PN/PT/Tầng) gộp 1 hàng qua .mform-row-3
│  ├─ src/features/Property/style/Property.module.scss      # style toolbar (copy từ Customer)
│  ├─ src/reduxs/api/propertyApiSlice.js                    # RTK Query CRUD; tags ['Property']
│  ├─ src/reduxs/api/locationApiSlice.js                    # getProvinces/getWards (tham chiếu tỉnh→phường, dùng chung)
│  ├─ src/reduxs/api/apiSlice.js                            # tag 'Property'
│  ├─ src/routes/PrivateRoutes.js                           # route /properties cap 'property_view' (DefaultLayout)
│  ├─ src/layout/Sidebar/NavBarData.js                      # menu "Kinh doanh" → "Bất động sản", gate useCan('property_view')
│  └─ src/context/AppProvider.js                            # appData.property.{property_types,transaction_types,statuses,visibilities,legal_statuses,furnitures,directions,road_types} (enum tĩnh)
└─ BE  routes/api.php  (prefix api/property, middleware jwt) + (prefix api/location, PUBLIC)
       ├─ app/Controllers/Api/PropertyApi.php               # index/detail/add/update/destroy; scope kho-chung; findViewable vs findOwned; auto-gen code; collectInput/transform
       ├─ app/Controllers/Api/ApiController.php             # BASE: paging/respondList + requireCap/canViewAll
       ├─ app/Controllers/Api/LocationApi.php               # provinces/wards qua SkillDo Cms Location2 (tỉnh→phường 2 cấp, int code)
       ├─ app/Controllers/Api/UtilsApi.php::index           # enum property.* cho FE
       ├─ app/Roles/RoleCapabilitiesProperty.php            # cap: property_view/add/edit/delete/view_all
       ├─ app/Models/Property.php (SoftDeletes) + PropertyMedia.php
       └─ bảng DB: properties, property_media (database/crm.php)
```

## Cap / Route / Env

- **Cap**: `property_view` (xem + route), `property_add/edit/delete`, `property_view_all` (xem toàn sàn).
- **Route BĐS** (`jwt`): `GET/POST api/property`, `GET/PUT/DELETE api/property/{id}`.
- **Route địa giới** (**PUBLIC**, không jwt): `GET api/location/provinces`, `GET api/location/wards?province_id=` → `[{value,label}]`.

## Data-scope (khác Khách hàng)

- **Xem** (`findViewable`): của mình HOẶC `visibility='shared'` (kho chung) HOẶC `property_view_all`.
  Query list: `where(assigned_user_id=me OR visibility='shared')` khi không có view_all.
- **Sửa/Xóa** (`findOwned`): CHỈ hàng của mình (hoặc view_all). Hàng shared của người khác xem được nhưng không sửa.

## Gotcha

- ⚠️ **`Str::clear(null)` trả `null` (KHÔNG phải `''`), và `Builder::update()` KHÔNG chạy cleaner
  null→''** (chỉ `create()` chạy). → cột **NOT NULL** (vd `direction` default '') nhận `null` khi update
  → lỗi SQL `Column cannot be null`. **Luôn ép `(string)`**: `Str::clear((string) $request->input('x'))`.
  (Lỗi này bị `JwtLoginAs` che thành **"Invalid token"** — xem dưới.)
- ⚠️ **Exception trong controller bị báo nhầm "Invalid token"**: middleware `JwtLoginAs::handle` bọc cả
  `$next($request)` trong `try/catch` → mọi exception từ controller thành 401 "Invalid token". Khi gặp
  401 lạ trên route đã có token hợp lệ, **đọc `D:/wamp/logs/php_error.log`** (dòng `[JwtLoginAs] ...`)
  để lấy exception thật, đừng tưởng lỗi token.
- **Auto-gen `code`**: bỏ trống → sinh `BDS` + 7 ký tự random (không unique cứng ở DB, chấp nhận).
- **Giá lưu VNĐ, nhập TRIỆU**: DB `price` là VNĐ (fmtPrice chia 1e9/1e6). Form nhập theo triệu → reset chia
  `/1e6`, submit nhân `*1e6`. Sửa unit ở form phải đổi cả 2 chiều để không lệch. **Đừng dùng `addonAfter` cho
  InputPriceField** — antd bọc InputNumber vào group-wrapper (inline-block) làm label nằm cùng hàng + viền lệch
  style hệ thống; đơn vị "triệu" để trong label thay vì addon.
- **`directions` / `road_types`** là enum slug ở `UtilsApi::index` (`direction`, `road_type` — vị trí/đường vào:
  frontage/car_alley/bike_alley/walk_alley). Cột `road_type` thêm qua migration `database/property.php`. Record cũ
  lưu direction free-text sẽ không khớp option (hiển thị trống).
- **Style form đồng bộ**: input select áp border-radius 4px (khớp `.form-control`) qua override
  `.form .ant-select-selector` ở `assets/style/styles.scss`. Field gộp hàng 3 cột dùng `.mform-row-3`
  (helper global ở `ModalForm.module.scss`, `grid-column: 1 / -1`).
- **Table bọc `.app-card`** (nền trắng + viền + shadow) để nổi khối trên canvas xám `--surface-muted` của
  `AppShell.content` (không bọc thì table trắng chìm vào nền xám). `.mediaItem` dùng chung cho modal media
  (div) lẫn thư viện panel (`<a href>`): hiệu ứng hover-nổi chỉ áp khi có `[href]`.
- **Địa giới 2 cấp** (bỏ quận/huyện): chỉ `province_code` + `ward_code` (int). FE cascade: đổi tỉnh →
  reset phường (`setValue('ward_code', undefined)`); phường query theo `province_code` (skip nếu chưa chọn tỉnh).
- **Media đã có** (xem [media.md](media.md)): upload/list/xóa/sắp xếp + kế toán dung lượng theo user;
  `detail` trả `media:[]` với `url`/`size`. Xóa mềm GIỮ media; xóa hẳn (`DELETE ?force=1`) mới purge.
- Các gotcha chung (base Model ép `''`/`0`, data-scope thủ công, xóa mềm `->trash()`, enum từ api/utils,
  lỗi nghiệp vụ `->setStatusCode(422)`): xem [customer.md](customer.md) + [../database.md](../database.md) §2.

