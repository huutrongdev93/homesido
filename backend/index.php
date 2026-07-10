<?php
include_once( __DIR__ . '/bootstrap/autoload.php' );

/*
|--------------------------------------------------------------------------
| Restore Authorization header bị Apache strip khỏi $_SERVER
|--------------------------------------------------------------------------
| Apache (mod_php / CGI) không tự chuyển Authorization header vào $_SERVER.
| Symfony Request xây HeaderBag từ $_SERVER['HTTP_*'] nên bị mất header này.
| Fix: bơm lại từ getallheaders() hoặc REDIRECT_HTTP_AUTHORIZATION.
*/
if (!isset($_SERVER['HTTP_AUTHORIZATION']))
{
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
    {
        // PHP-FPM / CGI qua mod_rewrite đặt vào REDIRECT_*
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    elseif (function_exists('getallheaders'))
    {
        $allHeaders = getallheaders();

        // getallheaders() trả về key case-insensitive tuỳ server
        foreach ($allHeaders as $name => $value)
        {
            if (strtolower($name) === 'authorization')
            {
                $_SERVER['HTTP_AUTHORIZATION'] = $value;
                break;
            }
        }
    }
}
/*
|--------------------------------------------------------------------------
| Resolve tenant (multi-tenant theo PREFIX — GĐ4 Bước 0)
|--------------------------------------------------------------------------
| Chạy TRƯỚC khi framework boot & TRƯỚC khi dựng Request (Symfony chụp $_SERVER lúc
| khởi tạo). Đọc segment đầu URL → map slug=>db_prefix (cache file, fallback query DB):
|  - tenant hợp lệ  → set DB_PREFIX (immutable Dotenv sẽ không ghi đè), rebind path.cache
|    sang thư mục riêng của tenant (cô lập TOÀN BỘ file-cache: table_columns_*, access_token_*,
|    system/roles — driver file không có prefix key), cắt segment /{slug} khỏi REQUEST_URI để
|    router khớp `api/...` như cũ, lưu bối cảnh tenant.
|  - slug lạ (MT đã bật) → 404 JSON.
|  - passthrough (chưa cấp phát tenant / segment gốc) → chạy y như bản 1-sàn.
| Xem docs/features/multi-tenant.md.
*/
$app = require_once __DIR__ . '/bootstrap/app.php';

$tenant = \App\Services\Tenant\TenantResolver::resolve($_SERVER);

if ($tenant['mode'] === 'not_found')
{
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 404,
        'message' => 'Không tìm thấy sàn (tenant) "' . $tenant['slug'] . '".',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($tenant['mode'] === 'tenant')
{
    // 1) Prefix bộ bảng của tenant (config/database.php đọc env('DB_PREFIX') khi LoadConfiguration).
    $_ENV['DB_PREFIX']    = $tenant['prefix'];
    $_SERVER['DB_PREFIX'] = $tenant['prefix'];

    // 2) Cô lập file-cache theo tenant: rebind path.cache sang thư mục con (trước mọi truy cập cache).
    //    CacheFile::setDir() yêu cầu thư mục PHẢI TỒN TẠI (realpath=false → throw) — tự tạo nếu chưa có.
    $tenantCacheDir = $app->make('path.base') . 'storage' . DIRECTORY_SEPARATOR . 'framework'
        . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . rtrim($tenant['prefix'], '_');

    if (!is_dir($tenantCacheDir))
    {
        @mkdir($tenantCacheDir, 0775, true);
    }

    $app->instance('path.cache', $tenantCacheDir);

    // 3) Bối cảnh tenant cho phần còn lại của app (build link, provisioning, PlanGate…).
    \App\Services\Tenant\TenantContext::set($tenant['slug'], $tenant['prefix']);
    $app->instance('tenant.slug', $tenant['slug']);
    $app->instance('tenant.prefix', $tenant['prefix']);

    // 4) Cắt /{slug} khỏi URI TRƯỚC khi dựng Request (route/controller giữ nguyên, không sửa).
    $stripped = \App\Services\Tenant\TenantResolver::stripSegment($_SERVER['REQUEST_URI'] ?? '/', $tenant['slug']);
    $_SERVER['REQUEST_URI'] = $stripped;

    // PATH_INFO (nếu server có đặt) cũng phải khớp — Symfony ưu tiên REQUEST_URI nhưng đồng bộ cho chắc.
    if (isset($_SERVER['PATH_INFO']))
    {
        $_SERVER['PATH_INFO'] = \App\Services\Tenant\TenantResolver::stripSegment($_SERVER['PATH_INFO'], $tenant['slug']);
    }
}

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
 */
$app->handleRequest(new \SkillDo\Http\Request($_GET, $_POST, [], $_COOKIE, $_FILES, $_SERVER));