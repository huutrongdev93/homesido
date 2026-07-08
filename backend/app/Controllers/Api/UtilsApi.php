<?php

namespace App\Controllers\Api;

use Illuminate\Support\Str;
use SkillDo\Cache\Cache;
use SkillDo\Cms\Models\User;
use SkillDo\Cms\Support\Role;
use SkillDo\Database\DB;
use SkillDo\Http\Request;
use SkillDo\Routing\Controller\Controller;
use SkillDo\Support\Auth;

class UtilsApi extends Controller
{
    /**
     * GET api/utils — trả DỮ LIỆU TĨNH mà FE dùng thường xuyên nhưng ÍT khi thay đổi
     * (danh sách trạng thái, chức vụ, các enum/hằng dùng chung). FE cache lại (localStorage)
     * và chỉ gọi lại khi có thay đổi — phát hiện qua `utilitiesKey` = md5(serialize($data)):
     * data đổi → key đổi → AuthController::current báo key mới → FE nạp lại. Thêm danh mục
     * tĩnh mới vào $data ở đây thay vì tạo endpoint riêng cho mỗi danh mục.
     */
    public function index(Request $request): void
    {
        $roles = Role::make()->getNames();

        // Ẩn chức vụ hệ thống khỏi danh sách gán cho user thường.
        unset($roles['administrator'], $roles['root'], $roles['subscriber']);

        $data = [
            'roles' =>  $roles,
            'data' => [
                // Enum tĩnh dùng-nhiều-đổi-hiếm cho FE (label VN). FE cache theo utilitiesKey.
                'customer' => [
                    'pipeline_stages' => [
                        ['value' => 'new',         'label' => 'Lead mới'],
                        ['value' => 'contacting',  'label' => 'Đang chăm'],
                        ['value' => 'potential',   'label' => 'Tiềm năng'],
                        ['value' => 'negotiating', 'label' => 'Đàm phán'],
                        ['value' => 'won',         'label' => 'Chốt thành công'],
                        ['value' => 'lost',        'label' => 'Thất bại'],
                    ],
                    'temperatures' => [
                        ['value' => 'hot',  'label' => 'Nóng'],
                        ['value' => 'warm', 'label' => 'Ấm'],
                        ['value' => 'cold', 'label' => 'Lạnh'],
                    ],
                    'genders' => [
                        ['value' => 'male',   'label' => 'Nam'],
                        ['value' => 'female', 'label' => 'Nữ'],
                        ['value' => 'other',  'label' => 'Khác'],
                    ],
                    'demand_types' => [
                        ['value' => 'buy',     'label' => 'Mua'],
                        ['value' => 'rent',    'label' => 'Thuê'],
                        ['value' => 'sell',    'label' => 'Bán'],
                        ['value' => 'consign', 'label' => 'Ký gửi'],
                    ],
                    // Mục đích của nhu cầu (dùng cho customer_demands).
                    'purposes' => [
                        ['value' => 'live',   'label' => 'Để ở'],
                        ['value' => 'invest', 'label' => 'Đầu tư'],
                    ],
                ],
                'care' => [
                    // Loại lịch chăm sóc (kênh liên hệ).
                    'care_types' => [
                        ['value' => 'call',    'label' => 'Gọi điện'],
                        ['value' => 'sms',     'label' => 'Nhắn SMS'],
                        ['value' => 'zalo',    'label' => 'Nhắn Zalo'],
                        ['value' => 'email',   'label' => 'Gửi email'],
                        ['value' => 'meeting', 'label' => 'Gặp mặt'],
                    ],
                    // Loại tương tác trong timeline (gồm ghi chú & dẫn xem nhà).
                    'interaction_types' => [
                        ['value' => 'call',    'label' => 'Gọi điện'],
                        ['value' => 'sms',     'label' => 'SMS'],
                        ['value' => 'zalo',    'label' => 'Zalo'],
                        ['value' => 'email',   'label' => 'Email'],
                        ['value' => 'meeting', 'label' => 'Gặp mặt'],
                        ['value' => 'note',    'label' => 'Ghi chú'],
                        ['value' => 'viewing', 'label' => 'Dẫn xem nhà'],
                    ],
                ],
                'property' => [
                    'property_types' => [
                        ['value' => 'apartment',  'label' => 'Căn hộ'],
                        ['value' => 'house',      'label' => 'Nhà phố'],
                        ['value' => 'villa',      'label' => 'Biệt thự'],
                        ['value' => 'land',       'label' => 'Đất nền'],
                        ['value' => 'shophouse',  'label' => 'Shophouse'],
                        ['value' => 'farmland',   'label' => 'Đất nông nghiệp'],
                        ['value' => 'warehouse',  'label' => 'Kho xưởng'],
                        ['value' => 'office',     'label' => 'Văn phòng'],
                    ],
                    'transaction_types' => [
                        ['value' => 'sale', 'label' => 'Bán'],
                        ['value' => 'rent', 'label' => 'Cho thuê'],
                    ],
                    'statuses' => [
                        ['value' => 'available', 'label' => 'Đang bán'],
                        ['value' => 'deposited', 'label' => 'Đang cọc'],
                        ['value' => 'sold',      'label' => 'Đã bán'],
                        ['value' => 'rented',    'label' => 'Đã cho thuê'],
                        ['value' => 'inactive',  'label' => 'Ngừng'],
                    ],
                    'visibilities' => [
                        ['value' => 'shared',  'label' => 'Kho chung (toàn sàn)'],
                        ['value' => 'private', 'label' => 'Riêng của tôi'],
                    ],
                    'legal_statuses' => [
                        ['value' => 'red_book',      'label' => 'Sổ đỏ'],
                        ['value' => 'pink_book',     'label' => 'Sổ hồng'],
                        ['value' => 'sale_contract', 'label' => 'HĐ mua bán'],
                        ['value' => 'waiting',       'label' => 'Đang chờ sổ'],
                        ['value' => 'other',         'label' => 'Khác'],
                    ],
                    'furnitures' => [
                        ['value' => 'none',  'label' => 'Bàn giao thô'],
                        ['value' => 'basic', 'label' => 'Cơ bản'],
                        ['value' => 'full',  'label' => 'Đầy đủ'],
                    ],
                    'directions' => [
                        ['value' => 'east',      'label' => 'Đông'],
                        ['value' => 'west',      'label' => 'Tây'],
                        ['value' => 'south',     'label' => 'Nam'],
                        ['value' => 'north',     'label' => 'Bắc'],
                        ['value' => 'southeast', 'label' => 'Đông Nam'],
                        ['value' => 'southwest', 'label' => 'Tây Nam'],
                        ['value' => 'northeast', 'label' => 'Đông Bắc'],
                        ['value' => 'northwest', 'label' => 'Tây Bắc'],
                    ],
                    // Vị trí / đường vào — mặt tiền hay hẻm (loại xe vào được).
                    'road_types' => [
                        ['value' => 'frontage',   'label' => 'Mặt tiền đường'],
                        ['value' => 'car_alley',  'label' => 'Hẻm xe hơi'],
                        ['value' => 'bike_alley', 'label' => 'Hẻm xe máy'],
                        ['value' => 'walk_alley', 'label' => 'Hẻm bộ'],
                    ],
                ],
                'matching' => [
                    // Trạng thái 1 lần "gửi SP cho khách" (property_customer_matches.status).
                    'statuses' => [
                        ['value' => 'sent',       'label' => 'Đã gửi'],
                        ['value' => 'interested', 'label' => 'Khách quan tâm'],
                        ['value' => 'rejected',   'label' => 'Khách từ chối'],
                    ],
                ],
                'appointment' => [
                    // Trạng thái buổi hẹn dẫn khách (appointments.status).
                    'statuses' => [
                        ['value' => 'pending',  'label' => 'Chờ dẫn'],
                        ['value' => 'done',     'label' => 'Đã dẫn'],
                        ['value' => 'canceled', 'label' => 'Đã hủy'],
                        ['value' => 'no_show',  'label' => 'Khách không đến'],
                    ],
                    // Kết quả sau khi dẫn xem (appointments.result; "" = chưa có).
                    'results' => [
                        ['value' => 'interested',  'label' => 'Quan tâm'],
                        ['value' => 'considering', 'label' => 'Đang cân nhắc'],
                        ['value' => 'rejected',    'label' => 'Từ chối'],
                        ['value' => 'deposited',   'label' => 'Đặt cọc'],
                    ],
                ],
            ],
        ];

        $key = md5(serialize($data));

        if (Cache::get('utilsDataKey') !== $key)
        {
            // TTL tính bằng PHÚT (Cache::save nhân 60) — giữ 30 ngày.
            Cache::save('utilsDataKey', $key, 30 * 24 * 60);
        }

        response()->success('success', [
            // Cùng tên field với AuthController::current (utilitiesKey) — FE so 2 giá trị
            // này để biết dữ liệu tiện ích đã đổi chưa, lệch tên là cache FE không bao giờ khớp.
            'utilitiesKey' => $key,
            'data' => $data,
        ]);
    }

    public function run(Request $request): void
    {
        // Scratch endpoint (xem CLAUDE.md): nơi chạy tay các đoạn code test/throwaway qua API.
        // KHÔNG kiểm tra auth — chỉ mở khi UTILS_API_OPEN=true (môi trường demo/dev). Trên
        // production để UTILS_API_OPEN=false thì endpoint này bị tắt (xem ensureOpen).
        $this->ensureOpen();

        $this->index($request);

        response()->success('OK');
    }

    /**
     * GET api/utils/database — KHỞI TẠO / cập nhật database.
     *
     * Chạy tất cả migration đã đăng ký (idempotent nhờ guard hasTable/hasColumn) rồi seed
     * tài khoản quản trị đầu tiên nếu bảng users trống. KHÔNG kiểm tra auth — chỉ mở khi
     * UTILS_API_OPEN=true (demo/dev). Production để false → endpoint bị tắt; muốn khởi tạo
     * lần đầu thì bật cờ tạm, gọi 1 lần, rồi tắt lại.
     */
    public function database(Request $request): void
    {
        $this->ensureOpen();

        // Mỗi file là 1 lớp Migration ẩn danh với up() được guard bằng schema()->hasTable()/
        // hasColumn(), nên gọi lại nhiều lần vẫn an toàn. Module mới thêm 1 dòng vào mảng.
        $migrations = [
            'database/database.php',
            'database/crm.php',
            'database/media.php',
            'database/property.php',
            'database/matching.php',
            'database/appointment.php',
        ];

        foreach ($migrations as $migrationFile)
        {
            $migration = require __ROOT__ . $migrationFile;

            $migration->up();
        }

        // Flush cache (gồm cache cột bảng table_columns_*) để model nạp lại schema mới.
        Cache::flush();

        // Seed tài khoản quản trị đầu tiên (id 1) nếu hệ thống chưa có user nào — mật khẩu
        // sinh ngẫu nhiên và CHỈ trả về 1 lần trong response này. Đổi mật khẩu sau khi vào.
        $adminPassword = $this->seedAdminUser();

        if ($adminPassword !== null)
        {
            response()->success('Đã khởi tạo database', [
                'admin_username' => 'admin',
                'admin_password' => $adminPassword,
                'note'           => 'Tài khoản quản trị vừa được khởi tạo — lưu mật khẩu này lại và đổi ngay sau khi đăng nhập.',
            ]);
        }

        response()->success('Đã cập nhật database');
    }

    /**
     * Cổng bật/tắt các tiện ích quản trị chạy trực tiếp (database/run). Mặc định TẮT —
     * chỉ bật ở môi trường demo/dev bằng `UTILS_API_OPEN=true` trong .env. Production để
     * false (hoặc bỏ trống) thì trả 403, tránh mở công khai endpoint chạy migration/code.
     */
    protected function ensureOpen(): void
    {
        if (filter_var(env('UTILS_API_OPEN', false), FILTER_VALIDATE_BOOLEAN))
        {
            return;
        }

        response()
            ->setStatusCode(403)
            ->setApiStatus(403)
            ->error('Tiện ích hệ thống đang tắt. Bật UTILS_API_OPEN=true trong .env (chỉ môi trường demo/dev).');
    }

    /**
     * Tạo tài khoản quản trị id 1 nếu bảng users đang trống. Trả về mật khẩu vừa sinh
     * (chỉ lần đầu), ngược lại trả null.
     */
    protected function seedAdminUser(): ?string
    {
        if ((int) DB::table('users')->count() > 0)
        {
            return null;
        }

        $password = Str::random(16);

        DB::table('users')->insert([
            'id'        => 1,
            'username'  => 'admin',
            'password'  => Auth::generatePassword($password),
            'salt'      => Str::random(32),
            'firstname' => 'Quản trị',
            'lastname'  => 'hệ thống',
            'status'    => 'public',
            'role'      => 'administrator',
        ]);

        // Cap qua meta — FE và Auth::getCap nhận diện siêu quản trị qua {administrator: 1}.
        User::updateMeta(1, 'capabilities', ['administrator' => 1]);

        return $password;
    }
}
