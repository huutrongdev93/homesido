<?php

namespace App\Controllers\Api;

use App\Services\Storage\StorageMeter;
use SkillDo\Http\Request;

/**
 * API dung lượng lưu trữ của user hiện tại (gói theo dung lượng — theo từng nhân viên).
 * Không cần cap: mỗi user chỉ xem dung lượng của chính mình.
 */
class StorageApi extends ApiController
{
    public function index(Request $request): void
    {
        $userId = $this->userId();

        response()->success('success', [
            'used_bytes'  => StorageMeter::used($userId),
            'quota_bytes' => StorageMeter::quota(),
        ]);
    }
}
