<?php

namespace App\Services\Tenant;

/**
 * Bối cảnh tenant HIỆN TẠI của request (multi-tenant — GĐ4 Bước 0).
 *
 * index.php gọi `TenantContext::set($slug, $prefix)` ngay sau khi resolve (trước khi boot).
 * Phần còn lại của app đọc `slug()/prefix()/active()` để: build link kèm `/{key}` (Notifier,
 * trang công khai BĐS — Bước 1), cấp phát, PlanGate (Bước 2)…
 *
 * State tĩnh sống trong 1 request (mỗi request PHP là 1 process/worker riêng). Ở chế độ 1-sàn
 * (passthrough) slug = null ⇒ `active()` false ⇒ mọi thứ hành xử như trước khi có multi-tenant.
 */
class TenantContext
{
    protected static ?string $slug = null;

    protected static ?string $prefix = null;

    public static function set(string $slug, string $prefix): void
    {
        self::$slug   = $slug;
        self::$prefix = $prefix;
    }

    public static function slug(): ?string
    {
        return self::$slug;
    }

    public static function prefix(): ?string
    {
        return self::$prefix;
    }

    public static function active(): bool
    {
        return self::$slug !== null && self::$slug !== '';
    }

    /**
     * Tiền tố path cho link TUYỆT ĐỐI do backend sinh ra (phải kèm `/{key}` để mở đúng tenant):
     * '/sana' khi đang ở tenant, '' khi 1-sàn. Vd: `TenantContext::pathPrefix() . '/p/' . $code`.
     */
    public static function pathPrefix(): string
    {
        return self::active() ? '/' . self::$slug : '';
    }
}
