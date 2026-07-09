<?php

namespace App\Services\Storage;

use App\Models\PropertyMedia;
use Illuminate\Support\Str;
use SkillDo\Cms\Support\Url;

/**
 * Lưu / xóa / purge media (ảnh, video) của Bất động sản.
 *
 * File lưu vào đĩa tại `<root>/storage/uploads/properties/`, phục vụ công khai qua đường ảo
 * `/uploads/properties/...` (.htaccess map). DB `property_media.path` lưu tương đối `properties/<file>`.
 * Mọi thao tác cập nhật kế toán dung lượng theo người upload ([[StorageMeter]]).
 */
class PropertyMediaService
{
    const DIR        = 'storage/uploads/properties';   // tương đối __ROOT__ (nơi lưu thật)
    const PATH_BASE  = 'properties';                    // prefix lưu trong DB (dưới uploads/)

    const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    const VIDEO_EXT = ['mp4', 'webm', 'mov', 'm4v'];
    const AUDIO_EXT = ['mp3', 'wav', 'm4a', 'aac', 'ogg'];

    /** Dung lượng tối đa mỗi file theo loại (byte); env override tính bằng MB. */
    public static function maxBytes(string $type): int
    {
        if ($type === 'video')
        {
            return ((int) env('MEDIA_MAX_VIDEO_MB', 100)) * 1024 * 1024;
        }

        if ($type === 'audio')
        {
            return ((int) env('MEDIA_MAX_AUDIO_MB', 50)) * 1024 * 1024;
        }

        return ((int) env('MEDIA_MAX_IMAGE_MB', 10)) * 1024 * 1024;
    }

    /** Phân loại theo đuôi file → 'image'|'video'|'audio', null nếu không hỗ trợ. */
    public static function classify(string $ext): ?string
    {
        $ext = strtolower($ext);

        if (in_array($ext, self::IMAGE_EXT, true))
        {
            return 'image';
        }

        if (in_array($ext, self::VIDEO_EXT, true))
        {
            return 'video';
        }

        if (in_array($ext, self::AUDIO_EXT, true))
        {
            return 'audio';
        }

        return null;
    }

    /** URL công khai từ path tương đối trong DB. */
    public static function url(string $relPath): string
    {
        return $relPath !== '' ? Url::base('uploads/' . ltrim($relPath, '/')) : '';
    }

    /**
     * Giải ảnh đại diện cho 1 lô BĐS (1 truy vấn media) → `[propertyId => url|null]`.
     * Ưu tiên ảnh được chọn (`cover_media_id`) nếu còn hợp lệ; không có → ảnh đầu tiên theo `sort_order`.
     * Chỉ xét media loại ẢNH. `$rows` là danh sách bản ghi BĐS (cần `->id` + `->cover_media_id`).
     * Dùng chung: danh sách/chi tiết BĐS lẫn gợi ý Matching.
     */
    public static function thumbnails($rows): array
    {
        $ids = [];
        foreach ($rows as $row)
        {
            $ids[] = (int) $row->id;
        }
        if (empty($ids))
        {
            return [];
        }

        $firstByProp = [];   // pid     => path (ảnh đầu tiên theo thứ tự)
        $imgById     = [];   // mediaId => [pid, path] (tra cover đã chọn)
        foreach (PropertyMedia::whereIn('property_id', $ids)->where('type', 'image')
                     ->orderBy('sort_order')->orderBy('id')->get() as $m)
        {
            $pid = (int) $m->property_id;
            if (!isset($firstByProp[$pid]))
            {
                $firstByProp[$pid] = (string) $m->path;
            }
            $imgById[(int) $m->id] = [$pid, (string) $m->path];
        }

        $out = [];
        foreach ($rows as $row)
        {
            $pid   = (int) $row->id;
            $cover = (int) ($row->cover_media_id ?? 0);

            $path = null;
            if ($cover > 0 && isset($imgById[$cover]) && $imgById[$cover][0] === $pid)
            {
                $path = $imgById[$cover][1];   // cover đã chọn còn hợp lệ
            }
            elseif (isset($firstByProp[$pid]))
            {
                $path = $firstByProp[$pid];    // fallback ảnh đầu tiên
            }

            $out[$pid] = ($path !== null) ? self::url($path) : null;
        }

        return $out;
    }

    /**
     * Lưu 1 file upload cho BĐS. KHÔNG throw — trả ['ok'=>bool,'error'=>?string,'id'=>int] để
     * controller gom lỗi từng file trong 1 lô upload.
     */
    public static function store($file, int $propertyId, int $userId, int $sortOrder): array
    {
        if (!$file || !$file->isValid())
        {
            return ['ok' => false, 'error' => 'Tệp không hợp lệ'];
        }

        // Đọc metadata TRƯỚC khi move (sau move, temp file biến mất → getSize/getMimeType lỗi).
        $ext          = strtolower((string) $file->getClientOriginalExtension());
        $originalName = (string) $file->getClientOriginalName();
        $mime         = (string) $file->getClientMimeType();
        $size         = (int) $file->getSize();

        $type = self::classify($ext);
        if ($type === null)
        {
            return ['ok' => false, 'error' => 'Định dạng "' . $ext . '" không được hỗ trợ'];
        }

        if ($size <= 0)
        {
            return ['ok' => false, 'error' => 'Tệp rỗng'];
        }

        if ($size > self::maxBytes($type))
        {
            return ['ok' => false, 'error' => 'Tệp vượt dung lượng tối đa (' . (self::maxBytes($type) / 1024 / 1024) . 'MB)'];
        }

        if (StorageMeter::wouldExceed($userId, $size))
        {
            return ['ok' => false, 'error' => 'Vượt hạn mức dung lượng của bạn'];
        }

        $dir = __ROOT__ . self::DIR;
        if (!is_dir($dir))
        {
            @mkdir($dir, 0755, true);
        }

        $name = Str::random(32) . '.' . $ext;

        try
        {
            $file->move($dir, $name);
        }
        catch (\Throwable $e)
        {
            return ['ok' => false, 'error' => 'Không lưu được tệp lên máy chủ'];
        }

        $id = PropertyMedia::create([
            'property_id'   => $propertyId,
            'user_id'       => $userId,
            'type'          => $type,
            'path'          => self::PATH_BASE . '/' . $name,
            'size'          => $size,
            'mime_type'     => mb_substr($mime, 0, 100),
            'original_name' => mb_substr($originalName, 0, 255),
            'sort_order'    => $sortOrder,
        ]);

        StorageMeter::add($userId, $size);

        return ['ok' => true, 'error' => null, 'id' => (int) $id, 'size' => $size];
    }

    /** Xóa 1 media: xóa file trên đĩa + trừ dung lượng người upload + xóa row. */
    public static function delete($row): void
    {
        self::deleteFile((string) $row->path);

        StorageMeter::subtract((int) $row->user_id, (int) $row->size);

        PropertyMedia::where('id', (int) $row->id)->delete();
    }

    /**
     * Purge TOÀN BỘ media của 1 BĐS (dùng khi xóa HẲN bất động sản): xóa file + trừ dung lượng
     * (theo từng người upload) + xóa row. Trả số file đã xóa.
     */
    public static function purgeProperty(int $propertyId): int
    {
        $count = 0;

        foreach (PropertyMedia::where('property_id', $propertyId)->get() as $row)
        {
            self::delete($row);
            $count++;
        }

        return $count;
    }

    /** Xóa file vật lý theo path tương đối (dưới storage/uploads/). */
    protected static function deleteFile(string $relPath): void
    {
        if ($relPath === '')
        {
            return;
        }

        $full = __ROOT__ . 'storage/uploads/' . ltrim($relPath, '/');

        if (is_file($full))
        {
            @unlink($full);
        }
    }
}
