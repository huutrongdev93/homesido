<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use SkillDo\Cache\Cache;

/**
 * Migration bổ sung cột cho `property_media` phục vụ KẾ TOÁN DUNG LƯỢNG (bán gói theo dung lượng).
 *
 * - `size`          : dung lượng file (byte) — ghi lại mọi file upload để tính tổng.
 * - `user_id`       : người upload — dung lượng tính THEO TỪNG NHÂN VIÊN (user meta `storage_used_bytes`).
 * - `mime_type`     : loại MIME (phục vụ hiển thị/kiểm tra).
 * - `original_name` : tên file gốc (hiển thị/tải xuống).
 *
 * Guard hasColumn ⇒ idempotent. Đăng ký ở UtilsApi::database() sau crm.php.
 */
return new class () extends Migration {

    public function up(): void
    {
        if (!schema()->hasTable('property_media'))
        {
            return;
        }

        if (!schema()->hasColumn('property_media', 'size'))
        {
            Schema()->table('property_media', function (Blueprint $table) {
                $table->unsignedBigInteger('size')->default(0)->comment('Dung lượng (byte)');
            });
        }

        if (!schema()->hasColumn('property_media', 'user_id'))
        {
            Schema()->table('property_media', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->default(0)->index()->comment('Người upload');
            });
        }

        if (!schema()->hasColumn('property_media', 'mime_type'))
        {
            Schema()->table('property_media', function (Blueprint $table) {
                $table->string('mime_type', 100)->default('');
            });
        }

        if (!schema()->hasColumn('property_media', 'original_name'))
        {
            Schema()->table('property_media', function (Blueprint $table) {
                $table->string('original_name', 255)->collation('utf8mb4_unicode_ci')->default('');
            });
        }

        Cache::flush();
    }

    public function down(): void
    {
    }
};
