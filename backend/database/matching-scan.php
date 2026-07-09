<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use SkillDo\Cache\Cache;

/**
 * Migration Auto-matching (GĐ2.1) — cờ quét nền cho tick `match-scan-tick`.
 *
 * Thêm cột `match_scanned` (tinyint 0/1) vào `properties` và `customer_demands`:
 *   0 = CHƯA quét (BĐS vừa tạo / nhu cầu vừa tạo hoặc vừa đổi tiêu chí) → tick sẽ nhặt.
 *   1 = ĐÃ quét → bỏ qua.
 * Tick `App\Services\Matching\MatchScanner` quét các bản ghi cờ 0, gợi ý khớp on-the-fly bằng
 * MatchEngine rồi báo digest cho sales phụ trách, cuối cùng set cờ = 1.
 *
 * Không dùng datetime NULL làm sentinel: base Model của SkillDo tự điền '' cho cột không truyền
 * khi create (xem database/matching.php), '' không phải NULL nên whereNull sẽ trượt. Cột int cờ
 * an toàn hơn (mọi giá trị rỗng đều quy về 0).
 *
 * Backfill 1 LẦN (trong guard hasColumn): toàn bộ dữ liệu HIỆN CÓ = 1 (đã quét) để tick không
 * bắn thông báo hàng loạt cho kho/nhu cầu cũ ở lần chạy đầu.
 *
 * Guard hasColumn ⇒ idempotent. Đăng ký ở UtilsApi::database() ($migrations).
 */
return new class () extends Migration {

    public function up(): void
    {
        if (schema()->hasTable('properties') && !schema()->hasColumn('properties', 'match_scanned'))
        {
            Schema()->table('properties', function (Blueprint $table) {
                $table->tinyInteger('match_scanned')->default(0)
                    ->comment('Auto-matching: 0=chưa quét (BĐS mới), 1=đã quét. Xem MatchScanner.');
                $table->index('match_scanned', 'idx_prop_match_scanned');
            });

            // Model cần thấy cột mới trước khi backfill.
            Cache::flush();
            \App\Models\Property::query()->update(['match_scanned' => 1]);
        }

        if (schema()->hasTable('customer_demands') && !schema()->hasColumn('customer_demands', 'match_scanned'))
        {
            Schema()->table('customer_demands', function (Blueprint $table) {
                $table->tinyInteger('match_scanned')->default(0)
                    ->comment('Auto-matching: 0=chưa quét (mới/đổi tiêu chí), 1=đã quét. Xem MatchScanner.');
                $table->index('match_scanned', 'idx_demand_match_scanned');
            });

            Cache::flush();
            \App\Models\CustomerDemand::query()->update(['match_scanned' => 1]);
        }

        Cache::flush();
    }

    public function down(): void
    {
    }
};
