<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/** Shared public page reads with one visibility rule. */
final class ContentRepository
{
    public static function publishedPageById(int $id): ?array
    {
        if ($id <= 0) return null;
        return DB::fetchOne(
            "SELECT id,title,seo_title,meta_description,slug,content,content_format,template,allow_comments,published_at,created_at,updated_at
             FROM pages WHERE id = ? AND status = 'PUBLISHED' AND deleted_at IS NULL
             AND (published_at IS NULL OR published_at <= NOW())",
            [$id]
        );
    }

    public static function publishedPageBySlug(string $slug): ?array
    {
        return DB::fetchOne(
            "SELECT id,title,seo_title,meta_description,slug,content,content_format,template,allow_comments,published_at,created_at,updated_at
             FROM pages WHERE slug = ? AND status = 'PUBLISHED' AND deleted_at IS NULL
             AND (published_at IS NULL OR published_at <= NOW())",
            [$slug]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function publishedPages(): array
    {
        return DB::fetchAll(
            "SELECT id,title,slug,content,content_format,status,published_at,updated_at,seo_title,meta_description
             FROM pages WHERE status = 'PUBLISHED' AND deleted_at IS NULL
             AND (published_at IS NULL OR published_at <= NOW()) ORDER BY published_at DESC, id DESC"
        );
    }
}
