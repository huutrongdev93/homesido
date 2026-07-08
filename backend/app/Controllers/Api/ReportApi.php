<?php

namespace App\Controllers\Api;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\LeadSource;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SkillDo\Cms\Models\User;
use SkillDo\Http\Request;

/**
 * API Báo cáo (GĐ2) — tổng hợp read-only từ dữ liệu sẵn có (customers/deals/lead_sources/users).
 *
 * Gộp 4 mục vào 1 endpoint `GET api/report`: phễu conversion (khách theo pipeline_stage), nguồn khách,
 * doanh số (deals theo giai đoạn + hoa hồng + doanh thu 6 tháng), hiệu suất theo nhân viên.
 * Data-scope: không có `report_view_all` → chỉ số của mình (`assigned_user_id = me`); có → toàn sàn,
 * lọc được theo `assigned_user_id`. Tính bằng cách nạp 1 lô rồi tally PHP (dữ liệu 1 sàn — nhỏ).
 * Xuất Excel dùng chỉ số cột số + Coordinate::stringFromColumnIndex (KHÔNG dùng `$col++` như CustomerSheet).
 */
class ReportApi extends ApiController
{
    const STAGE_LABELS = [
        'new'         => 'Lead mới',
        'contacting'  => 'Đang chăm',
        'potential'   => 'Tiềm năng',
        'negotiating' => 'Đàm phán',
        'won'         => 'Chốt thành công',
        'lost'        => 'Thất bại',
    ];

    const DEAL_STATUS_LABELS = [
        'deposit'   => 'Đặt cọc',
        'contract'  => 'Đã ký hợp đồng',
        'completed' => 'Hoàn tất',
        'canceled'  => 'Đã hủy',
    ];

    /** GET api/report — số liệu tổng hợp (JSON) cho trang Báo cáo. */
    public function index(Request $request): void
    {
        $this->requireCap('report_view', 'Bạn không có quyền xem báo cáo.');

        response()->success('success', $this->buildReport($request));
    }

    /** GET api/report/export — xuất báo cáo ra Excel (.xlsx). */
    public function export(Request $request): void
    {
        $this->requireCap('report_view', 'Bạn không có quyền xem báo cáo.');

        $report = $this->buildReport($request);

        $this->streamXlsx($this->buildWorkbook($report), 'bao-cao-' . date('Ymd-His') . '.xlsx');
    }

    // ─── Tổng hợp ──────────────────────────────────────────────────────────────────────

    /** Dựng toàn bộ số liệu báo cáo (dùng chung cho index + export). */
    protected function buildReport(Request $request): array
    {
        $viewAll = $this->canViewAll('report_view_all');

        // scopeUser: 0 = toàn sàn (chỉ khi view_all + không lọc); >0 = giới hạn 1 nhân viên.
        $filterUser = (int) $request->input('assigned_user_id');
        $scopeUser  = !$viewAll ? $this->userId() : ($filterUser > 0 ? $filterUser : 0);

        [$from, $to] = $this->dateRange($request);

        // ── Nạp khách trong phạm vi ──
        $custQuery = Customer::query();
        $this->applyScope($custQuery, $scopeUser, 'assigned_user_id');
        $this->applyDate($custQuery, $from, $to, 'created');
        $customers = $custQuery->get();

        $byStage = array_fill_keys(CustomerApi::PIPELINE_STAGES, 0);
        $sourceTally = [];   // sid => ['total'=>, 'won'=>]
        $custByUser  = [];   // uid => ['customers'=>, 'won'=>]
        $total = 0; $won = 0; $lost = 0;

        foreach ($customers as $c)
        {
            $stage = (string) $c->pipeline_stage;
            if (isset($byStage[$stage]))
            {
                $byStage[$stage]++;
            }
            $total++;
            $isWon = ($stage === 'won');
            if ($isWon)  $won++;
            if ($stage === 'lost') $lost++;

            $sid = (int) $c->lead_source_id;
            if (!isset($sourceTally[$sid])) $sourceTally[$sid] = ['total' => 0, 'won' => 0];
            $sourceTally[$sid]['total']++;
            if ($isWon) $sourceTally[$sid]['won']++;

            $uid = (int) $c->assigned_user_id;
            if (!isset($custByUser[$uid])) $custByUser[$uid] = ['customers' => 0, 'won' => 0];
            $custByUser[$uid]['customers']++;
            if ($isWon) $custByUser[$uid]['won']++;
        }

        // ── Nạp giao dịch trong phạm vi ──
        $dealQuery = Deal::query();
        $this->applyScope($dealQuery, $scopeUser, 'assigned_user_id');
        $this->applyDate($dealQuery, $from, $to, 'created');
        $deals = $dealQuery->get();

        $byStatus = [];
        foreach (DealApi::STATUSES as $st)
        {
            $byStatus[$st] = ['count' => 0, 'value' => 0.0];
        }
        $revenue = 0.0; $commission = 0.0; $pipelineValue = 0.0;
        $dealByUser = [];   // uid => ['deals'=>, 'completed'=>, 'revenue'=>, 'commission'=>]
        $months = $this->last6Months();

        foreach ($deals as $d)
        {
            $st  = (string) $d->status;
            $val = (float) $d->value;
            if (isset($byStatus[$st]))
            {
                $byStatus[$st]['count']++;
                $byStatus[$st]['value'] += $val;
            }
            if ($st === 'completed')
            {
                $revenue    += $val;
                $commission += (float) $d->commission_amount;

                $mk = substr((string) $d->completed_at, 0, 7);
                if ($mk !== '' && isset($months[$mk])) $months[$mk] += $val;
            }
            if ($st === 'deposit' || $st === 'contract')
            {
                $pipelineValue += $val;
            }

            $uid = (int) $d->assigned_user_id;
            if (!isset($dealByUser[$uid])) $dealByUser[$uid] = ['deals' => 0, 'completed' => 0, 'revenue' => 0.0, 'commission' => 0.0];
            $dealByUser[$uid]['deals']++;
            if ($st === 'completed')
            {
                $dealByUser[$uid]['completed']++;
                $dealByUser[$uid]['revenue']    += $val;
                $dealByUser[$uid]['commission'] += (float) $d->commission_amount;
            }
        }

        return [
            'scope'   => $viewAll ? ($scopeUser > 0 ? 'user' : 'all') : 'own',
            'range'   => ['from' => $from, 'to' => $to],
            'funnel'  => [
                'by_stage' => $byStage,
                'total'    => $total,
                'won'      => $won,
                'lost'     => $lost,
                'won_rate' => $total > 0 ? round($won * 100 / $total, 1) : 0,
            ],
            'sources' => $this->buildSources($sourceTally),
            'sales'   => [
                'by_status'      => $byStatus,
                'total_deals'    => count($deals),
                'revenue'        => $revenue,
                'commission'     => $commission,
                'pipeline_value' => $pipelineValue,
                'monthly'        => array_map(fn ($k) => ['month' => $k, 'value' => $months[$k]], array_keys($months)),
            ],
            'team'    => $this->buildTeam($custByUser, $dealByUser),
        ];
    }

    /** Nguồn khách → mảng (kèm tên; id 0 = "Không rõ nguồn"), sắp theo tổng giảm dần. */
    protected function buildSources(array $tally): array
    {
        $ids = array_filter(array_keys($tally), fn ($id) => $id > 0);

        $names = [];
        if (!empty($ids))
        {
            foreach (LeadSource::whereIn('id', $ids)->get() as $s)
            {
                $names[(int) $s->id] = (string) $s->name;
            }
        }

        $rows = [];
        foreach ($tally as $sid => $t)
        {
            $rows[] = [
                'id'    => $sid,
                'name'  => $sid > 0 ? ($names[$sid] ?? '(nguồn đã xóa)') : 'Không rõ nguồn',
                'total' => $t['total'],
                'won'   => $t['won'],
            ];
        }

        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $rows;
    }

    /** Hiệu suất theo nhân viên → mảng (kèm tên), sắp theo doanh thu giảm dần. */
    protected function buildTeam(array $custByUser, array $dealByUser): array
    {
        $uids = array_unique(array_merge(array_keys($custByUser), array_keys($dealByUser)));
        $uids = array_filter($uids, fn ($id) => $id > 0);

        $names = [];
        if (!empty($uids))
        {
            foreach (User::whereIn('id', $uids)->get() as $u)
            {
                $names[(int) $u->id] = $this->userLabel($u);
            }
        }

        $rows = [];
        foreach ($uids as $uid)
        {
            $cust = $custByUser[$uid] ?? ['customers' => 0, 'won' => 0];
            $deal = $dealByUser[$uid] ?? ['deals' => 0, 'completed' => 0, 'revenue' => 0.0, 'commission' => 0.0];

            $rows[] = [
                'user_id'    => $uid,
                'name'       => $names[$uid] ?? ('#' . $uid),
                'customers'  => $cust['customers'],
                'won'        => $cust['won'],
                'deals'      => $deal['deals'],
                'completed'  => $deal['completed'],
                'revenue'    => $deal['revenue'],
                'commission' => $deal['commission'],
            ];
        }

        usort($rows, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return $rows;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────────

    /** Khoảng ngày [from, to] dạng 'Y-m-d H:i:s' (rỗng nếu không lọc). */
    protected function dateRange(Request $request): array
    {
        $from = ''; $to = '';

        $f = trim((string) $request->input('from'));
        if ($f !== '' && ($ts = strtotime($f)) !== false)
        {
            $from = date('Y-m-d 00:00:00', $ts);
        }

        $t = trim((string) $request->input('to'));
        if ($t !== '' && ($ts = strtotime($t)) !== false)
        {
            $to = date('Y-m-d 23:59:59', $ts);
        }

        return [$from, $to];
    }

    protected function applyScope($query, int $scopeUser, string $col): void
    {
        if ($scopeUser > 0)
        {
            $query->where($col, $scopeUser);
        }
    }

    protected function applyDate($query, string $from, string $to, string $col): void
    {
        if ($from !== '') $query->where($col, '>=', $from);
        if ($to !== '')   $query->where($col, '<=', $to);
    }

    /** 6 tháng gần nhất (cũ → mới) => [YYYY-MM => 0.0]. */
    protected function last6Months(): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--)
        {
            $months[date('Y-m', strtotime("first day of -$i month"))] = 0.0;
        }
        return $months;
    }

    /** Nhãn tên nhân viên: "họ tên" hoặc username. */
    protected function userLabel($user): string
    {
        $name = trim((string) $user->lastname . ' ' . (string) $user->firstname);
        return $name !== '' ? $name : (string) $user->username;
    }

    // ─── Xuất Excel ─────────────────────────────────────────────────────────────────

    /** Dựng workbook 1 sheet gồm 4 mục (tiền để nguyên VNĐ). */
    protected function buildWorkbook(array $report): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Báo cáo');

        $r = 1;
        $r = $this->writeTitle($sheet, $r, 'PHỄU KHÁCH HÀNG');
        $r = $this->writeHeader($sheet, $r, ['Giai đoạn', 'Số khách']);
        foreach (CustomerApi::PIPELINE_STAGES as $stage)
        {
            $this->writeRow($sheet, $r++, [self::STAGE_LABELS[$stage] ?? $stage, $report['funnel']['by_stage'][$stage] ?? 0]);
        }
        $this->writeRow($sheet, $r++, ['Tổng', $report['funnel']['total']]);
        $this->writeRow($sheet, $r++, ['Tỉ lệ chốt (%)', $report['funnel']['won_rate']]);
        $r++;

        $r = $this->writeTitle($sheet, $r, 'NGUỒN KHÁCH');
        $r = $this->writeHeader($sheet, $r, ['Nguồn', 'Tổng khách', 'Đã chốt']);
        foreach ($report['sources'] as $s)
        {
            $this->writeRow($sheet, $r++, [$s['name'], $s['total'], $s['won']]);
        }
        $r++;

        $r = $this->writeTitle($sheet, $r, 'DOANH SỐ');
        $r = $this->writeHeader($sheet, $r, ['Giai đoạn', 'Số GD', 'Giá trị (VNĐ)']);
        foreach (DealApi::STATUSES as $st)
        {
            $bs = $report['sales']['by_status'][$st] ?? ['count' => 0, 'value' => 0];
            $this->writeRow($sheet, $r++, [self::DEAL_STATUS_LABELS[$st] ?? $st, $bs['count'], $bs['value']]);
        }
        $this->writeRow($sheet, $r++, ['Doanh thu (hoàn tất)', '', $report['sales']['revenue']]);
        $this->writeRow($sheet, $r++, ['Hoa hồng (hoàn tất)', '', $report['sales']['commission']]);
        $r++;

        $r = $this->writeTitle($sheet, $r, 'HIỆU SUẤT NHÂN VIÊN');
        $r = $this->writeHeader($sheet, $r, ['Nhân viên', 'Khách', 'Chốt', 'Giao dịch', 'Hoàn tất', 'Doanh thu (VNĐ)', 'Hoa hồng (VNĐ)']);
        foreach ($report['team'] as $t)
        {
            $this->writeRow($sheet, $r++, [$t['name'], $t['customers'], $t['won'], $t['deals'], $t['completed'], $t['revenue'], $t['commission']]);
        }

        foreach (range(1, 7) as $colIdx)
        {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIdx))->setWidth(20);
        }

        return $spreadsheet;
    }

    /** Ghi 1 dòng tiêu đề mục (in đậm), trả về số dòng kế. */
    protected function writeTitle($sheet, int $r, string $title): int
    {
        $sheet->setCellValue('A' . $r, $title);
        $sheet->getStyle('A' . $r)->getFont()->setBold(true)->setSize(13);
        return $r + 1;
    }

    /** Ghi dòng header cột (in đậm), trả về số dòng kế. */
    protected function writeHeader($sheet, int $r, array $cols): int
    {
        $this->writeRow($sheet, $r, $cols);
        $last = Coordinate::stringFromColumnIndex(count($cols));
        $sheet->getStyle('A' . $r . ':' . $last . $r)->getFont()->setBold(true);
        return $r + 1;
    }

    /** Ghi 1 dòng dữ liệu theo chỉ số cột số (KHÔNG dùng $col++). */
    protected function writeRow($sheet, int $r, array $values): void
    {
        $idx = 1;
        foreach ($values as $v)
        {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($idx) . $r, $v);
            $idx++;
        }
    }

    /** Xuất Spreadsheet ra output dạng tải file .xlsx (send-and-exit). */
    protected function streamXlsx(Spreadsheet $spreadsheet, string $filename): void
    {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0, no-store');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');

        $spreadsheet->disconnectWorksheets();
        exit;
    }
}
