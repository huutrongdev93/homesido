# Báo cáo (Report — GĐ2)

Báo cáo **read-only** tổng hợp từ dữ liệu sẵn có (không bảng mới): **phễu chuyển đổi** (khách theo
`pipeline_stage` + tỉ lệ chốt), **nguồn khách** (theo `lead_sources` + đã chốt), **doanh số** (deals theo
giai đoạn + doanh thu/hoa hồng hoàn tất + doanh thu 6 tháng), **hiệu suất nhân viên** (theo `assigned_user`).
Gộp vào 1 endpoint `GET api/report`. Data-scope: không có `report_view_all` → chỉ số của mình; có → toàn sàn.
Xuất Excel (`GET api/report/export`). Nhóm cap riêng `report`. Vẽ bằng **CSS bar** (không thư viện chart, như Dashboard).

## Bản đồ file (front-to-back)

```
Báo cáo (Report)
├─ FE  src/features/Report/pages/Report.js               # trang /reports: DateRange + nút Xuất Excel + KPI + phễu (CSS bar) + doanh thu 6 tháng (bar) + bảng nguồn + bảng doanh số theo giai đoạn + bảng hiệu suất (gate report_view_all)
│  ├─ src/features/Report/style/Report.module.scss        # kpiRow/kpi + grid2 + funnel/barRow/barTrack/barFill (thanh CSS)
│  ├─ src/features/Deal/dealUtils.js                       # fmtMoney (dùng lại — VNĐ→tỷ/triệu)
│  ├─ src/reduxs/api/reportApiSlice.js                     # getReport(params) — 1 query trả toàn bộ mục; tag 'Report'; dùng refetchOnMountOrArgChange
│  ├─ src/reduxs/api/apiSlice.js                           # tag 'Report' (thêm vào tagTypes)
│  ├─ src/api/reportFileApi.js                             # exportReport(params) — tải blob .xlsx (không qua RTK), như customerFileApi
│  ├─ src/routes/PrivateRoutes.js                          # route { path:'/reports', cap:'report_view' }
│  ├─ src/layout/Sidebar/NavBarData.js                     # menu "Báo cáo" (Kinh doanh, gate useCan('report_view'))
│  └─ src/context/AppProvider.js                           # appData.customer.pipeline_stages + appData.deal.statuses (nhãn cho biểu đồ/bảng)
└─ BE  routes/api.php  (prefix api/report, middleware jwt; gate cap trong controller)
       ├─ app/Controllers/Api/ReportApi.php                # index (JSON) + export (xlsx) + buildReport (tally PHP) + buildSources/buildTeam + buildWorkbook/writeRow (Excel) + streamXlsx
       ├─ app/Controllers/Api/ApiController.php            # BASE: requireCap/canViewAll
       ├─ app/Roles/RoleCapabilitiesReport.php             # cap: report_view / report_view_all
       ├─ app/Roles/register.php                           # $groups['report'] = 'Báo cáo'
       ├─ (đọc) app/Models/Customer.php · Deal.php · LeadSource.php · SkillDo\Cms\Models\User
       └─ (tham chiếu) CustomerApi::PIPELINE_STAGES · DealApi::STATUSES (dùng lại whitelist enum)
```

## Route (đều `jwt`, gate cap trong controller)

- `GET api/report` — JSON tổng hợp. Filter `?from=YYYY-MM-DD &to=YYYY-MM-DD &assigned_user_id=` (user chỉ khi view_all).
  Response: `scope` (own|all|user), `range`, `funnel{by_stage,total,won,lost,won_rate}`, `sources[]{id,name,total,won}`,
  `sales{by_status{count,value}, total_deals, revenue, commission, pipeline_value, monthly[]}`,
  `team[]{user_id,name,customers,won,deals,completed,revenue,commission}`. Cap `report_view`.
- `GET api/report/export` — tải .xlsx (4 mục, tiền để VNĐ). Cap `report_view`.

## Cách tính (buildReport)

- **Scope**: `scopeUser` = (không view_all → chính mình) / (view_all + `assigned_user_id` → user đó) / (view_all → 0 = toàn sàn).
  Áp vào query khách (`assigned_user_id`) + giao dịch (`assigned_user_id`) + khoảng ngày (`created`).
- **Nạp 1 lô rồi tally PHP** (dữ liệu 1 sàn nhỏ): nạp `Customer::query()->get()` + `Deal::query()->get()` trong phạm vi,
  cộng dồn theo giai đoạn / nguồn / nhân viên. Không groupBy (tránh phụ thuộc builder tuỳ biến), giống Dashboard.
- **Doanh thu/hoa hồng** = tổng deals `status=completed` (value / commission_amount). **Đang chốt** = value của deposit+contract.
- **Doanh thu 6 tháng**: `last6Months()` (first day of -N month) → bucket theo `substr(completed_at,0,7)`.
- **Nguồn**: tally theo `lead_source_id` (0 = "Không rõ nguồn"); tên từ `LeadSource::whereIn(...)`.
- **Hiệu suất nhóm**: tally per `assigned_user_id`; tên từ `User` (họ tên hoặc username). FE ẩn bảng này nếu không `report_view_all`.

## Gotcha

- **Excel KHÔNG dùng `$col++`** (bug PHP 8.3 của `CustomerSheet` khiến deprecation → HandleExceptions →
  JwtLoginAs trả nhầm 401). `ReportApi::writeRow` dùng **chỉ số cột số + `Coordinate::stringFromColumnIndex($idx)`**;
  chỉ tăng SỐ DÒNG (`$r++`). Stream file: `header(...)` + `Xlsx->save('php://output')` + `exit` (send-and-exit, như CustomerSheet).
- **Không bảng mới**: chỉ đọc `customers`/`deals`/`lead_sources`/`users`. Dùng lại whitelist `CustomerApi::PIPELINE_STAGES`
  + `DealApi::STATUSES` để cột không lệ thuộc chuỗi rời rạc.
- **Refetch**: FE dùng `refetchOnMountOrArgChange` để mỗi lần mở trang / đổi khoảng ngày là số mới (không có mutation nào invalidate 'Report').
- **Filter theo nhân viên**: BE hỗ trợ `assigned_user_id` (khi view_all) nhưng FE v1 chưa có dropdown chọn sale
  (đã có bảng "Hiệu suất nhân viên" để xem theo từng người) — có thể bổ sung dropdown sau nếu cần drill-down.
- **Tiền lưu VNĐ**: FE format qua `fmtMoney` (tỷ/triệu); Excel để nguyên số VNĐ.
- Gotcha chung: xem [customer.md](customer.md) + [dashboard.md](dashboard.md) + [../database.md](../database.md) §2.
