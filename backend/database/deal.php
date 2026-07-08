<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use SkillDo\Cache\Cache;

/**
 * Migration module Giao dịch (GĐ2).
 *
 * - `deals`         : 1 giao dịch (khách mua/thuê 1 BĐS). Vòng đời: deposit(cọc) → contract(hợp đồng)
 *                     → completed(hoàn tất) / canceled(hủy). Chuyển giai đoạn tự đổi `properties.status`.
 *                     Hoa hồng lưu ngay trên deal (`commission_rate`/`commission_amount`) + sinh 1 dòng
 *                     `commissions` cho sale phụ trách. Xoá mềm bằng `trash` (khuôn Customer/Property).
 * - `deal_payments` : các đợt thu tiền của 1 giao dịch (cọc, đợt 1, đợt 2...). Xoá cứng.
 * - `commissions`   : hoa hồng của 1 sale trên 1 giao dịch (đồng bộ từ deal). status pending → paid.
 *
 * Quy ước schema (xem crm.php): không tenant; timestamp `created`/`updated`; enum "tuỳ chọn" để string ''.
 * Guard hasTable ⇒ idempotent. Đăng ký ở UtilsApi::database() sau appointment.php.
 */
return new class () extends Migration {

    public function up(): void
    {
        if (!schema()->hasTable('deals'))
        {
            Schema()->create('deals', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('code', 50)->default('')->index()->comment('Mã giao dịch (auto GD+7)');
                $table->unsignedBigInteger('customer_id')->default(0)->index()->comment('Khách mua/thuê');
                $table->unsignedBigInteger('property_id')->default(0)->index()->comment('BĐS giao dịch');
                $table->unsignedBigInteger('assigned_user_id')->default(0)->comment('Sales phụ trách (hưởng hoa hồng)');
                $table->enum('transaction_type', ['sale', 'rent'])->default('sale');
                $table->decimal('value', 15, 2)->default(0)->comment('Giá trị giao dịch (VNĐ)');
                $table->decimal('commission_rate', 5, 2)->default(0)->comment('% hoa hồng trên value');
                $table->decimal('commission_amount', 15, 2)->default(0)->comment('Tiền hoa hồng (VNĐ)');
                $table->enum('status', ['deposit', 'contract', 'completed', 'canceled'])->default('deposit');
                $table->dateTime('deposit_at')->nullable()->comment('Mốc đặt cọc');
                $table->dateTime('contract_at')->nullable()->comment('Mốc ký hợp đồng');
                $table->dateTime('completed_at')->nullable()->comment('Mốc hoàn tất');
                $table->dateTime('canceled_at')->nullable()->comment('Mốc hủy');
                $table->text('note')->collation('utf8mb4_unicode_ci')->nullable();
                $table->unsignedBigInteger('user_created')->default(0);
                $table->tinyInteger('trash')->default(0)->index();
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

                $table->index(['assigned_user_id', 'status'], 'deal_assigned_status');
                $table->index('status', 'deal_status');
            });
        }

        if (!schema()->hasTable('deal_payments'))
        {
            Schema()->create('deal_payments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('deal_id')->default(0)->index();
                $table->decimal('amount', 15, 2)->default(0)->comment('Số tiền đợt thu (VNĐ)');
                $table->dateTime('paid_at')->nullable()->comment('Ngày thu');
                $table->string('method', 20)->default('')->comment('Hình thức: cash/transfer/card (enum payment_methods; "" = không rõ)');
                $table->text('note')->collation('utf8mb4_unicode_ci')->nullable();
                $table->unsignedBigInteger('user_created')->default(0);
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            });
        }

        if (!schema()->hasTable('commissions'))
        {
            Schema()->create('commissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('deal_id')->default(0)->index()->comment('Giao dịch (1 dòng/giao dịch)');
                $table->unsignedBigInteger('user_id')->default(0)->index()->comment('Sales hưởng hoa hồng');
                $table->decimal('rate', 5, 2)->default(0);
                $table->decimal('amount', 15, 2)->default(0)->comment('Tiền hoa hồng (VNĐ)');
                $table->enum('status', ['pending', 'paid'])->default('pending');
                $table->dateTime('paid_at')->nullable();
                $table->text('note')->collation('utf8mb4_unicode_ci')->nullable();
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            });
        }

        Cache::flush();
    }

    public function down(): void {}
};
