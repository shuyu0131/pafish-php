<?php

declare(strict_types=1);

namespace Pafish\Services;

/** 扩展清单中的公开链接解析，统一兼容当前字段与旧字段名。 */
final class ExtensionLinks
{
    public static function manifestUrl(?array $manifest, string $key): string
    {
        if (!is_array($manifest)) {
            return '';
        }
        $fallback = $key === 'homepage' ? ($manifest['url'] ?? '') : ($manifest['author_url'] ?? '');
        $value = $manifest[$key] ?? $fallback;
        return is_string($value) && preg_match('/^https?:\/\//i', $value) === 1 ? $value : '';
    }
}
