<?php

declare(strict_types=1);

namespace Pafish\Core;

/**
 * 系统版本号（唯一代码内版本常量，与发布 tag / CHANGELOG / 发布包文件名对齐）。
 * 在线更新通过 Upgrade 服务与官方商店的 pafish-php.json 元数据比较此值。
 */
final class Version
{
    /** 当前版本（发布新版本时同步更新，并保持一致的三处对齐） */
    public const VERSION = '0.1.17';

    /** 版本号（供模板/JSON 输出） */
    public static function current(): string
    {
        return self::VERSION;
    }

    /** 数字分段版本比较（"0.1.2" vs "0.1.10"，返回 -1/0/1） */
    public static function compare(string $a, string $b): int
    {
        $pa = self::parts($a);
        $pb = self::parts($b);
        $n = max(count($pa), count($pb));
        for ($i = 0; $i < $n; $i++) {
            $x = $pa[$i] ?? 0;
            $y = $pb[$i] ?? 0;
            if ($x < $y) {
                return -1;
            }
            if ($x > $y) {
                return 1;
            }
        }
        return 0;
    }

    private static function parts(string $v): array
    {
        $parts = preg_split('/[^\d]+/', $v);
        $out = [];
        foreach (is_array($parts) ? $parts : [] as $p) {
            if ($p !== '') {
                $out[] = (int) $p;
            }
        }
        return $out;
    }
}
