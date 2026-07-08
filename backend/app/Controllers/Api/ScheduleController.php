<?php
namespace App\Controllers\Api;

use Illuminate\Console\Scheduling\Schedule;
use SkillDo\Http\Request;
use SkillDo\Log\Log;
use SkillDo\Routing\Controller\Controller;
use Throwable;

/**
 * Endpoint chạy Laravel Schedule (cron gọi `schedule-run` mỗi phút).
 *
 * Kế thừa Routing\Controller (KHÔNG phải SkillDo\Cms\Controller): deployment này chạy
 * headless, tắt theme trong config/cms.php nên binding `themeConfig` không được đăng ký.
 * SkillDo\Cms\Controller::__construct() luôn gọi app('themeConfig')->boot() → lỗi
 * "Target class [themeConfig] does not exist". Base routing controller (giống mọi
 * controller api/*) không boot theme nên tránh được lỗi này.
 */
class ScheduleController extends Controller
{
    public function run(Request $request): void
    {
        // SEC-08: endpoint cron không được mở công khai. Chỉ cho phép gọi từ
        // localhost (cron nội bộ) hoặc khi kèm token bí mật khớp SCHEDULE_RUN_TOKEN.
        if (!$this->authorize($request))
        {
            http_response_code(403);

            echo 'Forbidden';

            return;
        }

        foreach (app(Schedule::class)->events() as $event)
        {
            if ($event->isDue(app()))
            {
                try
                {
                    $event->run(app());
                }
                catch (Throwable $e)
                {
                    Log::error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
                }
            }
        }
    }

    /**
     * SEC-08: Đã cấu hình SCHEDULE_RUN_TOKEN thì BẮT BUỘC token khớp (header
     * X-Schedule-Token hoặc ?token=) — không bypass theo IP, vì sau reverse proxy
     * cùng máy (nginx → Apache) REMOTE_ADDR luôn là 127.0.0.1 nên mọi request
     * ngoài đều "trông như" localhost. Chỉ khi CHƯA cấu hình token (máy dev)
     * mới chấp nhận localhost.
     */
    protected function authorize(Request $request): bool
    {
        $secret = (string) env('SCHEDULE_RUN_TOKEN', '');

        if ($secret !== '')
        {
            $provided = (string) ($request->header('X-Schedule-Token') ?? $request->query('token', ''));

            return $provided !== '' && hash_equals($secret, $provided);
        }

        return in_array($request->ip(), ['127.0.0.1', '::1'], true);
    }
}