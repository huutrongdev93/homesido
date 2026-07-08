<?php

namespace App\Services\Care;

use App\Models\Customer;
use App\Services\Notification\Notifier;

/**
 * Tick phát hiện khách "nguội" (chạy hằng ngày).
 *
 * Khách quá `CARE_COLD_DAYS` ngày (mặc định 7) không có tương tác nào (mốc = last_interaction_at,
 * hoặc created nếu chưa từng tương tác) mà chưa bị gắn cờ → set is_cold_flagged=1 + báo sales phụ trách.
 * Bỏ qua khách đã chốt/thất bại (won/lost). Xử lý theo lô để không nghẽn.
 */
class ColdDetector
{
    const BATCH = 300;

    /** @return array{flagged:int} */
    public function tick(): array
    {
        if (!schema()->hasTable('customers'))
        {
            return ['flagged' => 0];
        }

        $days = (int) env('CARE_COLD_DAYS', 7);
        if ($days <= 0)
        {
            $days = 7;
        }

        $threshold = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        // COALESCE: khách chưa từng tương tác thì tính theo ngày tạo.
        $rows = Customer::where('is_cold_flagged', 0)
            ->where('pipeline_stage', '!=', 'won')
            ->where('pipeline_stage', '!=', 'lost')
            ->whereRaw("COALESCE(last_interaction_at, created) < '" . $threshold . "'")
            ->limit(self::BATCH)
            ->get(['id', 'full_name', 'assigned_user_id']);

        $flagged = 0;

        foreach ($rows as $row)
        {
            Customer::where('id', (int) $row->id)->update(['is_cold_flagged' => 1]);

            Notifier::send(
                (int) $row->assigned_user_id,
                'warning',
                'Khách nguội cần chăm',
                'Khách "' . $row->full_name . '" đã hơn ' . $days . ' ngày không có tương tác.',
                '/customers'
            );

            $flagged++;
        }

        return ['flagged' => $flagged];
    }
}
