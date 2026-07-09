<?php

namespace App\Services\Matching;

use App\Models\Customer;
use App\Models\CustomerDemand;
use App\Models\Property;
use App\Services\Notification\Notifier;

/**
 * Tick AUTO-MATCHING (chạy mỗi phút) — chủ động đẩy cơ hội khớp Khách ↔ BĐS cho sales, thay vì
 * bắt họ tự mở tab Gợi ý khớp dò tay.
 *
 * Quét theo cờ `match_scanned` (0 = chưa quét), xử lý MỘT lô rồi thoát (không worker thường trú):
 *   - Chiều BĐS → Khách: BĐS mới (available) khớp nhu cầu active nào → báo sales PHỤ TRÁCH KHÁCH đó.
 *   - Chiều Khách → BĐS: nhu cầu mới/đổi tiêu chí có BĐS khớp trong kho (của sales + kho chung) →
 *     báo sales phụ trách khách.
 *
 * Toàn bộ so khớp tái dùng MatchEngine (1 nguồn logic, on-the-fly — không cache kết quả). Gom
 * DIGEST theo sales (1 thông báo/sales/lô/chiều) để không spam. Mỗi bản ghi chỉ quét 1 lần (set
 * cờ = 1 sau lô) nên dùng Notifier::send (không cần chống trùng như tick nhắc lịch định kỳ).
 *
 * GIỚI HẠN GĐ2.1 (có chủ đích): chỉ BĐS/nhu cầu MỚI kích hoạt. Sửa BĐS (kể cả chuyển sang
 * available) KHÔNG quét lại; sửa nhu cầu thì có (updateDemand reset cờ). Bảng chưa migrate/thiếu
 * cột → thoát êm.
 */
class MatchScanner
{
    /** Trần mỗi lô để tick nhẹ (BĐS quét ngược tốn hơn nên lô nhỏ hơn nhu cầu). */
    const PROPERTY_BATCH = 100;
    const DEMAND_BATCH   = 200;

    /** @return array{props:int, demands:int, notified:int} */
    public function tick(): array
    {
        // Cột match_scanned chưa migrate → chưa bật tính năng, thoát êm.
        if (!schema()->hasColumn('properties', 'match_scanned') || !schema()->hasColumn('customer_demands', 'match_scanned'))
        {
            return ['props' => 0, 'demands' => 0, 'notified' => 0];
        }

        [$pProcessed, $pNotified] = $this->scanNewProperties();
        [$dProcessed, $dNotified] = $this->scanNewDemands();

        return [
            'props'    => $pProcessed,
            'demands'  => $dProcessed,
            'notified' => $pNotified + $dNotified,
        ];
    }

    /**
     * BĐS mới (match_scanned=0) → khách có nhu cầu khớp → báo sales phụ trách các khách đó.
     *
     * @return array{0:int,1:int} [số BĐS đã xử lý, số sales được báo]
     */
    protected function scanNewProperties(): array
    {
        $rows = Property::where('match_scanned', 0)->limit(self::PROPERTY_BATCH)->get();
        if (count($rows) === 0)
        {
            return [0, 0];
        }

        $ids     = [];
        $perUser = []; // userId => số BĐS mới khớp khách của họ
        foreach ($rows as $property)
        {
            $ids[] = (int) $property->id;

            // BĐS không còn bán thì không có gì để khớp (vẫn đánh dấu đã quét ở dưới).
            if ((string) $property->status !== 'available')
            {
                continue;
            }

            foreach ($this->ownersMatchingProperty($property) as $uid)
            {
                $perUser[$uid] = ($perUser[$uid] ?? 0) + 1;
            }
        }

        Property::whereIn('id', $ids)->update(['match_scanned' => 1]);

        foreach ($perUser as $uid => $n)
        {
            Notifier::send(
                (int) $uid,
                'info',
                'Có bất động sản mới khớp khách của bạn',
                'Vừa có ' . $n . ' bất động sản mới phù hợp với khách bạn đang phụ trách. Mở Gợi ý khớp để gửi cho khách.',
                '/matching'
            );
        }

        return [count($ids), count($perUser)];
    }

    /**
     * Sales phụ trách các khách có nhu cầu ĐANG BẬT khớp 1 BĐS (distinct userId). Lọc sơ nhu cầu
     * ở SQL (loại giao dịch/loại hình/tỉnh) rồi verify chính xác bằng MatchEngine::matchesProperty
     * — cùng cách matchCustomers ở PropertyApi.
     *
     * @return int[]
     */
    protected function ownersMatchingProperty($property): array
    {
        $demandType = ((string) $property->transaction_type === 'rent') ? 'rent' : 'buy';

        $demands = CustomerDemand::where('is_active', 1)
            ->where('demand_type', $demandType)
            ->where(function ($q) use ($property) {
                $q->where('property_type', '')->orWhere('property_type', (string) $property->property_type);
            })
            ->where(function ($q) use ($property) {
                $q->where('province_code', 0)->orWhere('province_code', (int) $property->province_code);
            })
            ->get();

        $customerIds = [];
        foreach ($demands as $demand)
        {
            if (MatchEngine::matchesProperty($demand, $property))
            {
                $customerIds[(int) $demand->customer_id] = true;
            }
        }

        if (empty($customerIds))
        {
            return [];
        }

        $owners = [];
        foreach (Customer::whereIn('id', array_keys($customerIds))->get(['id', 'assigned_user_id']) as $c)
        {
            $uid = (int) $c->assigned_user_id;
            if ($uid > 0)
            {
                $owners[$uid] = true;
            }
        }

        return array_keys($owners);
    }

    /**
     * Nhu cầu mới/đổi tiêu chí (match_scanned=0) → có BĐS khớp trong kho (của sales + kho chung)
     * không → báo sales phụ trách khách.
     *
     * @return array{0:int,1:int} [số nhu cầu đã xử lý, số sales được báo]
     */
    protected function scanNewDemands(): array
    {
        $rows = CustomerDemand::where('match_scanned', 0)->limit(self::DEMAND_BATCH)->get();
        if (count($rows) === 0)
        {
            return [0, 0];
        }

        // Nạp khách của lô nhu cầu (1 truy vấn) để lấy sales phụ trách.
        $custIds = [];
        foreach ($rows as $d)
        {
            $custIds[(int) $d->customer_id] = true;
        }

        $customers = [];
        foreach (Customer::whereIn('id', array_keys($custIds))->get(['id', 'assigned_user_id']) as $c)
        {
            $customers[(int) $c->id] = $c;
        }

        $ids     = [];
        $perUser = []; // userId => số khách vừa có BĐS phù hợp
        foreach ($rows as $demand)
        {
            $ids[] = (int) $demand->id;

            // Nhu cầu tắt → không gợi ý (vẫn đánh dấu đã quét).
            if ((int) $demand->is_active !== 1)
            {
                continue;
            }

            $customer = $customers[(int) $demand->customer_id] ?? null;
            if ($customer === null)
            {
                continue;
            }

            $uid = (int) $customer->assigned_user_id;
            if ($uid <= 0)
            {
                continue; // khách ở kho chung, chưa có sales để báo
            }

            $query = MatchEngine::matchQueryForDemand($demand);
            if ($query === null)
            {
                continue; // nhu cầu sell/consign — không so với kho
            }

            // Chỉ tính hàng sales này THẤY: của mình + kho chung (shared) — khớp data-scope FE.
            $query->where(function ($q) use ($uid) {
                $q->where('assigned_user_id', $uid)->orWhere('visibility', 'shared');
            });

            if ($query->count() > 0)
            {
                $perUser[$uid] = ($perUser[$uid] ?? 0) + 1;
            }
        }

        CustomerDemand::whereIn('id', $ids)->update(['match_scanned' => 1]);

        foreach ($perUser as $uid => $n)
        {
            Notifier::send(
                (int) $uid,
                'info',
                'Có bất động sản phù hợp cho khách',
                $n . ' khách của bạn vừa có bất động sản phù hợp trong kho. Mở Gợi ý khớp để gửi cho khách.',
                '/matching'
            );
        }

        return [count($ids), count($perUser)];
    }
}
