<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Migration BẢNG TRUNG TÂM multi-tenant (GĐ4 — Bước 0).
 *
 * `tenants` là sổ đăng ký các sàn (mỗi dòng = 1 tenant). Bảng này KHÔNG theo tenant —
 * nó nằm ở prefix CỐ ĐỊNH `core_` (xem TenantProvisioner::CENTRAL_PREFIX). Vì vậy migration
 * này KHÔNG nằm trong `database/migrations.php` (bộ bảng per-tenant); nó được chạy riêng bởi
 * Provisioner sau khi đã set prefix connection = `core_`.
 *
 * Ghi chú thiết kế (xem docs/features/multi-tenant.md):
 *  - `slug` = "path key" resolve theo segment đầu URL (`domain.com/{slug}/...`) — [a-z0-9-], unique.
 *  - `db_prefix` = prefix bộ bảng của tenant (vd `sana_`) — connection dùng nó khi resolve.
 *  - `plan_code` trỏ tới `plans.code` (bảng `plans` làm ở Bước 2; PoC hardcode trong PlanGate).
 *  - Giới hạn (max_users, storage_quota_mb) KHÔNG ở đây mà ở `plans` — tránh trùng nguồn sự thật.
 *  - `expires_at` NULL = chưa đặt hạn (dùng cho trial/duyệt tay ở Bước 4).
 */
return new class () extends Migration {

    public function up(): void
    {
        if (!schema()->hasTable('tenants'))
        {
            Schema()->create('tenants', function (Blueprint $table) {
                $table->bigIncrements('id');
                // Path key resolve theo URL — unique toàn hệ thống.
                $table->string('slug', 30)->unique();
                $table->string('name', 255)->collation('utf8mb4_unicode_ci')->default('');
                // Prefix bộ bảng của tenant (vd 'sana_'). Unique để không 2 tenant đụng bảng.
                $table->string('db_prefix', 64)->unique();
                // Gói dịch vụ (trỏ plans.code) — quyết định role seed + giới hạn ở các bước sau.
                $table->string('plan_code', 50)->default('');
                // Hạn dùng gói (NULL = chưa đặt). Quá hạn → khóa thao tác (Bước 2), dữ liệu còn nguyên.
                $table->dateTime('expires_at')->nullable();
                // active | suspended | ... — suspended vẫn resolve được (để hiện màn gia hạn), không xóa dữ liệu.
                $table->string('status', 20)->default('active');
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            });
        }
    }

    public function down(): void
    {
    }
};
