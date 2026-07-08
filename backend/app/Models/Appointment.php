<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * Lịch hẹn dẫn khách (appointments). Model tối giản — base Model tự điền cột mặc định
 * ('' / 0) cho cột không đụng tới và tự set `user_created` = Auth::id() khi create.
 */
class Appointment extends Model
{
    protected string $table = 'appointments';
}
