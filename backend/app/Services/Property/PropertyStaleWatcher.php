<?php

namespace App\Services\Property;

use App\Models\Property;
use App\Services\Notification\Notifier;

/**
 * Tick cảnh báo BẤT ĐỘNG SẢN TỒN KHO LÂU (chạy hằng ngày).
 *
 * BĐS còn hàng (`status = available`) nhưng quá `PROPERTY_STALE_DAYS` ngày (mặc định 30) không được
 * cập nhật (`updated` — DB tự bump khi sửa) → gửi DIGEST cho sales phụ trách ("Bạn có N BĐS tồn kho
 * lâu") gợi ý đẩy giá / làm mới tin. Dùng Notifier::sendUnique (type+link) để KHÔNG spam mỗi ngày.
 *
 * BĐS kho chung chưa có người phụ trách (assigned_user_id=0) → bỏ qua (không ai để nhắc). Global
 * scope tự loại BĐS đã xoá mềm (trash). Bảng chưa migrate → thoát êm.
 */
class PropertyStaleWatcher
{
    const BATCH = 500;

    /** @return array{users:int, stale:int} */
    public function tick(): array
    {
        if (!schema()->hasTable('properties'))
        {
            return ['users' => 0, 'stale' => 0];
        }

        $days = (int) env('PROPERTY_STALE_DAYS', 30);
        if ($days <= 0)
        {
            $days = 30;
        }

        $threshold = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        $rows = Property::where('status', 'available')
            ->where('updated', '<', $threshold)
            ->limit(self::BATCH)
            ->get(['assigned_user_id']);

        // Gom số BĐS tồn theo từng sales.
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
                (int) $uid,
                'warning',
                'Bất động sản tồn kho lâu',
                'Bạn có ' . $n . ' bất động sản còn hàng quá ' . $days . ' ngày chưa cập nhật. Cân nhắc đẩy giá hoặc làm mới tin.',
                '/properties'
            );
        }

        return ['users' => count($counts), 'stale' => count($rows)];
    }
}
