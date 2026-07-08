<?php
/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/
const __ROOT__ = __DIR__.'/../';

/*
| Tắt PutenvAdapter của dotenv: trên WAMP (Apache mod_php đa luồng + PHP bản TS),
| putenv() ghi vào environ DÙNG CHUNG cho cả process — các request boot song song
| (nhất là ngay sau khi Apache tái sinh worker) ghi đồng thời ~40 biến làm environ
| bị race, getenv() thi thoảng trả rỗng → fatal "JWT_PRIVATE_KEY is not configured"
| ngẫu nhiên (log php_error thấy lặp lại nhiều ngày). Tắt đi thì env chỉ đọc/ghi
| $_ENV/$_SERVER (mỗi request một bản riêng — thread-safe). Toàn bộ code app +
| skilldo/* đều dùng env() (không getenv() trực tiếp) nên tắt an toàn.
*/
\Illuminate\Support\Env::disablePutenv();

$app = \SkillDo\Application::configure(__ROOT__)
        ->withRouting(
            web: [
                __ROOT__.'routes/admin.php',
                __ROOT__.'routes/web.php',
            ],
            api: __ROOT__.'routes/api.php',
        )
        ->withMiddleware(function (\SkillDo\Configuration\Middleware $middleware) {
        })
        ->create();
/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;