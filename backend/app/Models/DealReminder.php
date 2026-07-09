<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/** Nhắc hẹn tự do gắn với 1 giao dịch (deal_reminders). */
class DealReminder extends Model
{
    protected string $table = 'deal_reminders';
}
