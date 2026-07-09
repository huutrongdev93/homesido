# Danh mục phụ + Cấu hình (Catalog)

CRUD 4 danh mục dùng chung, gom vào 1 màn **Cấu hình** ở khu quản trị (`/admin/catalog`, cap
`permission`). Các danh mục này nạp vào dropdown ở form nghiệp vụ (Khách hàng / Bất động sản /
Chăm sóc). Mỗi danh mục: **đọc** mở cho view-cap tương ứng (để form của sales nạp select),
**ghi** (thêm/sửa/xóa) chỉ admin (`permission`).

## Bản đồ file (front-to-back)

```
Cấu hình danh mục (Catalog)
├─ FE  src/features/Catalog/pages/Catalog.js                 # trang Cấu hình: antd Tabs, mỗi tab 1 CatalogManager (cấu hình cột + field)
│  ├─ src/features/Catalog/components/CatalogManager.js      # generic: bảng + modal thêm/sửa ĐỘNG theo `fields` (text/textarea/select/switch/**number**); nhận hooks CRUD qua props. Nội dung mỗi tab bọc `.app-card` (nền trắng nổi trên canvas xám)
│  ├─ src/features/Catalog/style/Catalog.module.scss         # tabBar + rowActions/iconBtn
│  ├─ src/reduxs/api/catalogApiSlice.js                      # 4 danh mục × (get/add/update/delete); tags LeadSource/Project/PropertyOwner/CareTemplate
│  ├─ src/reduxs/api/apiSlice.js                             # 4 tag mới trong tagTypes
│  ├─ src/routes/PrivateRoutes.js                            # route /admin/catalog cap 'permission' (AdminLayout)
│  └─ src/layout/AdminSidebar/AdminNavData.js                # menu quản trị "Cấu hình danh mục" (gate useCan('permission'))
│  # Nối vào form (dropdown):
│  ├─ features/Customer/{pages/Customer.js, components/CustomerFormModal.js}   # select "Nguồn khách" (lead_source_id)
│  ├─ features/Property/{pages/Property.js, components/PropertyFormModal.js}   # select "Dự án" (project_id) + "Chủ nhà" (owner_id)
│  └─ features/Care/components/{CareFormModal.js, CareCompleteModal.js}        # select "Kịch bản" → prefill nội dung ({{ten_khach}})
└─ BE  routes/api.php (nhóm jwt, prefix api/lead-source|project|property-owner|care-template)
       ├─ app/Controllers/Api/LeadSourceApi.php     # index(customer_view) + add/update/destroy(permission); fields: name, is_active
       ├─ app/Controllers/Api/ProjectApi.php        # index(property_view) + ...; fields: name, developer, province_code, ward_code, address, description
       ├─ app/Controllers/Api/PropertyOwnerApi.php  # index(property_view) + ...; fields: full_name, phone, email, note
       ├─ app/Controllers/Api/CareTemplateApi.php   # index(customer_view) + ...; fields: name, channel(enum call/sms/zalo/email), content, stage, is_active
       ├─ app/Models/{LeadSource,Project,PropertyOwner,CareTemplate}.php
       └─ bảng DB: lead_sources, projects, property_owners, care_templates (đã có sẵn từ database/crm.php)
```

## Cap / Route / Response

- **Đọc** (`GET`, nạp dropdown ở form của sales): `lead-source`/`care-template` gate `customer_view`;
  `project`/`property-owner` gate `property_view`. **Ghi** (`POST`/`PUT`/`DELETE`): tất cả gate `permission`
  (admin) — đây là cấu hình danh mục. administrator/root bypass mọi cap.
- **Route** (đều `middleware('jwt')`): mỗi danh mục 4 route `GET/POST /api/<slug>`, `PUT/DELETE /api/<slug>/{id}`
  với slug: `lead-source`, `project`, `property-owner`, `care-template`.
- **Response list**: mảng phẳng (danh mục nhỏ, KHÔNG phân trang) — khác khuôn `{items,total,...}` của
  các module CRUD lớn. FE `transformResponse: body?.data || []`.

## Nối vào form nghiệp vụ

- **Persistence đã có sẵn từ trước** (không phải thêm ở bước này): `CustomerApi.collectInput` đọc
  `lead_source_id`; `PropertyApi.collectInput` đọc `project_id`/`owner_id`; `CareApi.add` đọc
  `care_template_id`. Bước này chỉ THÊM select ở FE + prefill.
- **Kịch bản chăm sóc (GĐ3 — chuỗi tự động)**: tab này có thêm field `auto_apply` (switch), `offset_days`
  (number — làm sau N ngày), `sort_order` (number). Template `auto_apply=1` → thuộc **chuỗi chăm sóc mặc định**
  tự áp cho khách mới (`CareSequence`, xem [care.md](care.md) §Chuỗi tự động). Vì vậy CatalogManager được bổ sung
  `type:'number'`.
- **Kịch bản chăm sóc**: chọn kịch bản trong CareFormModal / CareCompleteModal → prefill ô nội dung
  bằng `content` của kịch bản, thay biến `{{ten_khach}}` = tên khách; đồng thời set "Hình thức" theo
  `channel` của kịch bản (nếu là care type hợp lệ). CareCompleteModal cũng bổ sung ô **"Nội dung lịch
  tiếp"** (`next_content`) — trước đó payload có gửi field này nhưng thiếu input.

## Gotcha

- **CatalogManager là generic**: bảng + form sinh từ mảng `fields` (`{name,label,type,required,options}`).
  Type `switch` → `CheckBoxField` (onChange trả **event** → `field.onChange(e.target.checked)`; xem
  [care.md](care.md)). Thêm danh mục mới = thêm 1 tab trong `Catalog.js` + endpoint slice, không sửa
  CatalogManager.
- **Xóa CỨNG** (danh mục không có cột `trash`): khách/BĐS đang tham chiếu id đã xóa chỉ mất nhãn
  dropdown, không lỗi (id là int thường, không FK ràng buộc).
- **`channel` là ENUM** (`care_templates`): controller luôn whitelist về giá trị hợp lệ (mặc định
  `call`) trước khi ghi — an toàn với gotcha "enum + '' → Data truncated" ([../database.md](../database.md) §2).
- **`is_active` mặc định bật**: thiếu field (null/'') → 1; FE gửi bool từ CheckBoxField.
- **UTF-8**: tên tiếng Việt lưu bình thường (utf8mb4). (Khi test bằng curl trên Git Bash phải gửi body
  qua file UTF-8 `--data-binary @file`; truyền literal tiếng Việt inline bị mã hoá terminal làm hỏng JSON.)
- **Dropdown chỉ nạp bản active**: Customer form lọc `is_active` cho nguồn khách; Care form lọc kịch bản active.
