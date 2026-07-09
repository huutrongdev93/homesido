<?php

namespace App\Services\Care;

use App\Models\CareSchedule;
use App\Models\CareTemplate;
use App\Models\Customer;

/**
 * Sinh CHUỖI lịch chăm sóc tự động từ "kịch bản mặc định" = các `care_templates` có `auto_apply=1`.
 *
 * Mỗi template auto_apply là 1 BƯỚC: đặt lịch chăm sau `offset_days` ngày kể từ mốc kích hoạt (khách
 * mới tạo ở CustomerApi::add, hoặc thời điểm áp thủ công qua endpoint). Nội dung template thay biến
 * `{{ten_khach}}`; kênh (`channel`) → loại lịch (`care_schedules.type`, cùng enum call/sms/zalo/email).
 * Đánh dấu `care_template_id` để truy vết lịch nào sinh từ bước nào.
 *
 * Fire-and-forget (bọc try/catch ở nơi gọi). Chưa migrate cột `auto_apply` → trả 0 (chưa bật).
 */
class CareSequence
{
    /**
     * Áp chuỗi kịch bản mặc định cho 1 khách. Trả về SỐ lịch chăm đã tạo.
     */
    public static function applyAuto(int $customerId): int
    {
        if ($customerId <= 0)
        {
            return 0;
        }

        if (!schema()->hasTable('care_templates') || !schema()->hasColumn('care_templates', 'auto_apply'))
        {
            return 0;
        }

        $customer = Customer::where('id', $customerId)->first(['id', 'full_name', 'assigned_user_id']);
        if (!hasItems($customer))
        {
            return 0;
        }

        $templates = CareTemplate::where('is_active', 1)
            ->where('auto_apply', 1)
            ->orderBy('sort_order')
            ->orderBy('offset_days')
            ->get();

        $name     = (string) $customer->full_name;
        $assigned = (int) $customer->assigned_user_id;
        $created  = 0;

        foreach ($templates as $t)
        {
            $offset  = max(0, (int) $t->offset_days);
            $content = str_replace('{{ten_khach}}', $name, (string) ($t->content ?? ''));

            CareSchedule::create([
                'customer_id'      => $customerId,
                'assigned_user_id' => $assigned,
                'care_template_id' => (int) $t->id,
                'type'             => (string) $t->channel,
                'scheduled_at'     => date('Y-m-d H:i:s', strtotime('+' . $offset . ' days')),
                'content'          => ($content !== '') ? $content : (string) $t->name,
                'status'           => 'pending',
            ]);

            $created++;
        }

        return $created;
    }
}
