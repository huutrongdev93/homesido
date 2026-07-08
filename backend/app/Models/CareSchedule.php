<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * Lịch chăm sóc / nhắc việc cho khách. Tick nền quét `scheduled_at` đến hạn → Notifier::send.
 * Bảng `care_schedules`.
 */
class CareSchedule extends Model
{
    protected string $table = 'care_schedules';
}
