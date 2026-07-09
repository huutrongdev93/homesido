# Thông báo & Web Push

Thông báo in-app (chuông) + đẩy Web Push tới PC/mobile qua service worker (VAPID + aes128gcm, tự triển khai, không thư viện ngoài). Mọi tiến trình nền báo user qua `Notifier::send(...)`.

## Luồng tổng quát

```
Sự kiện (care tick, matching, deal…) 
  → Notifier::send($userId, $type, $title, $message, $link)
      ├─ ghi thông báo in-app (bảng notifications, tỉa 100/user)   → chuông FE
      └─ PushQueue::enqueue()  → 1 job/thiết bị vào push_queue
                                   → tick `push-tick` (mỗi phút, schedule.php)
                                        → WebPushClient::send() POST tới push service
                                             → service worker `push` event → showNotification
```

## Bản đồ file

**Frontend**
- `frontend/public/serviceWorker.js` — SW đăng ký ở scope gốc (index.html). Handler `push` (hiển thị OS notification) + `notificationclick` (focus/navigate tab). Payload JSON `{type,title,message,link}`. Đổi `CACHE_NAME` khi sửa để thay SW cũ.
- `frontend/src/utils/pushNotifications.js` — `pushSupported/pushPermission/subscribePush/unsubscribePush`. `subscribePush(publicKey)` xin quyền + `PushManager.subscribe({applicationServerKey})`. Cần HTTPS (hoặc localhost); iOS phải cài PWA.
- `frontend/src/layout/NotificationBell` — chuông + nút bật/tắt push (gọi config/subscribe/unsubscribe).
- FE lấy `publicKey` từ `GET api/notifications/push/config` (chính là VAPID_PUBLIC_KEY hiện tại của server).

**Backend**
- `app/Controllers/Api/PushController.php` — `config` (báo push đã bật + publicKey), `subscribe` (upsert theo `md5(endpoint)`, chặn SSRF, tối đa 10 thiết bị/user), `unsubscribe`. Route `api/notifications/push/*` (middleware jwt).
- `app/Models/PushSubscription.php` — bảng `push_subscriptions` (1 dòng/thiết bị): `endpoint, endpoint_hash, p256dh, auth, user_agent`.
- `app/Services/Notification/Notifier.php` — đầu ra chuẩn: ghi in-app + enqueue push, fire-and-forget (nuốt lỗi).
- `app/Services/Notification/PushQueue.php` — hàng đợi `push_queue`. `enqueue()` tạo job/thiết bị; `tick()` claim lô 50 (lockForUpdate), gửi tuần tự, retry ≤3, dọn job cũ ≥3 ngày, reset job 'sending' kẹt >5'. Xoá subscription khi `gone`.
- `app/Models/PushJob.php` — model `push_queue`.
- `app/Services/Notification/WebPushClient.php` — gửi 1 push: VAPID JWT ES256 (`vapidJwt`/`privateKeyPem`) + mã hoá payload aes128gcm (`encryptPayload`: ECDH với p256dh → HKDF → AES-128-GCM). `send()` trả `{ok,gone,retry,message}`.
- `app/Console/schedule.php` — task `push-tick` (everyMinute) gọi `PushQueue::tick()`. Cron gọi `schedule-run` mỗi phút mới chạy.
- `.env`: `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` (base64url raw P-256) / `VAPID_SUBJECT` (mailto:). `PUSH_SSL_VERIFY`, `PUSH_CA_BUNDLE` tuỳ chọn.

## Gotcha (đã gặp thực tế)

1. **Không có push nào gửi đi ⇒ trước hết xem cron.** `push-tick` chỉ chạy khi có cron gọi `schedule-run` mỗi phút. Máy dev không có cron → `push_queue` đọng mãi ở `pending`. Kiểm tra nhanh: đếm job theo `status` trong `push_queue`.

2. **Windows/WAMP: `openssl_pkey_new` thất bại** với lỗi khó hiểu `error:...:BIO routines::no such file` / `system library::No such process` → job `failed` với message "Không tạo được khoá ECDH". Nguyên nhân: PHP không tìm thấy `openssl.cnf` (biến `OPENSSL_CONF` trống) nên OpenSSL 3 không nạp được provider. **Fix đã làm** trong `WebPushClient::ecKeyArgs()`: thử không config trước (Linux prod OK), fail thì dò `openssl.cnf` thật (env `OPENSSL_CONF_PATH` → biến OS → suy từ `php_ini_loaded_file` layout WAMP → vị trí WAMP/XAMPP phổ biến), xác thực bằng cách tạo thử khoá, cache lại. Chỉ đường gửi `openssl_pkey_new` cần config; ký ES256/ECDH/nạp khoá thì không. Có thể khai `OPENSSL_CONF_PATH` trong `.env` để chỉ tay.

3. **Edge/WNS trả 401, Chrome/FCM lại OK.** WNS bind channel với `applicationServerKey` lúc subscribe và kiểm tra chặt: nếu server ký bằng VAPID key khác lúc thiết bị đăng ký → 401 `X-WNS-ERROR-DESCRIPTION: The public key used to sign JWT does not match with the one included in channel Url`. FCM dễ tính, chấp nhận. Xảy ra khi VAPID key đổi sau khi thiết bị đã subscribe (subscription cũ lệch khoá). **Fix:** `WebPushClient::send()` giờ coi **403** và **401 (kèm dấu hiệu "public key"/"channel" trong header WNS)** là `gone` → xoá subscription lệch khoá để thiết bị đăng ký lại. Người dùng chỉ cần **tắt rồi bật lại thông báo** trên trình duyệt đó để tạo channel mới khớp khoá hiện tại. (401 khác — nghi cấu hình VAPID sai toàn cục — vẫn cho retry, KHÔNG xoá hàng loạt.)

4. **Kiểm tra cặp VAPID khớp nhau:** public suy từ private phải bằng `VAPID_PUBLIC_KEY`. Lệch ⇒ mọi push 401. (Trong sự cố đã gặp thì cặp khớp; lỗi nằm ở subscription cũ lệch khoá.)

## Chẩn đoán nhanh (scratch)

`GET api/utils/run` (khi `UTILS_API_OPEN=true`) là nơi nhét code soi `push_subscriptions`/`push_queue` và chạy `PushQueue::tick()` thủ công. Xem git history của `UtilsApi::run` để lấy lại đoạn diag nếu cần.
