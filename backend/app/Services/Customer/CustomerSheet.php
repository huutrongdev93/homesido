<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\LeadSource;
use Illuminate\Support\Str;
use App\Services\Customer\LeadScorer;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Xuất / nhập khách hàng bằng Excel (xlsx/xls/csv) — PhpSpreadsheet.
 *
 * Cột chuẩn (theo THỨ TỰ; hàng 1 LUÔN là tiêu đề và bị bỏ qua khi nhập):
 *   họ tên* · SĐT* · SĐT phụ · email · giới tính · năm sinh · địa chỉ · nghề nghiệp ·
 *   giai đoạn · mức độ · nguồn khách · ghi chú
 * Enum xuất ra NHÃN tiếng Việt; nhập chấp nhận cả nhãn VN lẫn mã (không phân biệt hoa thường).
 * Nhập: chống trùng SĐT theo LÔ (trùng trong file + trùng với DB) → báo từng dòng lỗi, không tạo trùng.
 */
class CustomerSheet
{
    /** Nhãn cột (theo thứ tự) — dùng cho header xuất + template. */
    const HEADERS = [
        'Họ tên (*)', 'Số điện thoại (*)', 'SĐT phụ', 'Email', 'Giới tính', 'Năm sinh',
        'Địa chỉ', 'Nghề nghiệp', 'Giai đoạn', 'Mức độ quan tâm', 'Nguồn khách', 'Ghi chú',
    ];

    /** Map mã → nhãn VN (xuất) + đảo lại để nhập. */
    const GENDERS = ['male' => 'Nam', 'female' => 'Nữ', 'other' => 'Khác'];
    const STAGES  = [
        'new' => 'Lead mới', 'contacting' => 'Đang chăm', 'potential' => 'Tiềm năng',
        'negotiating' => 'Đàm phán', 'won' => 'Chốt thành công', 'lost' => 'Thất bại',
    ];
    const TEMPERATURES = ['hot' => 'Nóng', 'warm' => 'Ấm', 'cold' => 'Lạnh'];

    const MAX_EXPORT = 10000;   // trần số dòng xuất 1 lần (tránh cạn RAM).

    /** Đuôi file được chấp nhận khi nhập. */
    const IMPORT_EXT = ['xlsx', 'xls', 'csv'];

    // ── Xuất ──────────────────────────────────────────────────────────────────────────

    /**
     * Dựng spreadsheet từ danh sách khách (đã áp scope/filter ở controller).
     * $rows: iterable các bản ghi Customer.
     */
    public static function buildExport(iterable $rows): Spreadsheet
    {
        // Gom id nguồn khách để nạp tên 1 lần (tránh N+1).
        $sourceIds = [];
        $list      = [];
        foreach ($rows as $row)
        {
            $list[] = $row;
            if ((int) $row->lead_source_id > 0) $sourceIds[(int) $row->lead_source_id] = true;
        }

        $sourceNames = self::sourceNameMap(array_keys($sourceIds));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Khách hàng');

        self::writeHeader($sheet);

        $r = 2;
        foreach ($list as $row)
        {
            $sheet->setCellValue('A' . $r, (string) $row->full_name);
            // SĐT ghi dạng TEXT để giữ số 0 đầu.
            $sheet->setCellValueExplicit('B' . $r, (string) $row->phone, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C' . $r, (string) ($row->phone_alt ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue('D' . $r, (string) ($row->email ?? ''));
            $sheet->setCellValue('E' . $r, self::GENDERS[(string) $row->gender] ?? '');
            $sheet->setCellValue('F' . $r, (int) $row->birth_year > 0 ? (int) $row->birth_year : '');
            $sheet->setCellValue('G' . $r, (string) ($row->address ?? ''));
            $sheet->setCellValue('H' . $r, (string) ($row->occupation ?? ''));
            $sheet->setCellValue('I' . $r, self::STAGES[(string) $row->pipeline_stage] ?? '');
            $sheet->setCellValue('J' . $r, self::TEMPERATURES[(string) $row->temperature] ?? '');
            $sheet->setCellValue('K' . $r, $sourceNames[(int) $row->lead_source_id] ?? '');
            $sheet->setCellValue('L' . $r, (string) ($row->note ?? ''));
            $r++;
        }

        return $spreadsheet;
    }

    /** Template rỗng: header + 1 dòng ví dụ để người dùng biết định dạng. */
    public static function buildTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Khách hàng');

        self::writeHeader($sheet);

        $sample = ['Nguyễn Văn A', '0912345678', '', 'a@email.com', 'Nam', 1990,
            'Quận 1, TP.HCM', 'Kỹ sư', 'Lead mới', 'Ấm', 'Facebook', 'Khách quan tâm căn 2PN'];
        $col = 'A';
        foreach ($sample as $val)
        {
            if ($col === 'B') $sheet->setCellValueExplicit($col . '2', (string) $val, DataType::TYPE_STRING);
            else              $sheet->setCellValue($col . '2', $val);
            $col++;
        }

        return $spreadsheet;
    }

    // ── Nhập ──────────────────────────────────────────────────────────────────────────

    /**
     * Nhập khách từ file upload. Trả tổng kết:
     *   ['total'=>int, 'created'=>int, 'skipped'=>int, 'errors'=>[['row'=>int,'name'=>,'phone'=>,'message'=>]]]
     * Ném \Exception (message tiếng Việt) khi file không đọc được / sai định dạng.
     */
    public static function import($file, int $assignedUserId): array
    {
        if (!$file || !$file->isValid())
        {
            throw new \Exception('Tệp tải lên không hợp lệ.');
        }

        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($ext, self::IMPORT_EXT, true))
        {
            throw new \Exception('Chỉ hỗ trợ tệp Excel (.xlsx, .xls) hoặc CSV.');
        }

        $matrix = self::readRows($file->getRealPath(), $ext);

        // Bỏ hàng tiêu đề (hàng 1).
        array_shift($matrix);

        // ── Lượt 1: parse + gom SĐT để chống trùng theo LÔ ──
        $parsed     = [];
        $phonesSeen = [];   // phone => dòng đầu tiên xuất hiện trong file
        foreach ($matrix as $i => $cells)
        {
            $rowNo = $i + 2;   // +2: bỏ header + đổi về 1-based

            $name  = self::cellStr($cells[0] ?? '');
            $phone = Str::clear(self::cellStr($cells[1] ?? ''));

            // Bỏ qua dòng trống hoàn toàn (không tính là lỗi).
            if ($name === '' && $phone === '' && trim(implode('', array_map([self::class, 'cellStr'], $cells))) === '')
            {
                continue;
            }

            $parsed[] = [
                'row'   => $rowNo,
                'name'  => $name,
                'phone' => $phone,
                'cells' => $cells,
            ];
        }

        // Nạp SĐT đã có trong DB (1 truy vấn) + map nguồn khách theo tên.
        $allPhones = array_values(array_filter(array_map(fn($p) => $p['phone'], $parsed)));
        $existing  = self::existingPhoneMap($allPhones);
        $sourceIds = self::sourceIdByNameMap();

        $created = 0;
        $errors  = [];

        foreach ($parsed as $p)
        {
            $rowNo = $p['row'];
            $name  = $p['name'];
            $phone = $p['phone'];
            $cells = $p['cells'];

            if ($name === '')
            {
                $errors[] = self::err($rowNo, $name, $phone, 'Thiếu họ tên.');
                continue;
            }
            if ($phone === '')
            {
                $errors[] = self::err($rowNo, $name, $phone, 'Thiếu số điện thoại.');
                continue;
            }
            if (isset($phonesSeen[$phone]))
            {
                $errors[] = self::err($rowNo, $name, $phone, 'Trùng SĐT với dòng ' . $phonesSeen[$phone] . ' trong tệp.');
                continue;
            }
            if (isset($existing[$phone]))
            {
                $errors[] = self::err($rowNo, $name, $phone, 'SĐT đã tồn tại (khách "' . $existing[$phone] . '").');
                continue;
            }

            $phonesSeen[$phone] = $rowNo;

            $sourceName = self::cellStr($cells[10] ?? '');
            $leadId     = $sourceName !== '' ? ($sourceIds[mb_strtolower(trim($sourceName))] ?? 0) : 0;
            $stage      = self::toCode(self::cellStr($cells[8] ?? ''), self::STAGES, 'new');
            $temp       = self::toCode(self::cellStr($cells[9] ?? ''), self::TEMPERATURES, 'warm');

            $data = [
                'full_name'      => $name,
                'phone'          => $phone,
                'phone_alt'      => Str::clear(self::cellStr($cells[2] ?? '')),
                'email'          => Str::clear(self::cellStr($cells[3] ?? '')),
                'gender'         => self::toCode(self::cellStr($cells[4] ?? ''), self::GENDERS, ''),
                'birth_year'     => self::toYear($cells[5] ?? ''),
                'address'        => Str::clear(self::cellStr($cells[6] ?? '')),
                'occupation'     => Str::clear(self::cellStr($cells[7] ?? '')),
                'pipeline_stage' => $stage,
                'temperature'    => $temp,
                'lead_source_id' => $leadId,
                'note'           => Str::clear(self::cellStr($cells[11] ?? '')),
                'assigned_user_id' => $assignedUserId,
                'locked_until'   => Customer::lockExpiry(),
                // Điểm khởi tạo (chưa có tương tác) — tick nền/thao tác sau sẽ cập nhật.
                'lead_score'     => LeadScorer::computeScore($stage, $temp, 0, null),
            ];

            $id = Customer::create($data);

            if (is_numeric($id))
            {
                $created++;
                $existing[$phone] = $name;   // chặn trùng nếu file có 2 dòng cùng SĐT ở sau
            }
            else
            {
                $errors[] = self::err($rowNo, $name, $phone, 'Không lưu được khách vào hệ thống.');
            }
        }

        return [
            'total'   => count($parsed),
            'created' => $created,
            'skipped' => count($errors),
            'errors'  => $errors,
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────

    /** Đọc file → ma trận [row][col] (giá trị thô). Ném lỗi VN nếu không đọc được. */
    protected static function readRows(string $path, string $ext): array
    {
        try
        {
            $reader = match ($ext) {
                'csv' => IOFactory::createReader('Csv'),
                'xls' => IOFactory::createReader('Xls'),
                default => IOFactory::createReader('Xlsx'),
            };

            if ($ext === 'csv')
            {
                $reader->setInputEncoding('UTF-8');
            }

            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);

            return $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        }
        catch (\Throwable $e)
        {
            throw new \Exception('Không đọc được nội dung tệp. Hãy kiểm tra định dạng Excel/CSV.');
        }
    }

    /** Ghi hàng tiêu đề + set độ rộng cột. */
    protected static function writeHeader($sheet): void
    {
        $col = 'A';
        foreach (self::HEADERS as $label)
        {
            $sheet->setCellValue($col . '1', $label);
            $sheet->getColumnDimension($col)->setWidth(mb_strlen($label) < 12 ? 14 : 22);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $col++;
        }
    }

    /** Giá trị ô → chuỗi (số nguyên không kèm phần thập phân — giữ SĐT/năm sạch). */
    protected static function cellStr($v): string
    {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? '1' : '';
        if (is_float($v) || is_int($v))
        {
            // 912345678.0 → "912345678"; giữ nguyên nếu thật sự có phần lẻ.
            return rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
        }

        return trim((string) $v);
    }

    /** Nhãn/mã (từ ô) → mã enum. Chấp nhận cả mã lẫn nhãn VN (không phân biệt hoa thường). */
    protected static function toCode(string $input, array $map, string $default): string
    {
        $input = trim($input);
        if ($input === '') return $default;

        $lower = mb_strtolower($input);

        foreach ($map as $code => $label)
        {
            if ($lower === mb_strtolower($code) || $lower === mb_strtolower($label))
            {
                return $code;
            }
        }

        return $default;
    }

    /** Ô năm sinh → int (0 nếu không hợp lệ / ngoài khoảng hợp lý). */
    protected static function toYear($v): int
    {
        $year = (int) round((float) (is_string($v) ? preg_replace('/[^0-9.]/', '', $v) : $v));

        return ($year >= 1900 && $year <= (int) date('Y')) ? $year : 0;
    }

    protected static function err(int $row, string $name, string $phone, string $message): array
    {
        return ['row' => $row, 'name' => $name, 'phone' => $phone, 'message' => $message];
    }

    /** [phone => full_name] cho các SĐT đã tồn tại trong DB (1 truy vấn). */
    protected static function existingPhoneMap(array $phones): array
    {
        $map = [];
        if (empty($phones))
        {
            return $map;
        }

        foreach (Customer::whereIn('phone', array_unique($phones))->get() as $c)
        {
            $map[(string) $c->phone] = (string) $c->full_name;
        }

        return $map;
    }

    /** [tên-nguồn-viết-thường => id] cho toàn bộ nguồn khách (bảng nhỏ). */
    protected static function sourceIdByNameMap(): array
    {
        $map = [];
        foreach (LeadSource::query()->get() as $s)
        {
            $map[mb_strtolower(trim((string) $s->name))] = (int) $s->id;
        }

        return $map;
    }

    /** [id => tên nguồn] cho danh sách id. */
    protected static function sourceNameMap(array $ids): array
    {
        $map = [];
        if (empty($ids)) return $map;

        foreach (LeadSource::whereIn('id', $ids)->get() as $s)
        {
            $map[(int) $s->id] = (string) $s->name;
        }

        return $map;
    }
}
