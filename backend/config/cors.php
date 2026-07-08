<?php
/*
|--------------------------------------------------------------------------
| Cấu hình CORS cấp ứng dụng (ghi đè mặc định của framework)
|--------------------------------------------------------------------------
| Framework đã đăng ký sẵn middleware \SkillDo\Http\Middlewares\HandleCors.
| Middleware này CHỈ áp dụng CORS cho các path khớp với 'paths' dưới đây.
| Mặc định framework KHÔNG khai báo 'paths' → không path nào khớp → CORS bị bỏ
| qua. Vì vậy chỉ cần khai báo 'paths' ở đây là CORS hoạt động cho toàn bộ API.
|
| Cơ chế merge config (xem LoadConfiguration::configMerge):
|   - Mảng dạng list (allowed_origins, allowed_headers, allowed_methods...) được
|     NỐI THÊM vào giá trị mặc định của framework, KHÔNG thay thế.
|   - Vì framework đã đặt allowed_origins = ['*'] nên API mở cho mọi origin.
|     Điều này hợp lệ vì xác thực bằng Bearer JWT (không dùng cookie) và
|     supports_credentials = false.
*/

return [

    // Bật CORS cho các path này (Str::is pattern). SPA chỉ gọi /api/*.
    'paths' => [
        'api/*',
    ],

    // Bổ sung custom header mà frontend gửi kèm (utils/http.js: "loginAsToken").
    // Framework đã cho phép sẵn: Content-Type, Authorization, X-Requested-With.
    'allowed_headers' => [
        'loginAsToken',
    ],

    // Cache kết quả preflight (OPTIONS) ở trình duyệt trong 24h. Mặc định framework
    // để maxAge = 0 → trình duyệt gửi 1 request OPTIONS TRƯỚC MỖI request thật (vì API
    // cross-origin + có header Authorization/loginAsToken). Đặt max_age để trình duyệt
    // nhớ kết quả, bỏ hẳn OPTIONS lặp lại cho cùng endpoint → giảm một nửa số request.
    // Khoá đọc bởi fruitcake/php-cors (CorsService::setOptions: 'maxAge'|'max_age').
    'max_age' => 86400,
];
