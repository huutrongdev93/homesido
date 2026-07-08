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

## Giai đoạn 2 (backlog — làm sau khi GĐ1 xong)

- **Báo cáo phễu + hiệu suất nhóm** (conversion từng bước, nguồn khách, doanh số) + xuất Excel/PDF.
- **Marketing** (GĐ3): Zalo ZNS/SMS/Email hàng loạt, form thu lead từ web, bản đồ BĐS, mobile/PWA.
