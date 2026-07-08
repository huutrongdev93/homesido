# Dashboard tổng hợp (trang chủ)

Thay placeholder `features/Home` bằng dashboard thật: KPI tháng + "Cần chăm hôm nay" + phễu khách
theo giai đoạn + kho BĐS theo trạng thái. **1 endpoint** `GET api/dashboard` trả gộp mọi số liệu
(FE gọi 1 lần). Áp data-scope giống các module CRM khác.

## Bản đồ file (front-to-back)

```
Dashboard (Home)
├─ FE  src/features/Home/pages/index.js              # trang dashboard: KPI tiles + phễu khách + kho BĐS + lối tắt; RTK Query useGetDashboardQuery
│  ├─ src/features/Home/style/Home.module.scss       # style: kpiGrid/kpiValue + panel/bar (thanh phễu & trạng thái) + scopeBadge; tái dùng .statCard/.statIcon (tone màu)
│  ├─ src/reduxs/api/dashboardApiSlice.js             # getDashboard; providesTags ['Customer','Care','Property'] → tự refetch khi 3 module đổi
│  ├─ src/routes/PrivateRoutes.js                     # route { path:'/', component:Home } (KHÔNG gate cap — mọi user đăng nhập vào được)
│  └─ src/context/AppProvider.js                      # nhãn enum: customer.pipeline_stages, property.statuses (label cho phễu/thanh)
└─ BE  routes/api.php  (prefix api/dashboard, middleware jwt)
       ├─ app/Controllers/Api/DashboardApi.php        # index(): gộp care/customers/properties/month; đếm theo từng giá trị enum + áp scope
       └─ nguồn dữ liệu: customers, care_schedules, properties, customer_interactions (không có bảng riêng)
```

## Cap / Scope / Response

- **Cap**: endpoint gate `customer_view` (mọi sales có). Trang `/` KHÔNG gate cap → user thiếu
  `customer_view` vẫn vào Home nhưng endpoint trả 403 → FE fallback chỉ hiện lời chào + lối tắt.
- **Data-scope** (giống Customer/Property): không `customer_view_all` → chỉ tính khách/việc/tương tác
  **của mình** (`assigned_user_id = me`, tương tác theo `user_id = me`); không `property_view_all` →
  chỉ BĐS mình phụ trách. Có view_all → toàn sàn (`scope = 'all'`, FE hiện badge "Toàn sàn").
- **Response** `GET api/dashboard`:
  ```
  {
    scope: 'all' | 'own',
    care:       { today, overdue },                       // pending đến hạn cuối hôm nay / quá hạn
    customers:  { total, cold, by_stage: {new,contacting,potential,negotiating,won,lost} },
    properties: { total, by_status: {available,deposited,sold,rented,inactive} },
    month:      { new_customers, interactions }            // created >= đầu tháng
  }
  ```

## Gotcha

- **Đếm theo từng giá trị enum** (vòng lặp `count()` mỗi stage/status) thay vì `groupBy` — rẻ, rõ,
  tránh phụ thuộc builder tuỳ biến của SkillDo. Nếu thêm giá trị enum mới thì đếm tự bám theo
  `CustomerApi::PIPELINE_STAGES` / `PropertyApi::STATUSES` (dùng lại const, không lặp danh sách).
- **Soft delete**: `customers`/`properties` có global scope `trash=0` → count tự loại bản đã xóa.
- **KPI tháng** mốc `date('Y-m-01 00:00:00')` theo cột `created` (house-style, không phải `created_at`).
- **Phễu/thanh** vẽ bằng **CSS bar** (width tỉ lệ theo max trong nhóm) — không dùng thư viện chart;
  nhãn lấy từ `appData.customer.pipeline_stages` / `appData.property.statuses` (không hardcode).
- **Live refetch**: nhờ `providesTags`, mọi mutation invalidate `Customer`/`Care`/`Property` (thêm khách,
  hoàn thành chăm sóc, bàn giao, sửa BĐS...) khiến dashboard tự cập nhật khi user quay lại trang.
- **Panel BĐS** chỉ hiện khi user có cap `property_view` (gate FE bằng `useCan`); phần khách luôn hiện.
- Số liệu **hiệu suất theo nhân viên** (bản quản lý) chưa làm — để giai đoạn sau.
