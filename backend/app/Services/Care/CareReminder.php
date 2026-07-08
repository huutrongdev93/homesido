<?php

namespace App\Services\Care;

use App\Models\CareSchedule;
use App\Services\Notification\Notifier;

/**
 * Tick nhắc lịch chăm sóc đến hạn (chạy mỗi phút).
 *
 * Gửi 1 thông báo DIGEST cho từng sales có việc đến hạn ("Bạn có N việc chăm sóc đến hạn"),
 * dùng Notifier::sendUnique (type+link) để KHÔNG spam mỗi phút: chỉ nhắc lại sau khi user đã đọc.
 * Bảng chưa migrate → thoát êm.
 */
class CareReminder
{
    /** @return array{users:int, due:int} */
    public function tick(): array
    {
        if (!schema()->hasTable('care_schedules'))
        {
            return ['users' => 0, 'due' => 0];
        }

        $now = date('Y-m-d H:i:s');

        $rows = CareSchedule::where('status', 'pending')
            ->where('scheduled_at', '<=', $now)
            ->limit(2000)
            ->get(['assigned_user_id']);

        // Gom số việc theo từng sales.
        $counts = [];
        foreach ($rows as $r)
        {
            $uid = (int) $r->assigned_user_id;
            if ($uid <= 0)
            {
                continue;
            }
            $counts[$uid] = ($counts[$uid] ?? 0) + 1;
        }

        foreach ($counts as $uid => $n)
        {
            Notifier::sendUnique(
                $uid,
                'warning',
                'Cần chăm sóc hôm nay',
                'Bạn có ' . $n . ' việc chăm sóc đang đến hạn.',
                '/care'
            );
        }

        return ['users' => count($counts), 'due' => count($rows)];
    }
}
