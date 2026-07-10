<?php

namespace App\Services\Tenant;

use Illuminate\Support\Str;
use SkillDo\Cache\Cache;
use SkillDo\Cms\Models\User;
use SkillDo\Database\DB;
use SkillDo\Support\Auth;

/**
 * Cấp phát tenant (multi-tenant theo PREFIX — GĐ4 Bước 0/1).
 *
 * "Tạo tenant" = insert 1 dòng `core_tenants` + chạy bộ migration per-tenant dưới prefix mới
 * (tái dùng cơ chế idempotent của `utils/database`) + seed tài khoản admin của sàn + ghi lại
 * file cache map slug=>prefix. Không thao tác hạ tầng (không tạo DB/vhost). Xem
 * docs/features/multi-tenant.md.
 *
 * GOTCHA QUAN TRỌNG (vì sao `withPrefix` phải set CẢ hai prefix):
 *  - Query builder Illuminate (`DB::table`, `Schema()`) đọc prefix từ CONNECTION → `setTablePrefix()`.
 *  - Nhưng Model của SkillDo (User, …) đọc prefix từ `env('DB_PREFIX')` lúc khởi tạo instance
 *    (không theo connection). Provisioning chạy ở request GỐC (passthrough) nơi env prefix vẫn là
 *    mặc định, nên phải tạm set `$_ENV['DB_PREFIX']` = prefix tenant thì `User::updateMeta` mới ghi
 *    đúng bảng `{prefix}users_metadata`. `withPrefix` set + khôi phục cả hai + flush cache 2 đầu
 *    (cache cột `table_columns_*` theo tên bảng KHÔNG prefix nên phải flush khi đổi prefix).
 */
class TenantProvisioner
{
    const CENTRAL_PREFIX = TenantResolver::CENTRAL_PREFIX;

    /** Tạo bảng trung tâm (`core_tenants`) ở prefix cố định `core_`. Idempotent. */
    public static function ensureCentralTables(): void
    {
        self::withPrefix(self::CENTRAL_PREFIX, function () {
            $migration = require TenantResolver::rootPath() . 'database/tenant.php';
            $migration->up();
        });
    }

    /**
     * Cấp phát 1 tenant mới. Trả ['slug','prefix','admin_username','admin_password'].
     * `admin_password` chỉ có giá trị khi tài khoản admin vừa được tạo (lưu lại, đổi ngay).
     *
     * @throws \Exception nếu slug không hợp lệ / trùng.
     */
    public static function create(string $slug, string $name = '', string $planCode = '', array $adminOverrides = []): array
    {
        $slug = strtolower(trim($slug));

        if (!TenantResolver::isValidSlug($slug))
        {
            throw new \Exception('Mã sàn (slug) không hợp lệ: chỉ gồm chữ thường/số/gạch ngang, dài 3–30 ký tự.');
        }

        if (in_array($slug, TenantResolver::RESERVED, true))
        {
            throw new \Exception('Mã sàn "' . $slug . '" trùng từ khóa hệ thống, vui lòng chọn mã khác.');
        }

        self::ensureCentralTables();

        $prefix = str_replace('-', '_', $slug) . '_';

        $exists = self::withPrefix(self::CENTRAL_PREFIX, fn () =>
            DB::table('tenants')->where('slug', $slug)->orWhere('db_prefix', $prefix)->first()
        );

        if (hasItems($exists))
        {
            throw new \Exception('Mã sàn "' . $slug . '" đã tồn tại.');
        }

        // 1) Đăng ký vào sổ trung tâm.
        self::withPrefix(self::CENTRAL_PREFIX, function () use ($slug, $name, $prefix, $planCode) {
            DB::table('tenants')->insert([
                'slug'      => $slug,
                'name'      => $name !== '' ? $name : $slug,
                'db_prefix' => $prefix,
                'plan_code' => $planCode,
                'status'    => 'active',
            ]);
        });

        // 2) Dựng bộ bảng của tenant + seed admin dưới prefix tenant (cả env lẫn connection).
        $adminPassword = self::withPrefix($prefix, function () use ($adminOverrides) {
            $migrations = require TenantResolver::rootPath() . 'database/migrations.php';

            foreach ($migrations as $file)
            {
                $migration = require TenantResolver::rootPath() . $file;
                $migration->up();
            }

            Cache::flush();

            return self::seedAdmin($adminOverrides);
        });

        // 3) Cập nhật file cache để index.php resolve được ngay.
        self::rebuildCache();

        return [
            'slug'           => $slug,
            'prefix'         => $prefix,
            'admin_username' => $adminOverrides['username'] ?? 'admin',
            'admin_password' => $adminPassword,
        ];
    }

    /** Danh sách tenant (cho central API / kiểm chứng). */
    public static function all(): array
    {
        self::ensureCentralTables();

        return self::withPrefix(self::CENTRAL_PREFIX, fn () =>
            DB::table('tenants')->orderBy('id')->get()->toArray()
        );
    }

    /** Ghi lại `bootstrap/cache/tenants.php` từ `core_tenants`. Trả map slug=>prefix. */
    public static function rebuildCache(): array
    {
        self::ensureCentralTables();

        $rows = self::withPrefix(self::CENTRAL_PREFIX, fn () =>
            DB::table('tenants')->get()
        );

        $map = [];

        foreach ($rows as $row)
        {
            $map[$row->slug] = $row->db_prefix;
        }

        TenantResolver::writeMap($map);

        return $map;
    }

    /**
     * Chạy $fn với CẢ connection prefix LẪN env prefix = $prefix, rồi khôi phục + flush cache 2 đầu.
     * (Xem gotcha ở docblock lớp: Model SkillDo đọc prefix từ env, query builder đọc từ connection.)
     */
    protected static function withPrefix(string $prefix, callable $fn)
    {
        $conn          = app('db')->getConnection();
        $oldConnPrefix = $conn->getTablePrefix();
        $oldEnvPrefix  = $_ENV['DB_PREFIX'] ?? null;
        $oldSrvPrefix  = $_SERVER['DB_PREFIX'] ?? null;

        $conn->setTablePrefix($prefix);
        $_ENV['DB_PREFIX']    = $prefix;
        $_SERVER['DB_PREFIX'] = $prefix;

        Cache::flush();

        try
        {
            return $fn();
        }
        finally
        {
            $conn->setTablePrefix($oldConnPrefix);

            if ($oldEnvPrefix === null) { unset($_ENV['DB_PREFIX']); }    else { $_ENV['DB_PREFIX'] = $oldEnvPrefix; }
            if ($oldSrvPrefix === null) { unset($_SERVER['DB_PREFIX']); } else { $_SERVER['DB_PREFIX'] = $oldSrvPrefix; }

            Cache::flush();
        }
    }

    /** Seed admin (id 1) nếu bảng users của tenant đang trống. Trả mật khẩu vừa sinh hoặc null. */
    protected static function seedAdmin(array $overrides): ?string
    {
        if ((int) DB::table('users')->count() > 0)
        {
            return null;
        }

        $password = $overrides['password'] ?? Str::random(16);
        $username = $overrides['username'] ?? 'admin';

        DB::table('users')->insert([
            'id'        => 1,
            'username'  => $username,
            'password'  => Auth::generatePassword($password),
            'salt'      => Str::random(32),
            'firstname' => $overrides['firstname'] ?? 'Quản trị',
            'lastname'  => $overrides['lastname'] ?? 'sàn',
            'email'     => $overrides['email'] ?? '',
            'phone'     => $overrides['phone'] ?? '',
            'status'    => 'public',
            'role'      => 'administrator',
        ]);

        // Cap qua meta — nhận diện siêu quản trị (env prefix đã = tenant ⇒ ghi đúng {prefix}users_metadata).
        User::updateMeta(1, 'capabilities', ['administrator' => 1]);

        return $password;
    }
}
