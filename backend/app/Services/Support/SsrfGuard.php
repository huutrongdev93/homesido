<?php

namespace App\Services\Support;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Chống SSRF cho MỌI request outbound tới host do người dùng nhập (vd endpoint Web Push,
 * hoặc bất kỳ tính năng nào fetch URL người dùng cung cấp).
 *
 * Nguyên tắc: trước khi gọi, phân giải DNS của host → nếu resolve về dải nội bộ
 * (loopback 127.0.0.0/8 · private 10/172.16/192.168 · link-local/metadata cloud
 * 169.254.169.254 · ::1 ...) thì TỪ CHỐI. Vì client tự follow redirect, kẻ tấn công
 * có thể trỏ host công khai → 302 về nội bộ, nên còn kiểm TỪNG hop redirect qua
 * callback `on_redirect` của Guzzle (đính kèm bằng redirectOptions()).
 *
 * Chỉ cho http/https. Không phụ thuộc thư viện ngoài — dùng filter_var với cờ
 * NO_PRIV_RANGE | NO_RES_RANGE (đã phủ loopback/link-local/reserved cho cả IPv4 & IPv6).
 */
class SsrfGuard
{
    /**
     * Ném SsrfException nếu URL không an toàn (scheme lạ, host rỗng, không phân giải
     * được, hoặc phân giải về dải nội bộ).
     */
    public static function assertSafeUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true))
        {
            throw new SsrfException('Chỉ hỗ trợ URL http/https.');
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '')
        {
            throw new SsrfException('URL không hợp lệ.');
        }

        self::assertSafeHost($host);
    }

    /** Như assertSafeUrl nhưng trả bool (nuốt lỗi) — dùng ở nơi muốn fail lặng lẽ. */
    public static function isSafeUrl(string $url): bool
    {
        try
        {
            self::assertSafeUrl($url);

            return true;
        }
        catch (\Throwable $e)
        {
            return false;
        }
    }

    /**
     * Ném nếu host phân giải về địa chỉ nội bộ. Host có thể là tên miền hoặc IP literal.
     */
    public static function assertSafeHost(string $host): void
    {
        $ips = self::resolve($host);

        if (empty($ips))
        {
            throw new SsrfException('Không phân giải được tên miền: ' . $host);
        }

        foreach ($ips as $ip)
        {
            if (self::isBlockedIp($ip))
            {
                throw new SsrfException('Từ chối truy cập địa chỉ nội bộ.');
            }
        }
    }

    /**
     * Tuỳ chọn allow_redirects cho Guzzle: giới hạn số hop, chỉ http/https, và callback
     * kiểm mỗi hop redirect (chặn 302 → host nội bộ). Giữ track_redirects khi caller cần
     * dựng lại chuỗi redirect.
     *
     * @return array<string,mixed>
     */
    public static function redirectOptions(int $max = 5, bool $track = false): array
    {
        $options = [
            'max'         => $max,
            'strict'      => false,
            'referer'     => false,
            'protocols'   => ['http', 'https'],
            'on_redirect' => static function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                self::assertSafeHost($uri->getHost());
            },
        ];

        if ($track)
        {
            $options['track_redirects'] = true;
        }

        return $options;
    }

    /**
     * Phân giải host → danh sách IP (A + AAAA). IP literal trả về chính nó.
     *
     * @return string[]
     */
    protected static function resolve(string $host): array
    {
        // Bỏ ngoặc IPv6 dạng [::1].
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false)
        {
            return [$host];
        }

        $ips = [];

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (is_array($records))
        {
            foreach ($records as $r)
            {
                if (!empty($r['ip']))
                {
                    $ips[] = $r['ip'];
                }

                if (!empty($r['ipv6']))
                {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        // Dự phòng (một số host chỉ có A record hoặc dns_get_record bị chặn).
        if (empty($ips))
        {
            $list = @gethostbynamel($host);

            if (is_array($list))
            {
                $ips = $list;
            }
        }

        return $ips;
    }

    /**
     * IP thuộc dải bị chặn? (không hợp lệ · private · reserved: loopback/link-local/...).
     */
    public static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false)
        {
            return true;
        }

        // NO_PRIV_RANGE + NO_RES_RANGE → validate FAIL nếu IP nằm trong dải private/reserved.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
