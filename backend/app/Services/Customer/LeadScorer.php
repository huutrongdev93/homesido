<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\CustomerInteraction;

/**
 * Chấm điểm tiềm năng khách (`customers.lead_score`, thang 0–100).
 *
 * Điểm tổng hợp từ các tín hiệu:
 *   - Giai đoạn phễu (`pipeline_stage`) — càng sâu càng cao; won = 100, lost = 0 (chốt cứng).
 *   - Tần suất tương tác (số dòng `customer_interactions`) — mỗi tương tác +2, trần 20.
 *   - Độ MỚI của tương tác gần nhất (`last_interaction_at`) — vừa chạm thì cao, để lâu giảm dần.
 *   - Nhiệt độ (`temperature`) — tín hiệu quan tâm thủ công của sales.
 *
 * Tính ở 2 nơi: (1) `recompute()` ngay khi có tương tác / sửa khách (phản hồi tức thì);
 * (2) `tick()` nền hằng ngày để phản ánh SUY GIẢM độ mới theo thời gian (không có sự kiện nào
 * kích hoạt khi ngày trôi qua). Hàm `computeScore()` thuần → dùng chung cả 2 đường + lúc import.
 */
class LeadScorer
{
    const BATCH = 500;

    /** Điểm nền theo giai đoạn phễu. won/lost xử lý riêng (chốt cứng 100 / 0). */
    const STAGE_BASE = [
        'new'         => 5,
        'contacting'  => 20,
        'potential'   => 40,
        'negotiating' => 65,
        'won'         => 100,
        'lost'        => 0,
    ];

    /** Điểm cộng theo nhiệt độ. */
    const TEMP_BONUS = ['hot' => 15, 'warm' => 5, 'cold' => 0];

    /**
     * Tính điểm tiềm năng (0–100) từ các tín hiệu. Hàm THUẦN — không truy vấn DB.
     */
    public static function computeScore(string $stage, string $temperature, int $interactionCount, ?string $lastInteractionAt): int
    {
        if ($stage === 'won')  return 100;
        if ($stage === 'lost') return 0;

        $score  = self::STAGE_BASE[$stage] ?? self::STAGE_BASE['new'];
        $score += min(max($interactionCount, 0), 10) * 2;         // tần suất: trần 20
        $score += self::recencyPoints($lastInteractionAt);         // độ mới: 0–15
        $score += self::TEMP_BONUS[$temperature] ?? self::TEMP_BONUS['warm'];

        return max(0, min(100, $score));
    }

    /** Điểm theo độ mới của tương tác gần nhất (giảm dần theo số ngày). */
    protected static function recencyPoints(?string $lastInteractionAt): int
    {
        if (empty($lastInteractionAt))
        {
            return 0;
        }

        $days = (time() - strtotime((string) $lastInteractionAt)) / 86400;

        if ($days <= 3)  return 15;
        if ($days <= 7)  return 10;
        if ($days <= 14) return 5;

        return 0;
    }

    /**
     * Tính lại điểm cho 1 khách và lưu nếu đổi. Fire-and-forget: tự nuốt lỗi để KHÔNG chặn
     * luồng chính (ghi tương tác / sửa khách vẫn thành công dù chấm điểm lỗi). Gọi sau add /
     * update / addInteraction / hoàn thành chăm sóc.
     */
    public static function recompute(int $customerId): void
    {
        try
        {
            $customer = Customer::where('id', $customerId)->first();
            if (!hasItems($customer))
            {
                return;
            }

            $count = (int) CustomerInteraction::where('customer_id', $customerId)->count();

            $score = self::computeScore(
                (string) $customer->pipeline_stage,
                (string) $customer->temperature,
                $count,
                $customer->last_interaction_at
            );

            if ((int) $customer->lead_score !== $score)
            {
                Customer::where('id', $customerId)->update(['lead_score' => $score]);
            }
        }
        catch (\Throwable $e)
        {
            // Chấm điểm là phụ trợ — không được làm hỏng thao tác chính.
        }
    }

    /**
     * Tick nền: rescore TOÀN BỘ khách để phản ánh suy giảm độ mới theo thời gian. Đếm tương tác
     * theo khách bằng 1 truy vấn gộp, rồi duyệt khách theo lô, chỉ ghi khi điểm đổi.
     *
     * @return array{scored:int}
     */
    public function tick(): array
    {
        if (!schema()->hasTable('customers'))
        {
            return ['scored' => 0];
        }

        // Số tương tác theo từng khách — 1 truy vấn gộp (tránh N+1).
        $counts = [];
        foreach (CustomerInteraction::query()->selectRaw('customer_id, COUNT(*) as cnt')->groupBy('customer_id')->get() as $row)
        {
            $counts[(int) $row->customer_id] = (int) $row->cnt;
        }

        $scored = 0;
        $offset = 0;

        while (true)
        {
            $rows = Customer::query()
                ->orderBy('id')
                ->offset($offset)
                ->limit(self::BATCH)
                ->get(['id', 'pipeline_stage', 'temperature', 'last_interaction_at', 'lead_score']);

            if (!hasItems($rows) || count($rows) === 0)
            {
                break;
            }

            foreach ($rows as $row)
            {
                $score = self::computeScore(
                    (string) $row->pipeline_stage,
                    (string) $row->temperature,
                    $counts[(int) $row->id] ?? 0,
                    $row->last_interaction_at
                );

                if ((int) $row->lead_score !== $score)
                {
                    Customer::where('id', (int) $row->id)->update(['lead_score' => $score]);
                    $scored++;
                }
            }

            if (count($rows) < self::BATCH)
            {
                break;
            }

            $offset += self::BATCH;
        }

        return ['scored' => $scored];
    }
}
