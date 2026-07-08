# Chuẩn data-fetching & state (frontend)

Chuẩn thống nhất cho dự án. Mục tiêu: **mỗi loại state có đúng 1 công cụ**, không nhồi mọi thứ vào Redux.

> Đây là chuẩn **bắt buộc tuân thủ** khi viết/sửa code frontend. Ví dụ trong tài liệu trỏ tới các
> feature/component **thực có trong base** (`Account`, `Auth`, `Permission`, `notificationApiSlice`).

## Chọn công cụ theo loại state

| Loại state                                                        | Ví dụ                            | Dùng                                                                    |
|-------------------------------------------------------------------|----------------------------------|-------------------------------------------------------------------------|
| Auth toàn cục                                                     | token, user hiện tại             | **Redux slice** (`reduxs/Auth`)                                         |
| Quyền (permissions)                                               | `useCan('permission')`           | **Context** `AppProvider`                                               |
| **Danh mục / CRUD server-state**                                  | bản ghi của module nghiệp vụ mới | **RTK Query** (`reduxs/api/*`) ✅ chuẩn                                  |
| Danh mục **tĩnh, đổi hiếm** (danh sách trạng thái, chức vụ, enum) | dùng nhiều nhưng ít đổi          | **`api/utils`** + localStorage theo `utilitiesKey` (xem mục riêng dưới) |
| State form                                                        | giá trị input, validate          | **react-hook-form** (+ `yup` khi cần schema)                            |
| UI tạm                                                            | modal mở/đóng, dòng chọn, filter | **local `useState`**                                                    |

Câu hỏi tự kiểm trước khi cho vào Redux: *"State này có màn khác đọc/ghi không, và có cần sống sau khi rời màn không?"* — cả hai "không" ⇒ dùng local state.

## RTK Query — chuẩn cho danh mục & CRUD server-state

Lý do chọn thay cho pattern `useState + useEffect + handleRequest` thủ công:
- **Cache & chia sẻ**: nhiều màn cùng một query chỉ gọi mạng 1 lần.
- **Auto-invalidation**: mutation `invalidatesTags` → mọi list liên quan tự refetch.
- Không phải tự viết saga/slice cho từng CRUD.

> Base hiện chỉ có 1 slice RTK Query: `reduxs/api/notificationApiSlice.js` (dùng làm **mẫu tham chiếu**).
> Module nghiệp vụ mới tạo slice CRUD của mình theo cấu trúc dưới.

### Cấu trúc (1 base + inject theo feature)

- `reduxs/api/apiSlice.js` — base `createApi`:
  - `axiosBaseQuery()` bọc `utils/http` (`request`) → **giữ nguyên interceptor Bearer + refresh 401**.
  - ⚠️ **Lỗi nghiệp vụ HTTP 200**: `response()->error()` của BE chỉ set `code` trong body (=400),
    **KHÔNG đổi HTTP status** (chỉ `->setStatusCode(...)` mới đổi). Nên `axiosBaseQuery` coi
    `body.status === 'error'` là lỗi → mutation `.unwrap()` ném đúng. BE nên dùng `->setStatusCode(422)`
    cho lỗi nghiệp vụ để nhất quán.
  - `tagTypes` khai báo tập trung (base có `'Notification'`); **module mới thêm tag của mình vào mảng này**.
  - `rtkErrorMessage(error, fallback)` — lấy message lỗi.
- `reduxs/api/<feature>ApiSlice.js` — `apiSlice.injectEndpoints(...)`, export hook.
  - query: `transformResponse: body => body?.data` (FE đã unwrap 1 lớp; body = `{status,code,message,data}`), `providesTags`.
  - mutation: `invalidatesTags`.
- Store (đã cấu hình sẵn): `reducer.js` có `[apiSlice.reducerPath]: apiSlice.reducer`; `store.js`
  `.concat(apiSlice.middleware)` + `setupListeners`. Chỉ cần **import** slice mới ở đâu đó (feature nạp
  nó) là endpoint được inject — không phải sửa store.

### Dùng trong component

```js
const {data = [], isLoading} = useGetThingsQuery();          // query (cache chung)
const [addThing, {isLoading: adding}] = useAddThingMutation();
try { await addThing(data).unwrap(); onSaved(); }
catch (e) { notification.error({description: rtkErrorMessage(e, '...')}); }
```

> Sau mutation **không** cần tự cập nhật list — tag invalidation lo việc refetch.

## Danh mục tĩnh — `api/utils` + `utilitiesKey`

`GET api/utils` (`UtilsApi::index`) trả về **những dữ liệu FE cần dùng thường xuyên nhưng ÍT khi
thay đổi** — ví dụ danh sách trạng thái, danh sách chức vụ, các enum/hằng dùng chung. Mục đích: FE
**lưu lại (localStorage) và chỉ gọi lại API khi có thay đổi**, tránh fetch mỗi lần dùng.

Cơ chế đồng bộ bằng **`utilitiesKey`** (một chuỗi hash của toàn bộ data):
- `UtilsApi::index` tính `key = md5(serialize($data))`, cache ở server (`utilsDataKey`) và trả kèm data.
- `AuthController::current` cũng trả `utilitiesKey` hiện tại về FE mỗi lần load user.
- FE (`context/AppProvider`) so `utilitiesKey` server gửi với `utilities-key` trong localStorage:
  **khác nhau → gọi `api/utils` nạp lại + lưu key mới; giống nhau → dùng thẳng bản đã lưu**, không gọi mạng.

Vì vậy khi thêm/sửa dữ liệu tĩnh này ở BE, chỉ cần data đổi là `md5` đổi → `utilitiesKey` đổi → FE tự
nạp lại ở lần load kế tiếp. **Đặt dữ liệu tĩnh dùng-nhiều-đổi-hiếm vào đây**, không tạo query riêng cho
mỗi danh mục cố định.

> Lưu ý: `api/utils` (index) cần đăng nhập (middleware `jwt`). Khác hẳn `api/utils/database` và
> `api/utils/run` (bật/tắt bằng `UTILS_API_OPEN`, xem `docs/database.md`).

## API không qua RTK Query (auth, role...)

Một số API nền tảng dùng **API layer kiểu hàm** ở `src/api/*Api.js` (`authApi`, `roleApi`, `utilsApi`)
+ helper `handleRequest` / `apiError` (`~/utils`), KHÔNG qua RTK Query:

```js
const [error, response] = await handleRequest(roleApi.add(data));
if (error) return apiError(error);
```

Mẫu thực tế: **`features/Permission`** (màn Phân quyền) dùng `roleApi` + `handleRequest`. Áp dụng kiểu
này cho các flow xác thực/hệ thống; CRUD nghiệp vụ mới thì ưu tiên RTK Query.

## Convention thư mục feature

```
features/<Feature>/
  pages/<Feature>.js                 ← logic trang (query/mutation + table/nội dung)
  components/
    Forms/<Feature>Form...js         ← form (dùng field dùng chung + react-hook-form)
  style/<Feature>.module.scss        ← scss theo feature (KHÔNG để trong pages/)
```

Mẫu trong base: `features/Account/components/Forms/*` (form info/password), `features/Permission/components/*`
(form gán quyền + thêm chức vụ).

## Chuẩn trang CRUD (khi dựng module mới)

- **Quyền gom 1 object** truyền xuống con: `const can = {add: useCan('x_add'), edit: useCan('x_edit'), delete: useCan('x_delete')};`
- **Modal mở/đóng = state object** + toggle generic (mở rộng nhiều modal dễ): `const [openModal, setOpenModal] = useState({addEdit:false});` — `itemEdit` giữ record đang sửa.
- **Gom handler vào 1 object `events`**: `openAdd/openEdit/save/delete/reload`. `save(data, item)` gộp thêm+sửa (`item?.id` → update, ngược lại add), `reload: () => refetch()`.
- **Form là presentational**: nhận `open / item / loading / onCancel / onSubmit(data, item)`; mutation/loading do page sở hữu. Dùng `<ModalForm>`.
- Xóa: `Popconfirm` inline, đi qua `events.delete`.

## Style (scss)

SCSS theo feature ở **`features/<Feature>/style/<Feature>.module.scss`**. Import: `import style from "../style/<Feature>.module.scss"`.

## Design system / UI dùng chung

- **Design tokens** (`assets/style/styles.scss` `:root`): chiều cao control (34/44/48px), bo góc, `--border-color`,
  `--surface`/`--surface-muted`, `--text-*`, `--shadow-*`, `--primary-gradient`. **Dùng biến thay vì hard-code**.
- **`Button`** (`~/components`) — chiều cao cố định theo token; nút chỉ icon tự thành hình vuông. Dùng thay `antd` `Button` trong feature.
- **`<PageHeader icon title subtitle actions />`** (`components/PageHeader`) — header trang chuẩn. Mẫu: `features/Permission/pages/Permission.js`.
- **`<ModalForm>`** (`components/ModalForm`) — modal form chuẩn (header/footer gradient, body trong scope `.form`). Helper `.mform-grid-2` chia 2 cột. Mẫu: `features/Account/components/Forms/AccountFormEditModal.js`.

### Field nhập liệu — BẮT BUỘC dùng field dùng chung (không antd trực tiếp)

> ⚠️ **Quy tắc bắt buộc để form đồng nhất.** Mọi form nhập liệu (kể cả form trong 1 trang, không chỉ modal)
> phải dùng **field dùng chung ở `~/components/Forms`** + **react-hook-form**, **KHÔNG** dùng `antd`
> `Input`/`Input.TextArea`/`InputNumber`/`Select` trực tiếp trong feature. Field dùng chung mới áp đúng token
> chiều cao/bo góc/`.form-control` + hiển thị lỗi (`.error-message`) theo scope `.form`.
>
> 🔒 **Được ENFORCE bằng ESLint** (`no-restricted-imports` trong `package.json` → `eslintConfig.overrides`,
> phạm vi `src/features/**`): import `Input/InputNumber/Select/AutoComplete/Cascader/TreeSelect/Mentions/DatePicker/TimePicker`
> từ `'antd'` trong feature sẽ **báo lỗi** (đỏ ở IDE + fail `npm run build`). `Switch`/`Checkbox`/`Radio` (công tắc)
> KHÔNG bị chặn. Ô tìm kiếm/lọc ở `layout/` cũng không bị chặn (rule chỉ áp `features/`). Ngoại lệ thật sự:
> `// eslint-disable-next-line no-restricted-imports`.

- **Field có sẵn** (`~/components/Forms`, xem `index.js`): `InputField`, `TextAreaField`, `NumberField`,
  `SelectField`, `DateField`, `DateRangeField`, `CheckBoxField`, `GroupCheckBoxField`, `GroupRadioField`,
  `GroupRadioButton`, `InputPriceField`, `ImageUploadField`, `FileUpload`, `FileList`, `DebounceSelect`.
  Mỗi field nhận `{name, label, placeholder, value, onChange, onBlur, errors}` → khớp `{...field}` của
  react-hook-form `Controller`; tự render `<label>` + `.form-group` + `.form-control` + `.error-message`.
- **Bọc trong scope `.form`**: trang tự dựng dùng `<form className="form" onSubmit={handleSubmit(onSubmit)}>`;
  modal dùng `<ModalForm>` (đã có scope `.form` ở body). Thiếu `.form` → field mất style chuẩn.
- **Khuôn mẫu** (khớp `features/Permission/components/PermissionFormAdd.js`):
  ```jsx
  const {control, handleSubmit, formState:{errors}} = useForm({defaultValues});
  <form className="form" onSubmit={handleSubmit(onSubmit)}>
    <Controller control={control} name="name" rules={{required:'Vui lòng nhập tên'}}
      render={({field}) => <InputField label="Tên chức vụ" errors={errors} {...field} />} />
    <Button primary type="submit" loading={loading}>Lưu</Button>
  </form>
  ```
- **Nút submit**: dùng `Button` dùng chung (`~/components`, hỗ trợ `primary/loading/disabled/leftIcon/type="submit"`), không dùng `antd` `Button`.

## Tiện ích dùng chung

- `useCan('cap')` — gate menu/nút theo quyền; `useIsAdmin()` — có phải quản trị viên.
- `handleRequest` + `apiError` (`~/utils`) — cho API **không** qua RTK Query (auth, role).
- `renderDate(ts, 'fullTime')` — nhận **unix timestamp** (API phải trả timestamp).
