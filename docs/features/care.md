# Chăm sóc chủ động (Care + Timeline)

Điểm khác biệt cốt lõi: sales đặt **lịch chăm sóc** cho khách → đến hạn hiện ở **"Cần chăm hôm nay"**
→ làm xong ghi kết quả (tự tạo 1 **tương tác** vào timeline + cập nhật `last_interaction_at`) + đặt
lịch tiếp. Gồm cả **timeline tương tác** của khách (xem/ghi trong drawer chi tiết khách).

Có **tick nền**: nhắc lịch đến hạn + phát hiện khách nguội + **auto-release** khách quá hạn khóa
(xem mục Tác vụ nền dưới). **Kịch bản chăm sóc** (care_templates) đã có CRUD + nối vào form: chọn
kịch bản trong CareFormModal/CareCompleteModal → prefill nội dung (thay `{{ten_khach}}`) — xem
[catalog.md](catalog.md). **GĐ3:** kịch bản có thể đánh dấu `auto_apply` + `offset_days` để **tự sinh
chuỗi lịch chăm cho khách mới** (xem §Chuỗi chăm sóc tự động dưới).

## Bản đồ file (front-to-back)

```
Chăm sóc chủ động (Care)
├─ FE  src/features/Care/pages/CareToday.js                       # "Cần chăm hôm nay": Table việc đến hạn/quá hạn (bọc `.app-card`) + nút Hoàn thành
│  ├─ src/features/Care/components/CareFormModal.js               # đặt lịch: type + scheduled_at (DateField showTime) + content
│  ├─ src/features/Care/components/CareCompleteModal.js           # hoàn thành: result_note + (tuỳ chọn) đặt lịch tiếp (checkbox)
│  ├─ src/features/Customer/components/CustomerDetailDrawer.js    # Drawer chi tiết khách: Lịch chăm + Timeline; host 3 modal
│  ├─ src/features/Customer/components/InteractionFormModal.js    # ghi 1 tương tác vào timeline
│  ├─ src/features/Customer/style/CustomerDetail.module.scss      # style drawer (care item + timeline)
│  ├─ src/features/Customer/pages/Customer.js                     # bấm tên khách → mở drawer (setDetail)
│  ├─ src/reduxs/api/careApiSlice.js                              # getCareToday/getCustomerCares/addCare/completeCare/cancelCare + getCustomerInteractions/addInteraction; tags ['Care','Interaction']
│  ├─ src/reduxs/api/apiSlice.js                                  # tags 'Care','Interaction'
│  ├─ src/routes/PrivateRoutes.js                                 # route /care cap 'customer_view'
│  ├─ src/layout/Sidebar/NavBarData.js                            # menu "Cần chăm hôm nay"
│  └─ src/context/AppProvider.js                                  # appData.care.{care_types, interaction_types}
└─ BE  routes/api.php  (prefix api/care + api/customer/{id}/interactions, middleware jwt)
       ├─ app/Controllers/Api/CareApi.php                         # index(?customer_id) / today / add / complete / cancel; scope theo assigned_user_id
       ├─ app/Controllers/Api/CustomerApi.php                     # interactions() / addInteraction() + touchInteraction(); add() hook CareSequence::applyAuto (khách mới); applyCareSequence() (áp thủ công)
       ├─ app/Services/Care/CareSequence.php                      # GĐ3: applyAuto — sinh care_schedules từ care_templates auto_apply=1 (offset_days, thay {{ten_khach}}, type=channel)
       ├─ database/care-sequence.php                              # migration cột offset_days/auto_apply/sort_order cho care_templates
       ├─ FE src/features/Catalog/* + CatalogManager (type 'number') # cấu hình kịch bản: offset_days/auto_apply/sort_order; nút "Áp kịch bản" ở CustomerDetailDrawer (useApplyCareSequenceMutation)
       ├─ app/Controllers/Api/UtilsApi.php::index                 # enum care.* cho FE
       ├─ app/Models/CareSchedule.php + CustomerInteraction.php
       └─ bảng DB: care_schedules, customer_interactions (database/crm.php)
```

## Cap / Route

- **Cap**: đọc = `customer_view`; ghi (đặt lịch / hoàn thành / hủy / ghi tương tác) = `customer_edit`.
  `customer_view_all` → thấy việc/timeline toàn sàn; không có → chỉ của mình (`assigned_user_id`).
- **Route care** (`jwt`): `GET api/care?customer_id=`, `GET api/care/today`, `POST api/care`,
  `PUT api/care/{id}/complete`, `DELETE api/care/{id}` (hủy = status canceled).
- **Route timeline** (`jwt`): `GET/POST api/customer/{id}/interactions`.

## Luồng "hoàn thành chăm sóc" (quan trọng)

`PUT api/care/{id}/complete` (body: `result_note`, tuỳ chọn `next_scheduled_at`/`next_type`/`next_content`):
1. care → `status=done`, `completed_at=now`, lưu `result_note`.
2. **Tạo 1 `customer_interaction`** (type = care.type, content = result_note, direction `out`) → vào timeline.
3. **Cập nhật khách** qua `Customer::touch()`: `last_interaction_at=now`, `is_cold_flagged=0` (gỡ cờ nguội), **gia hạn khóa** `locked_until=now+CUSTOMER_LOCK_DAYS` (đang chăm tích cực → không bị auto-release, xem [customer.md](customer.md)).
4. Nếu có `next_scheduled_at` → tạo lịch chăm mới (pending).
→ FE invalidate `['Care','Interaction','Customer']` để mọi nơi refetch.

## Chuỗi chăm sóc tự động (GĐ3 — tự động hóa)

**Bài toán:** trước đây mỗi lịch chăm phải đặt tay. Nay 1 "kịch bản mặc định" tự sinh cả chuỗi lịch cho khách mới.

- **Mô hình (mô hình 1 — chuỗi mặc định đơn):** mở rộng `care_templates` bằng 3 cột `offset_days` (làm sau N ngày),
  `auto_apply` (1=thuộc chuỗi mặc định), `sort_order`. Mỗi template `auto_apply=1` = 1 **bước**. Không thêm bảng
  mới, không cột trên `customers` — tái dùng 100% UI Catalog (thêm `type:'number'` cho CatalogManager).
- **Sinh chuỗi:** `App\Services\Care\CareSequence::applyAuto($customerId)` — duyệt template `is_active=1 & auto_apply=1`
  (order `sort_order,offset_days`), mỗi bước tạo 1 `care_schedules`: `scheduled_at = now + offset_days`, `content`
  thay `{{ten_khach}}`, `type = channel`, `care_template_id` = template, `status=pending`, giao cho sales của khách.
- **Kích hoạt:** (1) tự động — hook cuối `CustomerApi::add` (khách mới, fire-and-forget); (2) thủ công — `POST
  api/customer/{id}/apply-care-sequence` (cap `customer_edit`) qua nút **"Áp kịch bản"** ở drawer khách (cho khách cũ /
  khi đổi kịch bản). Lịch sinh ra được `care-reminder-tick` nhắc như thường.
- **An toàn:** chưa migrate cột `auto_apply` → `applyAuto` trả 0 (chưa bật). Không có template `auto_apply` nào → không sinh gì.
- **Giới hạn có chủ đích:** chỉ **1 chuỗi mặc định chung** (tập template `auto_apply`), chưa phải nhiều kịch bản có tên
  gán theo loại khách. Muốn đa kịch bản: cần bảng `care_sequences` + `care_sequence_steps` + `customers.care_sequence_id`
  + UI soạn bước (GĐ3.1).

## Tác vụ nền (Schedule — Bước 6)

Cron gọi `schedule-run` mỗi phút → chạy các tick due (xem `app/Console/schedule.php`).

| Tick | Chu kỳ | Service | Việc |
|---|---|---|---|
| `care-reminder-tick` | mỗi phút | `app/Services/Care/CareReminder.php` | Digest 1 thông báo/sales có việc `pending` đến hạn (`scheduled_at <= now`) qua `Notifier::sendUnique` (không spam mỗi phút; nhắc lại sau khi user đọc). Link `/care`. |
| `customer-cold-tick` | 07:00 hằng ngày | `app/Services/Care/ColdDetector.php` | Khách quá `CARE_COLD_DAYS` (env, mặc định 7) ngày không tương tác (`COALESCE(last_interaction_at, created)`) mà chưa cờ, không phải won/lost → `is_cold_flagged=1` + báo sales. Lô `BATCH=300`. |
| `customer-release-tick` | 01:00 hằng ngày | `app/Services/Care/CustomerRelease.php` | Khách `locked_until < now` (lâu không ai `touch`), `assigned_user_id > 0`, không won/lost → trả về kho chung (`assigned_user_id=0`, `locked_until=null`) + báo sales cũ. Lô `BATCH=300`. Xem [customer.md](customer.md) (Bước 3). |
| `lead-score-tick` | 02:00 hằng ngày | `app/Services/Customer/LeadScorer.php` | Chấm lại `lead_score` (0–100) TOÀN BỘ khách để phản ánh **suy giảm độ mới** tương tác theo thời gian. Đếm tương tác bằng 1 truy vấn gộp, duyệt khách theo lô `BATCH=500`, chỉ ghi khi điểm đổi. Xem [customer.md](customer.md). |

- **Config**: `CARE_COLD_DAYS` (env, default 7). Không cần khai — có default trong code.
- Tick nào cũng guard `schema()->hasTable(...)` → bảng chưa migrate thì thoát êm; lỗi được `schedule.php` bọc try/catch + `Log`.

## Gotcha

- **`GET api/care/today`**: lấy care `status=pending` AND `scheduled_at <= cuối ngày hôm nay` (nên **gồm
  cả quá hạn**), scope theo `assigned_user_id`. Trả kèm `customer{full_name,phone}` (nạp theo lô, không
  eager load) + cờ `overdue` (scheduled_at < đầu ngày hôm nay).
- ⚠️ **`DateField` trả unix mili-giây** (`date.valueOf()`) qua onChange → **FE phải format
  `dayjs(ts).format('YYYY-MM-DD HH:mm:ss')`** trước khi gửi; BE `CareApi::normalizeDatetime` dùng
  `strtotime` (không nhận epoch-ms). Xem CareFormModal/CareCompleteModal.
- **`CheckBoxField` onChange trả event** (không phải bool) → Controller phải `field.onChange(e.target.checked)`.
- Các gotcha chung (base Model, `Str::clear((string)…)`, "Invalid token" che exception, xóa mềm):
  xem [customer.md](customer.md), [property.md](property.md), [../database.md](../database.md) §2.
