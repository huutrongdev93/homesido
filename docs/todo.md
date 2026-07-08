# TODO — HomeSido CRM (việc cần làm tiếp)

> **Quy ước file này:** đây là hàng đợi việc CÒN LẠI. **Làm xong việc nào thì XÓA hẳn mục đó khỏi file**
> (không tick, không gạch ngang — xóa luôn). File chỉ chứa việc chưa làm.
>
> **Đọc trước khi bắt đầu (bắt buộc):** `CLAUDE.md` · `docs/ke-hoach-giai-doan-1.md` (kế hoạch tổng) ·
> `docs/database.md` (schema + gotcha base Model) · `docs/features/frontend-data-standards.md` ·
> feature notes: `docs/features/{customer,property,care}.md` (khuôn CRUD đã xác lập — bám theo).

## Bối cảnh (KHÔNG phải task — để session mới nắm baseline)

Đã xong: nền multi-tenant bị bỏ (1 deployment = 1 sàn) · 11 bảng CRM (`database/crm.php`) · nhóm cap
`customer`/`property` · **Khách hàng** (CRUD + chống trùng SĐT + scope + xóa mềm + timeline) ·
**Bất động sản** (CRUD + kho chung + tỉnh/phường qua LocationApi) · **Chăm sóc** (lịch chăm + "Cần chăm
hôm nay" + drawer chi tiết + timeline) · **Tick nền** care-reminder + cold-detect.

Khuôn chuẩn để nhân module: BE `ApiController` base (paging/scope) + Model + route `middleware('jwt')` +
cap trong `register.php`; FE `<feature>ApiSlice` (RTK Query) + `features/<F>` + route + menu + **field
dùng chung `~/components/Forms`** (ESLint chặn antd form input trong `features/`). Enum tĩnh → `UtilsApi::index`.
DB workflow: sửa `database/crm.php` → `GET api/utils/database` (`UTILS_API_OPEN=true`, đã bật). Test HTTP có
auth: mint token tạm cho admin qua scratch `UtilsApi::run()` rồi hoàn nguyên. **Luôn cập nhật `docs/database.md`
khi đổi schema và tạo/cập nhật `docs/features/<feature>.md` + index khi làm module.**

---

## Bước 3 — Khóa khách + Bàn giao + Auto-release

**Mục tiêu:** bảo vệ khách theo nhân viên; chuyển giao có lịch sử; tự trả khách quá hạn khóa về kho chung.

- **Khóa khách (`customers.locked_until`)**: khi sales nhận/tạo khách → set `locked_until = now + X ngày`
  (X cấu hình, mặc định ~7, để env `CUSTOMER_LOCK_DAYS` hoặc hằng số). Sales khác không thấy/không nhận
  được khách đang khóa của người khác (bổ sung điều kiện vào scope `CustomerApi::index`/`findOwned`).
- **Bàn giao (`customer_transfers`)**: endpoint `POST api/customer/{id}/transfer` (cap `customer_transfer`)
  — đổi `assigned_user_id`, ghi 1 dòng `customer_transfers` (from/to/by/reason), reset `locked_until`,
  `Notifier::send` cho cả 2 bên. FE: nút "Bàn giao" trong drawer/list (chỉ hiện với `customer_transfer`),
  modal chọn nhân viên nhận (cần danh sách user — xem endpoint loginAs candidates hoặc tạo user list nhẹ).
- **Auto-release tick (`customer-release-tick`)**: thêm `Services\Care\CustomerRelease` + đăng ký trong
  `app/Console/schedule.php` (dailyAt). Khách `locked_until < now` mà không có tương tác trong hạn → gỡ khóa
  (`locked_until=null`, tuỳ chọn `assigned_user_id=0` = trả kho chung) + `Notifier::send` cho sales cũ.
  (Đây là tick còn thiếu từ Bước 6 — chỉ có tác dụng khi `locked_until` được set ở trên.)
- Cập nhật `docs/features/customer.md` (thêm khóa/bàn giao) + `docs/database.md` nếu thêm cột.

## Bước 7 — Dashboard tổng hợp

**Mục tiêu:** thay placeholder `features/Home` bằng dashboard thật.

- BE `DashboardApi`: số liệu cho user hiện tại (scope): việc cần chăm hôm nay (đếm), lịch hẹn (khi có),
  khách theo `pipeline_stage` (đếm nhóm), số BĐS theo `status`, KPI tháng (khách mới, tương tác). 1 endpoint
  `GET api/dashboard` trả gộp.
- FE `features/Home`: card KPI + widget "cần chăm hôm nay" (link `/care`) + biểu đồ phễu đơn giản (đếm theo
  pipeline). Dùng RTK Query. (Nếu vẽ chart: đọc skill dataviz trước.)
- Bản quản lý (thấy toàn sàn nếu có `customer_view_all`): hiệu suất theo nhân viên — có thể để GĐ sau.

## Bước 8 — Danh mục phụ + hoàn thiện form

- **CRUD danh mục** (skeleton CRUD gọn, có thể gộp 1 màn "Cấu hình" ở `/admin/*` AdminLayout):
  `lead_sources` (nguồn khách), `projects` (dự án), `property_owners` (chủ nhà), `care_templates` (kịch bản).
  Mỗi cái: model đã có → thêm Api + route + slice + trang nhỏ. Cap: dùng `customer`/`property` tương ứng hoặc
  cap `permission` (admin).
- **Nối vào form**: Customer form thêm select **nguồn khách** (`lead_source_id`); Property form thêm select
  **dự án** (`project_id`) + **chủ nhà** (`owner_id`); Care form/complete cho chọn **kịch bản** (`care_template_id`)
  để prefill nội dung (thay biến `{{ten_khach}}`).

## Media cho Bất động sản (pipeline upload)

**Base CHƯA có endpoint upload** — `ImageUploadField`/`FileUpload` chỉ là component chờ nối.
- BE: endpoint upload file (lưu vào storage, trả path) + `POST/DELETE api/property/{id}/media` (bảng
  `property_media` đã có). Cân nhắc giới hạn loại/dung lượng + bảo mật đường dẫn.
- FE: trong Property form/detail, upload nhiều ảnh + sắp xếp (`sort_order`) + xóa. Dùng lại được cho avatar,
  tài liệu hợp đồng sau này.

## Khách hàng — bổ sung còn thiếu (từ kế hoạch GĐ1)

- **Import/Export Excel/CSV** khách hàng (BE có `phpoffice/phpspreadsheet` trong vendor). Import: chống trùng
  SĐT theo lô, báo dòng lỗi. Export: theo filter hiện tại.
- **Lead scoring**: tính `lead_score` tự động (tần suất tương tác/giai đoạn) — tick hoặc tính khi có tương tác.
- **customer_demands (nhu cầu/tiêu chí)**: hiện detail trả `demands:[]` nhưng CHƯA có CRUD. Thêm add/edit/delete
  nhu cầu trong drawer khách (cần cho Matching ở GĐ2).

## Giai đoạn 2 (backlog — làm sau khi GĐ1 xong)

- **Matching khách ↔ BĐS** (`property_customer_matches`): lọc BĐS khớp tiêu chí `customer_demands` (loại/khu
  vực/giá/diện tích) + gợi ý khách cho 1 BĐS mới; "gửi SP cho khách" ghi vào timeline.
- **Lịch hẹn dẫn khách** (`appointments`): tạo lịch (khách + BĐS + giờ) + tick nhắc trước giờ + cập nhật kết quả.
- **Giao dịch** (`deals`, `deal_payments`, `commissions`): cọc→hợp đồng→hoàn tất; tự đổi status BĐS; tính hoa hồng.
- **Báo cáo phễu + hiệu suất nhóm** (conversion từng bước, nguồn khách, doanh số) + xuất Excel/PDF.
- **Marketing** (GĐ3): Zalo ZNS/SMS/Email hàng loạt, form thu lead từ web, bản đồ BĐS, mobile/PWA.
