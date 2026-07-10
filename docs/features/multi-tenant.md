# Multi-tenant theo PREFIX (Giai đoạn 4 — Bước 0: nền tảng / PoC)

Cho phép MỘT lần deploy phục vụ NHIỀU sàn (tenant), mỗi sàn có **bộ bảng riêng** trong **cùng một
DB**, phân biệt bằng **`DB_PREFIX` động** (vd `sana_`, `sanb_`). Tenant resolve theo **path key**:
`domain.com/{key}/...`. **KHÔNG thêm cột `tenant_id`** vào bảng nào — schema per-tenant giữ nguyên
100%. Cô lập dữ liệu là do **prefix bảng khác nhau + thư mục cache khác nhau**, không phải do lọc
`WHERE tenant_id` (nên không có rủi ro sót scope làm lộ dữ liệu chéo).

> Đây là **Bước 0** (nền tảng, opt-in, để kiểm chứng). Chưa làm: schedule-run lặp tenant, uploads
> tách thư mục theo tenant, provisioning có auth, gói/thanh toán, Staff, portal đăng ký (Bước 1–4
> trong `docs/todo.md`). Web Push per-tenant & service worker scope cũng để Bước 1.

## Ý tưởng cốt lõi (vì sao chỉ cần đổi PREFIX + thư mục CACHE)

Framework KHÔNG có Global Scope, nhưng **mọi query đều đi qua connection prefix**. Đổi prefix ⇒
mọi bảng trỏ sang bộ bảng của tenant. Hai "đòn bẩy" đặt Ở INDEX.PHP trước khi app boot:

1. **`DB_PREFIX` động** → cô lập DATA (mỗi sàn 1 bộ bảng).
2. **`path.cache` động** (thư mục cache riêng theo tenant) → cô lập CACHE FILE. Driver `file`
   **không có prefix key**, mọi key (`table_columns_*`, `access_token_*`, `system`/roles…) là tên
   file trần trong 1 thư mục ⇒ nếu KHÔNG tách thư mục, sàn A đọc nhầm cache sàn B (rò cột bảng,
   rò token, rò options). Rebind `path.cache` sang `storage/framework/cache/{prefix}` giải quyết
   TẤT CẢ key một lần.

### Cô lập token & cache (không dùng JWT claim)

JWT ký bằng secret **dùng chung** toàn cụm (RS/HS, `JWT_PRIVATE_KEY`), nên token sàn A **hợp lệ
chữ ký** ở sàn B. Ta **KHÔNG** thêm claim `tenant` (mint token nằm trong vendor `skilldo/framework`
`@dev` — sửa sẽ bị `composer install` ghi đè). Thay vào đó cô lập bằng **kiến trúc**:

- `TokenRepository::decode()` → `find()` truy vấn bảng **`{prefix}oauth_access_tokens`** của tenant
  đang resolve, và cache dò token nằm trong **thư mục cache riêng** của tenant.
- ⇒ Token sàn A đưa sang sàn B: cache-miss + không có dòng trong `sanb_oauth_access_tokens` →
  `find()` trả null → `decode()` ném lỗi → middleware `JwtLoginAs` trả **401**. Cô lập đạt yêu cầu.

> ⚠️ TUYỆT ĐỐI không thêm cache token/roles/options DÙNG CHUNG giữa các tenant — sẽ phá cô lập này.

## Bản đồ file (front-to-back)

### Backend

| File | Vai trò |
|---|---|
| `backend/index.php` | **Điểm resolve** (trước boot & trước khi dựng Request): gọi `TenantResolver::resolve($_SERVER)` → tenant hợp lệ thì set `$_ENV['DB_PREFIX']`, rebind `path.cache`, `TenantContext::set`, cắt `/{slug}` khỏi `REQUEST_URI`; slug lạ → **404 JSON**; passthrough → chạy như bản 1-sàn. |
| `app/Services/Tenant/TenantResolver.php` | Thuần, KHÔNG phụ thuộc framework (chạy trước boot). Parse segment đầu, danh sách `RESERVED`, regex slug, `loadMap()` (cache file) / `rebuildMapFromDb()` (PDO thô → `core_tenants`, ghi cache), `stripSegment()`. `CENTRAL_PREFIX='core_'`. |
| `app/Services/Tenant/TenantContext.php` | State tĩnh tenant hiện tại của request: `slug()/prefix()/active()/pathPrefix()`. Dùng để build link kèm `/{key}` (Bước 1), PlanGate (Bước 2). |
| `app/Services/Tenant/TenantProvisioner.php` | Cấp phát: tạo `core_tenants` (prefix `core_`), insert dòng tenant, chạy `database/migrations.php` dưới prefix tenant, seed admin, ghi cache map. `withPrefix()` set **cả** connection prefix **lẫn** `$_ENV['DB_PREFIX']` (Model SkillDo đọc prefix từ env, query builder đọc từ connection) + flush cache 2 đầu. |
| `app/Controllers/Api/CentralApi.php` | API trung tâm ở ROOT (`api/central/*`, ngoài `/{key}`, KHÔNG jwt, gate `UTILS_API_OPEN`): `tenants` (list), `provision` (tạo sàn), `rebuild-cache`. |
| `database/tenant.php` | Migration bảng `tenants` (chạy ở prefix `core_`). Xem `docs/database.md §3b`. |
| `database/migrations.php` | **Danh sách migration per-tenant** dùng chung `UtilsApi::database()` + `TenantProvisioner`. Module mới thêm 1 dòng Ở ĐÂY. |
| `app/Controllers/Api/UtilsApi.php` | `database()` nay `require database/migrations.php`; gọi `GET /{key}/api/utils/database` chạy dưới prefix tenant. |
| `app/Http/Middlewares/JwtLoginAs.php` | (Docblock) Ghi rõ cơ chế cô lập token per-tenant — không thêm code. |
| `routes/api.php` | Thêm nhóm `api/central/*`. |
| `bootstrap/cache/tenants.php` | **Sinh tự động** — map `slug=>db_prefix`. Không sửa tay. |

### Frontend (gate bằng `REACT_APP_MULTI_TENANT`, mặc định tắt = tương thích ngược)

| File | Vai trò |
|---|---|
| `src/utils/tenant.js` | **Nguồn duy nhất**: `getTenantKey()` (segment đầu path), `apiBaseURL()` (chèn `/{key}` trước `/api`), `routerBasename()`, `homePrefix()` (cho full-URL nav), `tstore` (localStorage namespaced `${key}:...` + `clear()` chỉ xoá khóa của tenant hiện tại). |
| `src/utils/http.js` | `baseURL: apiBaseURL()`; token/loginAs đọc-ghi qua `tstore`; refresh-fail `homePrefix()+'/login'`. |
| `src/utils/refreshToken.js` | Instance axios thứ 2 — cũng `apiBaseURL()` + `tstore`. |
| `src/utils/auth.js` · `loginAs.js` | Token/loginAs keys qua `tstore`; điều hướng full-URL qua `homePrefix()`. |
| `src/App.js` | `<Router basename={routerBasename()}>`. |
| `src/context/AppProvider.js` | Cache `utilities-key`/`rolesData`/`appData` + `clear()` qua `tstore`. |
| `src/hooks/useSidebarCollapsed.js`, `features/Auth/.../AuthLoginForm.js`, `Auth/pages/Login.js` | Storage qua `tstore`. |
| `features/Property/components/PropertyDetailPanel.js` | Link công khai copy ra ngoài: `origin + homePrefix() + '/p/'+code`. (`PublicProperty.js` dùng `<Link>` nên basename tự lo, không sửa.) |
| `features/Auth/components/Forms/CentralLoginForm.js` | **Đăng nhập trung tâm** (xem dưới) — form có ô "Mã sàn" + chip "sàn gần đây". |
| `features/Auth/pages/Login.js` | Root `/login` (bật MT, chưa có key) → toggle **Cá nhân/Sàn**: Cá nhân → `AuthLoginForm` (pool, không mã sàn); Sàn → `CentralLoginForm`. `/{key}/login` hoặc 1-sàn → `AuthLoginForm` thường (không toggle). |
| `.env.local` / `.env.development.local` | Thêm `REACT_APP_MULTI_TENANT`. |

## Luồng resolve 1 request (tenant)

```
GET domain.com/sana/api/customer
  → .htaccess → index.php
  → require bootstrap/app.php (app dựng, CHƯA boot; path.cache=default)
  → TenantResolver::resolve($_SERVER):
        seg='sana' (không RESERVED, đúng regex) → loadMap() từ bootstrap/cache/tenants.php
        → {'sana'=>'sana_'} → mode=tenant
  → $_ENV['DB_PREFIX']='sana_';  app->instance('path.cache', .../cache/sana)
  → TenantContext::set('sana','sana_');  REQUEST_URI='/api/customer'
  → $app->handleRequest(new Request(...))   // boot: config đọc DB_PREFIX=sana_, cache→thư mục sana
  → router khớp 'api/customer' như thường  → CustomerApi (data của sàn sana)
```

## Đăng nhập trung tâm (hướng A — "không cần nhớ link sàn")

Vì username KHÔNG duy nhất toàn hệ thống (mỗi sàn 1 bảng `users` — sàn nào cũng có `admin`), không
thể login "chỉ user+mật khẩu" ở root cho tài khoản sàn. Giải pháp: **1 trang login duy nhất
`domain.com/login`** với **toggle chọn cách đăng nhập**.

- `domain.com/` (hoặc `/login`) khi bật MT & chưa có key → `Login.js` hiện toggle **Cá nhân / Sàn**
  (state `mode`, mặc định `personal`):
  - **Cá nhân** (`mode='personal'`) → render `AuthLoginForm` thường, **KHÔNG cần mã sàn**. Đây là
    tài khoản gói cá nhân nằm trong **pool chung** (`cle_` = prefix root/passthrough), nên login ở root
    (`http.js` baseURL = root khi chưa có key) đúng vào pool; token lưu non-namespaced như 1-sàn.
  - **Sàn** (`mode='agency'`) → render `CentralLoginForm` (ô **Mã sàn** + chip "sàn gần đây").
- Người dùng (mode Sàn) nhập **Mã sàn + tài khoản + mật khẩu**. Form gọi THẲNG `apiBaseForKey(key) + '/auth/login'`
  (instance axios riêng, vì `http.js` có baseURL root) → lưu token vào **namespace `{key}:`** của sàn
  đích (`saveTenantSession`) → `window.location.assign(homePrefixForKey(key) + '/')` reload vào `/{key}/`
  (app tự nạp phiên từ URL như login thường).
- **Nhớ mã sàn**: login thành công → `rememberTenant(key)` đẩy key vào danh sách `recent_tenants`
  (localStorage DÙNG CHUNG, không namespaced) → lần sau prefill ô Mã sàn + hiện chip 1-chạm.
- Lỗi: sàn không tồn tại → BE 404 → "Mã sàn không tồn tại"; sai mật khẩu → 401/422 → thông báo.
- Helper ở `utils/tenant.js`: `isTenancyEnabled()`, `apiBaseForKey()`, `homePrefixForKey()`,
  `saveTenantSession()`, `getRecentTenants()`, `rememberTenant()`.

> Vẫn giữ được link trực tiếp `domain.com/{key}/login` (bookmark theo sàn) — khi URL đã có key thì
> render `AuthLoginForm` thường (không hiện toggle). Portal chọn-sàn kiểu Slack (liệt kê sàn gần đây
> ở root) có thể thêm sau (hướng C).

## Gotcha (đọc kỹ trước khi sửa)

- **Thứ tự Ở INDEX.PHP là bắt buộc**: set `$_ENV['DB_PREFIX']` + rebind `path.cache` + cắt URI phải
  xong TRƯỚC `new Request()` và trước `handleRequest()` (config/DB/`SystemServiceProvider` đọc env
  & cache trong lúc boot; Symfony chụp `$_SERVER` lúc dựng Request). Đặt sau là trễ.
- **Config cache đóng băng prefix**: hiện `APP_DEBUG=true` ⇒ KHÔNG ghi `bootstrap/cache/config.php`,
  nên `$_ENV['DB_PREFIX']` mỗi request có hiệu lực. **Nếu bật `APP_DEBUG=false`** framework sẽ cache
  config (prefix bị đông cứng) → multi-tenant HỎNG. Giữ debug=true, hoặc chuyển sang set prefix bằng
  `setTablePrefix()` sau boot (chưa làm) nếu cần cache config.
- **Model SkillDo đọc prefix từ `env('DB_PREFIX')`, KHÔNG từ connection**. Request tenant bình
  thường OK (index.php đã set env). Nhưng khi đổi prefix TRONG một request (Provisioner) phải set
  **cả** `$_ENV['DB_PREFIX']` **lẫn** `setTablePrefix()` — nếu chỉ set connection, `User::updateMeta`
  ghi nhầm prefix. `TenantProvisioner::withPrefix()` đã lo + flush cache 2 đầu.
- **`core_tenants` ở prefix cố định `core_`** — Provisioner tự set/restore prefix quanh mọi thao tác
  central. CentralApi chạy ở request ROOT (passthrough) nên connection đang là prefix .env mặc định.
- **Cache file là nguồn nhanh, DB là chân lý**: provisioning luôn ghi lại `bootstrap/cache/tenants.php`.
  Xoá/hỏng file → request kế tự rebuild từ `core_tenants` (query PDO thô 1 lần rồi ghi lại).
- **FE 2 sàn cùng trình duyệt**: mọi key localStorage PHẢI qua `tstore` (namespaced). Đừng gọi
  `localStorage.clear()` trực tiếp (xoá cả sàn kia) — dùng `tstore.clear()`.
- **Passthrough = tương thích ngược**: chưa cấp phát tenant nào (không cache file + `core_tenants`
  chưa có/rỗng) ⇒ app chạy y hệt bản 1-sàn cũ (prefix .env, không cắt URI). Multi-tenant là opt-in.

## Kiểm chứng PoC (checklist)

> Cần `UTILS_API_OPEN=true` (backend `.env`) và một backend chạy được (WAMP/host). DB thao tác qua
> API — không CLI. Thay `HOST` bằng domain backend của bạn.

**BE — tạo 2 sàn:**
1. `POST HOST/api/central/provision` body `{"slug":"sana","name":"Sàn A"}` → lưu `admin_password` trả về.
2. `POST HOST/api/central/provision` body `{"slug":"sanb","name":"Sàn B"}` → lưu `admin_password`.
3. `GET HOST/api/central/tenants` → thấy 2 dòng; kiểm tra `bootstrap/cache/tenants.php` có `sana`/`sanb`.
4. `GET HOST/sana/api/utils/database` và `GET HOST/sanb/api/utils/database` → mỗi sàn báo cập nhật DB
   (đã có sẵn từ provision; gọi lại phải idempotent, không lỗi). Trong DB thấy bảng `sana_*`, `sanb_*`, `core_tenants`.
5. **Cô lập token**: đăng nhập `POST HOST/sana/api/auth/login` (admin sàn A) lấy access token →
   gọi `GET HOST/sanb/api/auth/current` với token đó → **401** (token sàn A không dùng được ở sàn B).
   Gọi `GET HOST/sana/api/auth/current` với chính token đó → **200**.
6. Slug lạ: `GET HOST/khong-ton-tai/api/...` → **404 JSON** "Không tìm thấy sàn".
7. Root cũ vẫn chạy: `GET HOST/api/central/tenants` (không `/{key}`) → 200 (passthrough).

**FE — 2 sàn cùng trình duyệt:**
8. Đặt `REACT_APP_MULTI_TENANT=true`, build/serve FE (hoặc `npm start`).
9. Tab 1: mở `FE_HOST/sana` → login admin A. Tab 2: mở `FE_HOST/sanb` → login admin B.
10. DevTools → Application → Local Storage: thấy khóa `sana:access_token` và `sanb:access_token`
    RIÊNG BIỆT (không đè nhau). Dữ liệu (khách/BĐS) mỗi tab tách biệt hoàn toàn.
11. Reload từng tab → mỗi tab giữ đúng phiên của mình. Đăng xuất tab A không đá tab B.

## Liên quan
- Kế hoạch đầy đủ GĐ4 (Bước 1–4): `docs/todo.md`.
- Schema bảng `tenants`: `docs/database.md §3b`.
- Cô lập token: `app/Http/Middlewares/JwtLoginAs.php` (docblock).
