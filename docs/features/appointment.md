# Lịch hẹn dẫn khách (Appointment — GĐ2)

Đặt lịch **dẫn khách đi xem BĐS**: khách + (tuỳ chọn) BĐS + giờ hẹn → tick nhắc trước giờ → dẫn xong
**ghi kết quả** (chốt). Vòng đời trạng thái giống Care (KHÔNG xoá mềm): `pending → done / no_show / canceled`.
Chốt `done` → tạo 1 tương tác **"Dẫn xem nhà"** vào timeline khách + `Customer::touch()` + rescore điểm
(y hệt `CareApi::complete`). Data-scope theo `assigned_user_id` (nhóm cap riêng `appointment`).

## Bản đồ file (front-to-back)

```
Lịch hẹn dẫn khách (Appointment)
├─ FE  src/features/Appointment/pages/Appointment.js                 # trang /appointments: list phân trang + lọc trạng thái (SelectField) + nút Tạo; mỗi dòng Chốt/Sửa/Hủy theo status; tag trạng thái + tag kết quả (done)
│  ├─ src/features/Appointment/components/AppointmentFormModal.js    # tạo/sửa (width 900, 2 cột): form trái (DebounceSelect khách + quick-pick 5 BĐS gợi ý (Matching) + DebounceSelect BĐS tuỳ chọn + DateField(showTime) + NumberField thời lượng + InputField địa điểm + TextAreaField ghi chú) + panel phải khi đã chọn khách (info khách + "BĐS đã xem" từ buổi hẹn `done`, nút chọn lại 1 căn cũ); sửa nạp sẵn khách/BĐS qua optionsDefault
│  ├─ src/features/Appointment/components/AppointmentCompleteModal.js # chốt: SelectField "Đã dẫn xem"/"Khách không đến" + (khi done) SelectField kết quả (results) + ghi chú
│  ├─ src/features/Appointment/style/Appointment.module.scss         # .filterBar + .rowActions
│  ├─ src/reduxs/api/appointmentApiSlice.js                          # getAppointments(list, params)/add/update/complete/cancel; tag 'Appointment'; complete invalidate 'Appointment','Interaction','Customer'
│  ├─ src/reduxs/api/apiSlice.js                                     # tag 'Appointment' (thêm vào tagTypes)
│  ├─ src/routes/PrivateRoutes.js                                    # route { path:'/appointments', cap:'appointment_view' }
│  ├─ src/layout/Sidebar/NavBarData.js                               # menu "Lịch hẹn dẫn khách" (section Kinh doanh, gate useCan('appointment_view'))
│  └─ src/context/AppProvider.js                                     # appData.appointment.{statuses,results} (enum tĩnh từ api/utils)
└─ BE  routes/api.php  (prefix api/appointment, middleware jwt; gate cap trong controller)
       ├─ app/Controllers/Api/AppointmentApi.php                     # index/detail/add/update/complete/cancel + scope (findAppointment/findCustomerInScope/resolveProperty) + enrich(khách/BĐS lô + overdue) + transform + buildInteractionContent
       ├─ app/Controllers/Api/ApiController.php                      # BASE: paging/respondList + requireCap/canViewAll
       ├─ app/Models/Appointment.php                                 # model tối giản (table 'appointments')
       ├─ app/Controllers/Api/UtilsApi.php::index                    # enum appointment.{statuses,results} cho FE
       ├─ app/Controllers/Api/UtilsApi.php::database                 # đăng ký 'database/appointment.php' vào $migrations
       ├─ app/Roles/RoleCapabilitiesAppointment.php                  # cap: appointment_view/add/edit/delete/view_all
       ├─ app/Roles/register.php                                     # $groups['appointment'] = 'Lịch hẹn dẫn khách'
       ├─ app/Console/schedule.php                                   # tick 'appointment-reminder-tick' (everyMinute)
       ├─ app/Services/Appointment/AppointmentReminder.php           # tick nhắc trước giờ (cửa sổ env APPOINTMENT_REMIND_MINUTES, mặc định 60'); đánh dấu reminded_at (nhắc 1 lần/buổi hẹn)
       ├─ app/Services/Notification/Notifier.php                     # Notifier::send(uid, ...) — thông báo in-app + Web Push
       ├─ app/Services/Customer/LeadScorer.php                       # recompute điểm khi có tương tác (chốt done)
       └─ database/appointment.php                                   # bảng `appointments` (guard hasTable, idempotent)
```

## Route (đều `jwt`, gate cap trong controller)

- `GET  api/appointment` — list phân trang. Filter: `?status= &customer_id= &property_id= &from=YYYY-MM-DD &to=YYYY-MM-DD`.
  `pending` xếp giờ-hẹn sớm-trước; trạng thái khác mới-trước. Response `respondList` (`items/total/page/pageSize`);
  mỗi item kèm `customer{full_name,phone}`, `property{code,title}|null`, cờ `overdue`. Cap `appointment_view`.
- `POST api/appointment` — tạo. Bắt buộc `customer_id` (trong scope) + `scheduled_at`; `property_id` tuỳ chọn.
  `location` bỏ trống → tự lấy `property.address`. `assigned_user_id` = sales của khách (else người tạo). Cap `appointment_add`.
- `GET  api/appointment/{id}` — chi tiết (kèm khách/BĐS). Cap `appointment_view`.
- `PUT  api/appointment/{id}` — sửa (chỉ khi `pending`). Cap `appointment_edit`.
- `PUT  api/appointment/{id}/complete` — chốt. body `{status: done|no_show, result?, result_note?}`.
  `done` → ghi `result` (interested/considering/rejected/deposited hoặc '') + tạo tương tác timeline (type `viewing`)
  + `Customer::touch` + `LeadScorer::recompute` + **AUTO FOLLOW-UP** (xem dưới). `no_show` → chỉ đánh dấu (không tính tương tác). Cap `appointment_edit`.
- `DELETE api/appointment/{id}` — hủy (`status=canceled`; chặn nếu đã `done`). Cap `appointment_delete`.

## Tick nền — `appointment-reminder-tick` (mỗi phút)

`AppointmentReminder::tick()`: quét buổi hẹn `pending` có `scheduled_at ∈ [now, now + cửa sổ]` & `reminded_at`
null → `Notifier::send` cho sales phụ trách ("Buổi hẹn với <khách> lúc HH:mm") + set `reminded_at` (mỗi buổi hẹn
nhắc **1 lần**, không spam). Cửa sổ = env `APPOINTMENT_REMIND_MINUTES` (mặc định 60'). Bảng chưa migrate → thoát êm.

## Auto follow-up sau buổi xem (GĐ2 — tự động hóa)

Chốt buổi hẹn `done` (có gắn khách) → `AppointmentApi::complete` **tự tạo 1 lịch chăm sóc**
(`CareSchedule::create`) hẹn **+1 ngày** ("Hỏi cảm nhận của khách sau buổi dẫn xem…", `type=call`,
`status=pending`, giao cho sales phụ trách buổi hẹn) — sales không phải nhớ đặt lịch tay. Khi đến hạn,
`care-reminder-tick` (xem [care.md](care.md)) sẽ nhắc như mọi lịch chăm. **Bỏ qua** khi `result=deposited`
(đã cọc → chuyển sang giao dịch). Fire-and-forget: lỗi tạo lịch được nuốt, KHÔNG làm hỏng việc chốt hẹn.
Cần import `App\Models\CareSchedule` trong `AppointmentApi`.

## Panel "BĐS đã xem" + quick-pick BĐS gợi ý (trong form tạo/sửa)

`AppointmentFormModal` widen (900) + layout 2 cột khi mở — không đổi API/DB, chỉ tái dùng dữ liệu sẵn
có ở FE để trả lời "khách này đã xem những BĐS nào" ngay trong lúc đặt lịch mới:

- **Panel phải** (khi đã chọn khách qua `useWatch` theo dõi `customer_id`): info khách (`useGetCustomerQuery`,
  cap `customer_view`) + **"BĐS đã xem"** = `useGetAppointmentsQuery({customer_id, status:'done'})` (cap
  `appointment_view`), lọc các buổi hẹn có gắn BĐS (`item.property` khác null). Mỗi dòng có nút chọn lại
  (icon `fa-rotate`) → `setValue('property_id', ...)` để đặt lịch xem lại nhanh 1 căn cũ.
- **Quick-pick BĐS gợi ý**: thay vì chỉ có ô `DebounceSelect` trơn, hiện tối đa 5 thẻ BĐS từ
  `useGetSuggestedPropertiesQuery(customerId)` (tái dùng Matching, cap `matching_view`) phía trên ô select
  — bấm thẻ để chọn nhanh; không có gợi ý (chưa có nhu cầu khớp) thì panel quick-pick tự ẩn, sale gõ tìm
  ở `DebounceSelect` như cũ.
- **Đồng bộ nhãn Select khi chọn qua quick-pick/nút chọn lại**: `DebounceSelect` chỉ hiện đúng nhãn cho
  item có trong `options` hiện tại (state nội bộ nạp từ `optionsDefault`) — set giá trị `property_id`
  bằng `setValue` (ngoài Controller) không tự có nhãn. State `propertyPicked` giữ `{value,label}` vừa
  chọn và được đưa vào `optionsDefault` (ưu tiên hơn `propertyDefault` của chế độ sửa) để nhãn hiện đúng
  ngay không cần gõ tìm lại.
- Toàn bộ 3 query trên đều `skip` khi chưa chọn khách hoặc thiếu cap tương ứng — không có route/cột/cap mới.

## Gotcha

- **Bảng `appointments` KHÔNG có `trash`** — theo khuôn Care (dùng vòng đời status), khác Customer/Property. Hủy = `status=canceled`.
- **`result` là `string default ''`** (khuôn "enum tuỳ chọn" — MySQL enum không nhận ''); validate ở app theo `AppointmentApi::RESULTS`.
- **Timeline khi `done`**: tạo `CustomerInteraction` type `viewing` (đã có sẵn trong enum `interaction_types`), nội dung gồm BĐS + nhãn kết quả + ghi chú. `no_show` KHÔNG ghi timeline / KHÔNG touch (khách không đến ≠ tương tác thành công).
- **Đăng ký migration**: `database/appointment.php` phải nằm trong `$migrations` của `UtilsApi::database()` (sau `matching.php`) → `GET api/utils/database` để áp.
- **DebounceSelect trong form**: dùng endpoint list sẵn có (`GET api/customer|property?keyword=`), không route riêng; sửa nạp `optionsDefault` để Select hiện đúng nhãn khách/BĐS đang gắn.
- Các gotcha chung (base Model ép `''`/`0`, data-scope thủ công, lỗi nghiệp vụ `->setStatusCode(422)`, enum từ api/utils): xem [customer.md](customer.md) + [care.md](care.md) + [../database.md](../database.md) §2.
