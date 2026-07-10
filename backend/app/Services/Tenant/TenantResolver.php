<?php

namespace App\Services\Tenant;

/**
 * Resolve tenant từ URL (multi-tenant theo PREFIX — GĐ4 Bước 0).
 *
 * Chạy Ở INDEX.PHP, TRƯỚC khi framework boot: đọc segment đầu của URI → tra map slug=>db_prefix
 * (cache file `bootstrap/cache/tenants.php`, fallback query `core_tenants` rồi ghi cache) → cho
 * index.php set `DB_PREFIX` + rebind `path.cache` + cắt segment khỏi URI. Xem docs/features/multi-tenant.md.
 *
 * Nguyên tắc AN TOÀN / TƯƠNG THÍCH NGƯỢC:
 *  - CHƯA cấp phát tenant nào (không cache file, `core_tenants` chưa có/rỗng) ⇒ 'passthrough':
 *    app chạy y như bản 1-sàn (prefix từ .env, không cắt URI). Multi-tenant là OPT-IN.
 *  - Segment gốc dành riêng (api/uploads/schedule-run…) ⇒ passthrough (phục vụ ở root).
 *  - Đã có tenant nhưng slug lạ ⇒ 'not_found' (index.php trả 404 JSON).
 *
 * Toàn bộ dùng đường dẫn tuyệt đối tự tính (KHÔNG phụ thuộc hằng __ROOT__ vì có thể chạy trước
 * bootstrap/app.php) và KHÔNG phụ thuộc framework (dùng PDO/Dotenv trực tiếp).
 */
class TenantResolver
{
    /** Prefix CỐ ĐỊNH cho bảng trung tâm (tenants/plans). Không theo tenant. */
    const CENTRAL_PREFIX = 'core_';

    /**
     * Segment đầu KHÔNG được coi là tenant (tài nguyên / route gốc phục vụ ở root).
     * Cũng là danh sách cấm khi đặt slug lúc đăng ký (Bước 4).
     */
    const RESERVED = [
        'api', 'p', 'uploads', 'storage', 'portal', 'admin', 'static',
        'assets', 'schedule-run', 'login', 'www', 'app',
        'favicon.ico', 'robots.txt', 'serviceworker.js', 'index.php',
    ];

    /** Thư mục gốc backend (…/backend/) — tính từ vị trí file này (app/Services/Tenant). */
    public static function rootPath(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
    }

    public static function cacheFile(): string
    {
        return self::rootPath() . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'tenants.php';
    }

    /** Segment đầu của path (bỏ query, bỏ slash đầu, lowercase). */
    public static function firstSegment(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = ltrim((string) $path, '/');
        $seg  = explode('/', $path)[0] ?? '';
        return strtolower(rawurldecode($seg));
    }

    public static function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9-]{3,30}$/', $slug);
    }

    /** Map slug=>db_prefix từ file cache, hoặc null nếu chưa có file. */
    public static function loadMap(): ?array
    {
        $file = self::cacheFile();

        if (!is_file($file))
        {
            return null;
        }

        $map = require $file;

        return is_array($map) ? $map : null;
    }

    /** Ghi file cache map slug=>db_prefix (atomic: ghi tmp rồi rename). */
    public static function writeMap(array $map): void
    {
        $php = "<?php\n"
            . "// File SINH TỰ ĐỘNG bởi TenantProvisioner — KHÔNG sửa tay.\n"
            . "// Map slug => db_prefix để index.php resolve tenant khỏi query DB mỗi request.\n"
            . 'return ' . var_export($map, true) . ";\n";

        $file = self::cacheFile();
        $dir  = dirname($file);

        if (!is_dir($dir))
        {
            @mkdir($dir, 0775, true);
        }

        $tmp = $file . '.' . getmypid() . '.tmp';

        if (file_put_contents($tmp, $php, LOCK_EX) !== false)
        {
            @rename($tmp, $file);

            if (function_exists('opcache_invalidate'))
            {
                @opcache_invalidate($file, true);
            }
        }
    }

    /**
     * Rebuild map từ bảng `core_tenants` khi cache miss. Trả map (đồng thời ghi cache) hoặc
     * null nếu không tra được (bảng chưa tồn tại / lỗi kết nối) ⇒ coi như chưa bật multi-tenant.
     */
    public static function rebuildMapFromDb(): ?array
    {
        try
        {
            $env = self::readEnv();

            if (empty($env['DB_DATABASE']))
            {
                return null;
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $env['DB_HOST'] ?? '127.0.0.1',
                $env['DB_PORT'] ?? '3306',
                $env['DB_DATABASE'],
                $env['DB_CHARSET'] ?? 'utf8mb4'
            );

            $pdo = new \PDO($dsn, $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 3,
            ]);

            $table = self::CENTRAL_PREFIX . 'tenants';
            $rows  = $pdo->query("SELECT `slug`, `db_prefix` FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC);

            $map = [];
            foreach ($rows as $r)
            {
                $map[$r['slug']] = $r['db_prefix'];
            }

            self::writeMap($map);

            return $map;
        }
        catch (\Throwable $e)
        {
            return null; // chưa bật multi-tenant hoặc lỗi tra cứu → passthrough
        }
    }

    /** Đọc .env thành mảng (KHÔNG đụng $_ENV toàn cục) — chỉ để lấy DB creds khi cache miss. */
    protected static function readEnv(): array
    {
        if (!empty($_ENV['DB_DATABASE']))
        {
            return $_ENV; // bootstrap đã nạp env
        }

        try
        {
            return \Dotenv\Dotenv::createArrayBacked(rtrim(self::rootPath(), '/\\'))->load();
        }
        catch (\Throwable $e)
        {
            return [];
        }
    }

    /**
     * Resolve tenant từ $_SERVER. Trả một trong:
     *   ['mode' => 'passthrough']                          — không tenant (route/tài nguyên gốc hoặc chưa bật MT)
     *   ['mode' => 'tenant', 'slug' => …, 'prefix' => …]   — tenant hợp lệ
     *   ['mode' => 'not_found', 'slug' => …]               — MT đã bật nhưng slug lạ → 404
     */
    public static function resolve(array $server): array
    {
        $uri = $server['REQUEST_URI'] ?? '/';
        $seg = self::firstSegment($uri);

        if ($seg === '' || in_array($seg, self::RESERVED, true) || !self::isValidSlug($seg))
        {
            return ['mode' => 'passthrough'];
        }

        $map = self::loadMap();

        if ($map === null)
        {
            $map = self::rebuildMapFromDb(); // tự ghi cache nếu tra được
        }

        // Chưa bật multi-tenant → giữ hành vi 1-sàn cũ (tương thích ngược).
        if (empty($map))
        {
            return ['mode' => 'passthrough'];
        }

        if (isset($map[$seg]))
        {
            return ['mode' => 'tenant', 'slug' => $seg, 'prefix' => $map[$seg]];
        }

        return ['mode' => 'not_found', 'slug' => $seg];
    }

    /** Bỏ segment tenant khỏi REQUEST_URI: /sana/api/x?y=1 → /api/x?y=1 (chỉ 1 lần ở đầu). */
    public static function stripSegment(string $requestUri, string $slug): string
    {
        $prefix = '/' . $slug;
        $len    = strlen($prefix);

        if (strncasecmp($requestUri, $prefix, $len) === 0)
        {
            $rest = substr($requestUri, $len);

            if ($rest === '' || $rest[0] === '/' || $rest[0] === '?')
            {
                return $rest === '' ? '/' : $rest;
            }
        }

        return $requestUri;
    }
}
