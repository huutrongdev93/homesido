<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use SkillDo\Cache\Cache;

/**
 * Migration Care Sequence (GĐ3 tự động hóa) — biến `care_templates` (kịch bản đơn) thành BƯỚC của
 * "chuỗi chăm sóc mặc định".
 *
 * Thêm 3 cột vào `care_templates`:
 *   - `offset_days`  INT      — làm bước này sau N ngày kể từ mốc kích hoạt (khách mới / áp thủ công).
 *   - `auto_apply`   TINYINT  — 1 = thuộc chuỗi mặc định, tự áp cho khách mới; 0 = template rời (như cũ).
 *   - `sort_order`   INT      — thứ tự bước trong chuỗi.
 *
 * Khách mới (CustomerApi::add) → App\Services\Care\CareSequence::applyAuto sinh 1 care_schedules cho
 * mỗi template auto_apply=1 (scheduled_at = now + offset_days). Guard hasColumn ⇒ idempotent.
 * Đăng ký ở UtilsApi::database() ($migrations).
 */
return new class () extends Migration {

    public function up(): void
    {
        if (!schema()->hasTable('care_templates'))
        {
            return; // care_templates tạo ở database/crm.php — chạy trước trong $migrations.
        }

        if (!schema()->hasColumn('care_templates', 'offset_days'))
        {
            Schema()->table('care_templates', function (Blueprint $table) {
                $table->integer('offset_days')->default(0)->comment('Làm sau N ngày kể từ mốc kích hoạt (chuỗi chăm sóc)');
            });
        }

        if (!schema()->hasColumn('care_templates', 'auto_apply'))
        {
            Schema()->table('care_templates', function (Blueprint $table) {
                $table->tinyInteger('auto_apply')->default(0)->comment('1 = thuộc chuỗi mặc định, tự áp cho khách mới');
            });
        }

        if (!schema()->hasColumn('care_templates', 'sort_order'))
        {
            Schema()->table('care_templates', function (Blueprint $table) {
                $table->integer('sort_order')->default(0)->comment('Thứ tự bước trong chuỗi');
            });
        }

        Cache::flush();
    }

    public function down(): void
    {
    }
};
