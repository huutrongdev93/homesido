<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/** Hoa hồng của 1 sale trên 1 giao dịch (commissions). */
class Commission extends Model
{
    protected string $table = 'commissions';
}
