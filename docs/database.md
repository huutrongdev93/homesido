# Kiến trúc Database

> **Nguồn sự thật (single source of truth) cho schema DB.** Agent/lập trình viên cần hiểu
> database **đọc file này trước**. **Mọi thay đổi schema (thêm/sửa/xoá bảng hoặc cột) BẮT BUỘC
> cập nhật lại file này** trong cùng lần sửa — nếu không, tài liệu và code sẽ lệch nhau.

## 1. Workflow migration (KHÔNG dùng `php artisan migrate`)

Schema được áp qua một **API endpoint**, không qua CLI Artisan:

1. Mỗi migration là 1 file trong `backend/database/` trả về **lớp `Migration` ẩn danh** với `up()`
   guard bằng `schema()->hasTable(...)` / `schema()->hasColumn(...)` ⇒ **idempotent** (gọi lại
   nhiều lần vẫn an toàn).
2. Đăng ký file vào mảng `$migrations` trong `App\Controllers\Api\UtilsApi::database()`
   (`backend/app/Controllers/Api/UtilsApi.php`).
3. Gọi **`GET api/utils/database`** để chạy tất cả migration đã đăng ký (theo thứ tự trong mảng)
   → cuối cùng `Cache::flush()` để model nạp lại schema mới.

> `database/database.php` (master, schema lõi: users, oauth, api_keys, notifications, push...)
> là phần tử ĐẦU TIÊN của mảng `$migrations` — fresh clone chỉ cần gọi `GET api/utils/database`
> 1 lần là có đủ schema. Xem mục 3.

**Bật/tắt & bootstrap:** `api/utils/database` và `api/utils/run` **KHÔNG kiểm tra auth** — bật/tắt
bằng biến `UTILS_API_OPEN` trong `backend/.env` (`true` = mở cho demo/dev, **production để `false`**
→ 403). Lần chạy `database` đầu tiên khi bảng `users` trống sẽ **seed tài khoản `admin` (id 1,
role `administrator`, meta `capabilities => {administrator:1}`)** với mật khẩu ngẫu nhiên trả về
MỘT LẦN trong response — lưu lại và đổi ngay. Khởi tạo trên prod: bật tạm `true` → gọi 1 lần → tắt.

> `root` KHÔNG phải role/user trong DB — nó là tài khoản **master của framework** (đăng nhập
> qua license server SkillDo). Siêu quản trị của app là role **`administrator`**.

## 2. Quy ước chung

- **Prefix bảng**: cấu hình `DB_PREFIX` trong `backend/.env`. Trong code
  `Schema()->create('users', ...)` viết tên **không prefix** — DB tự áp. **Raw SQL** phải tự ghép
  prefix qua `DB::getTablePrefix()` (vd `'ALTER TABLE ' . DB::getTablePrefix() . 'users ...'`) —
  KHÔNG hardcode prefix, base nhân bản sang dự án khác sẽ đổi `DB_PREFIX`.
- **Kết nối**: MySQL, charset `utf8mb4` (`DB_CHARSET`), collation cột tiếng Việt `utf8mb4_unicode_ci`.
- **Timestamps house-style**: cột `created` (`DEFAULT CURRENT_TIMESTAMP`) và `updated`
  (`ON UPDATE CURRENT_TIMESTAMP`) — **không** dùng `created_at/updated_at` của Laravel.
- **Metadata pattern**: dữ liệu phụ của user KHÔNG thêm cột vào `users` — lưu key/value ở
  `users_metadata` qua `User::updateMeta($id, $key, $value)` / `User::getMeta($id, $key)`.
  `metabox` là bảng meta tổng quát cho các object khác (`object_type` + `object_id`).
- **Khoá/định danh dài**: chuỗi dài không unique trực tiếp được (vd endpoint push) → lưu thêm cột
  hash (`md5`) và unique trên hash (xem `push_subscriptions.endpoint_hash`).
- ⚠️ **Base Model tự điền MỌI cột khi `create()`** (`DatabaseColumnCleaner::beforeAdd` duyệt toàn bộ
  cột của bảng, không chỉ field truyền vào). Cột không truyền / null bị ép về **`''`** (string) hoặc
  **`0`** (int/float) theo kiểu cột — KHÔNG chèn `NULL`. Hệ quả bắt buộc khi thiết kế bảng cho model
  app-level (model không khai `$columns`):
  - **KHÔNG dùng `enum` cho cột tùy chọn** (không luôn có giá trị): `''` không hợp lệ với `enum` → lỗi
    `Data truncated`. Dùng **`string` + `default('')`** (‘’ = chưa chọn) và validate giá trị hợp lệ ở
    controller. Chỉ dùng `enum` khi cột LUÔN được gán giá trị hợp lệ (có `->default(...)` + app luôn set).
  - **`decimal`/số tùy chọn**: đặt `->default(0)` (0 = chưa nhập) — nếu để nullable-không-default, model
    chèn `''` → `decimal` báo lỗi truncation.
  - Nullable an toàn cho: `varchar`/`text` (nhận `''`) và `datetime` (cleaner giữ `NULL`).
  - ⚠️ **`Builder::update()` KHÔNG chạy cleaner** (chỉ `create()` chạy) và **`Str::clear(null)` trả `null`**
    (không phải `''`). ⇒ khi build mảng update, cột **NOT NULL** (vd string `default('')`) mà nhận `null`
    → lỗi `Column cannot be null`. **Luôn ép `Str::clear((string) $request->input('x'))`** cho field string.
    (Lỗi runtime này bị middleware `JwtLoginAs` che thành 401 "Invalid token" — đọc `D:/wamp/logs/php_error.log`.)

## 3. Schema lõi — `database/database.php` (master)

Nguồn của khung xác thực & user. `up()` guard từng bảng bằng `hasTable`.

### `system`
Key/value cấu hình hệ thống của CMS engine (`option_name` / `option_value`). Base seed tối giản:
`language_default` (vi), `user_roles` (2 role gốc `administrator` + `subscriber` — role nghiệp vụ
tạo động ở màn Phân quyền, lưu JSON trong chính option này), `api_user`, `api_secret_key`.

### `users`
Tài khoản. `id=1` là **quản trị viên** — seed tự động ở lần chạy `api/utils/database` đầu tiên
khi bảng trống (username `admin`, role `administrator`, mật khẩu ngẫu nhiên trả về 1 lần; meta
`capabilities => {administrator:1}`). AUTO_INCREMENT bắt đầu từ 2 cho user thường.

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `id` | bigint PK | |
| `username` | varchar(255) | đăng nhập bằng username/email/phone |
| `password` | varchar(255) | băm |
| `salt` | varchar(255) | |
| `firstname` / `lastname` | varchar | tên hiển thị (utf8mb4) |
| `email` | varchar(100) | index |
| `phone` | varchar(100) | index |
| `status` | varchar(50) | rỗng/`publish` = hoạt động; `block`/`suspended`/`pending`/`trash` = vô hiệu (xem `AuthController::INACTIVE_STATUSES`) |
| `role` | varchar(255) | slug chức vụ (mặc định `subscriber`; đăng nhập/phân quyền dùng role động) |
| `trash` | tinyint | soft-delete flag |
| `password_changed_at` | bigint | |
| `remember_token` / `activation_key` | varchar(255) | |
| `time` | int | |
| `address` | varchar(255) | |
| `city` / `district` / `ward` | int | mã địa giới hành chính (0 = trống) |
| `created` / `updated` | datetime | |

### `users_metadata`
Meta key/value của user (metadata pattern).

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `id` | bigint PK | |
| `object_id` | int | = `users.id` |
| `meta_key` | varchar(255) | vd `capabilities`, `last_login`, quyền riêng của user |
| `meta_value` | text | |
| `order` | int | |
| `created` / `updated` | datetime | index `(object_id, meta_key)` |

### `metabox`
Meta key/value tổng quát cho object bất kỳ (không chỉ user).

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `id` | bigint PK | |
| `object_id` | int | |
| `object_type` | varchar(100) | phân biệt loại object |
| `meta_key` | varchar(100) | |
| `meta_value` | text | |
| `order` | int | |
| `created` / `updated` | datetime | index `(object_id, object_type, meta_key)` |

### `oauth_access_tokens`
Access token JWT.

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `id` | char(36) PK | |
| `token` | varchar(255) unique | |
| `user_id` | bigint | index |
| `name` / `platform` / `browser` / `device` | varchar | nhận diện phiên |
| `revoked` | bool | |
| `expires_at` | datetime | |
| `created` / `updated` | datetime | |

### `oauth_refresh_tokens`
Refresh token (rotation: revoke cũ + tạo mới mỗi lần refresh).

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `id` | char(36) PK | |
| `token` | varchar(255) unique | |
| `access_token_id` | varchar(255) | index — liên kết access token |
| `user_id` | bigint | index |
| `revoked` | bool | |
| `expires_at` | datetime | |

### `api_keys`
Credential dài hạn thay thế JWT (từ framework; base chưa expose UI quản lý).

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `id` | int PK | |
| `user_id` | bigint | index |
| `name` | varchar | |
| `key_hash` | varchar(255) unique | băm của key |
| `key_hint` | varchar(10) | 10 ký tự cuối để hiển thị |
| `platform` / `browser` / `device` | varchar | |
| `status` | enum(`active`,`revoked`,`expired`) | |
| `expires_at` | timestamp | |
| `created` / `updated` | datetime | |

### `notifications`
"Đầu ra" của mọi tiến trình nền — ghi qua `Services\Notification\Notifier::send()`; FE poll
`GET api/notifications` hiển thị chuông. Model: `App\Models\Notification`.

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint | index — chủ thông báo |
| `type` | varchar(40) | chuỗi tự do (`info`/`success`/`warning`/`error`/...); FE map → icon/màu |
| `title` | varchar(255) | |
| `message` | text | nullable |
| `link` | varchar(255) | đường dẫn FE mở khi bấm (rỗng = không điều hướng) |
| `is_read` | tinyint | 0/1 |
| `created` | datetime | |

Index: `(user_id, is_read)`, `(user_id, created)`. Notifier tự tỉa còn tối đa 100 dòng/user.

### `push_subscriptions`
Mỗi dòng = 1 thiết bị/trình duyệt user đã bật thông báo đẩy. Model: `App\Models\PushSubscription`.

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint | index |
| `endpoint` | text | endpoint push service của trình duyệt |
| `endpoint_hash` | char(32) unique | `md5(endpoint)` để upsert/dedup |
| `p256dh` | varchar(255) | khoá mã hoá payload (RFC 8291) |
| `auth` | varchar(100) | khoá xác thực |
| `user_agent` | varchar(255) | nhận diện thiết bị (chỉ hiển thị) |
| `created` | datetime | |

### `push_queue`
Hàng đợi gửi push. Notifier ghi in-app xong → enqueue 1 dòng/subscription của người nhận; tick
`push-tick` (`PushQueue`) gửi lần lượt. Model: `App\Models\PushJob`.

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `id` | bigint PK | |
| `subscription_id` | bigint | index |
| `user_id` | bigint | index |
| `type` / `title` / `message`(500) / `link` | varchar | payload JSON gửi đi (giữ nhỏ, push ~4KB) |
| `status` | varchar(20) | `pending` → `sending` → `sent`/`failed` |
| `attempts` | tinyint | retry tới MAX_ATTEMPTS |
| `last_error` | varchar(255) | |
| `claimed_at` | datetime | dòng `sending` kẹt lâu được reset |
| `sent_at` | datetime | |
| `created` | datetime | index `(status, id)` |

## 4. Bảng nghiệp vụ — Giai đoạn 1 (Core CRM)

Nguồn: `database/crm.php` (đăng ký thứ 2 trong `$migrations`). **Không multi-tenant** (1 deployment = 1 sàn, không có `tenant_id`). Timestamps house-style (`created`/`updated`). Soft delete (`trash`) cho `customers` + `properties`. Người tạo (`user_created`) Model tự điền. Địa giới 2 cấp tỉnh→phường (int, qua `LocationApi`) — không có cấp quận/huyện. `property_type` là `string` (danh sách loại hình khai ở `api/utils`).

### `lead_sources` — Nguồn khách
`id` · `name` (utf8mb4) · `is_active` tinyint(1) · `created` · `updated`.

### `customers` — Khách hàng (core)
`id` · `assigned_user_id` (sales phụ trách, index) · `lead_source_id` · `full_name` · `phone`(20, index — chống trùng) · `phone_alt` · `email` · `gender` string(male/female/other, ''=chưa chọn) · `birth_year` int(0=trống) · `address` · `occupation` · `pipeline_stage` enum(new,contacting,potential,negotiating,won,lost) · `temperature` enum(hot,warm,cold) · `lead_score` int · `locked_until` (hạn khóa khách) · `last_interaction_at` (cảnh báo nguội) · `is_cold_flagged` tinyint · `note` · `trash` · `user_created` · `created` · `updated`.
Index: `(phone)`, `(assigned_user_id, pipeline_stage)`.

### `customer_demands` — Nhu cầu / tiêu chí (1 khách N nhu cầu)
`id` · `customer_id` (index) · `demand_type` enum(buy,rent,sell,consign) · `property_type` string · `purpose` string(live/invest, ''=chưa chọn) · `province_code`/`ward_code` int · `budget_min`/`budget_max` decimal(15,2) default 0 · `area_min`/`area_max` decimal(10,2) default 0 · `bedrooms_min` tinyint(0=trống) · `direction` · `is_active` · `created` · `updated`.

### `projects` — Dự án
`id` · `name` · `developer` (chủ đầu tư) · `province_code`/`ward_code` int · `address` · `description` · `created` · `updated`.

### `property_owners` — Chủ nhà (hàng ký gửi)
`id` · `full_name` · `phone`(20, index) · `email` · `note` · `created` · `updated`.

### `properties` — Bất động sản (kho hàng)
`id` · `project_id` · `owner_id` · `code`(50, index) · `title` · `property_type` string · `transaction_type` enum(sale,rent) · `price` decimal(15,2) default 0 · `price_per_m2`/`area_land`/`area_usable`/`latitude`/`longitude` decimal default 0 · `bedrooms`/`bathrooms`/`floors` tinyint(0=trống) · `direction` · `legal_status` string(red_book/pink_book/sale_contract/waiting/other, ''=chưa chọn) · `furniture` string(none/basic/full, ''=chưa chọn) · `province_code`/`ward_code` int · `address` · `description` longtext · `visibility` enum(private,shared) · `status` enum(available,deposited,sold,rented,inactive) · `assigned_user_id` · `trash` · `user_created` · `created` · `updated`.
Index: `(code)`, `(assigned_user_id)`, `(status)`, `(property_type, transaction_type)`, `(province_code, ward_code)`, `(price)`.

### `property_media` — Ảnh / video / tài liệu
`id` · `property_id` (index) · `type` enum(image,video,document) · `path` · `sort_order` int · `created`.

### `customer_interactions` — Timeline tương tác
`id` · `customer_id` (index) · `user_id` (người thực hiện) · `type` enum(call,sms,zalo,email,meeting,note,viewing) · `content` · `direction` string(in/out, ''=không rõ) · `interacted_at` · `created`.

### `care_schedules` — Lịch chăm sóc / nhắc việc
`id` · `customer_id` (index) · `assigned_user_id` · `care_template_id` · `type` enum(call,sms,zalo,email,meeting) · `scheduled_at` · `content` · `status` enum(pending,done,missed,canceled) · `completed_at` · `result_note` · `created` · `updated`.
Index: `(scheduled_at, status)`, `(assigned_user_id, status)`.

### `care_templates` — Kịch bản chăm sóc
`id` · `name` · `channel` enum(call,sms,zalo,email) · `content` (biến `{{ten_khach}}`) · `stage` · `is_active` · `created` · `updated`.

### `customer_transfers` — Lịch sử bàn giao khách
`id` · `customer_id` (index) · `from_user_id` · `to_user_id` · `transferred_by` · `reason` · `created`.

## 5. Thêm bảng/cột mới (checklist)

1. Tạo `backend/database/<ten>.php` — trả `Migration` ẩn danh, `up()` guard `hasTable`/`hasColumn`,
   tên bảng **không prefix**, dùng timestamps house-style (`created`/`updated`).
2. Đăng ký file vào mảng `$migrations` của `UtilsApi::database()`.
3. (Tuỳ chọn) tạo Model ở `backend/app/Models/` với `protected string $table = '<ten>';`.
4. Gọi `GET api/utils/database` để áp dụng.
5. **Cập nhật file `docs/database.md` này** — thêm mục mô tả bảng/cột mới.
