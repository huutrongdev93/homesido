<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * Dự án — gom sản phẩm BĐS theo khu/dự án. Bảng `projects`.
 */
class Project extends Model
{
    protected string $table = 'projects';
}
