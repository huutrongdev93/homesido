<?php

namespace App\Services\Matching;

use App\Models\Property;

/**
 * Engine so khớp Khách ↔ BĐS (Matching GĐ2). Biến tiêu chí `customer_demands` thành gợi ý
 * BĐS trong kho, và ngược lại gợi ý khách cho 1 BĐS.
 *
 * TÍNH ON-THE-FLY — không lưu cache kết quả (tránh lỗi thời). Toàn bộ là HÀM THUẦN (giống
 * LeadScorer): 2 chiều dùng chung 1 bộ tiêu chí:
 *   - `matchQueryForDemand()` — dựng query kho BĐS thỏa 1 nhu cầu (chiều Khách → BĐS).
 *   - `matchesProperty()`     — kiểm 1 BĐS có thỏa 1 nhu cầu không (chiều BĐS → Khách).
 *   - `score()` / `reasons()` — chấm độ khớp 0–100 (dùng chung cho cả 2 chiều).
 *
 * Chỉ nhu cầu `buy`/`rent` mới so với kho BĐS (`sell`/`consign` = khách muốn bán → không có
 * gì để gợi ý từ kho hàng).
 */
class MatchEngine
{
    /** Ánh xạ loại nhu cầu → hình thức giao dịch của BĐS. buy→bán, rent→cho thuê. */
    const DEMAND_TRANSACTION = ['buy' => 'sale', 'rent' => 'rent'];

    /** Nới biên ngân sách khi lọc cứng (khách thường co giãn ~10% quanh khoảng đã khai). */
    const BUDGET_MARGIN = 0.1;

    /** Hình thức giao dịch tương ứng loại nhu cầu; null nếu nhu cầu không khớp kho (sell/consign). */
    public static function transactionFor(string $demandType): ?string
    {
        return self::DEMAND_TRANSACTION[$demandType] ?? null;
    }

    /**
     * Dựng query BĐS thỏa 1 nhu cầu (chiều Khách → BĐS). Trả `null` nếu nhu cầu không so được
     * với kho (sell/consign). Chỉ HARD FILTER (loại/hình thức/khu vực/khoảng giá + còn hàng);
     * ward/diện tích/PN/hướng để chấm điểm (score) chứ không loại cứng.
     */
    public static function matchQueryForDemand($demand)
    {
        $transaction = self::transactionFor((string) $demand->demand_type);
        if ($transaction === null)
        {
            return null;
        }

        // Global scope tự lọc trash=0. Chỉ hàng CÒN BÁN mới đem gợi ý.
        $query = Property::query()
            ->where('status', 'available')
            ->where('transaction_type', $transaction);

        $propertyType = (string) $demand->property_type;
        if ($propertyType !== '')
        {
            $query->where('property_type', $propertyType);
        }

        $province = (int) $demand->province_code;
        if ($province > 0)
        {
            $query->where('province_code', $province);
        }

        // Chỉ lọc giá khi khách đã khai TRẦN ngân sách (budget_max > 0); nới ±BUDGET_MARGIN.
        $budgetMax = (float) $demand->budget_max;
        if ($budgetMax > 0)
        {
            $budgetMin = (float) $demand->budget_min;
            $query->where('price', '>=', max(0, $budgetMin * (1 - self::BUDGET_MARGIN)))
                  ->where('price', '<=', $budgetMax * (1 + self::BUDGET_MARGIN));
        }

        return $query;
    }

    /**
     * 1 BĐS có thỏa HARD FILTER của 1 nhu cầu không (chiều BĐS → Khách). Cùng bộ điều kiện với
     * `matchQueryForDemand` nhưng kiểm trên object BĐS đã nạp sẵn (tránh N+1 query mỗi khách).
     */
    public static function matchesProperty($demand, $property): bool
    {
        $transaction = self::transactionFor((string) $demand->demand_type);
        if ($transaction === null)
        {
            return false;
        }

        if ((string) $property->status !== 'available')
        {
            return false;
        }

        if ((string) $property->transaction_type !== $transaction)
        {
            return false;
        }

        $propertyType = (string) $demand->property_type;
        if ($propertyType !== '' && (string) $property->property_type !== $propertyType)
        {
            return false;
        }

        $province = (int) $demand->province_code;
        if ($province > 0 && (int) $property->province_code !== $province)
        {
            return false;
        }

        $budgetMax = (float) $demand->budget_max;
        if ($budgetMax > 0)
        {
            $budgetMin = (float) $demand->budget_min;
            $price     = (float) $property->price;
            if ($price < $budgetMin * (1 - self::BUDGET_MARGIN) || $price > $budgetMax * (1 + self::BUDGET_MARGIN))
            {
                return false;
            }
        }

        return true;
    }

    /**
     * Chấm độ khớp 0–100 giữa 1 nhu cầu và 1 BĐS (giả định đã qua hard filter). HÀM THUẦN.
     * Base 40 (đã khớp loại/hình thức/khu vực/giá cơ bản) + các tiêu chí mềm cộng thêm.
     */
    public static function score($demand, $property): int
    {
        $score = 40;

        // Cùng phường/xã — tín hiệu vị trí mạnh nhất sau tỉnh.
        $ward = (int) $demand->ward_code;
        if ($ward > 0 && (int) $property->ward_code === $ward)
        {
            $score += 20;
        }

        // Giá nằm gọn trong khoảng khách khai (không tính phần nới biên).
        $budgetMax = (float) $demand->budget_max;
        if ($budgetMax > 0)
        {
            $price = (float) $property->price;
            if ($price >= (float) $demand->budget_min && $price <= $budgetMax)
            {
                $score += 15;
            }
        }

        // Diện tích sử dụng nằm trong khoảng khách khai (thiếu thì lấy diện tích đất).
        $areaMax = (float) $demand->area_max;
        if ($areaMax > 0)
        {
            $area = (float) $property->area_usable;
            if ($area <= 0)
            {
                $area = (float) $property->area_land;
            }
            if ($area >= (float) $demand->area_min && $area <= $areaMax)
            {
                $score += 15;
            }
        }

        // Đủ số phòng ngủ tối thiểu.
        $bedroomsMin = (int) $demand->bedrooms_min;
        if ($bedroomsMin > 0 && (int) $property->bedrooms >= $bedroomsMin)
        {
            $score += 5;
        }

        // Đúng hướng mong muốn.
        $direction = (string) $demand->direction;
        if ($direction !== '' && (string) $property->direction === $direction)
        {
            $score += 5;
        }

        return max(0, min(100, $score));
    }

    /**
     * Danh sách lý do khớp (nhãn tiếng Việt) để hiển thị chip gợi ý ở FE. Song song với `score`.
     *
     * @return string[]
     */
    public static function reasons($demand, $property): array
    {
        $reasons = ['Đúng loại & khu vực'];

        $ward = (int) $demand->ward_code;
        if ($ward > 0 && (int) $property->ward_code === $ward)
        {
            $reasons[] = 'Đúng phường/xã';
        }

        $budgetMax = (float) $demand->budget_max;
        if ($budgetMax > 0)
        {
            $price = (float) $property->price;
            if ($price >= (float) $demand->budget_min && $price <= $budgetMax)
            {
                $reasons[] = 'Trong tầm giá';
            }
        }

        $areaMax = (float) $demand->area_max;
        if ($areaMax > 0)
        {
            $area = (float) $property->area_usable;
            if ($area <= 0)
            {
                $area = (float) $property->area_land;
            }
            if ($area >= (float) $demand->area_min && $area <= $areaMax)
            {
                $reasons[] = 'Đúng diện tích';
            }
        }

        $bedroomsMin = (int) $demand->bedrooms_min;
        if ($bedroomsMin > 0 && (int) $property->bedrooms >= $bedroomsMin)
        {
            $reasons[] = 'Đủ phòng ngủ';
        }

        $direction = (string) $demand->direction;
        if ($direction !== '' && (string) $property->direction === $direction)
        {
            $reasons[] = 'Đúng hướng';
        }

        return $reasons;
    }
}
