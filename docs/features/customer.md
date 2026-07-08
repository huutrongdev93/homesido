# Khách hàng (Customer — Core CRM)

Quản lý danh bạ khách hàng: CRUD + filter/phân trang + chống trùng SĐT + data-scope (của tôi /
toàn sàn) + xóa mềm. Là **vertical slice đầu tiên** xác lập khuôn CRUD cho mọi module CRM sau
(Bất động sản, Chăm sóc...). Nền tảng DB/schema: [`../database.md`](../database.md) §4.

## Bản đồ file (front-to-back)

```
Khách hàng (Customer)
├─ FE  src/features/Customer/pages/Customer.js              # trang list: antd Table (bọc `.app-card` nền trắng để nổi trên canvas xám) + toolbar (search debounce + lọc giai đoạn) + phân trang server-side; gom can{add,edit,delete} + events{openAdd,openEdit,save,remove}
│  ├─ src/features/Customer/components/CustomerFormModal.js # form thêm/sửa (presentational): ModalForm + react-hook-form + yup; field dùng chung ~/components/Forms. Có select "Nguồn khách" (lead_source_id) — danh mục ở [catalog.md](catalog.md)
│  ├─ src/features/Customer/components/CustomerTransferModal.js # bàn giao khách: select nhân viên nhận (getAssignableUsers) + lý do; gate cap customer_transfer
│  ├─ src/features/Customer/components/CustomerDemandModal.js # form thêm/sửa 1 nhu cầu/tiêu chí (loại/mục đích/ngân sách/diện tích/PN/hướng/tỉnh-phường); ngân sách nhập TRIỆU ×/÷ 1e6 → VNĐ
│  ├─ src/features/Customer/components/CustomerDetailDrawer.js # bấm tên khách → drawer chi tiết (nhu cầu + lịch chăm + timeline + nút Bàn giao). Host CustomerDemandModal (CRUD nhu cầu). Xem [care.md](care.md)
│  ├─ src/features/Customer/style/Customer.module.scss      # style toolbar/search/filter/iconBtn (dùng design tokens)
│  ├─ src/reduxs/api/customerApiSlice.js                    # RTK Query: getCustomers/getCustomer/add/update/deleteCustomer; providesTags/invalidatesTags ['Customer']
│  ├─ src/reduxs/api/apiSlice.js                            # tag 'Customer' khai trong tagTypes
│  ├─ src/routes/PrivateRoutes.js                           # route { path:'/customers', component:Customer, cap:'customer_view' } (DefaultLayout — màn user)
│  ├─ src/layout/Sidebar/NavBarData.js                      # menu section "Kinh doanh" → "Khách hàng", gate useCan('customer_view')
│  └─ src/context/AppProvider.js                            # appData.customer.{pipeline_stages,temperatures,genders,demand_types} (enum tĩnh, cache theo utilitiesKey)
└─ BE  routes/api.php  (prefix api/customer, middleware jwt)
       ├─ app/Controllers/Api/CustomerApi.php               # index/detail/add/update/destroy + assertPhoneUnique + findOwned (scope) + collectInput + transform; + interactions()/addInteraction() (timeline); + demands()/addDemand()/updateDemand()/destroyDemand() + collectDemandInput()/findDemand() (nhu cầu); + transfer()/assignableUsers() (bàn giao) + applyScope()/isSharedUnlocked() (khóa + kho chung)
       ├─ app/Controllers/Api/ApiController.php             # BASE dùng chung: paging()/respondList() (phân trang) + requireCap()/canViewAll() (gate + data-scope)
       ├─ app/Controllers/Api/UtilsApi.php::index           # trả enum tĩnh customer.* cho FE (utilitiesKey)
       ├─ app/Roles/RoleCapabilitiesCustomer.php            # cap: customer_view/add/edit/delete/view_all/transfer
       ├─ app/Roles/register.php                            # khai nhóm cap 'customer' (label "Khách hàng") vào màn Phân quyền
       ├─ app/Models/Customer.php                           # extends SkillDo Model + SoftDeletes; + lockDays()/lockExpiry()/touch() (khóa khách + gia hạn khi có tương tác)
       ├─ app/Models/CustomerDemand.php                     # nhu cầu/tiêu chí (detail trả kèm demands[])
       ├─ app/Models/CustomerTransfer.php                   # lịch sử bàn giao (append-only): from/to/by/reason
       ├─ app/Services/Care/CustomerRelease.php             # tick customer-release-tick (01:00): trả khách quá hạn khóa về kho chung + báo sales cũ
       └─ bảng DB: customers, customer_demands, customer_transfers (database/crm.php)
```

## Cap / Route / Env

- **Cap** (gate trong controller, KHÔNG ở route; administrator/root bypass):
  `customer_view` (xem + vào route), `customer_add`, `customer_edit`, `customer_delete`,
  `customer_view_all` (xem toàn sàn — không có thì chỉ thấy `assigned_user_id = mình` + kho chung chưa khóa),
  `customer_transfer` (bàn giao/thu hồi khách — dùng cho `transfer`/`assignableUsers`).
- **Route** (đều `middleware('jwt')`): `GET api/customer` (list, query: `page,pageSize,keyword,pipeline_stage,temperature,assigned_user_id`),
  `POST api/customer`, `GET api/customer/users` (danh sách nhân viên nhận bàn giao — đặt TRƯỚC `/{id}`),
  `GET api/customer/{id}`, `PUT api/customer/{id}`, `DELETE api/customer/{id}`,
  `POST api/customer/{id}/transfer` (body `to_user_id`,`reason`).
  **Nhu cầu/tiêu chí** (`customer_demands`): `GET api/customer/{id}/demands` (đọc, cap `customer_view`),
  `POST api/customer/{id}/demands` · `PUT api/customer/{id}/demands/{demandId}` · `DELETE …/{demandId}`
  (ghi, cap `customer_edit`). Xóa nhu cầu là **xóa CỨNG** (bảng không có cột `trash`).
- **Response list**: `{items, total, page, pageSize}` (khuôn `ApiController::respondList`).
- **Env**: `CUSTOMER_LOCK_DAYS` (số ngày khóa khách, mặc định 7 — có default trong code, không cần khai).

## Khóa khách + Bàn giao + Kho chung (Bước 3)

- **Khóa khách (`locked_until`)**: nhận/tạo khách (`add`), auto-claim (sales chạm khách kho chung
  ở `update`/`addInteraction`), hoặc bàn giao (`transfer`) → set `locked_until = now + CUSTOMER_LOCK_DAYS`.
  Mỗi tương tác/chăm sóc gọi **`Customer::touch()`** (dùng chung ở `CustomerApi::addInteraction` +
  `CareApi::complete`) để cập nhật `last_interaction_at` + gỡ cờ nguội + **GIA HẠN khóa** (đang chăm
  tích cực → không bị auto-release).
- **Kho chung (`assigned_user_id = 0`)**: khách chưa ai phụ trách. Sales thường (không `view_all`)
  thấy được khách của mình **HOẶC** kho chung chưa khóa (`applyScope`/`isSharedUnlocked`). Sales chạm
  (sửa/ghi tương tác) khách kho chung → **tự nhận** (auto-claim: set `assigned_user_id = mình` + khóa).
- **Bàn giao (`POST /{id}/transfer`, cap `customer_transfer`)**: đổi `assigned_user_id` sang nhân
  viên nhận (`assertAssignable` chặn tài khoản admin/root/bị vô hiệu), reset khóa, ghi 1 dòng
  `customer_transfers`, `Notifier::send` cho cả người nhận + người giao cũ. FE: nút "Bàn giao" trong
  drawer chi tiết → `CustomerTransferModal` (select nhân viên từ `GET api/customer/users`).
- **Auto-release (`customer-release-tick`, 01:00 hằng ngày)**: `App\Services\Care\CustomerRelease` —
  khách `locked_until < now` (lâu không ai `touch`), `assigned_user_id > 0`, không won/lost → trả về
  kho chung (`assigned_user_id = 0`, `locked_until = null`) + báo sales cũ. Lô `BATCH = 300`.

## Nhu cầu / tiêu chí (customer_demands)

- 1 khách có **nhiều nhu cầu** (mua/thuê/bán/ký gửi). Mỗi nhu cầu là bộ tiêu chí tìm BĐS: `demand_type`,
  `property_type` (enum `property.property_types`), `purpose` (`live`/`invest` — enum `customer.purposes`),
  `province_code`/`ward_code` (LocationApi), `budget_min/max` (VNĐ), `area_min/max` (m²), `bedrooms_min`,
  `direction` (enum `property.directions`), `is_active`. **Nền cho Matching GĐ2** (`property_customer_matches`).
- **CRUD trong drawer chi tiết khách** (section "Nhu cầu / tiêu chí"): `CustomerDemandModal` (thêm/sửa) +
  nút Xóa (confirm). RTK Query tag **`Demand`** — mutation invalidate → danh sách nhu cầu tự refetch.
  `detail()` vẫn trả kèm `demands[]` (dùng chung helper `transformDemand`/`listDemands`).
- **Ngân sách nhập theo TRIỆU trên form** → `× 1e6` khi lưu, `÷ 1e6` khi nạp (DB lưu VNĐ — đồng nhất với
  `properties.price` để Matching so trực tiếp). Giống convention giá ở `PropertyFormModal`.
- BE whitelist mọi enum trong `collectDemandInput` (giá trị lạ → default/`''`); `findDemand` chặn sửa/xóa
  nhu cầu không thuộc đúng khách (404). Ghi/xóa scope qua `findOwned` như mọi thao tác khách.

## Gotcha

- ⚠️ **Base Model tự điền `''`/`0` mọi cột khi `create()`** → enum tùy chọn phải là `string default ''`,
  decimal phải `default(0)` (xem [`../database.md`](../database.md) §2). Vì vậy `customers.gender` là
  **string** (`''`=chưa chọn), không phải enum. `collectInput()` gửi `''`/`0` thay vì `null`.
- **KHÔNG có Global Scope tenant/owner như Laravel** → data-scope áp THỦ CÔNG ở mọi query list qua
  `canViewAll('customer_view_all')` + `where('assigned_user_id', userId())`. `findOwned()` chặn
  truy cập chéo (403 nếu khách không thuộc mình mà không có view_all).
- **Xóa mềm**: dùng `Customer::where('id',$id)->trash()` (macro của trait SoftDeletes, set `trash=1`);
  `delete()` là **xóa CỨNG**. Global scope tự lọc `trash=0`; `onlyTrashed()`/`withTrashed()` để truy cập bản đã xóa.
- **Chống trùng SĐT** xét TOÀN sàn (kể cả khách người khác), trả 422 kèm tên khách đang giữ SĐT.
- **Enum hiển thị**: nhãn VN lấy từ `appData.customer.*` (api/utils), không hardcode ở FE. Thêm/bớt
  enum ở `UtilsApi::index` → `utilitiesKey` đổi → FE tự nạp lại ở lần load kế.
- **Lỗi nghiệp vụ dùng `->setStatusCode(422)`** (không chỉ `->error()`) để `axiosBaseQuery` coi là lỗi
  → mutation `.unwrap()` ném đúng ở FE.
- **Ô tìm kiếm** trong feature dùng `<input className="form-control">` thuần (không phải antd `Input` —
  bị ESLint chặn trong `features/`); `SelectField` dùng chung thì được phép.
