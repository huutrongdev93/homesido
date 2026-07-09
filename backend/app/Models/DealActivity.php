<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/** Nhật ký hoạt động của giao dịch (deal_activities) — append-only. */
class DealActivity extends Model
{
    protected string $table = 'deal_activities';
}
