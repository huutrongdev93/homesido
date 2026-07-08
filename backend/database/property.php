<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use SkillDo\Cache\Cache;

/**
 * Migration bổ sung cột cho `properties`.
 *
 * - `road_type`      : vị trí / đường vào (mặt tiền, hẻm xe hơi, hẻm xe máy...) — enum `road_types` ở api/utils.
 * - `cover_media_id` : id ảnh đại diện (trỏ tới property_media). 0 = chưa chọn → FE/BE fallback ảnh đầu tiên.
 *
 * Guard hasColumn ⇒ idempotent. Đăng ký ở UtilsApi::database() sau crm.php.
 */
return new class () extends Migration {

    public function up(): void
    {
        if (!schema()->hasTable('properties'))
        {
            return;
        }

        if (!schema()->hasColumn('properties', 'road_type'))
        {
            Schema()->table('properties', function (Blueprint $table) {
                $table->string('road_type', 20)->default('')->after('direction')->comment('frontage/car_alley/bike_alley/walk_alley');
            });
        }

        if (!schema()->hasColumn('properties', 'cover_media_id'))
        {
            Schema()->table('properties', function (Blueprint $table) {
                $table->unsignedBigInteger('cover_media_id')->default(0)->after('status')->comment('id ảnh đại diện (property_media); 0 = tự lấy ảnh đầu tiên');
            });
        }

        Cache::flush();
    }

    public function down(): void
    {
    }
};
