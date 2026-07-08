<?php

namespace App\Services\Appointment;

use App\Models\Appointment;
use App\Models\Customer;
use App\Services\Notification\Notifier;

/**
 * Tick nhắc buổi hẹn sắp đến giờ.
 *
 * Mỗi phút quét các buổi hẹn `pending` có `scheduled_at` trong khoảng [now, now + cửa sổ] mà CHƯA
 * được nhắc (`reminded_at` null) → gửi 1 thông báo cho sales phụ trách rồi đánh dấu `reminded_at`
 * (mỗi buổi hẹn chỉ nhắc 1 lần, không spam). Cửa sổ nhắc = env APPOINTMENT_REMIND_MINUTES (mặc định 60').
 * Bảng chưa migrate → thoát êm.
 */
class AppointmentReminder
{
    /** @return array{reminded:int, users:int} */
    public function tick(): array
    {
        if (!schema()->hasTable('appointments'))
        {
            return ['reminded' => 0, 'users' => 0];
        }

        $windowMin = max(1, (int) env('APPOINTMENT_REMIND_MINUTES', 60));
        $now       = time();
        $from      = date('Y-m-d H:i:s', $now);
        $to        = date('Y-m-d H:i:s', $now + $windowMin * 60);

        $rows = Appointment::where('status', 'pending')
            ->whereNull('reminded_at')
            ->where('scheduled_at', '>=', $from)
            ->where('scheduled_at', '<=', $to)
            ->orderBy('scheduled_at')
            ->limit(500)
            ->get();

        if (count($rows) === 0)
        {
            return ['reminded' => 0, 'users' => 0];
        }

        // Nạp tên khách theo lô cho nội dung thông báo.
        $customerIds = [];
        foreach ($rows as $r)
        {
            $customerIds[(int) $r->customer_id] = true;
        }

        $names = [];
        if (!empty($customerIds))
        {
            foreach (Customer::whereIn('id', array_keys($customerIds))->get() as $c)
            {
                $names[(int) $c->id] = (string) $c->full_name;
            }
        }

        $ids   = [];
        $users = [];
        foreach ($rows as $r)
        {
            $uid = (int) $r->assigned_user_id;
            $ids[] = (int) $r->id;

            if ($uid <= 0)
            {
                continue;
            }

            $time = date('H:i', strtotime((string) $r->scheduled_at));
            $name = $names[(int) $r->customer_id] ?? 'khách';

            Notifier::send($uid, 'info', 'Sắp đến giờ hẹn dẫn khách',
                'Buổi hẹn với ' . $name . ' lúc ' . $time . '.', '/appointments');

            $users[$uid] = true;
        }

        // Đánh dấu đã nhắc (cả buổi hẹn không có sales để không quét lại mãi).
        Appointment::whereIn('id', $ids)->update(['reminded_at' => date('Y-m-d H:i:s', $now)]);

        return ['reminded' => count($ids), 'users' => count($users)];
    }
}
