<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use SkillDo\Cache\Cache;

/**
 * Migration mở rộng Giao dịch (GĐ2): Lịch sử + Nhắc hẹn.
 *
 * 1. `deal_payments` (+cột): tách "kế hoạch thu" khỏi "đã thu".
 *    - `status`      : planned(dự kiến) / paid(đã thu). Default 'paid' ⇒ dòng cũ = đã thu (tương thích ngược).
 *    - `due_date`    : ngày đến hạn (chỉ đợt dự kiến).
 *    - `reminded_at` : mốc đã nhắc (chống nhắc lặp, khuôn appointments.reminded_at).
 * 2. `deal_reminders` : lời nhắc tự do gắn 1 giao dịch (gọi khách, chuẩn bị hồ sơ...). tick nhắc khi đến hạn.
 * 3. `deal_activities`: nhật ký hoạt động của giao dịch (append-only, không sửa/xóa) → dòng thời gian ở drawer.
 *
 * Guard hasTable/hasColumn ⇒ idempotent. Đăng ký ở UtilsApi::database() sau deal.php.
 */
return new class () extends Migration {

    public function up(): void
    {
        // ── 1. Bổ sung cột cho deal_payments ──────────────────────────────────────────
        if (schema()->hasTable('deal_payments'))
        {
            if (!schema()->hasColumn('deal_payments', 'status'))
            {
                Schema()->table('deal_payments', function (Blueprint $table) {
                    $table->enum('status', ['planned', 'paid'])->default('paid')->after('method')
                        ->comment('planned=dự kiến (có due_date), paid=đã thu (có paid_at)');
                });
            }

            if (!schema()->hasColumn('deal_payments', 'due_date'))
            {
                Schema()->table('deal_payments', function (Blueprint $table) {
                    $table->dateTime('due_date')->nullable()->after('status')->comment('Ngày đến hạn (đợt dự kiến)');
                });
            }

            if (!schema()->hasColumn('deal_payments', 'reminded_at'))
            {
                Schema()->table('deal_payments', function (Blueprint $table) {
                    $table->dateTime('reminded_at')->nullable()->after('due_date')->comment('Mốc đã nhắc đến hạn (chống nhắc lặp)');
                });
            }
        }

        // ── 2. Bảng nhắc hẹn tự do ─────────────────────────────────────────────────────
        if (!schema()->hasTable('deal_reminders'))
        {
            Schema()->create('deal_reminders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('deal_id')->default(0)->index();
                $table->unsignedBigInteger('assigned_user_id')->default(0)->index()->comment('Người được nhắc (mặc định = sale phụ trách deal)');
                $table->string('title', 255)->default('')->comment('Nội dung nhắc');
                $table->dateTime('remind_at')->nullable()->index()->comment('Thời điểm nhắc');
                $table->enum('status', ['pending', 'done'])->default('pending');
                $table->dateTime('reminded_at')->nullable()->comment('Mốc đã bắn thông báo (chống nhắc lặp)');
                $table->dateTime('done_at')->nullable();
                $table->text('note')->collation('utf8mb4_unicode_ci')->nullable();
                $table->unsignedBigInteger('user_created')->default(0);
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

                $table->index(['status', 'remind_at'], 'deal_reminder_due');
            });
        }

        // ── 3. Bảng nhật ký hoạt động ──────────────────────────────────────────────────
        if (!schema()->hasTable('deal_activities'))
        {
            Schema()->create('deal_activities', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('deal_id')->default(0)->index();
                $table->string('type', 20)->default('')->comment('created/status/payment/payment_plan/payment_paid/payment_delete/commission/reminder/reminder_done/update');
                $table->string('title', 255)->default('')->comment('Tiêu đề sự kiện hiển thị');
                $table->decimal('amount', 15, 2)->default(0)->comment('Số tiền liên quan (nếu có)');
                $table->text('note')->collation('utf8mb4_unicode_ci')->nullable();
                $table->unsignedBigInteger('user_id')->default(0)->comment('Người thực hiện');
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
            });
        }

        Cache::flush();
    }

    public function down(): void {}
};
