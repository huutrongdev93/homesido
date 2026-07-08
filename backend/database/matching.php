<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use SkillDo\Cache\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Migration Giai đoạn 2 — Matching khách ↔ BĐS (HomeSido).
 *
 * 1 bảng: `property_customer_matches` — LƯU HÀNH ĐỘNG "đã gửi SP cho khách" (append/log,
 * giống customer_transfers), KHÔNG phải cache của kết quả so khớp. Việc so khớp được tính
 * on-the-fly bởi App\Services\Matching\MatchEngine (tránh dữ liệu lỗi thời).
 *
 * Quy ước (xem docs/database.md):
 * - Timestamps house-style: `created` / `updated` — KHÔNG _at.
 * - `status` là string default 'sent' (KHÔNG enum — base Model tự điền '' cho cột không truyền,
 *   '' không hợp lệ với enum MySQL; validate giá trị hợp lệ ở controller). Xem docs/database.md §2.
 * - Guard hasTable ⇒ idempotent. Đăng ký ở UtilsApi::database() ($migrations).
 */
return new class () extends Migration {

    public function up(): void
    {
        if (!schema()->hasTable('property_customer_matches'))
        {
            Schema()->create('property_customer_matches', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('customer_id')->default(0);
                $table->unsignedBigInteger('property_id')->default(0);
                $table->unsignedBigInteger('demand_id')->default(0)->comment('Nhu cầu khớp; 0 nếu gửi thủ công');
                $table->unsignedBigInteger('user_id')->default(0)->comment('Người gửi SP');
                $table->integer('score')->default(0)->comment('Điểm khớp lúc gửi (0-100)');
                // 'sent' → 'interested' → 'rejected' (phản hồi khách). String default (không enum).
                $table->string('status', 20)->default('sent');
                $table->string('note', 255)->collation('utf8mb4_unicode_ci')->default('');
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
                // Dedup 1 cặp (khách, BĐS): đã gửi thì cập nhật thay vì tạo dòng mới.
                $table->index(['customer_id', 'property_id'], 'idx_pcm_customer_property');
                $table->index('property_id', 'idx_pcm_property');
            });
        }

        Cache::flush();
    }

    public function down(): void
    {
    }
};
