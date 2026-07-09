# Giao dịch (Deal — GĐ2)

Quản lý **giao dịch** 1 khách mua/thuê 1 BĐS: vòng đời **deposit(cọc) → contract(hợp đồng) → completed(hoàn tất)**
/ **canceled(hủy)**. Chuyển giai đoạn **tự đổi `properties.status`**; theo dõi **các đợt thanh toán**
(`deal_payments` — **đã thu / dự kiến có ngày đến hạn**); **tính + chi hoa hồng** (`commissions`, 1 dòng/giao dịch cho
sale phụ trách); **nhắc hẹn tự do** (`deal_reminders`) + **nhật ký hoạt động** (`deal_activities`, dòng thời gian).
Tick `deal-reminder-tick` nhắc đợt thu dự kiến đến hạn + nhắc hẹn tự do. Xoá mềm (`trash`) như Customer/Property.
Data-scope theo `assigned_user_id` (nhóm cap riêng `deal`).

## Bản đồ file (front-to-back)

```
Giao dịch (Deal)
├─ FE  src/features/Deal/pages/Deal.js                        # trang /deals: list phân trang + tìm mã + lọc giai đoạn; click mã → drawer; nút Tạo; hàng có Sửa/Xóa
│  ├─ src/features/Deal/components/DealFormModal.js           # tạo/sửa: DebounceSelect khách + BĐS + InputPriceField giá trị (triệu↔VNĐ) + % hoa hồng + hoa hồng (triệu, bỏ trống = tự tính) + ghi chú
│  ├─ src/features/Deal/components/DealDetailDrawer.js        # chi tiết: header + chuyển giai đoạn + đợt thanh toán (đã thu/dự kiến, đánh dấu đã thu) + NHẮC HẸN (thêm/xong/xóa) + hoa hồng + LỊCH SỬ (timeline)
│  ├─ src/features/Deal/components/DealPaymentFormModal.js    # ghi 1 đợt thu: SelectField Loại (đã thu/dự kiến) + InputPriceField số tiền (triệu) + hình thức + DateField (ngày thu HOẶC ngày đến hạn) + ghi chú
│  ├─ src/features/Deal/components/DealReminderFormModal.js   # tạo nhắc hẹn: InputField nội dung + DateField thời điểm nhắc + ghi chú
│  ├─ src/features/Deal/dealUtils.js                          # fmtMoney + DEAL_STATUS_TAG + PAYMENT_STATUS_TAG + REMINDER_STATUS_TAG + ACTIVITY_DOT (màu chấm timeline)
│  ├─ src/features/Deal/style/Deal.module.scss               # toolbar/codeLink/rowActions + drawer (head/section/list/commission) + timeline/dot/link/doneText
│  ├─ src/reduxs/api/dealApiSlice.js                          # ...+ markDealPaymentPaid + addDealReminder/updateDealReminder/deleteDealReminder; tag 'Deal'
│  ├─ src/reduxs/api/apiSlice.js                              # tag 'Deal' (thêm vào tagTypes)
│  ├─ src/routes/PrivateRoutes.js                             # route { path:'/deals', cap:'deal_view' }
│  ├─ src/layout/Sidebar/NavBarData.js                        # menu "Giao dịch" (Kinh doanh, gate useCan('deal_view'))
│  └─ src/context/AppProvider.js                             # appData.deal.{statuses, payment_methods, commission_statuses, payment_statuses, reminder_statuses}
└─ BE  routes/api.php  (prefix api/deal, middleware jwt; gate cap trong controller)
       ├─ app/Controllers/Api/DealApi.php                     # ...+ markPaymentPaid + addReminder/updateReminder/deleteReminder + logActivity + remindersOf/activitiesOf + transformReminder/transformActivity
       ├─ app/Controllers/Api/ApiController.php               # BASE: paging/respondList + requireCap/canViewAll
       ├─ app/Models/Deal.php                                 # SoftDeletes, table 'deals'
       ├─ app/Models/DealPayment.php                          # table 'deal_payments'
       ├─ app/Models/Commission.php                           # table 'commissions'
       ├─ app/Models/DealReminder.php                         # table 'deal_reminders'
       ├─ app/Models/DealActivity.php                         # table 'deal_activities' (append-only)
       ├─ app/Controllers/Api/UtilsApi.php::index             # enum deal.{statuses,payment_methods,commission_statuses,payment_statuses,reminder_statuses}
       ├─ app/Controllers/Api/UtilsApi.php::database          # đăng ký 'database/deal.php' + 'database/deal-history.php' vào $migrations
       ├─ app/Roles/RoleCapabilitiesDeal.php                  # cap: deal_view/add/edit/delete/view_all + commission_manage (nhắc hẹn/đợt thu dùng deal_edit)
       ├─ app/Roles/register.php                              # $groups['deal'] = 'Giao dịch'
       ├─ app/Console/schedule.php                            # tick 'deal-stale-tick' (08:00) + 'deal-reminder-tick' (mỗi phút)
       ├─ app/Services/Deal/DealStaleWatcher.php              # tick: deal deposit/contract updated < now-DEAL_STALE_DAYS → digest sendUnique cho chủ deal
       ├─ app/Services/Deal/DealReminderWatcher.php           # tick: (A) đợt thu dự kiến đến hạn → digest + (B) nhắc hẹn tự do đến giờ → gửi từng lời; stamp reminded_at
       ├─ database/deal.php                                   # 3 bảng deals / deal_payments / commissions (guard hasTable)
       └─ database/deal-history.php                           # +cột deal_payments (status/due_date/reminded_at) + 2 bảng deal_reminders / deal_activities
```

## Route (đều `jwt`, gate cap trong controller)

- `GET  api/deal` — list phân trang. Filter `?keyword=`(mã) `&status= &customer_id= &property_id=`.
  Response `respondList`; mỗi item kèm `customer{full_name,phone}` + `property{code,title}`. Cap `deal_view`.
- `POST api/deal` — tạo. Bắt buộc `customer_id`(scope) + `property_id`; `value` VNĐ, `commission_rate` %,
  `commission_amount` VNĐ (bỏ trống → tự tính theo %). **Chặn** nếu BĐS đã `sold` (422). Tạo `status=deposit`
  + `deposit_at=now` → đặt BĐS `deposited` + sinh 1 dòng `commissions`. Cap `deal_add`.
- `GET  api/deal/{id}` — chi tiết + `payments[]`(kèm `status`/`due_date`) + `commission` + `paid_total`(chỉ paid)/`planned_total`/`remaining` + `reminders[]` + `activities[]`(≤100, mới nhất trước). Cap `deal_view`.
- `PUT  api/deal/{id}` — sửa (khách/BĐS/giá trị/hoa hồng/ghi chú) + đồng bộ lại commission. Cap `deal_edit`.
- `PUT  api/deal/{id}/status` — chuyển giai đoạn (body `status`). Điền mốc giai đoạn + đổi `properties.status`
  (deposit/contract→deposited; completed→sold|rented; canceled→available). Cap `deal_edit`.
- `DELETE api/deal/{id}` — xóa mềm; nếu đang cọc/hợp đồng → trả BĐS về `available`. Cap `deal_delete`.
- `POST api/deal/{id}/payments` — thêm đợt thu. `status`=`paid`(mặc định, có `paid_at`) hoặc `planned`(dự kiến, **bắt buộc `due_date`**, 422 nếu thiếu). Cap `deal_edit`.
- `PUT  api/deal/{id}/payments/{paymentId}/paid` — đánh dấu đợt dự kiến đã thu (set `paid`, `paid_at`=now). Cap `deal_edit`.
- `DELETE api/deal/{id}/payments/{paymentId}` — xóa đợt thu (xóa cứng). Cap `deal_edit`.
- `POST api/deal/{id}/reminders` — tạo nhắc hẹn (`title`+`remind_at` bắt buộc; `assigned_user_id`=chủ deal). Cap `deal_edit`.
- `PUT  api/deal/{id}/reminders/{reminderId}` — sửa/đánh dấu xong (`status` pending|done; done→`done_at`; đổi `remind_at`→reset `reminded_at`). Cap `deal_edit`.
- `DELETE api/deal/{id}/reminders/{reminderId}` — xóa nhắc hẹn (xóa cứng). Cap `deal_edit`.
- `PUT  api/deal/{id}/commission` — đánh dấu chi/chưa chi (body `status` pending|paid). Cap **`commission_manage`**.

> Mọi mutation (add/status/payment*/reminder done/commission) đều ghi 1 dòng `deal_activities` qua `DealApi::logActivity` → dòng thời gian "Lịch sử". Log tự nuốt lỗi, không chặn nghiệp vụ chính.

## Tick nền

### `deal-stale-tick` (cảnh báo deal treo — dailyAt 08:00)
`DealStaleWatcher::tick()`: quét deal **còn mở** (`status ∈ deposit/contract`) mà `updated`
đã quá **`DEAL_STALE_DAYS`** ngày (env, mặc định 7) → gom **digest theo sales phụ trách** →
`Notifier::sendUnique('warning','Giao dịch đang treo', …, '/deals')` (không spam mỗi ngày, chỉ nhắc lại
sau khi đọc). Bảng chưa migrate → thoát êm.
> **GOTCHA:** `deals.updated` chỉ bump khi sửa/chuyển giai đoạn deal — `DealApi::addPayment` **KHÔNG** chạm
> bảng `deals`, nên "lâu không cập nhật" = lâu không chuyển giai đoạn (không tính việc thu tiền).

### `deal-reminder-tick` (nhắc hẹn giao dịch — mỗi phút)
`DealReminderWatcher::tick()` — 2 nguồn độc lập, đều stamp `reminded_at` để chỉ bắn 1 lần:
- **A. Đợt thu dự kiến đến hạn**: `deal_payments` `status=planned`, `reminded_at` null, `due_date <= now + LEAD`
  (env `DEAL_PAYMENT_REMIND_LEAD_DAYS`, mặc định **1** ngày → nhắc trước 1 ngày + bắt cả quá hạn). Nạp `deals`
  lấy `assigned_user_id` → **digest** `Notifier::send('warning','Khoản thu đến hạn','Bạn có N khoản…','/deals')`.
- **B. Nhắc hẹn tự do đến giờ**: `deal_reminders` `status=pending`, `reminded_at` null, `remind_at <= now` →
  gửi **từng** lời `Notifier::send('info','Nhắc hẹn giao dịch', title, '/deals')`.
Bảng/cột chưa migrate → thoát êm.
> **GOTCHA:** đổi giờ nhắc (`remind_at`) qua `updateReminder` sẽ **reset `reminded_at`=null** để cho phép nhắc lại
> theo mốc mới. Đợt thu đã "Đánh dấu đã thu" chuyển `status=paid` nên tự thoát khỏi nguồn A.

## Nghiệp vụ chính

- **Auto đổi trạng thái BĐS** (`applyPropertyStatus`): mọi tạo/chuyển-giai-đoạn/xóa đều cập nhật `properties.status`.
  `sale`→`sold`, `rent`→`rented` khi completed (lấy `transaction_type` từ chính BĐS lúc tạo deal).
- **Hoa hồng** (`syncCommission` + `resolveCommission`): `commission_amount` = số nhập tay (nếu >0) else
  `value × rate/100`. Mỗi deal có đúng 1 dòng `commissions` (upsert theo `deal_id`), giữ nguyên `status` khi cập nhật deal.
- **Đã thu / còn lại / dự kiến**: `paid_total` = tổng `deal_payments.amount` **chỉ `status=paid`**; `remaining` =
  `value − paid_total` (≥0); `planned_total` = tổng đợt `status=planned` (chỉ hiển thị, không trừ vào remaining).
- **Kế hoạch thu (payment plan)**: đợt `planned` có `due_date`, chưa tính là đã thu; "Đánh dấu đã thu" (`markPaymentPaid`)
  chuyển sang `paid` + set `paid_at`. Tick `deal-reminder-tick` nhắc khi đợt planned tới/quá hạn.
- **Nhắc hẹn tự do** (`deal_reminders`): lời nhắc bất kỳ gắn deal, `assigned_user_id` = chủ deal; đến giờ → thông báo.
- **Nhật ký hoạt động** (`deal_activities`, append-only): mỗi thao tác ghi 1 dòng (`type`+`title`+`amount`) → timeline
  read-only ở drawer, mới nhất trước.

## Gotcha

- **Tiền lưu VNĐ, form nhập triệu**: `value`/`commission_amount`/`payment.amount` quy đổi ÷/×1e6 ở FE (như giá BĐS);
  `commission_rate` là % (không đổi). `InputPriceField` chỉ thêm dấu phẩy, trả số thô — quy đổi nằm ở reset/submit.
- **`deals` có `trash` + SoftDeletes** (khác Appointment) → Model `use SoftDeletes`, xóa mềm bằng `->trash()`,
  query tự loại bản trashed. **`result`/`method` là `string default ''`** (khuôn enum tuỳ chọn).
- **Chuyển giai đoạn linh hoạt** (không ép thứ tự): có thể lùi/tiến; mốc thời gian chỉ điền lần đầu tới giai đoạn đó.
- **`commission_manage`** tách khỏi `deal_edit`: sale sửa deal được nhưng chỉ quản lý/kế toán mới đánh dấu **chi** hoa hồng.
- **Route ordering**: nested `/{id}/payments/...` + `/{id}/reminders/...` khai sau `/{id}` (không có collection route nào ngoài index nên an toàn).
- **`deal_payments.status` default `paid`**: dòng cũ (trước migration) tự thành "đã thu" → `paid_total` không đổi (tương thích ngược).
- **Đăng ký migration**: `database/deal.php` **và** `database/deal-history.php` phải nằm trong `$migrations` của `UtilsApi::database()` (deal-history sau deal).
- Gotcha chung (base Model ép `''`/`0`, data-scope thủ công, lỗi nghiệp vụ `->setStatusCode(422)`, enum từ api/utils):
  xem [customer.md](customer.md) + [property.md](property.md) + [../database.md](../database.md) §2.
