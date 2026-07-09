<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use SkillDo\Cache\Cache;

/**
 * Mở rộng enum `property_media.type` để thêm loại `audio` (file âm thanh: mp3/wav/m4a/aac/ogg).
 *
 * Ban đầu enum là ('image','video','document') (xem crm.php). Người dùng cần upload cả âm thanh,
 * nên bổ sung 'audio'. Dùng ALTER MODIFY vì cột đã tồn tại (không phải thêm cột mới).
 *
 * Idempotent: chỉ ALTER khi enum hiện tại CHƯA có 'audio' (dò qua SHOW COLUMNS). Đăng ký ở
 * UtilsApi::database() sau media.php.
 */
return new class () extends Migration {

    public function up(): void
    {
        if (!schema()->hasTable('property_media'))
        {
            return;
        }

        $table = DB::getTablePrefix() . 'property_media';

        $col = DB::selectOne("SHOW COLUMNS FROM `{$table}` LIKE 'type'");

        // $col->Type ví dụ: "enum('image','video','document')" — chỉ sửa khi thiếu 'audio'.
        if ($col && stripos($col->Type, "'audio'") === false)
        {
            DB::statement(
                "ALTER TABLE `{$table}` MODIFY COLUMN `type` "
                . "ENUM('image','video','audio','document') NOT NULL DEFAULT 'image'"
            );
        }

        Cache::flush();
    }

    public function down(): void
    {
    }
};
