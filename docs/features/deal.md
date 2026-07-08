# Giao dịch (Deal — GĐ2)

Quản lý **giao dịch** 1 khách mua/thuê 1 BĐS: vòng đời **deposit(cọc) → contract(hợp đồng) → completed(hoàn tất)**
/ **canceled(hủy)**. Chuyển giai đoạn **tự đổi `properties.status`**; theo dõi **các đợt thanh toán**
(`deal_payments`); **tính + chi hoa hồng** (`commissions`, 1 dòng/giao dịch cho sale phụ trách). Xoá mềm
(`trash`) như Customer/Property. Data-scope theo `assigned_user_id` (nhóm cap riêng `deal`).

## Bản đồ file (front-to-back)

```
Giao dịch (Deal)
├─ FE  src/features/Deal/pages/Deal.js                        # trang /deals: list phân trang + tìm mã + lọc giai đoạn; click mã → drawer; nút Tạo; hàng có Sửa/Xóa
│  ├─ src/features/Deal/components/DealFormModal.js           # tạo/sửa: DebounceSelect khách + BĐS + InputPriceField giá trị (triệu↔VNĐ) + % hoa hồng + hoa hồng (triệu, bỏ trống = tự tính) + ghi chú
│  ├─ src/features/Deal/components/DealDetailDrawer.js        # chi tiết: header (mã/giai đoạn/giá trị/khách/BĐS) + nút chuyển giai đoạn + đợt thanh toán (thêm/xóa + đã thu/còn lại) + hoa hồng (đánh dấu chi)
│  ├─ src/features/Deal/components/DealPaymentFormModal.js    # ghi 1 đợt thu: InputPriceField số tiền (triệu) + SelectField hình thức + DateField ngày thu + ghi chú
│  ├─ src/features/Deal/dealUtils.js                          # fmtMoney (VNĐ→tỷ/triệu) + DEAL_STATUS_TAG (màu tag giai đoạn)
│  ├─ src/features/Deal/style/Deal.module.scss               # toolbar/codeLink/rowActions + drawer (head/section/list/commission)
│  ├─ src/reduxs/api/dealApiSlice.js                          # getDeals(list)/getDeal(detail)/add/update/changeStatus/delete + addPayment/deletePayment + updateCommission; tag 'Deal' (+ 'Property' cho status)
│  ├─ src/reduxs/api/apiSlice.js                              # tag 'Deal' (thêm vào tagTypes)
│  ├─ src/routes/PrivateRoutes.js                             # route { path:'/deals', cap:'deal_view' }
│  ├─ src/layout/Sidebar/NavBarData.js                        # menu "Giao dịch" (Kinh doanh, gate useCan('deal_view'))
│  └─ src/context/AppProvider.js                             # appData.deal.{statuses, payment_methods, commission_statuses}
└─ BE  routes/api.php  (prefix api/deal, middleware jwt; gate cap trong controller)
       ├─ app/Controllers/Api/DealApi.php                     # index/detail/add/update/changeStatus/destroy + addPayment/deletePayment + updateCommission + scope (findDeal/findCustomerInScope/requireProperty) + applyPropertyStatus + syncCommission + resolveCommission + enrich/transform
       ├─ app/Controllers/Api/ApiController.php               # BASE: paging/respondList + requireCap/canViewAll
       ├─ app/Models/Deal.php                                 # SoftDeletes, table 'deals'
       ├─ app/Models/DealPayment.php                          # table 'deal_payments'
       ├─ app/Models/Commission.php                           # table 'commissions'
       ├─ app/Controllers/Api/UtilsApi.php::index             # enum deal.{statuses,payment_methods,commission_statuses}
       ├─ app/Controllers/Api/UtilsApi.php::database          # đăng ký 'database/deal.php' vào $migrations
       ├─ app/Roles/RoleCapabilitiesDeal.php                  # cap: deal_view/add/edit/delete/view_all + commission_manage
       ├─ app/Roles/register.php                              # $groups['deal'] = 'Giao dịch'
       └─ database/deal.php                                   # 3 bảng deals / deal_payments / commissions (guard hasTable)
```

## Route (đều `jwt`, gate cap trong controller)

- `GET  api/deal` — list phân trang. Filter `?keyword=`(mã) `&status= &customer_id= &property_id=`.
  Response `respondList`; mỗi item kèm `customer{full_name,phone}` + `property{code,title}`. Cap `deal_view`.
- `POST api/deal` — tạo. Bắt buộc `customer_id`(scope) + `property_id`; `value` VNĐ, `commission_rate` %,
  `commission_amount` VNĐ (bỏ trống → tự tính theo %). **Chặn** nếu BĐS đã `sold` (422). Tạo `status=deposit`
  + `deposit_at=now` → đặt BĐS `deposited` + sinh 1 dòng `commissions`. Cap `deal_add`.
- `GET  api/deal/{id}` — chi tiết + `payments[]` + `commission` + `paid_total`/`remaining`. Cap `deal_view`.
- `PUT  api/deal/{id}` — sửa (khách/BĐS/giá trị/hoa hồng/ghi chú) + đồng bộ lại commission. Cap `deal_edit`.
- `PUT  api/deal/{id}/status` — chuyển giai đoạn (body `status`). Điền mốc giai đoạn + đổi `properties.status`
  (deposit/contract→deposited; completed→sold|rented; canceled→available). Cap `deal_edit`.
- `DELETE api/deal/{id}` — xóa mềm; nếu đang cọc/hợp đồng → trả BĐS về `available`. Cap `deal_delete`.
- `POST api/deal/{id}/payments` — thêm đợt thu (`amount`>0, `method`, `paid_at`, `note`). Cap `deal_edit`.
- `DELETE api/deal/{id}/payments/{paymentId}` — xóa đợt thu (xóa cứng). Cap `deal_edit`.
- `PUT  api/deal/{id}/commission` — đánh dấu chi/chưa chi (body `status` pending|paid). Cap **`commission_manage`**.

## Nghiệp vụ chính

- **Auto đổi trạng thái BĐS** (`applyPropertyStatus`): mọi tạo/chuyển-giai-đoạn/xóa đều cập nhật `properties.status`.
  `sale`→`sold`, `rent`→`rented` khi completed (lấy `transaction_type` từ chính BĐS lúc tạo deal).
- **Hoa hồng** (`syncCommission` + `resolveCommission`): `commission_amount` = số nhập tay (nếu >0) else
  `value × rate/100`. Mỗi deal có đúng 1 dòng `commissions` (upsert theo `deal_id`), giữ nguyên `status` khi cập nhật deal.
- **Đã thu / còn lại**: `paid_total` = tổng `deal_payments.amount`; `remaining` = `value − paid_total` (≥0).

## Gotcha

- **Tiền lưu VNĐ, form nhập triệu**: `value`/`commission_amount`/`payment.amount` quy đổi ÷/×1e6 ở FE (như giá BĐS);
  `commission_rate` là % (không đổi). `InputPriceField` chỉ thêm dấu phẩy, trả số thô — quy đổi nằm ở reset/submit.
- **`deals` có `trash` + SoftDeletes** (khác Appointment) → Model `use SoftDeletes`, xóa mềm bằng `->trash()`,
  query tự loại bản trashed. **`result`/`method` là `string default ''`** (khuôn enum tuỳ chọn).
- **Chuyển giai đoạn linh hoạt** (không ép thứ tự): có thể lùi/tiến; mốc thời gian chỉ điền lần đầu tới giai đoạn đó.
- **`commission_manage`** tách khỏi `deal_edit`: sale sửa deal được nhưng chỉ quản lý/kế toán mới đánh dấu **chi** hoa hồng.
- **Route ordering**: nested `/{id}/payments/...` khai sau `/{id}` (không có collection route nào ngoài index nên an toàn).
- **Đăng ký migration**: `database/deal.php` phải nằm trong `$migrations` của `UtilsApi::database()` (sau `appointment.php`).
- Gotcha chung (base Model ép `''`/`0`, data-scope thủ công, lỗi nghiệp vụ `->setStatusCode(422)`, enum từ api/utils):
  xem [customer.md](customer.md) + [property.md](property.md) + [../database.md](../database.md) §2.
