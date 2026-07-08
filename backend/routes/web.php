<?php

/*
|--------------------------------------------------------------------------
| Web routes — ĐỂ TRỐNG trong base
|--------------------------------------------------------------------------
| Deployment headless: backend chỉ phục vụ REST API (routes/api.php), giao diện
| do React SPA (frontend/) đảm nhiệm. Các controller Web (HomeController,
| AuthController...) không tồn tại trong app/ nên KHÔNG khai route ở đây —
| khai route trỏ controller thiếu sẽ 500 khi truy cập.
*/
