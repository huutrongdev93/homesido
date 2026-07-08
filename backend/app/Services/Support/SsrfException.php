<?php

namespace App\Services\Support;

/**
 * Ném khi phát hiện request outbound có nguy cơ SSRF (host phân giải về nội bộ,
 * scheme không hỗ trợ, redirect về địa chỉ nội bộ). Xem SsrfGuard.
 */
class SsrfException extends \RuntimeException
{
}
