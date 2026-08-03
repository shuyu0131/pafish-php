<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/**
 * 媒体上传（对齐 Node src/app/api/upload/route.ts + src/lib/upload.ts）：
 * - 大小受 upload_max_mb 设置（夹在 1–200MB）约束
 * - 扩展名白名单：图片/文档/压缩包/音频/视频
 * - 图片压缩（GD）：仅 png/jpg/jpeg/webp——EXIF 方向转正 → 长边 >1920 缩放
 *   → png compressionLevel 9 / webp q82 / jpeg q82；GIF 保留动画、SVG 保留矢量跳过；失败回退原图
 * - 云存储插件优先：激活插件中第一个声明 storage 且实现 storeFile 的生效（M5 接入），失败回退本地
 * - 本地存储 public/uploads/{随机16hex}.{ext}，入库 uploads 表
 */
final class Upload
{
    private const MAX_SIDE = 1920;   // 长边上限（与 Node 一致）
    private const JPEG_Q = 82;

    public const ALLOWED_EXT = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'csv',
        'zip', 'rar', '7z', 'tar', 'gz',
        'mp3', 'wav', 'ogg', 'm4a', 'flac',
        'mp4', 'webm', 'mov', 'mkv',
    ];

    public const MIME_MAP = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv',
        'zip' => 'application/zip', 'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed', 'tar' => 'application/x-tar', 'gz' => 'application/gzip',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4', 'flac' => 'audio/flac',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
        'mkv' => 'video/x-matroska',
    ];

    /** 媒体类型筛选 SQL（mime 前缀 + url 扩展名兜底，对齐 Node lib/media-filter.ts buildTypeWhere） */
    public static function typeWhere(string $type): string
    {
        static $prefixes = [
            'image' => 'image/', 'doc' => 'application/', 'archive' => 'application/',
            'audio' => 'audio/', 'video' => 'video/',
        ];
        static $exts = [
            'image' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'],
            'doc' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'csv'],
            'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
            'audio' => ['mp3', 'wav', 'ogg', 'm4a', 'flac'],
            'video' => ['mp4', 'webm', 'mov', 'mkv'],
        ];
        if (!isset($prefixes[$type])) {
            return '';
        }
        $conds = ["mime LIKE '" . $prefixes[$type] . "%'"];
        foreach ($exts[$type] as $e) {
            $conds[] = "url LIKE '%.{$e}'";
        }
        return '(' . implode(' OR ', $conds) . ')';
    }

    /**
     * 处理上传文件（$_FILES 单元素），成功返回 ['url','mime','size','width','height']
     * @throws \RuntimeException 中文错误信息
     */
    public static function handle(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件超过大小限制',
                UPLOAD_ERR_PARTIAL => '文件上传不完整',
                default => '上传失败，请重试',
            });
        }
        $buffer = (string) file_get_contents((string) ($file['tmp_name'] ?? ''));
        return self::process($buffer, (string) ($file['name'] ?? ''), (int) ($file['size'] ?? 0));
    }

    /** 处理 PSR-7 上传流（Slim UploadedFileInterface），校验/压缩/存储与 handle 完全一致 */
    public static function handleStream(string $buffer, string $origName): array
    {
        return self::process($buffer, $origName, strlen($buffer));
    }

    /** 核心处理：大小 → 扩展名 → 压缩 → 云存储/本地 → 入库 */
    private static function process(string $buffer, string $origName, int $size): array
    {
        $maxMb = (int) Settings::get('upload_max_mb', '20');
        $maxMb = max(1, min(200, $maxMb));
        if ($size > $maxMb * 1024 * 1024) {
            throw new \RuntimeException("文件不能超过 {$maxMb}MB");
        }

        $origName = trim($origName);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            throw new \RuntimeException('不支持的文件类型：' . ($ext !== '' ? $ext : '无扩展名'));
        }

        $mime = self::MIME_MAP[$ext] ?? 'application/octet-stream';
        if ($buffer === '') {
            throw new \RuntimeException('读取文件失败');
        }

        $width = null;
        $height = null;

        // 图片压缩（失败回退原图）
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            $compressed = self::compressImage($buffer, $ext);
            if ($compressed !== null) {
                [$buffer, $width, $height] = $compressed;
            }
        }

        // 云存储插件优先（激活插件声明 storage 且实现 storeFile；M5 接入插件系统）
        $storageUrl = apply_filters('upload_store_to_cloud', null, [
            'buffer' => $buffer, 'ext' => $ext, 'mime' => $mime,
            'originalName' => $origName, 'size' => strlen($buffer),
            'width' => $width, 'height' => $height,
        ]);
        if (is_string($storageUrl) && $storageUrl !== '') {
            $url = $storageUrl;
        } else {
            $url = self::storeLocal($buffer, $ext, $mime);
        }

        DB::execute(
            'INSERT INTO uploads (original_name, url, mime, size, width, height, uploader_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$origName, $url, $mime, strlen($buffer), $width, $height, \Pafish\Core\Auth::id()]
        );

        return ['url' => $url, 'mime' => $mime, 'size' => strlen($buffer), 'width' => $width, 'height' => $height];
    }

    /** 本地存储：public/uploads/{随机}.{ext}（目录自动创建） */
    public static function storeLocal(string $buffer, string $ext, string $mime): string
    {
        $dir = dirname(__DIR__, 2) . '/public/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $name, $buffer) === false) {
            throw new \RuntimeException('保存文件失败，请检查 uploads 目录权限');
        }
        return \Pafish\Core\Url::to('/uploads/' . $name);
    }

    // ---------- 内部 ----------

    /** GD 压缩：返回 [buffer, width, height]；失败返回 null（回退原图） */
    private static function compressImage(string $buffer, string $ext): ?array
    {
        try {
            $img = @imagecreatefromstring($buffer);
            if ($img === false) {
                return null;
            }
            $w = imagesx($img);
            $h = imagesy($img);

            // EXIF 方向转正（仅 JPEG 有 EXIF）
            if ($ext === 'jpeg' || $ext === 'jpg') {
                $tmp = tempnam(sys_get_temp_dir(), 'up');
                if ($tmp !== false) {
                    file_put_contents($tmp, $buffer);
                    $orientation = 1;
                    $exif = @exif_read_data($tmp);
                    if (is_array($exif) && isset($exif['Orientation'])) {
                        $orientation = (int) $exif['Orientation'];
                    }
                    @unlink($tmp);
                    switch ($orientation) {
                        case 3: $img = imagerotate($img, 180, 0); break;
                        case 6: $img = imagerotate($img, -90, 0); break;
                        case 8: $img = imagerotate($img, 90, 0); break;
                    }
                    if (in_array($orientation, [2, 5, 7], true)) {
                        imageflip($img, IMG_FLIP_HORIZONTAL);
                    }
                }
            }

            // 长边 > 1920 缩放（不放大）
            $w = imagesx($img);
            $h = imagesy($img);
            $long = max($w, $h);
            if ($long > self::MAX_SIDE) {
                $scale = self::MAX_SIDE / $long;
                $nw = (int) round($w * $scale);
                $nh = (int) round($h * $scale);
                $resized = imagecreatetruecolor($nw, $nh);
                if ($ext === 'png') {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                }
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                $img = $resized;
                $w = $nw;
                $h = $nh;
            }

            ob_start();
            switch ($ext) {
                case 'png':
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                    imagepng($img, null, 9);
                    break;
                case 'webp':
                    imagewebp($img, null, self::JPEG_Q);
                    break;
                default:
                    imagejpeg($img, null, self::JPEG_Q);
                    break;
            }
            $out = (string) ob_get_clean();
            if ($out === '') {
                return null;
            }
            return [$out, $w, $h];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
