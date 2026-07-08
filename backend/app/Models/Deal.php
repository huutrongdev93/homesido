<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;
use SkillDo\Traits\Eloquent\SoftDeletes;

/**
 * Giao dịch (deals). Xoá mềm qua cột `trash` (auto-scope + ->trash()). Base Model tự điền
 * cột mặc định ('' / 0) + set `user_created`.
 */
class Deal extends Model
{
    use SoftDeletes;

    protected string $table = 'deals';
}
