# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

**HomeSido** — là một app hỗ trợ các đội ngũ kinh doanh bất động sản trong việc quản lý và chăm sóc khách hàng, giúp tăng hiệu quả kinh doanh và nâng cao trải nghiệm khách hàng. Monorepo: PHP backend API (`backend/`) + React SPA (`frontend/`), deploy tách rời, giao tiếp REST xác thực bằng JWT.

Chức năng cơ bản:

- **Đăng nhập** — username/email/phone + mật khẩu → JWT (access + refresh, có rotation).
- **Phân quyền** — gán capabilities cho từng chức vụ (role) ở màn Phân quyền; `administrator` (siêu quản trị của app) hoặc cap `permission` bypass. (`root` là tài khoản **master của framework** — đăng nhập qua license server, không phải role trong DB; app vẫn nhận diện phòng khi được nối.)
- **Đăng nhập vào tài khoản khác** (login-as / impersonation) — giữ phiên gốc, thao tác dưới danh nghĩa user khác.
- **Hồ sơ cá nhân** — user tự sửa họ tên/điện thoại/địa chỉ + đổi mật khẩu.
- **Thông báo** — in-app (chuông) + **Web Push tới PC & mobile qua service worker** (VAPID).

Dashboard trang chủ hiện là placeholder gọn. Bắt đầu dựng module mới từ skeleton này: thêm route + controller + model + migration ở backend, thêm feature + slice + menu ở frontend, và khai nhóm quyền mới (nếu cần) ở `app/Roles/register.php`.

> **Tiếng Việt:** comment code và message hướng tới người dùng đều bằng tiếng Việt — giữ đúng phong cách đó khi sửa.

## Repository layout

- `backend/` — PHP API trên **SkillDo** framework + CMS (`skilldo/framework`, `skilldo/cms` trong `vendor/`, lắp từ các component Laravel `illuminate/*`). Code app ở `app/`.
- `frontend/` — React 19 SPA (create-react-app qua `react-app-rewired`), Redux Toolkit + RTK Query + redux-saga.
- `docs/features/` — nơi ghi chú per-feature khi bạn quét source (xem `docs/features/README.md`).

## Backend

### Kiến trúc

- Điểm vào `backend/index.php` → `bootstrap/app.php`: boot `\SkillDo\Application`, đăng ký route files (`routes/api.php` là phần dự án dùng; `routes/web.php`/`routes/admin.php` là lớp CMS web/admin — phần lớn trơ ở deployment headless này).
- `bootstrap/app.php` gọi `Env::disablePutenv()` — **đừng bỏ dòng này**: WAMP mod_php đa luồng + putenv của dotenv gây race → `env()` thi thoảng trả rỗng → fatal ngẫu nhiên. Code mới cũng **đừng gọi `getenv()` trực tiếp**, dùng `env()`.
- `config/cms.php` **tắt** CMS admin UI/plugins/theme (`use => false`). Deployment headless: CMS chỉ cung cấp models/auth/helpers, không có admin frontend.
- Controllers kế thừa `SkillDo\Routing\Controller\Controller` (hoặc `SkillDo\Cms\Controller`). Chúng **không return response** — gọi helper toàn cục `response()` (send-and-exit): `response()->setStatusCode(422)->error(...)`, `->success('msg', $data)`.
- **`response()->error($message, $data)` tự ghi log khi truyền vào một `\Exception`** (`Response::error` — nếu `$message instanceof \Exception` thì gọi `Log::error()` kèm file + trace, rồi lấy `$e->getMessage()` làm message trả về; **chỉ tham số `$message` — tham số đầu — mới được check & log**, `$data` thì không). **BẮT BUỘC:** trong khối `catch (\Exception $e)` mà có Exception trong tay, **truyền `$e` vào `error()`** thay vì `error_log()` thủ công, để được auto-log. Vì message người dùng khi đó = `$e->getMessage()`, hãy `throw` với message tiếng Việt thân thiện sẵn; nếu Exception là lỗi hệ thống thô (message kỹ thuật) thì `throw` lại bằng message tiếng Việt bọc ngoài trước khi trả.
- Auth: JWT (`firebase/php-jwt`). Route public (`login`, `refresh`) phát token; route protected dùng middleware `jwt`. Token nằm ở `oauth_access_tokens`/`oauth_refresh_tokens`; `api_keys` là credential dài hạn thay thế (bảng có sẵn từ framework). `SkillDo\Support\Auth` là facade auth (`Auth::user()`, `Auth::getCap()`, `Auth::passwordConfirm()`).
- Middleware `jwt` được **re-alias** sang `App\Http\Middlewares\JwtLoginAs` (ở đầu `routes/api.php`) để hỗ trợ login-as: header `Authorization` LUÔN là tài khoản gốc; header `loginAsToken` (nếu có) là tài khoản mạo danh. Container bind `user` = tài khoản hiệu lực, `original_user` = tài khoản gốc. Token mạo danh hỏng/hết hạn → trả **409 `LOGIN_AS_EXPIRED`** (FE bắt mã này để tự thoát mạo danh, phiên gốc còn nguyên); thoát chủ động qua `POST api/auth/login-as/exit` (revoke token đích). Không cho mạo danh tài khoản có quyền `root` (kể cả root qua meta capabilities).
- Models (vd `SkillDo\Cms\Models\User`) từ package CMS, dùng **metadata pattern**: `User::updateMeta($id, $key, $value)` / `User::getMeta(...)` (bảng metabox) thay vì cột rộng.
- Helper toàn cục: `schema()`, `response()`, `hasItems($x)` (kiểm tra truthy/non-empty — dùng thay `empty()`), `Str::clear()` (sanitize input). Đọc `vendor/skilldo/framework` và `vendor/skilldo/cms/src` trước khi giả định hành vi Laravel chuẩn — API giống Laravel nhưng là custom.

### Phân quyền (Roles / capabilities)

- Engine role của CMS. Màn Phân quyền (`api/role/*` + FE `features/Permission`) gán capabilities cho từng chức vụ.
- Nhóm quyền gốc khai ở `app/Roles/RoleGroup::groups()` (hiện chỉ có nhóm `roles` → cap `permission`). Nhóm bổ sung khai qua filter `role_capabilities_groups` trong `app/Roles/register.php` (được require ở đầu `routes/api.php`). HomeSido thêm nhóm `auth` → cap `login_as`.
- **Siêu quản trị = cap `administrator`** (seed sẵn cho tài khoản `admin` id 1) → bypass mọi cap ở cả BE (`Auth::hasCap('administrator')`) và FE (`useCan`/`useIsAdmin`/`RequireCap` check `permissions.administrator`). Chức vụ hệ thống (`administrator`, `subscriber`) bị ẩn khỏi màn Phân quyền và không cho login-as.
- **Thêm quyền cho module mới:** tạo `app/Roles/RoleCapabilitiesXxx.php` (method tĩnh `all()` trả `[capKey => 'Nhãn']`), rồi thêm 1 nhánh `$groups['xxx'] = [...]` trong `register.php`. Route mới gate bằng cap tương ứng; `administrator` bypass mọi cap.

### Database workflow (project-specific — QUAN TRỌNG)

> 📗 **Kiến trúc database chi tiết ở [`docs/database.md`](docs/database.md)** — đây là nguồn sự
> thật cho schema (danh sách bảng/cột). **Cần hiểu database thì đọc file đó trước.** **Mỗi khi
> thêm/sửa/xoá bảng hoặc cột, BẮT BUỘC cập nhật `docs/database.md`** trong cùng lần sửa.

Dự án **không** dùng `php artisan migrate`. Schema thay đổi qua một API endpoint:

1. `backend/database/database.php` là **master migration** — lớp `Migration` ẩn danh, `up()` guard mọi `Schema()->create(...)` bằng `if(!schema()->hasTable(...))`. Đây là nguồn sự thật cho schema lõi (`users`, `users_metadata`, `metabox`, `oauth_access_tokens`, `oauth_refresh_tokens`, `api_keys`).
2. Migration tăng dần khác đặt trong `backend/database/`.
3. Để chạy được, **thêm file vào mảng `$migrations` trong `App\Controllers\Api\UtilsApi::database()`** (`backend/app/Controllers/Api/UtilsApi.php`) — `database/database.php` là phần tử đầu tiên sẵn rồi. Gọi `GET api/utils/database` để áp dụng. Guard `hasTable`/`hasColumn` khiến gọi lại nhiều lần vẫn an toàn (idempotent).
4. `UtilsApi::run()` (`GET api/utils/run`) là **scratch endpoint** — để trống trong base; nhét code test/throwaway vào đây để chạy qua API khi cần.

> `GET api/utils` (`UtilsApi::index`, cần jwt) khác hẳn nhóm trên: trả **dữ liệu tĩnh dùng-nhiều-đổi-hiếm** (danh sách trạng thái, chức vụ, enum...) để FE cache lại và chỉ nạp lại khi đổi — đồng bộ bằng `utilitiesKey` (hash md5 của data; `AuthController::current` trả key hiện tại, FE so với localStorage). Chi tiết ở [`docs/features/frontend-data-standards.md`](docs/features/frontend-data-standards.md).
5. **Bật/tắt + bootstrap:** `database` (khởi tạo/cập nhật DB) và `run` **KHÔNG kiểm tra auth** — bật/tắt bằng biến `UTILS_API_OPEN` trong `.env` (`true` = mở, chỉ dùng demo/dev; **production để `false`** → trả 403). Lần chạy `database` đầu tiên khi bảng `users` trống tự **seed tài khoản `admin` (id 1, role `administrator`)** với mật khẩu ngẫu nhiên trả về MỘT LẦN trong response (lưu lại, đổi ngay). Khởi tạo DB lần đầu trên prod: bật tạm `true` → gọi 1 lần → tắt lại.

Lưu ý khi viết migration:
- Bảng tạo **không** kèm prefix trong code (`Schema()->create('users', ...)`); DB tự áp prefix cấu hình (`DB_PREFIX` trong `backend/.env`). Raw SQL tự ghép prefix qua `DB::getTablePrefix()` — **không hardcode prefix**.
- DB connection/prefix/charset cấu hình ở `backend/.env` (MySQL, `utf8mb4`). Mẫu cấu hình cho dự án mới: `backend/.env.example` — **nhân bản dự án phải sinh lại toàn bộ secret** (JWT, VAPID, SCHEDULE_RUN_TOKEN...).

### Tác vụ nền (Schedule)

- `app/Console/schedule.php` (require ở `routes/api.php`) đăng ký task vào Laravel Schedule. Cron gọi route `schedule-run` (token-guarded, ngoài `api/`) mỗi phút → mỗi task xử lý MỘT lô rồi thoát (không worker thường trú, không Redis/CLI).
- Các tick hiện có: **`push-tick`** (`Services\Notification\PushQueue`) — gửi hàng đợi Web Push; **`care-reminder-tick`** (`Services\Care\CareReminder`, mỗi phút) — nhắc lịch chăm đến hạn; **`customer-cold-tick`** (`Services\Care\ColdDetector`, 07:00) — gắn cờ khách nguội. Module mới tự thêm tick vào file này. Chi tiết care: [`docs/features/care.md`](docs/features/care.md).
- Cron mẫu: `* * * * * curl -s -H "X-Schedule-Token: SCHEDULE_RUN_TOKEN" "https://your-domain/schedule-run" >/dev/null 2>&1` (ưu tiên header — token trên query lọt vào access log). Đã cấu hình `SCHEDULE_RUN_TOKEN` thì token là BẮT BUỘC (không bypass theo IP); chưa cấu hình (máy dev) mới chấp nhận localhost.

### Thông báo & Web Push

- `Services\Notification\Notifier::send($userId, $type, $title, $message, $link)` — ghi thông báo in-app (fire-and-forget, tự nuốt lỗi, tự tỉa 100/user) + enqueue Web Push. Đây là "đầu ra" chuẩn cho mọi tiến trình nền: gọi `Notifier::send(...)` khi có sự kiện cần báo user.
- Web Push tự triển khai VAPID + aes128gcm (`WebPushClient`) — enqueue vào `push_queue` (1 job/thiết bị) → tick `push-tick` gửi lần lượt (retry, 404/410 xoá subscription). Cần `.env`: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`; HTTPS bắt buộc; iOS phải cài PWA. Service worker = `frontend/public/serviceWorker.js`.

### Commands

```bash
cd backend
composer install          # cài PHP dependencies
```
Không có build/test runner cho backend; chạy trực tiếp dưới PHP server (WAMP/tương tự), `.env` trỏ MySQL.

## Frontend

### Kiến trúc

- create-react-app tuỳ biến qua `react-app-rewired` + `config-overrides.js` (chỉ bật `.babelrc`). Alias `~` → `src/`, khai ở **cả** `.babelrc` (module-resolver, cho build) và `jsconfig.json` (cho editor). Giữ 2 nơi đồng bộ.
- State: một Redux store (`src/app/store.js`) gộp reducers (`src/app/reducer.js`) + chạy sagas (`src/app/saga.js`). HomeSido chỉ có slice `Auth` (`src/reduxs/Auth/`).
- **RTK Query**: base API service `src/reduxs/api/apiSlice.js` (bọc axios instance `utils/http.js` để dùng chung interceptor: gắn Bearer token + tự refresh khi 401). Mỗi feature tự inject endpoint qua `apiSlice.injectEndpoints(...)` — xem `reduxs/api/notificationApiSlice.js` làm mẫu. `tagTypes` khai tập trung ở `apiSlice.js`; module mới thêm tag của mình vào đó.
- API layer cũ kiểu hàm: `src/api/*Api.js` (authApi, roleApi, utilsApi) đi qua `utils/http.js`; interceptor tự refresh 401 qua `memoizedRefreshToken` (`utils/refreshToken.js`).
- Routing (`src/routes/`): `publicRoutes` (chỉ `/login`) vs `privateRoutes`. `PrivateRoutes` gate `localStorage('access_token')` → không có thì về `/login`. `App.js` bọc mỗi route trong layout (`DefaultLayout` mặc định; `route.layout` để đổi — `AdminLayout` cho `/admin/*`, `null` = Fragment) và bọc `<RequireCap>` khi route có `cap`. `GlobalHistory` expose `globalNavigate` cho điều hướng ngoài component.
- Layout: `AppShell` dùng chung (sidebar collapse persist localStorage, Drawer mobile, topbar) → `DefaultLayout` (chế độ user, `Sidebar`/`NavBarData`) và `AdminLayout` (chế độ quản trị, `AdminSidebar`/`AdminNavData`). Banner login-as (`layout/LoginAs`) + chuông thông báo (`layout/NotificationBell`) chèn sẵn.
- UI tổ chức theo `features/<Feature>/{pages,components,style}`. Building block dùng chung ở `src/components/`, `src/layout/`, `src/hooks/`.
- Auth bootstrap: `useCurrentUser()` chạy trong `App.js` để hydrate user hiện tại lúc load; permissions nạp vào `context/AppProvider` (dùng qua `useCan`, `useIsAdmin`).

### Thêm module mới (frontend)

1. Tạo `features/<Feature>/pages|components`.
2. Tạo `reduxs/api/<feature>ApiSlice.js` (inject vào `apiSlice`), thêm `tagTypes` cần dùng vào `apiSlice.js`.
3. Khai route trong `routes/PrivateRoutes.js` (kèm `cap` nếu cần gate quyền).
4. Thêm mục menu vào `layout/Sidebar/NavBarData.js` (menu user) hoặc `layout/AdminSidebar/AdminNavData.js` (menu quản trị), gate hiển thị bằng `useCan('<cap>')`.

### Commands

```bash
cd frontend
npm install
npm start          # dev server (react-app-rewired)
npm run build      # production build
npm test           # CRA/jest test runner
```

### Environment

`REACT_APP_SERVICE_URL` — base API (vd `.env.development.local` → `http://.../api`; `.env.local` → `/api`). `REACT_APP_HOMEPAGE` — router base path, prepend vào redirect.

## Tài liệu tham chiếu (đọc trước khi làm)

- ✅ [`docs/todo.md`](docs/todo.md) — **việc cần làm tiếp** (hàng đợi để tiếp tục ở session khác; xong việc nào xóa mục đó).
- 📗 [`docs/database.md`](docs/database.md) — **kiến trúc database** (nguồn sự thật schema). Đọc trước khi động vào DB; **cập nhật khi đổi schema**.
- 📘 [`docs/features/frontend-data-standards.md`](docs/features/frontend-data-standards.md) — **chuẩn data-fetching/state & UI frontend** (RTK Query vs Redux/Context/local, field dùng chung, convention feature). Tuân thủ khi viết code frontend.

## Feature notes (scan once, document)

Khi quét source để hiểu một chức năng, **ghi lại vào `docs/features/<feature>.md`** thay vì quét lại lần sau (trace end-to-end + gotcha), rồi liên kết từ index dưới. Xem `docs/features/README.md`.

> **BẮT BUỘC — đồng bộ feature notes khi code:**
> - **Tạo chức năng/module mới** → **BẮT BUỘC tạo mới** `docs/features/<feature>.md` (trace end-to-end + gotcha) trong cùng lần sửa, và thêm dòng vào index dưới.
> - **Chỉnh sửa chức năng cũ** → **BẮT BUỘC cập nhật** `docs/features/<feature>.md` cho khớp thay đổi; **nếu file chưa có thì tạo mới**.
> - Đừng coi việc này là tuỳ chọn: thay đổi code mà không cập nhật feature note = chưa hoàn thành task.
>
> **Cấu trúc note & cách dựng** (chi tiết + ví dụ ở `docs/features/README.md`):
> - **BẮT BUỘC có "bản đồ file"**: cây file front-to-back kèm **đường dẫn + 1 dòng vai trò** (FE feature/api/slice/saga → BE route → controller → model → bảng DB) + gotcha. **Sơ đồ flow (mermaid) chỉ là tuỳ chọn**, dùng khi luồng phức tạp.
> - **Ưu tiên giao subagent** (`Explore`/`general-purpose`) để quét source & dựng note — đỡ tốn token context chính (agent giữ file dump, chỉ trả về nội dung doc) và note chất lượng hơn.

### Feature notes index
- [Khách hàng (Customer — Core CRM)](docs/features/customer.md) — CRUD + filter/phân trang + chống trùng SĐT + data-scope + xóa mềm; vertical slice xác lập khuôn CRUD (ApiController base, RTK Query, caps).
- [Bất động sản (Property — Kho hàng)](docs/features/property.md) — CRUD + data-scope kho chung (shared) + địa chỉ tỉnh→phường (LocationApi) + xóa mềm; media chờ pipeline upload.
- [Chăm sóc chủ động (Care + Timeline)](docs/features/care.md) — lịch chăm sóc + "Cần chăm hôm nay" + timeline tương tác (drawer chi tiết khách); hoàn thành care → tạo tương tác + cập nhật last_interaction_at. Tick nền (Bước 6) & template (Bước 8) chưa làm.
- [Dashboard tổng hợp (Home)](docs/features/dashboard.md) — `GET api/dashboard` gộp KPI tháng + "cần chăm hôm nay" + phễu khách theo giai đoạn + kho BĐS theo trạng thái; áp data-scope; CSS bar (không thư viện chart).
- [Danh mục phụ + Cấu hình (Catalog)](docs/features/catalog.md) — CRUD 4 danh mục (nguồn khách/dự án/chủ nhà/kịch bản) gom ở `/admin/catalog` (cap `permission`); nối select vào form Khách hàng/BĐS/Chăm sóc; CatalogManager generic (form động theo `fields`).
- [Media BĐS + Dung lượng](docs/features/media.md) — upload ảnh/video cho BĐS (`property_media`, lưu `storage/uploads` phục vụ qua `/uploads`); kế toán **dung lượng theo từng nhân viên** (user meta `storage_used_bytes`) cho gói theo dung lượng; xóa mềm giữ media, xóa hẳn (`?force=1`) purge file + hoàn dung lượng.
- [Matching khách ↔ BĐS (GĐ2)](docs/features/matching.md) — so khớp on-the-fly nhu cầu↔kho (`MatchEngine`, hàm thuần): gợi ý BĐS cho khách + gợi ý khách cho BĐS + "gửi SP cho khách" (log `property_customer_matches` + ghi timeline); trang `/matching` (2 tab) + tích hợp drawer khách/panel BĐS; nhóm cap `matching`.
- [Lịch hẹn dẫn khách (Appointment — GĐ2)](docs/features/appointment.md) — đặt lịch dẫn khách xem BĐS (`appointments`, vòng đời status không xoá mềm) + tick `appointment-reminder-tick` nhắc trước giờ + chốt kết quả (`done`→tương tác "viewing" vào timeline + touch + rescore / `no_show`); trang `/appointments` (list+lọc+form) + nhóm cap `appointment`.
