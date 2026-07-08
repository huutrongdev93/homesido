# Kế hoạch Giai đoạn 1 — MVP Core CRM (HomeSido)

> Bản kế hoạch triển khai, bám **convention thực tế** của base source (đã khảo sát backend + frontend).
> Đặc tả gốc: `dac-ta-crm-bat-dong-san.md`. Quyết định kiến trúc: **BỎ multi-tenant** — 1 deployment = 1 sàn.

## 0. Quyết định kiến trúc đã chốt

| Vấn đề | Quyết định |
|---|---|
| Multi-tenant | **Bỏ** — mỗi bản nhân bản phục vụ 1 sàn / nhóm cá nhân. Không có `tenant_id`, không Global Scope. |
| Chế độ personal vs business | 1 cờ cấu hình (`system` option `app_mode` hoặc `.env`). Business bật khóa khách/bàn giao/báo cáo; personal ẩn. |
| **Phòng ban / cây tổ chức** | **GĐ1 để phẳng** — chỉ users + role phẳng, KHÔNG có `departments`/`manager_id`. Data-scope 2 mức: *của tôi* vs *toàn sàn* (cap `*_view_all`). Cây phòng ban + scope theo nhóm để GĐ2. |
| Timestamps | House-style: cột `created` (default CURRENT_TIMESTAMP) + `updated` (nullable / ON UPDATE). **KHÔNG** `created_at/updated_at`. |
| Soft delete | Cột `trash` (tinyInteger) + trait `SoftDeletes` như CMS User model. |
| Người tạo | Cột `user_created` (unsignedBigInteger) — base Model **tự điền** `Auth::id()` khi create. |
| Phân quyền | Dùng engine role/capability sẵn có (không spatie). Thêm nhóm cap `customer`, `property`. |
| Data scope | Làm ở tầng query trong controller: Sales thấy của mình + kho chung; cap `*_view_all` xem toàn sàn. |
| Phân trang | Base **chưa có `paginate()`** → tự viết helper limit/offset + count. |
| Mã địa giới | **2 cấp: tỉnh → phường** (int code), qua `App\Controllers\Api\LocationApi` sẵn có (`Location2`): `GET /api/location/provinces`, `/api/location/wards?province_id=` trả `[{value,label}]`. **Bỏ cấp quận/huyện** (`district`). Cột dùng: `province_code` + `ward_code` (integer). |
| property_type | **string** + validate ở app; danh sách loại hình khai ở `api/utils` (thêm/bớt không ALTER bảng). |

## 1. Phạm vi Giai đoạn 1 (theo roadmap MVP)

**Có trong GĐ1:**
- Khách hàng: hồ sơ + nhu cầu + nguồn khách + **chống trùng SĐT** + **khóa khách** + bàn giao.
- Bất động sản: hồ sơ + media + dự án + chủ nhà ký gửi + kho chung/riêng.
- Chăm sóc: timeline tương tác + lịch chăm sóc/nhắc việc + kịch bản (template).
- Tác vụ nền: nhắc chăm sóc đến hạn, phát hiện khách nguội, auto-release khách quá hạn.
- Dashboard **"Cần chăm hôm nay"** + báo cáo cơ bản.

**Để Giai đoạn 2** (không làm bây giờ): Matching khách↔SP, Lịch hẹn (appointments), Giao dịch/thanh toán/hoa hồng, báo cáo phễu nâng cao, cây phòng ban đầy đủ.

---

## 2. Schema Giai đoạn 1 (đã chuyển house-style, bỏ tenant_id)

> Tất cả bảng: thêm `created` dateTime default CURRENT_TIMESTAMP; `updated` dateTime nullable (bảng có sửa). Tên bảng **không prefix**. Guard `if(!schema()->hasTable(...))`.

### `lead_sources` — Nguồn khách
`id` bigIncrements · `name` string(255) utf8mb4 · `is_active` tinyInteger default 1 · `created` · `updated`

### `customers` — Khách hàng
`id` · `assigned_user_id` unsignedBigInteger default 0 **index** · `lead_source_id` unsignedBigInteger default 0 · `full_name` string(255) utf8mb4 · `phone` string(20) **index** · `phone_alt` string(20) null · `email` string(100) null · `gender` enum(male,female,other) null · `birth_year` integer null · `address` string(255) null · `occupation` string(255) null · `pipeline_stage` enum(new,contacting,potential,negotiating,won,lost) default 'new' · `temperature` enum(hot,warm,cold) default 'warm' · `lead_score` integer default 0 · `locked_until` dateTime null · `last_interaction_at` dateTime null · `is_cold_flagged` tinyInteger default 0 · `note` text null · `trash` tinyInteger default 0 · `user_created` unsignedBigInteger default 0 · `created` · `updated`
Index tổ hợp: `(assigned_user_id, pipeline_stage)`, `(phone)`.

### `customer_demands` — Nhu cầu / tiêu chí (1 khách nhiều nhu cầu)
`id` · `customer_id` unsignedBigInteger **index** · `demand_type` enum(buy,rent,sell,consign) · `property_type` string(50) default '' · `purpose` enum(live,invest) null · `province_code` integer default 0 · `ward_code` integer default 0 · `budget_min`/`budget_max` decimal(15,2) null · `area_min`/`area_max` decimal(10,2) null · `bedrooms_min` tinyInteger null · `direction` string(30) null · `is_active` tinyInteger default 1 · `created` · `updated`

### `projects` — Dự án
`id` · `name` string(255) utf8mb4 · `developer` string(255) null · `province_code` integer default 0 · `ward_code` integer default 0 · `address` string(255) null · `description` text null · `created` · `updated`

### `property_owners` — Chủ nhà (hàng ký gửi)
`id` · `full_name` string(255) utf8mb4 · `phone` string(20) **index** · `email` string(100) null · `note` text null · `created` · `updated`

### `properties` — Bất động sản
`id` · `project_id` unsignedBigInteger default 0 · `owner_id` unsignedBigInteger default 0 · `code` string(50) **index** · `title` string(255) utf8mb4 · `property_type` string(50) · `transaction_type` enum(sale,rent) default 'sale' · `price` decimal(15,2) default 0 · `price_per_m2` decimal(15,2) null · `area_land` decimal(10,2) null · `area_usable` decimal(10,2) null · `bedrooms`/`bathrooms`/`floors` tinyInteger null · `direction` string(30) null · `legal_status` enum(red_book,pink_book,sale_contract,waiting,other) null · `furniture` enum(none,basic,full) null · `province_code`/`ward_code` integer default 0 · `address` string(255) null · `latitude`/`longitude` decimal(10,7) null · `description` longText null · `visibility` enum(private,shared) default 'shared' · `status` enum(available,deposited,sold,rented,inactive) default 'available' · `assigned_user_id` unsignedBigInteger default 0 **index** · `trash` tinyInteger default 0 · `user_created` unsignedBigInteger default 0 · `created` · `updated`
Index tổ hợp: `(status)`, `(property_type, transaction_type)`, `(province_code, ward_code)`, `(price)`.

### `property_media` — Ảnh / video / tài liệu
`id` · `property_id` unsignedBigInteger **index** · `type` enum(image,video,document) default 'image' · `path` string(255) · `sort_order` integer default 0 · `created`

### `customer_interactions` — Timeline tương tác
`id` · `customer_id` unsignedBigInteger **index** · `user_id` unsignedBigInteger default 0 · `type` enum(call,sms,zalo,email,meeting,note,viewing) · `content` text · `direction` enum(in,out) null · `interacted_at` dateTime · `created`

### `care_schedules` — Lịch chăm sóc / nhắc việc
`id` · `customer_id` unsignedBigInteger **index** · `assigned_user_id` unsignedBigInteger **index** · `care_template_id` unsignedBigInteger default 0 · `type` enum(call,sms,zalo,email,meeting) default 'call' · `scheduled_at` dateTime **index** · `content` text null · `status` enum(pending,done,missed,canceled) default 'pending' · `completed_at` dateTime null · `result_note` text null · `created` · `updated`
Index tổ hợp: `(scheduled_at, status)`, `(assigned_user_id, status)`.

### `care_templates` — Kịch bản chăm sóc
`id` · `name` string(255) utf8mb4 · `channel` enum(call,sms,zalo,email) default 'call' · `content` text · `stage` string(30) null · `is_active` tinyInteger default 1 · `created` · `updated`

### `customer_transfers` — Lịch sử bàn giao khách
`id` · `customer_id` unsignedBigInteger **index** · `from_user_id` unsignedBigInteger default 0 · `to_user_id` unsignedBigInteger default 0 · `transferred_by` unsignedBigInteger default 0 · `reason` string(255) null · `created`

---

## 3. Capabilities mới (nhóm quyền)

Tạo 2 class + khai vào `register.php`:

**`RoleCapabilitiesCustomer::all()`** (nhóm `customer`, label "Khách hàng"):
`customer_view`→Xem khách · `customer_add`→Thêm · `customer_edit`→Sửa · `customer_delete`→Xóa · `customer_view_all`→Xem toàn sàn · `customer_transfer`→Bàn giao khách

**`RoleCapabilitiesProperty::all()`** (nhóm `property`, label "Bất động sản"):
`property_view` · `property_add` · `property_edit` · `property_delete` · `property_view_all`

> Care/interaction gate chung theo `customer_*`. `administrator` bypass tất cả.

---

## 4. Backend — file cần tạo/sửa

**Migration & DB**
1. Thêm các block `if(!schema()->hasTable(...))` vào `backend/database/database.php` (hoặc file riêng `database/crm.php` rồi đăng ký).
2. `UtilsApi::database()` — nếu tách file: thêm `'database/crm.php'` vào mảng `$migrations`.
3. **Cập nhật `docs/database.md`** — thêm mô tả 11 bảng mới (bắt buộc cùng lần sửa).

**Models** (`backend/app/Models/`, mỗi file ~4 dòng, `extends SkillDo\Database\Eloquent\Model` + `$table`; Customer/Property thêm `use SoftDeletes`):
`LeadSource`, `Customer`, `CustomerDemand`, `Project`, `PropertyOwner`, `Property`, `PropertyMedia`, `CustomerInteraction`, `CareSchedule`, `CareTemplate`, `CustomerTransfer`.

**Controllers** (`backend/app/Controllers/Api/`, mẫu = `RoleApi.php`: `authorize()` + `$request->validate([...])` + `response()->success/error`):
- `CustomerApi` (index có filter/scope/phân trang, detail, add, update, destroy, transfer, addInteraction).
- `CustomerDemandApi` (hoặc gộp vào CustomerApi qua sub-route).
- `PropertyApi`, `PropertyMediaApi`, `ProjectApi`, `PropertyOwnerApi`.
- `CareScheduleApi` (index "cần chăm hôm nay", add, complete), `CareTemplateApi`.
- `LeadSourceApi`.
- `DashboardApi` (số liệu cần-chăm-hôm-nay, khách theo pipeline).
- Helper phân trang dùng chung (trait `Paginates` hoặc method base): `limit/offset` + `count`.
- Helper data-scope: `scopeMine($query, $capViewAll)` — nếu không có cap view_all thì `where('assigned_user_id', Auth::id())`.

**Routes** — thêm nhóm `->middleware('jwt')->prefix('api/customer')->group(...)` (và property, care...) vào `backend/routes/api.php`.

**Roles** — tạo `RoleCapabilitiesCustomer.php`, `RoleCapabilitiesProperty.php`; thêm 2 nhánh `$groups[...]` vào `app/Roles/register.php`.

**Enum tĩnh** — thêm vào `UtilsApi::index()` (data cache theo `utilitiesKey`): danh sách `property_types`, `legal_status`, `pipeline_stages`, `temperatures`, `demand_types`, `care_types`, `directions`, `furniture`.

**Schedule** (`app/Console/schedule.php`) — 3 tick mới, mỗi tick gọi `Notifier::send(...)`:
- `care-reminder-tick` (everyMinute, lọc theo giờ) — lịch chăm đến hạn → notify sales.
- `customer-cold-tick` (daily) — khách quá X ngày không tương tác → set `is_cold_flagged`, notify.
- `customer-release-tick` (daily) — `locked_until` quá hạn chưa chăm → gỡ khóa/trả kho chung, notify.
> Service xử lý đặt ở `app/Services/Care/` (mẫu cấu trúc = `Services/Notification/PushQueue`).

---

## 5. Frontend — file cần tạo/sửa

**RTK Query** (chuẩn cho CRUD nghiệp vụ — KHÔNG dùng api-layer-hàm):
- Sửa `reduxs/api/apiSlice.js`: `tagTypes: ['Notification','Customer','Property','CareSchedule','LeadSource','Project']`.
- Tạo `reduxs/api/customerApiSlice.js`, `propertyApiSlice.js`, `careApiSlice.js` (inject endpoints, `transformResponse: b=>b?.data`, `providesTags`/`invalidatesTags`).

**Features** (`features/<F>/{pages,components,style}`, mẫu = `Permission` + chuẩn CRUD ở `frontend-data-standards.md`):
- `Customer/` — trang list (filter + phân trang), `CustomerFormModal`, tab timeline + đặt lịch chăm, gom `can={add,edit,delete,transfer}`.
- `Property/` — list + `PropertyFormModal` + upload media (`ImageUploadField`/`FileUpload`).
- `Care/` — trang "Cần chăm hôm nay".
- `Dashboard/` — thay placeholder Home: widget cần chăm, khách theo pipeline, KPI.
- (Danh mục nhỏ) `LeadSource`, `CareTemplate`, `Project`, `PropertyOwner` — CRUD gọn, có thể gộp trong màn cấu hình.

**Bắt buộc dùng field `~/components/Forms`** (ESLint chặn antd Input/Select/DatePicker trong `features/`). `ModalForm` + react-hook-form + yup.

**Routing** (`routes/PrivateRoutes.js`) — thêm route (layout `DefaultLayout` vì đây là màn dùng hằng ngày của sales, không phải admin):
`/customers` cap `customer_view` · `/properties` cap `property_view` · `/care` cap `customer_view` · Dashboard = `/`.
Danh mục cấu hình (lead source, template, project, owner) → `/admin/*` layout `AdminLayout`.

**Menu** — thêm section "Kinh doanh" vào `layout/Sidebar/NavBarData.js` (Khách hàng, Bất động sản, Cần chăm), gate `useCan`. Danh mục cấu hình → `layout/AdminSidebar/AdminNavData.js`.

---

## 6. Thứ tự triển khai (đề xuất)

1. **Migration + Models + đăng ký cap** (nền DB & quyền) → chạy `GET api/utils/database`, cập nhật `docs/database.md`.
2. **CustomerApi + data-scope helper + phân trang helper** → FE `Customer` list + form (vertical slice đầu tiên, xác lập khuôn CRUD).
3. **Chống trùng SĐT + khóa khách + bàn giao** (nghiệp vụ khách).
4. **PropertyApi + media** → FE `Property`.
5. **Timeline + care_schedules + care_templates** → FE tab chăm sóc + trang "Cần chăm hôm nay".
6. **3 tick nền** (reminder/cold/release) + `Notifier::send`.
7. **Dashboard** thay placeholder + báo cáo cơ bản.
8. Danh mục phụ (lead source, project, owner, template).

Mỗi bước là 1 lát cắt dọc (BE→FE) chạy được và kiểm thử qua app trước khi sang bước sau.

---

## 7. Rủi ro / điểm cần lưu ý kỹ thuật

- **Chưa có `paginate()`** → phải tự chuẩn hóa 1 helper trả `{items, total, page, pageSize}` để mọi list dùng chung.
- **Data-scope không có Global Scope** → phải tự nhớ áp `scopeMine()` ở MỌI query list; gom vào 1 helper để tránh sót (rủi ro lộ dữ liệu chéo sales).
- **Enum ở BE** (migration dùng `enum(...)`): đổi enum sau này tốn ALTER — cân nhắc để `string` + validate ở app cho các trường hay thay đổi (property_type).
- **Địa giới**: dùng `LocationApi` sẵn có (tỉnh→phường, 2 cấp, int code). FE: cascade province→ward bằng `SelectField`/`DebounceSelect`, gọi `/api/location/provinces` + `/api/location/wards?province_id=`. Không cần seed thêm bảng.
- **`response()->error()` mặc định HTTP 200** (chỉ set code trong body). Dùng `->setStatusCode(422)->setApiStatus(422)->error(...)` cho lỗi nghiệp vụ để FE `.unwrap()` bắt đúng.
