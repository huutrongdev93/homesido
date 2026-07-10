# TODO — HomeSido CRM (việc cần làm tiếp)

> **Quy ước file này:** đây là hàng đợi việc CÒN LẠI. **Làm xong việc nào thì XÓA hẳn mục đó khỏi file**
> (không tick, không gạch ngang — xóa luôn). File chỉ chứa việc chưa làm.
>
> **Đọc trước khi bắt đầu (bắt buộc):** `CLAUDE.md` · `docs/ke-hoach-giai-doan-1.md` (kế hoạch tổng) ·
> `docs/database.md` (schema + gotcha base Model) · `docs/features/frontend-data-standards.md` ·
> feature notes: `docs/features/{customer,property,care}.md` (khuôn CRUD đã xác lập — bám theo).

## Bối cảnh (KHÔNG phải task — để session mới nắm baseline)

Đã xong: nền multi-tenant bị bỏ (1 deployment = 1 sàn — **quyết định này được ĐẢO LẠI ở GĐ4 dưới**,
theo kiểu prefix-per-tenant, KHÔNG thêm cột tenant_id) · 11 bảng CRM (`database/crm.php`) · nhóm cap
`customer`/`property` · **Khách hàng** (CRUD + chống trùng SĐT + scope + xóa mềm + timeline) ·
**Bất động sản** (CRUD + kho chung + tỉnh/phường qua LocationApi) · **Chăm sóc** (lịch chăm + "Cần chăm
hôm nay" + drawer chi tiết + timeline) · **Khóa khách + Bàn giao + Kho chung** (locked_until +
auto-claim + `customer_transfers` + auto-release) · **Dashboard** (`GET api/dashboard`: KPI + phễu +
kho BĐS, data-scope) · **Danh mục phụ + Cấu hình** (`/admin/catalog`: nguồn khách/dự án/chủ nhà/kịch bản
+ nối select vào form) · **Media BĐS + kế toán dung lượng** (upload ảnh/video, dung lượng theo user,
xóa hẳn purge) · **Nhu cầu/tiêu chí khách** (`customer_demands` CRUD trong drawer — nền Matching GĐ2) ·
**Import/Export Excel khách** (`CustomerSheet`: xuất theo filter, nhập chống trùng SĐT theo lô + báo dòng
lỗi) · **Lead scoring** (`LeadScorer`: lead_score 0–100 theo giai đoạn/tần suất/độ mới/nhiệt độ) ·
**Tick nền** care-reminder + cold-detect + customer-release + lead-score. **→ GĐ1 hoàn tất** — kịch bản
kiểm thử GĐ1 ở [`docs/test-giai-doan-1.md`](test-giai-doan-1.md).

Khuôn chuẩn để nhân module: BE `ApiController` base (paging/scope) + Model + route `middleware('jwt')` +
cap trong `register.php`; FE `<feature>ApiSlice` (RTK Query) + `features/<F>` + route + menu + **field
dùng chung `~/components/Forms`** (ESLint chặn antd form input trong `features/`). Enum tĩnh → `UtilsApi::index`.
DB workflow: sửa `database/crm.php` → `GET api/utils/database` (`UTILS_API_OPEN=true`, đã bật). Test HTTP có
auth: mint token tạm cho admin qua scratch `UtilsApi::run()` rồi hoàn nguyên. **Luôn cập nhật `docs/database.md`
khi đổi schema và tạo/cập nhật `docs/features/<feature>.md` + index khi làm module.**

---

## Giai đoạn 2 — HOÀN TẤT

Đã xong toàn bộ GĐ2: **Lịch hẹn dẫn khách** (`appointments` + tick nhắc) · **Giao dịch** (`deals`/`deal_payments`/
`commissions`: cọc→hợp đồng→hoàn tất, tự đổi status BĐS, hoa hồng) · **Báo cáo** (phễu + nguồn + doanh số +
hiệu suất nhóm + xuất Excel). Feature notes: `docs/features/{appointment,deal,report}.md`.

> **Xuất PDF** chưa làm (mới có Excel) — nếu cần, thêm sau (hoặc dùng in trình duyệt cho trang Báo cáo).

## Giai đoạn 4 — Multi-tenant + bán gói (ƯU TIÊN KẾ TIẾP — kế hoạch chi tiết)

> **Mục tiêu thương mại — 3 gói** (khác nhau CHỈ bằng tham số plan + bộ role seed, không tách codebase):
>
> | | Gói cá nhân | Gói team | Gói sàn (agency) |
> |---|---|---|---|
> | Triển khai | **POOL chung** — user trong bộ bảng `cle_` hiện có, KHÔNG tạo bảng mới | **prefix riêng** (bộ bảng mới) | **prefix riêng** (bộ bảng mới) |
> | Số user | 1 | N theo gói | N theo gói |
> | BĐS | của mình (**ép `visibility='private'`** — pool chung) | kho chung (đã có sẵn) | kho chung + phân quyền |
> | Khách/doanh thu | của mình | **mỗi người chỉ thấy của mình — kể cả chủ gói** | quản lý có cap `*_view_all` thấy hết |
> | Chủ gói | role `ca_nhan` — **KHÔNG administrator** (admin bypass cap → thấy cả pool!) | role "trưởng nhóm": quản lý tài khoản + thanh toán, **KHÔNG administrator, KHÔNG `*_view_all`** | administrator đầy đủ |
> | Màn Phân quyền | ẩn + chặn API | **ẩn + CHẶN API theo plan** (chống chủ gói tự nâng quyền) | mở như hiện tại |

### Quyết định thiết kế đã chốt (đọc trước khi code)

- **Multi-tenant theo PREFIX** (đảo quyết định "1 deployment = 1 sàn"): mỗi tenant = 1 bộ bảng riêng
  trong cùng DB, phân biệt bằng `DB_PREFIX` động, resolve theo **path key** (`domain.com/{key}`). KHÔNG thêm cột
  `tenant_id` vào bất kỳ bảng nào — schema hiện tại giữ nguyên 100% (ghi chú "không tenant_id"
  trong `database/crm.php` vẫn đúng). Không đụng vendor, không có rủi ro lộ dữ liệu chéo do sót scope.
- **Mô hình LAI cho gói cá nhân — POOL chung, không tạo bảng mới**: mọi khách gói cá nhân là user
  trong MỘT tenant "pool" dùng bộ bảng `cle_` hiện có (data-scope per-user sẵn có tự cách ly;
  đăng ký = tạo 1 user, kích hoạt tức thì, không bùng nổ số bảng). Chỉ gói team/agency mới provision
  prefix riêng. Pool = 1 dòng đặc biệt trong `tenants` (slug vd `app`, `db_prefix='cle_'`,
  group `personal_pool`) → resolver KHÔNG cần case riêng. Administrator của pool = tài khoản
  nhà cung cấp (admin id 1), không phải của khách.
- **Chế độ pool phải TẮT MỌI tính năng chéo-user** (các khách cá nhân là khách hàng trả tiền riêng
  biệt, tuyệt đối không thấy nhau) — gate theo `PlanGate` group `personal_pool`, chặn ở BE không chỉ ẩn UI:
  BĐS ép `visibility='private'` khi create/update (vì `shared` hiện cho MỌI user cùng bộ bảng);
  TẮT kho chung khách + auto-claim + bàn giao (`customer_transfers`) + tick `customer-release` bỏ qua
  pool; `assignableUsers` chỉ trả chính mình; matching chỉ chạy trong dữ liệu của mình; catalog
  (nguồn khách/dự án/kịch bản) dùng bộ mặc định chung READ-ONLY (giới hạn chấp nhận được của gói rẻ).
- **Nâng cấp cá nhân → team/sàn = DI TRÚ dữ liệu** (copy các bảng theo `assigned_user_id`/`created_by`
  từ pool `cle_` sang prefix mới) — viết script khi có nhu cầu thật, ban đầu làm tay; ghi nhận là
  chi phí cố hữu của mô hình lai.
- **Data-scope sẵn có đã đủ** cho khác biệt team/sàn: `ApiController::canViewAll` (cap `*_view_all`)
  + kho chung BĐS. Khác biệt gói = bộ role seed lúc provisioning, không đổi data model.
- **Resolve tenant theo PATH** (`domain.com/{key}/...`, KHÔNG dùng subdomain — chốt để dễ triển khai:
  không cần wildcard DNS/vhost/SSL, chạy được trên hosting thường, dev WAMP không đụng vhost).
  Resolver ở bootstrap: đọc **segment đầu của URI** → tra `tenants` → set `DB_PREFIX` → **cắt segment
  khỏi URI trước khi framework routing chạy** (route/controller hiện tại giữ nguyên, không sửa).
  Thiết kế resolver nhận **path HOẶC host** — sau này nâng lên subdomain chỉ là đổi config.
  Tạo tenant mới = insert 1 dòng DB, không thao tác hạ tầng.
- **3 gotcha riêng của path-based (phải xử lý đủ):**
  1. Mọi tenant CHUNG origin → chung `localStorage` — FE phải **namespace key theo tenant**
     (`{key}:access_token`...), không thì mở 2 sàn cùng trình duyệt sẽ đè token nhau.
  2. FE base path động: Router basename + axios baseURL đọc từ segment đầu URL lúc runtime
     (`/{key}`, `/{key}/api`) — vẫn 1 build duy nhất, assets serve từ root.
  3. Mọi link backend sinh ra phải kèm `/{key}`: link thông báo đẩy (`Notifier`), link công khai
     BĐS `/p/{code}` → `/{key}/p/{code}`.
- **Cô lập token giữa các tenant — ĐÃ GIẢI QUYẾT ở Bước 0, KHÔNG dùng JWT claim** (mint token nằm
  trong vendor `skilldo/framework`): cô lập bằng KIẾN TRÚC — bảng `{prefix}oauth_*` riêng + thư mục
  cache riêng per-tenant (`path.cache` rebind) ⇒ token sàn A ở sàn B = 401 (đã kiểm chứng e2e).
  Với pool gói cá nhân: mọi user pool chung bảng token `cle_` — an toàn vì token gắn user,
  cách ly giữa các khách cá nhân là data-scope per-user.
- **Đã cân nhắc và LOẠI phương án chung bảng + cột `tenant_id`** (KHÔNG bàn lại): phải fork vendor
  (`users`/`options`/roles của `skilldo/cms` không có khái niệm tenant — username unique toàn hệ thống,
  catalog/options không tách được, role engine chung phá yêu cầu "gói sàn tự phân quyền"); framework
  KHÔNG có Global Scope → phải áp `WHERE tenant_id` thủ công MỌI query, sót 1 chỗ = lộ dữ liệu giữa
  các sàn cạnh tranh. Lợi ích duy nhất (đăng ký không cần tạo bảng) đã có ở prefix-per-tenant vì
  provisioning = chạy migration idempotent vài giây.
- **Ngưỡng scale**: vài trăm tenant × ~25 bảng — MySQL thoải mái. Chạm mức nghìn tenant → tách bớt
  sang DB thứ hai (resolver map theo DB thay vì chỉ prefix — thiết kế resolver chừa sẵn khả năng này).

### Bước 0 — PoC multi-tenant trên dev — ✅ XONG (xem [`docs/features/multi-tenant.md`](features/multi-tenant.md))

Đã làm: bảng trung tâm `core_tenants` (`database/tenant.php`, prefix cố định `core_`) · resolver
`App\Services\Tenant\TenantResolver` gọi TỪ `index.php` trước boot (path key → set `DB_PREFIX` +
**rebind `path.cache` sang thư mục riêng theo tenant** để cô lập toàn bộ file-cache gồm cả token,
cắt segment khỏi URI, slug lạ→404, passthrough=tương thích ngược) · `TenantProvisioner` + `api/central/*`
(provision/tenants/rebuild-cache, gate `UTILS_API_OPEN`) · `database/migrations.php` (list per-tenant
dùng chung) · FE `utils/tenant.js` (baseURL/basename/`tstore` namespaced, gate `REACT_APP_MULTI_TENANT`).

> **Quyết định lệch todo (đã chốt với chủ dự án):** KHÔNG thêm JWT claim `tenant`. Mint token nằm
> trong vendor `skilldo/framework` (`@dev` → `composer install` ghi đè), nên cô lập token bằng KIẾN
> TRÚC: bảng `{prefix}oauth_access_tokens` riêng + thư mục cache `access_token_*` riêng ⇒ token sàn A
> ở sàn B = 401. Xem multi-tenant.md §"Cô lập token & cache".
>
> **Kiểm chứng BACKEND — ✅ ĐÃ CHẠY e2e trên `homesido-sanbox.com` (DB thật):** provision `sana`/`sanb`
> OK · token sàn A dùng ở sàn B = **401** (cả 2 chiều) · mật khẩu A không login được sàn B (user tách
> biệt) · tạo khách ở A → A total=1, **B total=0** (data tách biệt) · slug lạ = 404 · passthrough
> root trả 200. **Bug đã sửa khi test:** `CacheFile::setDir()` ném lỗi nếu thư mục cache của tenant chưa tồn tại →
> `index.php` nay `mkdir` `storage/framework/cache/{prefix}` trước khi bind.
>
> **Còn lại (cần chạy ở máy bạn):** FE 2 sàn cùng trình duyệt — `REACT_APP_MULTI_TENANT=true`, `npm start`,
> mở `/sana` + `/sanb`, kiểm localStorage namespaced (`sana:access_token` vs `sanb:...`).

### Bước 1 — Hoàn thiện nền multi-tenant

- `schedule-run` lặp qua tenants `active`: mỗi tick (push, care-reminder, cold-detect, customer-release,
      lead-score, appointment-reminder) chạy lần lượt từng tenant — chú ý set prefix + reset state giữa các vòng.
- Uploads tách thư mục theo tenant: `storage/uploads/{slug}/...`; `StorageMeter` giữ nguyên
      (user meta đã theo prefix nên tự per-tenant).
- Mở rộng `TenantProvisioner` (ĐÃ CÓ: insert tenant + migrate prefix mới + seed admin + cache map):
      thêm **seed role preset theo group gói** + tài khoản chủ gói đúng role (trưởng nhóm/administrator).
- Link do BE sinh ra phải kèm `/{key}` (qua `TenantContext::pathPrefix()` — dựng sẵn cho việc này):
      link trong thông báo/push (`Notifier::send`), link công khai BĐS phía BE. (FE đã xử ở
      `PropertyDetailPanel` bằng `homePrefix()`.)
- Web Push: VAPID key dùng chung OK; `push_queue` theo prefix — tick lặp tenant xử lý; service worker
      scope root dùng chung (đã ghi ở multi-tenant.md là việc Bước 1).
- Rà mọi chỗ đọc env/option: cái gì per-tenant phải nằm ở `options` (đã theo prefix), env chỉ giữ thứ toàn cụm.
- **Gotcha config-cache (chặn production)**: nếu bật `APP_DEBUG=false`, framework ghi
      `bootstrap/cache/config.php` → `DB_PREFIX` bị đông cứng → **multi-tenant HỎNG**. Trước khi lên
      prod phải chọn: giữ `APP_DEBUG=true` (tạm) HOẶC chuyển sang set prefix bằng `setTablePrefix()`
      sau boot (đúng bài, chưa làm). Chi tiết: multi-tenant.md §Gotcha.

### Bước 2 — PlanGate + bảng `plans` (gói lớn + gói con) + role preset theo gói

- Bảng danh mục `plans` (prefix trung tâm, cạnh `tenants`): `code`, `name`, **`group`**
      (personal|team|agency — quyết định HÀNH VI: bộ role seed, khóa API phân quyền), `max_users`,
      `storage_quota_mb`, `price_month`, `active`. Gói con = thêm dòng (vd `personal_1gb`/`personal_10gb`/
      `personal_20gb` cùng group `personal`), KHÔNG sửa code. `tenants.plan_code` → `plans.code`.
- `App\Services\Plan\PlanGate` — **2 nguồn plan** (hệ quả mô hình lai):
      tenant prefix riêng (team/agency) → đọc `tenants.plan_code` → `plans`;
      user trong POOL (cá nhân) → plan per-USER (user meta `plan_code`/`plan_expires_at` → `plans`).
      API chung: `group()`, `maxUsers()`, `storageQuotaMb()`, `isExpired()`, `allow($feature)`.
      Nâng/hạ gói = đổi plan_code; hạ xuống dưới mức đang dùng (xài 15GB mua gói 10GB) → chỉ chặn
      upload/tạo mới (`wouldExceed`), KHÔNG xóa dữ liệu.
- Hết hạn gói: check trong middleware jwt → **402 `PLAN_EXPIRED`** (dữ liệu còn nguyên, chỉ khóa thao tác);
      FE bắt mã này → màn "Gia hạn gói"; banner cảnh báo trước 7 ngày hết hạn. (Pool: hết hạn theo
      từng USER; tenant riêng: theo tenant.)
- `StorageMeter::quota()` chuyển nguồn từ env `STORAGE_QUOTA_MB_PER_USER` sang PlanGate —
      pool: quota per-USER (khớp StorageMeter sẵn có); tenant riêng: quota TỔNG tenant (dễ định giá).
- Role preset: POOL → user cá nhân mới nhận role `ca_nhan` (cap dữ liệu của mình, KHÔNG
      administrator/`*_view_all` — administrator của pool là tài khoản nhà cung cấp) · `team` → role
      `truong_nhom` (cap quản lý nhân viên + catalog, KHÔNG `*_view_all`/administrator) + role `thanh_vien`
      · `agency` → chủ gói = administrator, phân quyền đầy đủ.
- Chặn `api/role/*` theo plan ở BE (chỉ gói `agency` được sửa phân quyền) — chặn API thật, không chỉ ẩn UI.
- Rà từng endpoint Báo cáo/Giao dịch/Dashboard: xác nhận không lộ số liệu tổng khi thiếu `*_view_all`
      (khuôn `canViewAll` đúng nguyên tắc nhưng phải kiểm từng chỗ cho gói team).
- Trang FE "Gói dịch vụ": gói hiện tại, số user đang dùng/tối đa, dung lượng đã dùng, ngày hết hạn.

### Bước 3 — Module Nhân viên (Staff) — cần bất kể multi-tenant (hiện chủ sàn KHÔNG tự tạo được nhân viên)

- BE `StaffApi`: list/create/update/khóa tài khoản + gán role; chặn tạo mới theo `PlanGate::maxUsers()`;
      gói `team` chỉ cho gán role `thanh_vien`.
- Nhóm cap `staff` trong `app/Roles/register.php`.
- FE `features/Staff` + `staffApiSlice` + route + menu (gate `useCan('staff')`).

### Bước 4 — Portal đăng ký + mua gói

- Trang public ở ROOT `domain.com/` (ngoài mọi `/{key}`): đăng ký + chọn gói con trong bảng `plans`
      (hoặc trial mặc định). **Hai luồng theo group**: gói cá nhân → tạo USER trong pool `cle_`
      (kích hoạt tức thì, không provision) · gói team/agency → nhập key → `TenantProvisioner`
      tạo prefix riêng + seed chủ gói + role preset.
- Validate key: `[a-z0-9-]`, 3–30 ký tự, danh sách cấm = mọi segment root đang dùng (`api`, `uploads`,
      `static`, `p`, `portal`, `admin`, `login`, `schedule-run`, `www`, `app`...).
- **Xung đột phải chốt khi làm bước này**: resolver hiện passthrough ROOT → `cle_` (tương thích ngược),
      nhưng portal cũng muốn ở ROOT. Phương án: pool cá nhân nhận key riêng (vd `/app` → `cle_`,
      thêm dòng `core_tenants`) và ROOT nhường cho portal — hoặc portal ở `/portal`.
- Thanh toán giai đoạn đầu: chuyển khoản + duyệt tay (portal/admin set `plan_code` + `expires_at`);
      cổng thanh toán tự động để sau.
- (Sau, khi nhiều khách) Tick `license-check` gọi portal trung tâm lấy payload plan có ký số, cache-có-hạn
      — **KHÔNG fail-open** (quá 7 ngày không xác thực được mới khóa, tránh vết xe license framework fail-open).

### Dọn dẹp trước khi bán (bất kể tiến độ các bước trên)

- Production: tắt `UTILS_API_OPEN`, sinh lại TOÀN BỘ secret (JWT, VAPID, SCHEDULE_RUN_TOKEN, DB password).
- **`api/central/*` (provision/tenants/rebuild-cache) hiện gate bằng `UTILS_API_OPEN`, KHÔNG auth** —
      trước prod phải thay bằng auth thật (token riêng cho portal, kiểu `SCHEDULE_RUN_TOKEN`),
      vì tắt `UTILS_API_OPEN` sẽ khóa luôn provisioning mà portal Bước 4 cần gọi.
- Giải quyết gotcha config-cache (xem Bước 1) trước khi bật `APP_DEBUG=false` trên prod.
- Tạo `backend/.env.example` (CLAUDE.md tham chiếu nhưng repo chưa có) — không chứa secret thật.

> **Đồng bộ docs khi làm GĐ4** (theo quy ước CLAUDE.md): mỗi bước xong phải tạo/cập nhật feature note —
> `docs/features/multi-tenant.md` (Bước 0–1), `plan.md` (Bước 2), `staff.md` (Bước 3), `portal.md`
> (Bước 4) + thêm vào index; bảng mới (`tenants`, `plans`) phải vào `docs/database.md`; và cập nhật
> `CLAUDE.md` phần Overview khi mô hình 1-deployment-1-sàn chính thức đổi.

## Giai đoạn 3 (backlog)

- **Marketing**: Zalo ZNS/SMS/Email hàng loạt, form thu lead từ web, bản đồ BĐS, mobile/PWA.
