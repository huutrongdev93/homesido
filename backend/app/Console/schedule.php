<?php

/**
 * Đăng ký task nền vào Laravel Schedule.
 *
 * File này được require ở routes/api.php (điểm boot của app, chạy MỌI request kể cả
 * schedule-run). Schedule::class được bind singleton trong SkillDo\Application nên
 * event đăng ký ở đây hiện ra khi ScheduleController::run() duyệt app(Schedule)->events().
 *
 * Cơ chế chung: cron gọi `schedule-run` mỗi phút → task xử lý MỘT lô việc rồi thoát
 * (không vòng lặp thường trú). Các tick chồng lấn an toàn nhờ claim theo điều kiện.
 *
 * Cấu hình cron server (route schedule-run token-guarded, nằm ngoài api/):
 *   * * * * * curl -s "https://your-domain/schedule-run?token=SCHEDULE_RUN_TOKEN" >/dev/null 2>&1
 */

use App\Services\Care\CareReminder;
use App\Services\Care\ColdDetector;
use App\Services\Care\CustomerRelease;
use App\Services\Customer\LeadScorer;
use App\Services\Notification\PushQueue;
use Illuminate\Console\Scheduling\Schedule;
use SkillDo\Log\Log;

/**
 * Tick gửi thông báo đẩy (Web Push). Notifier ghi thông báo in-app xong sẽ enqueue vào
 * push_queue (1 job/thiết bị của người nhận); mỗi phút tick này claim một lô (50) và gửi
 * LẦN LƯỢT từng job qua WebPushClient (VAPID + aes128gcm). Chưa cấu hình VAPID / chưa
 * migrate bảng → tick thoát êm ngay.
 */
app(Schedule::class)->call(function () {

    try
    {
        $summary = (new PushQueue())->tick();

        if (array_sum($summary) > 0)
        {
            Log::info('Push queue tick', $summary);
        }
    }
    catch (\Throwable $e)
    {
        Log::error('Push queue tick error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);
    }
})->everyMinute()->name('push-tick');

/**
 * Tick nhắc lịch chăm sóc đến hạn (mỗi phút). Gửi digest 1 thông báo/sales qua Notifier::sendUnique
 * (không spam mỗi phút). Bảng chưa migrate → thoát êm.
 */
app(Schedule::class)->call(function () {

    try
    {
        $summary = (new CareReminder())->tick();

        if (array_sum($summary) > 0)
        {
            Log::info('Care reminder tick', $summary);
        }
    }
    catch (\Throwable $e)
    {
        Log::error('Care reminder tick error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);
    }
})->everyMinute()->name('care-reminder-tick');

/**
 * Tick phát hiện khách "nguội" (hằng ngày lúc 07:00). Gắn cờ is_cold_flagged + báo sales phụ trách.
 */
app(Schedule::class)->call(function () {

    try
    {
        $summary = (new ColdDetector())->tick();

        if (array_sum($summary) > 0)
        {
            Log::info('Cold detector tick', $summary);
        }
    }
    catch (\Throwable $e)
    {
        Log::error('Cold detector tick error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);
    }
})->dailyAt('07:00')->name('customer-cold-tick');

/**
 * Tick tự trả khách quá hạn khóa về kho chung (hằng ngày lúc 01:00). Gỡ khóa + đưa
 * assigned_user_id về 0 + báo sales cũ. Xem App\Services\Care\CustomerRelease.
 */
app(Schedule::class)->call(function () {

    try
    {
        $summary = (new CustomerRelease())->tick();

        if (array_sum($summary) > 0)
        {
            Log::info('Customer release tick', $summary);
        }
    }
    catch (\Throwable $e)
    {
        Log::error('Customer release tick error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);
    }
})->dailyAt('01:00')->name('customer-release-tick');

/**
 * Tick chấm lại điểm tiềm năng khách (hằng ngày lúc 02:00). Rescore toàn bộ để phản ánh SUY GIẢM
 * độ mới của tương tác theo thời gian (không sự kiện nào kích hoạt khi ngày trôi qua).
 * Xem App\Services\Customer\LeadScorer.
 */
app(Schedule::class)->call(function () {

    try
    {
        $summary = (new LeadScorer())->tick();

        if (array_sum($summary) > 0)
        {
            Log::info('Lead score tick', $summary);
        }
    }
    catch (\Throwable $e)
    {
        Log::error('Lead score tick error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);
    }
})->dailyAt('02:00')->name('lead-score-tick');
