<?php

namespace App\Controllers\Api;

use App\Models\CareSchedule;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Property;
use SkillDo\Http\Request;

/**
 * API Dashboard — số liệu tổng hợp cho trang chủ (features/Home).
 *
 * Gộp mọi số liệu vào 1 endpoint `GET api/dashboard` để FE gọi 1 lần. Áp data-scope giống các
 * module: không có `customer_view_all` → chỉ tính khách/việc của mình (`assigned_user_id = me`);
 * không có `property_view_all` → chỉ tính BĐS mình phụ trách. Có view_all → toàn sàn.
 * Đếm theo từng giá trị enum (rẻ, rõ ràng) thay vì groupBy để tránh phụ thuộc builder tuỳ biến.
 */
class DashboardApi extends ApiController
{
    public function index(Request $request): void
    {
        $this->requireCap('customer_view', 'Bạn không có quyền xem trang tổng quan.');

        $me              = $this->userId();
        $viewAllCustomer = $this->canViewAll('customer_view_all');
        $viewAllProperty = $this->canViewAll('property_view_all');

        $endToday   = date('Y-m-d 23:59:59');
        $startToday = date('Y-m-d 00:00:00');
        $monthStart = date('Y-m-01 00:00:00');

        // ── Cần chăm hôm nay (pending, đến hạn cuối hôm nay — gồm quá hạn) ──
        $careToday = CareSchedule::where('status', 'pending')->where('scheduled_at', '<=', $endToday);
        $careOver  = CareSchedule::where('status', 'pending')->where('scheduled_at', '<', $startToday);
        if (!$viewAllCustomer)
        {
            $careToday->where('assigned_user_id', $me);
            $careOver->where('assigned_user_id', $me);
        }

        // ── Khách theo giai đoạn (phễu) + tổng + cờ nguội ──
        $byStage = [];
        $totalCustomers = 0;
        foreach (CustomerApi::PIPELINE_STAGES as $stage)
        {
            $q = Customer::where('pipeline_stage', $stage);
            if (!$viewAllCustomer)
            {
                $q->where('assigned_user_id', $me);
            }
            $count = (int) $q->count();
            $byStage[$stage] = $count;
            $totalCustomers += $count;
        }

        $coldQuery = Customer::where('is_cold_flagged', 1);
        if (!$viewAllCustomer)
        {
            $coldQuery->where('assigned_user_id', $me);
        }

        // ── BĐS theo trạng thái ──
        $byStatus = [];
        $totalProperties = 0;
        foreach (PropertyApi::STATUSES as $status)
        {
            $q = Property::where('status', $status);
            if (!$viewAllProperty)
            {
                $q->where('assigned_user_id', $me);
            }
            $count = (int) $q->count();
            $byStatus[$status] = $count;
            $totalProperties += $count;
        }

        // ── KPI tháng này (khách mới + số tương tác đã thực hiện) ──
        $newCustomers = Customer::where('created', '>=', $monthStart);
        $interactions = CustomerInteraction::where('created', '>=', $monthStart);
        if (!$viewAllCustomer)
        {
            $newCustomers->where('assigned_user_id', $me);
            $interactions->where('user_id', $me);   // tương tác do CHÍNH user thực hiện
        }

        response()->success('success', [
            'scope'      => $viewAllCustomer ? 'all' : 'own',
            'care'       => [
                'today'   => (int) $careToday->count(),
                'overdue' => (int) $careOver->count(),
            ],
            'customers'  => [
                'total'    => $totalCustomers,
                'cold'     => (int) $coldQuery->count(),
                'by_stage' => $byStage,
            ],
            'properties' => [
                'total'     => $totalProperties,
                'by_status' => $byStatus,
            ],
            'month'      => [
                'new_customers' => (int) $newCustomers->count(),
                'interactions'  => (int) $interactions->count(),
            ],
        ]);
    }
}
