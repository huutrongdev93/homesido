<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * 1 thiết bị/trình duyệt user đã bật thông báo đẩy (Web Push). Cột tự nạp từ schema
 * bảng `push_subscriptions`. Ghi qua PushController::subscribe, đọc bởi PushQueue.
 */
class PushSubscription extends Model
{
    protected string $table = 'push_subscriptions';
}
