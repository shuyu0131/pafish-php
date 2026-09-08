<?php

declare(strict_types=1);

namespace Pafish\Services;

/**
 * 别名工具：生成并校验唯一别名
 * - slugify：小写、空白→连字符、保留中文、去特殊字符、合并连字符、空兜底
 * - resolveUniqueSlug：冲突自动加 -2/-3/... 后缀（标签/分类/页面用；文章 slug 不启用）
 */
final class Slug
{
    /** 生成 URL 别名（中文保留不转拼音） */
    public static function slugify(string $input): string
    {
        $s = mb_strtolower(trim($input), 'UTF-8');
        $s = preg_replace('/\s+/u', '-', $s);
        $s = (string) preg_replace('/[^\p{L}\p{N}_-]/u', '', $s);
        $s = (string) preg_replace('/-+/u', '-', $s);
        return $s !== '' ? $s : 'post-' . time();
    }

    /**
     * 唯一别名：不冲突直接返回；冲突依次尝试 {slug}-2、{slug}-3 …（最多 50 次）
     * @param callable(string): bool $check 返回 true 表示已被占用
     */
    public static function resolveUnique(string $slug, callable $check, int $maxAttempts = 50): string
    {
        $candidate = $slug;
        for ($i = 2; $i <= $maxAttempts; $i++) {
            if (!$check($candidate)) {
                return $candidate;
            }
            $candidate = $slug . '-' . $i;
        }
        return $candidate;
    }
}
