<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use SkillDo\Cache\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Migration Giai đoạn 1 — Core CRM Bất động sản (HomeSido).
 *
 * 11 bảng nghiệp vụ: nguồn khách, khách hàng + nhu cầu, dự án, chủ nhà, bất động sản + media,
 * timeline tương tác, lịch chăm sóc + kịch bản, lịch sử bàn giao khách.
 *
 * Quy ước (xem docs/database.md):
 * - KHÔNG multi-tenant (1 deployment = 1 sàn) — không có cột tenant_id.
 * - Timestamps house-style: `created` (CURRENT_TIMESTAMP) + `updated` (ON UPDATE) — KHÔNG _at.
 * - Soft delete: cột `trash` (trait SkillDo\Traits\Eloquent\SoftDeletes) cho customers/properties.
 * - Người tạo: cột `user_created` — base Model tự set Auth::id() khi create.
 * - Địa giới 2 cấp tỉnh→phường (int code) qua LocationApi/Location2 — không có district.
 * - Mỗi block guard hasTable ⇒ idempotent. Đăng ký ở UtilsApi::database() ($migrations).
 */
return new class () extends Migration {

    public function up(): void
    {
        // ── Nguồn khách ────────────────────────────────────────────────────────────────
        if (!schema()->hasTable('lead_sources'))
        {
            Schema()->create('lead_sources', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 255)->collation('utf8mb4_unicode_ci')->default('');
                $table->tinyInteger('is_active')->default(1);
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            });
        }

        // ── Khách hàng ─────────────────────────────────────────────────────────────────
        if (!schema()->hasTable('customers'))
        {
            Schema()->create('customers', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assigned_user_id')->default(0)->comment('Sales phụ trách');
                $table->unsignedBigInteger('lead_source_id')->default(0);
                $table->string('full_name', 255)->collation('utf8mb4_unicode_ci')->default('');
                $table->string('phone', 20)->default('')->comment('Dùng chống trùng');
                $table->string('phone_alt', 20)->nullable();
                $table->string('email', 100)->nullable();
                // Enum "tùy chọn" lưu string '' = chưa chọn (base Model tự điền '' cho cột không
                // truyền — '' không hợp lệ với enum MySQL; validate giá trị ở app). Xem docs/database.md §2.
                $table->string('gender', 10)->default('');
                $table->integer('birth_year')->default(0);
                $table->string('address', 255)->collation('utf8mb4_unicode_ci')->nullable();
                $table->string('occupation', 255)->collation('utf8mb4_unicode_ci')->nullable();
                // Phễu bán hàng.
                $table->enum('pipeline_stage', ['new', 'contacting', 'potential', 'negotiating', 'won', 'lost'])->default('new');
                // Nóng / ấm / lạnh.
                $table->enum('temperature', ['hot', 'warm', 'cold'])->default('warm');
                $table->integer('lead_score')->default(0);
                $table->dateTime('locked_until')->nullable()->comment('Hạn khóa khách');
                $table->dateTime('last_interaction_at')->nullable()->comment('Phục vụ cảnh báo nguội');
                $table->tinyInteger('is_cold_flagged')->default(0);
                $table->text('note')->collation('utf8mb4_unicode_ci')->nullable();
                $table->tinyInteger('trash')->default(0);
                $table->unsignedBigInteger('user_created')->default(0);
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

                $table->index('phone');
                $table->index(['assigned_user_id', 'pipeline_stage'], 'assigned_stage');
            });
        }

        // ── Nhu cầu / tiêu chí của khách (1 khách nhiều nhu cầu) ──────────────────────────
        if (!schema()->hasTable('customer_demands'))
        {
            Schema()->create('customer_demands', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('customer_id')->default(0)->index();
                $table->enum('demand_type', ['buy', 'rent', 'sell', 'consign'])->default('buy');
                $table->string('property_type', 50)->default('')->comment('Loại hình mong muốn (enum ở api/utils)');
                $table->string('purpose', 10)->default('')->comment('live/invest — "" = chưa chọn');
                $table->integer('province_code')->default(0);
                $table->integer('ward_code')->default(0);
                $table->decimal('budget_min', 15, 2)->default(0);
                $table->decimal('budget_max', 15, 2)->default(0);
                $table->decimal('area_min', 10, 2)->default(0);
                $table->decimal('area_max', 10, 2)->default(0);
                $table->tinyInteger('bedrooms_min')->default(0);
                $table->string('direction', 30)->default('');
                $table->tinyInteger('is_active')->default(1);
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            });
        }

        // ── Dự án (gom sản phẩm theo khu/dự án) ──────────────────────────────────────────
        if (!schema()->hasTable('projects'))
        {
            Schema()->create('projects', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 255)->collation('utf8mb4_unicode_ci')->default('');
                $table->string('developer', 255)->collation('utf8mb4_unicode_ci')->nullable()->comment('Chủ đầu tư');
                $table->integer('province_code')->default(0);
                $table->integer('ward_code')->default(0);
                $table->string('address', 255)->collation('utf8mb4_unicode_ci')->nullable();
                $table->text('description')->collation('utf8mb4_unicode_ci')->nullable();
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            });
        }

        // ── Chủ nhà (hàng ký gửi) ────────────────────────────────────────────────────────
        if (!schema()->hasTable('property_owners'))
        {
            Schema()->create('property_owners', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('full_name', 255)->collation('utf8mb4_unicode_ci')->default('');
                $table->string('phone', 20)->default('');
                $table->string('email', 100)->nullable();
                $table->text('note')->collation('utf8mb4_unicode_ci')->nullable();
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

                $table->index('phone');
            });
        }

        // ── Bất động sản ─────────────────────────────────────────────────────────────────
        if (!schema()->hasTable('properties'))
        {
            Schema()->create('properties', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('project_id')->default(0);
                $table->unsignedBigInteger('owner_id')->default(0)->comment('Chủ nhà ký gửi');
                $table->string('code', 50)->default('')->comment('Mã sản phẩm nội bộ');
                $table->string('title', 255)->collation('utf8mb4_unicode_ci')->default('');
                $table->string('property_type', 50)->default('')->comment('Loại hình (enum ở api/utils)');
                $table->enum('transaction_type', ['sale', 'rent'])->default('sale');
                $table->decimal('price', 15, 2)->default(0)->comment('Giá tổng');
                $table->decimal('price_per_m2', 15, 2)->default(0);
                $table->decimal('area_land', 10, 2)->default(0)->comment('Diện tích đất');
                $table->decimal('area_usable', 10, 2)->default(0)->comment('DT sử dụng/xây dựng');
                $table->tinyInteger('bedrooms')->default(0);
                $table->tinyInteger('bathrooms')->default(0);
                $table->tinyInteger('floors')->default(0);
                $table->string('direction', 30)->default('')->comment('Hướng nhà');
                // legal_status / furniture: string '' = chưa chọn (validate ở app; xem docs/database.md §2).
                $table->string('legal_status', 20)->default('')->comment('red_book/pink_book/sale_contract/waiting/other');
                $table->string('furniture', 10)->default('')->comment('none/basic/full');
                $table->integer('province_code')->default(0);
                $table->integer('ward_code')->default(0);
                $table->string('address', 255)->collation('utf8mb4_unicode_ci')->nullable();
                $table->decimal('latitude', 10, 7)->default(0);
                $table->decimal('longitude', 10, 7)->default(0);
                $table->longText('description')->collation('utf8mb4_unicode_ci')->nullable();
                // Riêng nhân viên / chung sàn.
                $table->enum('visibility', ['private', 'shared'])->default('shared');
                $table->enum('status', ['available', 'deposited', 'sold', 'rented', 'inactive'])->default('available');
                $table->unsignedBigInteger('assigned_user_id')->default(0);
                $table->tinyInteger('trash')->default(0);
                $table->unsignedBigInteger('user_created')->default(0);
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

                $table->index('code');
                $table->index('assigned_user_id');
                $table->index('status');
                $table->index(['property_type', 'transaction_type'], 'type_transaction');
                $table->index(['province_code', 'ward_code'], 'province_ward');
                $table->index('price');
            });
        }

        // ── Ảnh / video / tài liệu của BĐS ───────────────────────────────────────────────
        if (!schema()->hasTable('property_media'))
        {
            Schema()->create('property_media', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('property_id')->default(0)->index();
                $table->enum('type', ['image', 'video', 'document'])->default('image');
                $table->string('path', 255)->default('');
                $table->integer('sort_order')->default(0);
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
            });
        }

        // ── Timeline tương tác với khách ─────────────────────────────────────────────────
        if (!schema()->hasTable('customer_interactions'))
        {
            Schema()->create('customer_interactions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('customer_id')->default(0)->index();
                $table->unsignedBigInteger('user_id')->default(0)->comment('Người thực hiện');
                $table->enum('type', ['call', 'sms', 'zalo', 'email', 'meeting', 'note', 'viewing'])->default('note');
                $table->text('content')->collation('utf8mb4_unicode_ci')->nullable();
                $table->string('direction', 3)->default('')->comment('in/out — "" = không rõ');
                $table->dateTime('interacted_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
            });
        }

        // ── Lịch chăm sóc / nhắc việc ────────────────────────────────────────────────────
        if (!schema()->hasTable('care_schedules'))
        {
            Schema()->create('care_schedules', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('customer_id')->default(0)->index();
                $table->unsignedBigInteger('assigned_user_id')->default(0)->comment('Sales chịu trách nhiệm');
                $table->unsignedBigInteger('care_template_id')->default(0);
                $table->enum('type', ['call', 'sms', 'zalo', 'email', 'meeting'])->default('call');
                $table->dateTime('scheduled_at')->nullable()->comment('Thời điểm cần chăm');
                $table->text('content')->collation('utf8mb4_unicode_ci')->nullable();
                $table->enum('status', ['pending', 'done', 'missed', 'canceled'])->default('pending');
                $table->dateTime('completed_at')->nullable();
                $table->text('result_note')->collation('utf8mb4_unicode_ci')->nullable();
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

                $table->index(['scheduled_at', 'status'], 'scheduled_status');
                $table->index(['assigned_user_id', 'status'], 'assigned_status');
            });
        }

        // ── Kịch bản chăm sóc (template có biến {{ten_khach}}) ────────────────────────────
        if (!schema()->hasTable('care_templates'))
        {
            Schema()->create('care_templates', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 255)->collation('utf8mb4_unicode_ci')->default('');
                $table->enum('channel', ['call', 'sms', 'zalo', 'email'])->default('call');
                $table->text('content')->collation('utf8mb4_unicode_ci')->nullable();
                $table->string('stage', 30)->nullable()->comment('Giai đoạn áp dụng');
                $table->tinyInteger('is_active')->default(1);
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            });
        }

        // ── Lịch sử bàn giao / thu hồi khách ─────────────────────────────────────────────
        if (!schema()->hasTable('customer_transfers'))
        {
            Schema()->create('customer_transfers', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('customer_id')->default(0)->index();
                $table->unsignedBigInteger('from_user_id')->default(0);
                $table->unsignedBigInteger('to_user_id')->default(0);
                $table->unsignedBigInteger('transferred_by')->default(0);
                $table->string('reason', 255)->collation('utf8mb4_unicode_ci')->nullable();
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
            });
        }

        Cache::flush();
    }

    public function down(): void
    {
    }
};
