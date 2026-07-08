<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * Nguồn khách (Facebook, hotline, giới thiệu...). Cột tự nạp từ schema bảng `lead_sources`.
 */
class LeadSource extends Model
{
    protected string $table = 'lead_sources';
}
