<?php

declare(strict_types=1);

namespace Pafish\Core;

/** 系统版本号和统一的版本比较规则。 */
final class Version
{
    /** 当前版本。 */
    public const VERSION = '1.2.5';

    /** 版本号（供模板/JSON 输出） */
    public static function current(): string
    {
        return self::VERSION;
    }

    /** 支持 v 前缀、预发布版本；构建元数据不参与排序，返回 -1/0/1。 */
    public static function compare(string $a, string $b): int
    {
        return version_compare(self::normalize($a), self::normalize($b));
    }

    private static function normalize(string $version): string
    {
        $version = explode('+', ltrim(trim($version), 'vV'), 2)[0];
        if (preg_match('/^(\d+(?:\.\d+)*)(.*)$/D', $version, $matches) === 1) {
            $parts = array_map(static fn (string $part): string => ltrim($part, '0') ?: '0', explode('.', $matches[1]));
            // 保持旧规则：1.1 与 1.1.0 相同。
            return implode('.', array_pad($parts, 3, '0')) . $matches[2];
        }
        return $version;
    }
}
