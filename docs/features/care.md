# Chăm sóc chủ động (Care + Timeline)

Điểm khác biệt cốt lõi: sales đặt **lịch chăm sóc** cho khách → đến hạn hiện ở **"Cần chăm hôm nay"**
→ làm xong ghi kết quả (tự tạo 1 **tương tác** vào timeline + cập nhật `last_interaction_at`) + đặt
lịch tiếp. Gồm cả **timeline tương tác** của khách (xem/ghi trong drawer chi tiết khách).

Có **tick nền** (Bước 6): nhắc lịch đến hạn + phát hiện khách nguội (xem mục Tác vụ nền dưới).
Chưa làm: **auto-release** khách quá hạn khóa (gắn với khóa khách — Bước 3); **quản lý kịch bản**
care_templates (Bước 8) — hiện care form nhập nội dung tự do.

## Bản đồ file (front-to-back)

```
Chăm sóc chủ động (Care)
├─ FE  src/features/Care/pages/CareToday.js                       # "Cần chăm hôm nay": Table việc đến hạn/quá hạn + nút Hoàn thành
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
       ├─ app/Controllers/Api/CustomerApi.php                     # interactions() / addInteraction() + touchInteraction() (cập nhật last_interaction_at, gỡ cờ nguội)
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
3. **Cập nhật khách**: `last_interaction_at=now`, `is_cold_flagged=0` (gỡ cờ nguội).
4. Nếu có `next_scheduled_at` → tạo lịch chăm mới (pending).
→ FE invalidate `['Care','Interaction','Customer']` để mọi nơi refetch.

## Tác vụ nền (Schedule — Bước 6)

Cron gọi `schedule-run` mỗi phút → chạy các tick due (xem `app/Console/schedule.php`).

| Tick | Chu kỳ | Service | Việc |
|---|---|---|---|
| `care-reminder-tick` | mỗi phút | `app/Services/Care/CareReminder.php` | Digest 1 thông báo/sales có việc `pending` đến hạn (`scheduled_at <= now`) qua `Notifier::sendUnique` (không spam mỗi phút; nhắc lại sau khi user đọc). Link `/care`. |
| `customer-cold-tick` | 07:00 hằng ngày | `app/Services/Care/ColdDetector.php` | Khách quá `CARE_COLD_DAYS` (env, mặc định 7) ngày không tương tác (`COALESCE(last_interaction_at, created)`) mà chưa cờ, không phải won/lost → `is_cold_flagged=1` + báo sales. Lô `BATCH=300`. |

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
