<?php

namespace App\Services\Notification;

use App\Services\Support\SsrfGuard;
use Firebase\JWT\JWT;
use SkillDo\Http\Http;

/**
 * Gửi 1 thông báo Web Push tới endpoint của trình duyệt — TỰ TRIỂN KHAI chuẩn
 * VAPID (RFC 8292) + mã hoá payload aes128gcm (RFC 8291), không dùng thư viện
 * web-push ngoài (composer không resolve được vì skilldo/* là package private).
 *
 * Chỉ cần openssl + hash_hkdf (PHP >= 7.3) + firebase/php-jwt (ES256) — đều có sẵn.
 *
 * Khoá VAPID trong .env (sinh 1 lần, đổi khoá = mọi subscription cũ vô hiệu):
 *   VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY (base64url, raw P-256) + VAPID_SUBJECT (mailto:).
 * Gotcha config cache như AI_API_KEY: bootstrap/cache/config.php làm env() trả null.
 */
class WebPushClient
{
    /** DER prefix SubjectPublicKeyInfo cho EC P-256 — nối 65 byte điểm public vào sau. */
    const DER_PUBLIC_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Thời gian push service giữ thông báo khi thiết bị offline (giây). */
    const TTL = 86400;

    /** Payload sống 12h là đủ — JWT VAPID tối đa 24h theo spec. */
    const JWT_EXPIRE = 43200;

    /** Khoá VAPID đã cấu hình trong .env chưa (chưa có → tính năng push tắt êm). */
    public static function configured(): bool
    {
        return self::publicKey() !== '' && trim((string) env('VAPID_PRIVATE_KEY')) !== '';
    }

    /** Khoá public VAPID (base64url) — FE cần khi gọi PushManager.subscribe. */
    public static function publicKey(): string
    {
        return trim((string) env('VAPID_PUBLIC_KEY'));
    }

    /**
     * Gửi 1 push. KHÔNG ném exception — trả mảng kết quả để hàng đợi quyết định
     * retry / xoá subscription.
     *
     * @param string $endpoint URL push service của trình duyệt.
     * @param string $p256dh   khoá public của subscription (base64url, 65 byte).
     * @param string $auth     auth secret của subscription (base64url, 16 byte).
     * @param string $payload  JSON gửi cho service worker (giữ < 3KB cho an toàn).
     *
     * @return array{ok:bool, gone:bool, retry:bool, message:string}
     */
    public function send(string $endpoint, string $p256dh, string $auth, string $payload): array
    {
        if (!self::configured())
        {
            return ['ok' => false, 'gone' => false, 'retry' => false, 'message' => 'Chưa cấu hình khoá VAPID'];
        }

        try
        {
            // Chống SSRF tại THỜI ĐIỂM GỬI (subscribe đã check nhưng DNS có thể đổi
            // giữa 2 thời điểm — rebinding). Tắt luôn follow-redirect: push service
            // hợp lệ không bao giờ redirect, còn 302 → host nội bộ là vector tấn công.
            SsrfGuard::assertSafeUrl($endpoint);

            $body = $this->encryptPayload($payload, $p256dh, $auth);

            $jwt = $this->vapidJwt($endpoint);

            $response = Http::withOptions(['verify' => $this->sslVerify(), 'allow_redirects' => false])
                ->withHeaders([
                    'Authorization'    => 'vapid t=' . $jwt . ', k=' . self::publicKey(),
                    'Content-Encoding' => 'aes128gcm',
                    'TTL'              => (string) self::TTL,
                    'Urgency'          => 'normal',
                ])
                ->timeout(15)
                ->connectTimeout(10)
                ->withBody($body, 'application/octet-stream')
                ->post($endpoint);

            $status = $response->status();

            if ($status >= 200 && $status < 300)
            {
                return ['ok' => true, 'gone' => false, 'retry' => false, 'message' => ''];
            }

            // 404/410: subscription hết hạn hoặc user thu hồi — xoá, không retry.
            if (in_array($status, [404, 410], true))
            {
                return ['ok' => false, 'gone' => true, 'retry' => false, 'message' => 'Subscription hết hạn (' . $status . ')'];
            }

            // 429/5xx: lỗi tạm thời phía push service — cho retry.
            $retry = $status === 429 || $status >= 500;

            return [
                'ok'      => false,
                'gone'    => false,
                'retry'   => $retry,
                'message' => 'HTTP ' . $status . ': ' . mb_substr((string) $response->body(), 0, 150),
            ];
        }
        catch (\Throwable $e)
        {
            // Lỗi mạng → retry; lỗi dữ liệu khoá (decode/encrypt) → không.
            $retry = $e instanceof \Illuminate\Http\Client\ConnectionException;

            return ['ok' => false, 'gone' => false, 'retry' => $retry, 'message' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /* ---------------------------------------------------------------------
     | VAPID (RFC 8292)
     * -------------------------------------------------------------------- */

    /** JWT ES256 xác thực server với push service — aud là origin của endpoint. */
    protected function vapidJwt(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        $aud = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

        $claims = [
            'aud' => $aud,
            'exp' => time() + self::JWT_EXPIRE,
            'sub' => trim((string) env('VAPID_SUBJECT')) ?: 'mailto:admin@example.com',
        ];

        return JWT::encode($claims, $this->privateKeyPem(), 'ES256');
    }

    /** Dựng PEM EC PRIVATE KEY (SEC1) từ khoá raw base64url trong .env. */
    protected function privateKeyPem(): string
    {
        $d = self::b64Decode(trim((string) env('VAPID_PRIVATE_KEY')));

        $pub = self::b64Decode(self::publicKey());

        if (strlen($d) !== 32 || strlen($pub) !== 65)
        {
            throw new \RuntimeException('Khoá VAPID trong .env không hợp lệ');
        }

        // DER template SEC1 cho P-256 — độ dài cố định nên ghép cứng được.
        $der = hex2bin('30770201010420') . $d
            . hex2bin('a00a06082a8648ce3d030107a144034200') . $pub;

        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
    }

    /* ---------------------------------------------------------------------
     | Mã hoá payload aes128gcm (RFC 8291)
     * -------------------------------------------------------------------- */

    /**
     * Mã hoá payload theo Content-Encoding aes128gcm: ECDH với khoá p256dh của
     * subscription → HKDF ra khoá AES-128-GCM + nonce → body = header || ciphertext.
     */
    protected function encryptPayload(string $payload, string $p256dh, string $auth): string
    {
        $uaPublic   = self::b64Decode($p256dh);
        $authSecret = self::b64Decode($auth);

        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16)
        {
            throw new \RuntimeException('Khoá subscription không hợp lệ');
        }

        // Cặp khoá tạm (ephemeral) cho riêng lần gửi này.
        $asKey = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($asKey === false)
        {
            throw new \RuntimeException('Không tạo được khoá ECDH: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($asKey);

        $asPublic = "\x04"
            . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        // ECDH shared secret với khoá public của trình duyệt (dựng PEM từ raw qua DER template).
        $peerPem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(hex2bin(self::DER_PUBLIC_PREFIX) . $uaPublic), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        $peerKey = openssl_pkey_get_public($peerPem);

        // Không truyền $key_length: từ PHP 8.4 tham số này bị deprecated (error handler
        // của framework biến deprecation thành exception → mọi push fail). Với P-256 shared
        // secret vốn 32 byte; left-pad phòng openssl cắt byte 0 ở đầu (secret < 32 byte).
        $sharedSecret = openssl_pkey_derive($peerKey, $asKey);

        if ($sharedSecret === false)
        {
            throw new \RuntimeException('ECDH thất bại: ' . openssl_error_string());
        }

        $sharedSecret = str_pad($sharedSecret, 32, "\0", STR_PAD_LEFT);

        // HKDF chuỗi theo RFC 8291 §3.3-3.4 (hash_hkdf gộp extract+expand).
        $ikm = hash_hkdf('sha256', $sharedSecret, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $authSecret);

        $salt = random_bytes(16);

        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // Bản ghi duy nhất: plaintext + delimiter 0x02 (bản ghi cuối), không pad thêm.
        $tag = '';

        $cipherText = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($cipherText === false)
        {
            throw new \RuntimeException('Mã hoá payload thất bại: ' . openssl_error_string());
        }

        // Header aes128gcm: salt(16) || record_size uint32 || idlen(1) || keyid(as_public 65).
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipherText . $tag;
    }

    /* ---------------------------------------------------------------------
     | Helpers
     * -------------------------------------------------------------------- */

    public static function b64Decode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), false);

        return $decoded === false ? '' : $decoded;
    }

    /** Cấu hình SSL (CA bundle đóng gói, tắt được qua env cho môi trường dev). */
    protected function sslVerify(): bool|string
    {
        $verifyEnv = env('PUSH_SSL_VERIFY');

        if (!is_null($verifyEnv) && in_array(strtolower(trim((string) $verifyEnv)), ['0', 'false', 'off', 'no'], true))
        {
            return false;
        }

        $custom = env('PUSH_CA_BUNDLE');

        if (!empty($custom) && is_file($custom))
        {
            return $custom;
        }

        $bundled = dirname(__DIR__, 3) . '/storage/certs/cacert.pem';

        return is_file($bundled) ? $bundled : true;
    }
}
