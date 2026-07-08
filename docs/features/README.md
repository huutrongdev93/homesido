# Feature notes

Khi bạn quét source để hiểu một chức năng (nhằm dựng/sửa nó), **ghi lại vào một file
ở đây thay vì quét lại lần sau**. Mỗi file mô tả một chức năng end-to-end: các file
liên quan front-to-back (frontend `api`/`slice`/`saga`/`feature` → backend
`route` → `controller` → `model` → bảng DB) kèm đường dẫn + các lưu ý (gotcha).

Liên kết mỗi file mới từ mục "Feature notes index" trong `CLAUDE.md` để phiên sau
tìm được mà không phải quét lại.

## Quy tắc BẮT BUỘC — luôn đồng bộ với code

- **Tạo chức năng/module mới** → **BẮT BUỘC tạo mới** `docs/features/<feature>.md`
  (trace end-to-end + gotcha) ngay trong cùng lần sửa, và thêm dòng vào "Feature notes
  index" ở `CLAUDE.md`.
- **Chỉnh sửa chức năng cũ** → **BẮT BUỘC cập nhật** `docs/features/<feature>.md` cho
  khớp thay đổi; **nếu file chưa có thì tạo mới**.
- Thay đổi code mà không cập nhật feature note = **task chưa hoàn thành**.

## Cấu trúc mỗi file feature

- **BẮT BUỘC có "bản đồ file"** — cây file liên quan front-to-back, **kèm đường dẫn +
  1 dòng vai trò** cho mỗi file (FE `feature`/`api`/`slice`/`saga` → BE `route` →
  `controller` → `model` → bảng DB). Đây là phần giá trị nhất: giúp phiên sau nhảy
  thẳng vào đúng file, khỏi quét lại. Ví dụ:

  ```
  Login (đăng nhập)
  ├─ FE  src/features/Auth/pages/Login.jsx        # form
  │  ├─ src/api/authApi.js                        # login()/refresh()
  │  ├─ src/reduxs/Auth/authSlice.js              # state
  │  └─ src/utils/refreshToken.js                 # memoizedRefreshToken (gotcha: 401)
  └─ BE  routes/api.php → AuthController::login → User model → bảng oauth_access_tokens
  ```

- **Sơ đồ flow (mermaid/ASCII) là TUỲ CHỌN** — chỉ thêm khi luồng phức tạp/nhiều nhánh
  (vd login-as, refresh token rotation, web push qua service worker). Chức năng thường
  thì bỏ qua: sơ đồ tốn công cập nhật, dễ lệch pha với code. Doc để **định vị nhanh**,
  không phải để đẹp — ưu tiên đường dẫn chính xác + gotcha hơn hình vẽ.
- Ngoài bản đồ file: liệt kê **gotcha** (điểm dễ sai, quyết định thiết kế bất thường) và
  các **cap/route/env** liên quan.

## Cách dựng note — ưu tiên dùng subagent

Khi quét source để viết note, **ưu tiên giao cho một subagent** (`Explore` hoặc
`general-purpose`) thay vì tự đọc từng file: subagent đọc hàng chục file nhưng chỉ trả về
**kết luận (nội dung doc)**, không nhét "file dump" vào context chính → **đỡ tốn token** và
cho note chất lượng hơn (quét được sâu, rộng). Bản thân file note sau đó là cơ chế tiết
kiệm token cho các phiên sau: đọc 1 note ngắn thay vì quét lại cả cây source.