<?php

namespace App\Models;

use SkillDo\Database\Eloquent\Model;

/**
 * 1 job trong hàng đợi gửi thông báo đẩy (bảng `push_queue`) — mỗi job = 1 thông báo
 * cho 1 subscription. Ghi bởi Services\Notification\PushQueue::enqueue, gửi lần lượt
 * bởi PushQueue::tick (task push-tick, xem app/Console/schedule.php).
 */
class PushJob extends Model
{
    protected string $table = 'push_queue';
}
