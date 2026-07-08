# Bất động sản (Property — Kho hàng)

Quản lý kho sản phẩm BĐS: CRUD + filter/phân trang + **data-scope kho chung** (của tôi + hàng
`visibility='shared'`) + xóa mềm + địa chỉ tỉnh→phường. Nhân khuôn từ [Khách hàng](customer.md);
khác ở scope (thấy kho chung) và có địa giới hành chính. Media (ảnh/tài liệu) **chưa làm** — cần
dựng pipeline upload riêng (base chưa có endpoint upload; `ImageUploadField`/`FileUpload` là component
presentational chờ nối).

## Bản đồ file (front-to-back)

```
Bất động sản (Property)
├─ FE  src/features/Property/pages/Property.js              # list: antd Table + toolbar (search + lọc loại hình/trạng thái) + phân trang; fmtPrice (tỷ/tr); can{add,edit,delete} + events
│  ├─ src/features/Property/components/PropertyFormModal.js # form thêm/sửa: ModalForm + RHF + yup; cascade tỉnh→phường (watch province_code, reset ward khi đổi tỉnh)
│  ├─ src/features/Property/style/Property.module.scss      # style toolbar (copy từ Customer)
│  ├─ src/reduxs/api/propertyApiSlice.js                    # RTK Query CRUD; tags ['Property']
│  ├─ src/reduxs/api/locationApiSlice.js                    # getProvinces/getWards (tham chiếu tỉnh→phường, dùng chung)
│  ├─ src/reduxs/api/apiSlice.js                            # tag 'Property'
│  ├─ src/routes/PrivateRoutes.js                           # route /properties cap 'property_view' (DefaultLayout)
│  ├─ src/layout/Sidebar/NavBarData.js                      # menu "Kinh doanh" → "Bất động sản", gate useCan('property_view')
│  └─ src/context/AppProvider.js                            # appData.property.{property_types,transaction_types,statuses,visibilities,legal_statuses,furnitures} (enum tĩnh)
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
- **Địa giới 2 cấp** (bỏ quận/huyện): chỉ `province_code` + `ward_code` (int). FE cascade: đổi tỉnh →
  reset phường (`setValue('ward_code', undefined)`); phường query theo `province_code` (skip nếu chưa chọn tỉnh).
- **Media chưa có**: `properties.property_media` bảng đã tạo nhưng chưa có API upload; detail trả `media:[]`.
- Các gotcha chung (base Model ép `''`/`0`, data-scope thủ công, xóa mềm `->trash()`, enum từ api/utils,
  lỗi nghiệp vụ `->setStatusCode(422)`): xem [customer.md](customer.md) + [../database.md](../database.md) §2.

