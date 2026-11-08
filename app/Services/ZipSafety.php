<?php

declare(strict_types=1);

namespace Pafish\Services;

/** Shared resource limits for extension archives before extraction. */
final class ZipSafety
{
    public const MAX_ENTRIES = 5000;
    public const MAX_ENTRY_BYTES = 20 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 50 * 1024 * 1024;

    public static function assertWithinLimits(\ZipArchive $zip): void
    {
        $count = $zip->numFiles;
        if ($count < 1 || $count > self::MAX_ENTRIES) {
            throw new \RuntimeException('压缩包条目数量超出限制');
        }

        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat) || !isset($stat['size']) || !is_int($stat['size']) || $stat['size'] < 0) {
                throw new \RuntimeException('压缩包条目大小无效');
            }
            if ($stat['size'] > self::MAX_ENTRY_BYTES) {
                throw new \RuntimeException('压缩包单个文件超出解压限制');
            }
            $total += $stat['size'];
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException('压缩包解压后总大小超出 50MB 限制');
            }
        }
    }
}
