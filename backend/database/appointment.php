<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use SkillDo\Cache\Cache;

/**
 * Migration module Lịch hẹn dẫn khách (GĐ2).
 *
 * Bảng `appointments` — 1 buổi hẹn dẫn khách đi xem BĐS: khách + (tuỳ chọn) BĐS + giờ hẹn.
 * Vòng đời trạng thái giống Care (không xoá mềm): pending → done / canceled / no_show.
 * Khi `done` ghi kết quả (`result`) + tạo 1 tương tác timeline cho khách (giống CareApi::complete).
 *
 * Quy ước schema (xem crm.php): không tenant; timestamp là `created`/`updated`; cột enum "tuỳ chọn"
 * để `string default ''`; `user_created` do base Model tự set. Guard hasTable ⇒ idempotent.
 * Đăng ký ở UtilsApi::database() sau matching.php.
 */
return new class () extends Migration {

    public function up(): void
    {
        if (!schema()->hasTable('appointments'))
        {
            Schema()->create('appointments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('customer_id')->default(0)->index()->comment('Khách được dẫn');
                $table->unsignedBigInteger('property_id')->default(0)->comment('BĐS đi xem (0 = chưa gắn)');
                $table->unsignedBigInteger('assigned_user_id')->default(0)->comment('Sales phụ trách buổi hẹn');
                $table->dateTime('scheduled_at')->nullable()->comment('Thời điểm hẹn');
                $table->unsignedInteger('duration_min')->default(0)->comment('Thời lượng dự kiến (phút); 0 = không đặt');
                $table->string('location', 255)->collation('utf8mb4_unicode_ci')->default('')->comment('Địa điểm gặp (mặc định lấy địa chỉ BĐS)');
                $table->text('note')->collation('utf8mb4_unicode_ci')->nullable()->comment('Ghi chú/kế hoạch buổi hẹn');
                $table->enum('status', ['pending', 'done', 'canceled', 'no_show'])->default('pending');
                $table->string('result', 20)->default('')->comment('Kết quả sau khi dẫn: interested/considering/rejected/deposited (enum appointment_results ở api/utils; "" = chưa có)');
                $table->text('result_note')->collation('utf8mb4_unicode_ci')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->dateTime('reminded_at')->nullable()->comment('Mốc đã gửi nhắc trước giờ (tick không nhắc lại)');
                $table->unsignedBigInteger('user_created')->default(0);
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

                $table->index(['scheduled_at', 'status'], 'appt_scheduled_status');
                $table->index(['assigned_user_id', 'status'], 'appt_assigned_status');
                $table->index('property_id', 'appt_property');
            });
        }

        Cache::flush();
    }

    public function down(): void {}
};
