<?php

namespace App\Services\Deal;

use App\Models\Deal;
use App\Services\Notification\Notifier;

/**
 * Tick cảnh báo GIAO DỊCH TREO (chạy hằng ngày).
 *
 * Deal còn ĐANG MỞ (`status` ∈ deposit/contract) nhưng quá `DEAL_STALE_DAYS` ngày (mặc định 7)
 * không được cập nhật (`updated` — DB tự bump khi sửa/chuyển giai đoạn) → gửi DIGEST cảnh báo cho
 * sales phụ trách ("Bạn có N giao dịch đang treo"). Dùng Notifier::sendUnique (type+link) để KHÔNG
 * spam mỗi ngày: chỉ nhắc lại sau khi user đã đọc.
 *
 * GOTCHA: `deals.updated` KHÔNG đổi khi chỉ thêm đợt thu (DealApi::addPayment không chạm bảng
 * deals), nên "lâu không cập nhật" ở đây = lâu không sửa deal / không chuyển giai đoạn. Global
 * scope tự loại deal đã xoá mềm (trash). Bảng chưa migrate → thoát êm.
 */
class DealStaleWatcher
{
    const BATCH = 500;

    /** @return array{users:int, stale:int} */
    public function tick(): array
    {
        if (!schema()->hasTable('deals'))
        {
            return ['users' => 0, 'stale' => 0];
        }

        $days = (int) env('DEAL_STALE_DAYS', 7);
        if ($days <= 0)
        {
            $days = 7;
        }

        $threshold = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        $rows = Deal::whereIn('status', ['deposit', 'contract'])
            ->where('updated', '<', $threshold)
            ->limit(self::BATCH)
            ->get(['assigned_user_id']);

        // Gom số deal treo theo từng sales.
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
                'Giao dịch đang treo',
                'Bạn có ' . $n . ' giao dịch chưa chuyển giai đoạn quá ' . $days . ' ngày. Kiểm tra và cập nhật.',
                '/deals'
            );
        }

        return ['users' => count($counts), 'stale' => count($rows)];
    }
}
