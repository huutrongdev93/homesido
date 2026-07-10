<?php

/**
 * Danh sách migration NGHIỆP VỤ của MỘT tenant (bộ bảng của 1 sàn).
 *
 * Dùng chung cho 2 nơi để khỏi lệch:
 *  - `UtilsApi::database()` — khởi tạo/cập nhật DB của tenant ĐANG resolve theo URL
 *    (gọi `GET /{key}/api/utils/database`).
 *  - `App\Services\Tenant\TenantProvisioner` — tạo bộ bảng cho tenant MỚI lúc cấp phát.
 *
 * Mỗi phần tử là 1 lớp Migration ẩn danh với `up()` guard bằng schema()->hasTable()/
 * hasColumn() nên chạy lại nhiều lần vẫn an toàn (idempotent). Tất cả chạy dưới prefix
 * CỦA TENANT (đã set trước khi require). Thêm module mới ⇒ thêm 1 dòng vào đây.
 *
 * LƯU Ý: KHÔNG chứa `database/tenant.php` — đó là bảng TRUNG TÂM (`core_tenants`), nằm ở
 * prefix cố định `core_`, được tạo riêng bởi Provisioner (không thuộc bộ bảng per-tenant).
 */
return [
    'database/database.php',
    'database/crm.php',
    'database/care-sequence.php',
    'database/media.php',
    'database/property-media-audio.php',
    'database/property.php',
    'database/property-unique-code.php',
    'database/matching.php',
    'database/matching-scan.php',
    'database/appointment.php',
    'database/deal.php',
    'database/deal-history.php',
];
