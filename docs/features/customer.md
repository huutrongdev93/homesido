# Khách hàng (Customer — Core CRM)

Quản lý danh bạ khách hàng: CRUD + filter/phân trang + chống trùng SĐT + data-scope (của tôi /
toàn sàn) + xóa mềm. Là **vertical slice đầu tiên** xác lập khuôn CRUD cho mọi module CRM sau
(Bất động sản, Chăm sóc...). Nền tảng DB/schema: [`../database.md`](../database.md) §4.

## Bản đồ file (front-to-back)

```
Khách hàng (Customer)
├─ FE  src/features/Customer/pages/Customer.js              # trang list: antd Table + toolbar (search debounce + lọc giai đoạn) + phân trang server-side; gom can{add,edit,delete} + events{openAdd,openEdit,save,remove}
│  ├─ src/features/Customer/components/CustomerFormModal.js # form thêm/sửa (presentational): ModalForm + react-hook-form + yup; field dùng chung ~/components/Forms (InputField/SelectField/TextAreaField)
│  ├─ src/features/Customer/components/CustomerDetailDrawer.js # bấm tên khách → drawer chi tiết (lịch chăm + timeline). Xem [care.md](care.md)
│  ├─ src/features/Customer/style/Customer.module.scss      # style toolbar/search/filter/iconBtn (dùng design tokens)
│  ├─ src/reduxs/api/customerApiSlice.js                    # RTK Query: getCustomers/getCustomer/add/update/deleteCustomer; providesTags/invalidatesTags ['Customer']
│  ├─ src/reduxs/api/apiSlice.js                            # tag 'Customer' khai trong tagTypes
│  ├─ src/routes/PrivateRoutes.js                           # route { path:'/customers', component:Customer, cap:'customer_view' } (DefaultLayout — màn user)
│  ├─ src/layout/Sidebar/NavBarData.js                      # menu section "Kinh doanh" → "Khách hàng", gate useCan('customer_view')
│  └─ src/context/AppProvider.js                            # appData.customer.{pipeline_stages,temperatures,genders,demand_types} (enum tĩnh, cache theo utilitiesKey)
└─ BE  routes/api.php  (prefix api/customer, middleware jwt)
       ├─ app/Controllers/Api/CustomerApi.php               # index/detail/add/update/destroy + assertPhoneUnique + findOwned (scope) + collectInput + transform; + interactions()/addInteraction() (timeline — xem care.md)
       ├─ app/Controllers/Api/ApiController.php             # BASE dùng chung: paging()/respondList() (phân trang) + requireCap()/canViewAll() (gate + data-scope)
       ├─ app/Controllers/Api/UtilsApi.php::index           # trả enum tĩnh customer.* cho FE (utilitiesKey)
       ├─ app/Roles/RoleCapabilitiesCustomer.php            # cap: customer_view/add/edit/delete/view_all/transfer
       ├─ app/Roles/register.php                            # khai nhóm cap 'customer' (label "Khách hàng") vào màn Phân quyền
       ├─ app/Models/Customer.php                           # extends SkillDo Model + SoftDeletes (cột trash)
       ├─ app/Models/CustomerDemand.php                     # nhu cầu/tiêu chí (detail trả kèm demands[])
       └─ bảng DB: customers, customer_demands (database/crm.php)
```

## Cap / Route / Env

- **Cap** (gate trong controller, KHÔNG ở route; administrator/root bypass):
  `customer_view` (xem + vào route), `customer_add`, `customer_edit`, `customer_delete`,
  `customer_view_all` (xem toàn sàn — không có thì chỉ thấy `assigned_user_id = mình`),
  `customer_transfer` (bàn giao — chưa dùng, để Bước 3).
- **Route** (đều `middleware('jwt')`): `GET api/customer` (list, query: `page,pageSize,keyword,pipeline_stage,temperature,assigned_user_id`),
  `POST api/customer`, `GET api/customer/{id}`, `PUT api/customer/{id}`, `DELETE api/customer/{id}`.
- **Response list**: `{items, total, page, pageSize}` (khuôn `ApiController::respondList`).

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
