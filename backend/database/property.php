<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use SkillDo\Cache\Cache;

/**
 * Migration bổ sung cột cho `properties`.
 *
 * - `road_type` : vị trí / đường vào (mặt tiền, hẻm xe hơi, hẻm xe máy...) — enum `road_types` ở api/utils.
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

        Cache::flush();
    }

    public function down(): void
    {
    }
};
