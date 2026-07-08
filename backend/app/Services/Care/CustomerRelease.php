<?php

namespace App\Services\Care;

use App\Models\Customer;
use App\Services\Notification\Notifier;

/**
 * Tick tự trả khách quá hạn khóa về "kho chung" (chạy hằng ngày).
 *
 * Khách có `locked_until` đã qua (`< now`) mà sales phụ trách không còn chăm tích cực (mỗi tương
 * tác/chăm sóc gọi `Customer::touch()` để GIA HẠN khóa — hết hạn nghĩa là đã lâu không đụng tới)
 * → gỡ khóa + trả về kho chung (`assigned_user_id = 0`) để nhân viên khác nhận. Báo sales cũ.
 * Bỏ qua khách đã chốt/thất bại (won/lost) và khách chưa từng bị khóa. Xử lý theo lô.
 */
class CustomerRelease
{
    const BATCH = 300;

    /** @return array{released:int} */
    public function tick(): array
    {
        if (!schema()->hasTable('customers'))
        {
            return ['released' => 0];
        }

        $now = date('Y-m-d H:i:s');

        $rows = Customer::whereNotNull('locked_until')
            ->where('locked_until', '<', $now)
            ->where('assigned_user_id', '>', 0)
            ->where('pipeline_stage', '!=', 'won')
            ->where('pipeline_stage', '!=', 'lost')
            ->limit(self::BATCH)
            ->get(['id', 'full_name', 'assigned_user_id']);

        $released = 0;

        foreach ($rows as $row)
        {
            Customer::where('id', (int) $row->id)->update([
                'assigned_user_id' => 0,
                'locked_until'     => null,
            ]);

            Notifier::send(
                (int) $row->assigned_user_id,
                'warning',
                'Khách được trả về kho chung',
                'Khách "' . $row->full_name . '" đã hết hạn khóa và được trả về kho chung do lâu không có tương tác.',
                '/customers'
            );

            $released++;
        }

        return ['released' => $released];
    }
}
