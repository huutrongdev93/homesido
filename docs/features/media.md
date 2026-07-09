# Media Bất động sản + Kế toán dung lượng

Upload ảnh/video cho Bất động sản (bảng `property_media`) + **kế toán dung lượng theo từng nhân
viên** để phục vụ bán gói theo dung lượng. Mọi file upload **ghi lại dung lượng** (`size`) và **người
upload** (`user_id`); tổng byte của mỗi user lưu ở user meta `storage_used_bytes`. Xóa file / xóa
hẳn dữ liệu chứa media → **trừ dung lượng** tương ứng.

## Quyết định nghiệp vụ (đã chốt)
- **Quota theo TỪNG NHÂN VIÊN** (không phải toàn sàn): cộng/trừ theo `property_media.user_id`.
- **Xóa MỀM giữ media** (BĐS vào thùng rác vẫn giữ ảnh + vẫn tính dung lượng); chỉ **xóa HẲN**
  (`DELETE api/property/{id}?force=1`) mới purge file + hoàn dung lượng.
- **Ảnh đại diện** (`properties.cover_media_id`): nhân viên bấm ⭐ chọn 1 **ảnh** làm đại diện; không
  chọn (=0) thì tự lấy **ảnh đầu tiên** theo `sort_order`. BE giải sẵn URL `thumbnail` cho list/detail
  (không phải fetch media riêng); media trả cờ `is_cover` (ảnh đại diện hiệu lực, gồm cả fallback).

## Ảnh đại diện (cover)
- **Route** (`jwt`, cap `property_edit`, findOwned): `PUT api/property/{id}/cover` body `{media_id}` —
  `media_id>0` chỉ nhận **ảnh** thuộc chính BĐS (404 nếu không tồn tại, 422 nếu không phải ảnh);
  `media_id=0` = bỏ chọn (về ảnh đầu tiên). Xóa đúng ảnh đang là cover → BE tự reset `cover_media_id=0`.
- **Giải thumbnail (list)**: `PropertyMediaService::thumbnails($rows)` — 1 truy vấn `property_media`
  (`type='image'`) cho cả trang → `[pid => url]`; ưu tiên `cover_media_id` (nếu còn hợp lệ) else ảnh đầu.
  Dùng chung: danh sách BĐS + gợi ý Matching (match-properties / matches).
- **is_cover (detail/mediaIndex)**: `PropertyApi::effectiveCoverId($mediaRows, cover_media_id)` chọn id
  hiệu lực → `transformMedia($m, $coverId)` gắn `is_cover`. FE: badge "Đại diện" + nút ⭐ toggle
  (`useSetPropertyCoverMutation`, invalidate `PropertyMedia` + `Property`).

## Bản đồ file (front-to-back)

```
Media BĐS + Dung lượng
├─ FE  src/features/Property/pages/Property.js                 # nút "Ảnh/video" mỗi hàng → mở PropertyMediaModal; cột ảnh đại diện (record.thumbnail)
│  ├─ src/features/Property/components/PropertyMediaModal.js   # gallery: upload nhiều (FormData) + xóa + sắp xếp (up/down) + ⭐ đặt/bỏ ảnh đại diện (badge is_cover) + thanh dung lượng
│  ├─ src/features/Property/components/PropertyDetailPanel.js  # header dùng cover = p.thumbnail (BE giải sẵn) fallback ảnh đầu
│  ├─ src/features/Property/style/Property.module.scss         # style storageBar/mediaGrid/mediaThumb + thumbCell (list) + coverBadge/mediaCover/coverActive
│  ├─ src/reduxs/api/propertyApiSlice.js                       # getPropertyMedia/upload(FormData multipart)/delete/reorder + setPropertyCover + getStorageUsage; tags PropertyMedia/Storage
│  └─ src/reduxs/api/apiSlice.js                               # tag 'PropertyMedia','Storage'; axiosBaseQuery hỗ trợ headers (multipart)
└─ BE  routes/api.php  (prefix api/property, middleware jwt)   # +PUT /{id}/cover
       ├─ app/Controllers/Api/PropertyApi.php                 # mediaIndex/mediaUpload/mediaDelete/mediaReorder + setCover + transformMedia(is_cover)/effectiveCoverId; destroy(?force=1) purge
       ├─ app/Controllers/Api/StorageApi.php                  # GET api/storage — dung lượng đã dùng + hạn mức của user hiện tại
       ├─ app/Services/Storage/PropertyMediaService.php       # store (validate loại/size/quota → move → row → cộng dung lượng) / delete / purgeProperty / url() / thumbnails($rows) (giải ảnh đại diện lô)
       ├─ app/Services/Storage/StorageMeter.php               # used/add/subtract/quota/wouldExceed (user meta storage_used_bytes)
       ├─ app/Models/PropertyMedia.php
       ├─ database/media.php                                  # +cột size/user_id/mime_type/original_name (đăng ký sau crm.php)
       ├─ database/property-media-audio.php                   # ALTER enum property_media.type +'audio' (idempotent, sau media.php)
       ├─ .htaccess                                           # php_value post_max_size/upload_max_filesize (nâng giới hạn upload)
       └─ lưu file: backend/storage/uploads/properties/<hash>.<ext>  (phục vụ qua /uploads/... — .htaccess map)
```

## Cap / Route / Env

- **Cap**: xem media = `property_view` (qua `findViewable` — của mình / kho chung / view_all); ghi
  (upload/xóa/sắp xếp) = `property_edit` (qua `findOwned`). `GET api/storage` không cần cap (của chính mình).
- **Route** (`jwt`): `GET/POST api/property/{id}/media`, `PUT api/property/{id}/media/reorder`,
  `DELETE api/property/{id}/media/{mediaId}`, `DELETE api/property/{id}?force=1` (xóa hẳn + purge),
  `GET api/storage`.
- **Env**: `MEDIA_MAX_IMAGE_MB` (mặc định 10), `MEDIA_MAX_VIDEO_MB` (mặc định 100),
  `MEDIA_MAX_AUDIO_MB` (mặc định 50), `STORAGE_QUOTA_MB_PER_USER` (mặc định 0 = không giới hạn) — có
  default trong code.
- **Giới hạn PHP (QUAN TRỌNG)**: `MEDIA_MAX_*` chỉ là kiểm tra ở tầng app — file còn phải lọt qua
  `post_max_size`/`upload_max_filesize` của PHP TRƯỚC (mặc định WAMP 8M/2M → video/audio bị chặn với
  `PostTooLargeException` hoặc `isValid()=false`). Đã nâng trong `backend/.htaccess` (`<IfModule
  mod_php.c>`: upload 100M / post 120M / max_execution_time 300); nếu Apache chạy FastCGI thì sửa
  `php.ini` + restart.

## Luồng

- **Upload** (`POST .../media`, field `files[]`, multipart): mỗi file → `PropertyMediaService::store`:
  validate đuôi (image: jpg/jpeg/png/webp/gif; video: mp4/webm/mov/m4v; audio: mp3/wav/m4a/aac/ogg) +
  dung lượng tối đa + hạn mức
  user → `Str::random(32).ext` → `move` vào `storage/uploads/properties/` → insert row (size, user_id,
  mime, original_name, sort_order kế tiếp) → `StorageMeter::add(uploader, size)`. Gom lỗi từng file
  (`saved` + `errors[]`); cả lô fail mới trả 422.
- **Xóa 1 media**: xóa file vật lý + `StorageMeter::subtract(user_id, size)` + xóa row.
- **Sắp xếp**: FE gửi mảng `order` (id theo thứ tự) → cập nhật `sort_order`.
- **Xóa hẳn BĐS** (`?force=1`): `PropertyMediaService::purgeProperty` (xóa mọi file + hoàn dung lượng
  theo từng người upload + xóa row) rồi xóa CỨNG record. (Mặc định không `force` = xóa mềm, giữ media.)

## Gotcha

- **URL công khai**: `PropertyMediaService::url($path)` = `Url::base('uploads/'.$path)`; file lưu thật ở
  `storage/uploads/...` nhưng truy cập qua đường ảo **`/uploads/...`** (`.htaccess` map). DB `path` lưu
  tương đối (`properties/<file>`), KHÔNG lưu URL tuyệt đối (đổi domain vẫn đúng).
- **Đọc metadata TRƯỚC `move()`**: sau `move()` temp file biến mất → `getSize()`/`getMimeType()` lỗi;
  dùng `getClientMimeType()` (đọc từ header client) + đọc size/ext/name trước.
- **`StorageMeter` per-user**: `User::getMeta` trả **`''`** khi chưa có key → luôn ép `(int)`. Cộng/trừ
  là read-modify-write (chấp nhận cho tải đồng thời thấp); có `wouldExceed` để chặn vượt hạn mức khi upload.
- **FormData qua RTK Query**: mutation gửi `headers: {'Content-Type':'multipart/form-data'}` để axios tự
  set boundary (mặc định `http.js` là `application/json`). `axiosBaseQuery` truyền `headers` xuống axios.
- **Multipart khó test bằng curl trong sandbox** (HTTP 000/size_up=0) — đã verify logic server-side
  (StorageMeter add/delete/purge + xóa file vật lý + phục vụ `/uploads` HTTP 200) qua scratch; phần nhận
  `$request->file('files')` + `move()` là API chuẩn của Illuminate/Symfony (Request extends Laravel Request).
- **Media của khách hàng / dự án**: hiện CHƯA có (chỉ BĐS có media). `purgeProperty` là khuôn tái dùng;
  khi entity khác có media, viết `purgeXxx` tương tự + gọi khi xóa hẳn.
