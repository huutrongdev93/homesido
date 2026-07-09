<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SkillDo\Cache\Cache;

/**
 * Đảm bảo `properties.code` là DUY NHẤT — phục vụ URL công khai `/p/{code}` (resolve theo mã).
 *
 * 1) Khử trùng dữ liệu cũ: quét toàn bộ (gồm cả bản đã xóa mềm) theo id tăng dần; mã RỖNG hoặc
 *    TRÙNG bản trước → gán mã ngẫu nhiên mới chưa dùng (giữ bản id nhỏ nhất).
 * 2) Thêm UNIQUE index `properties_code_unique`.
 *
 * Guard: đã có index → return sớm (mã chắc chắn duy nhất) ⇒ idempotent, chạy lại rẻ.
 * Đăng ký ở UtilsApi::database() sau property.php.
 */
return new class () extends Migration {

    public function up(): void
    {
        if (!schema()->hasTable('properties'))
        {
            return;
        }

        $table = DB::getTablePrefix() . 'properties';

        // Đã có ràng buộc → khỏi quét lại (uniqueness được DB đảm bảo từ đây).
        $exists = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = 'properties_code_unique'");
        if (!empty($exists))
        {
            return;
        }

        // 1) Khử trùng trước khi áp UNIQUE (nếu không sẽ lỗi khi có mã trùng/rỗng).
        $seen = [];
        foreach (DB::select("SELECT id, code FROM `{$table}` ORDER BY id ASC") as $row)
        {
            $code = (string) $row->code;

            if ($code !== '' && !isset($seen[$code]))
            {
                $seen[$code] = true;
                continue;
            }

            do {
                $new = 'BDS' . strtoupper(Str::random(7));
            } while (isset($seen[$new]));

            DB::update("UPDATE `{$table}` SET code = ? WHERE id = ?", [$new, (int) $row->id]);
            $seen[$new] = true;
        }

        // 2) Áp UNIQUE index.
        DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `properties_code_unique` (`code`)");

        Cache::flush();
    }

    public function down(): void
    {
    }
};
