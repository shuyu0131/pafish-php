<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Auth;
use Pafish\Core\Cache;
use Pafish\Core\DB;

/** 微语核心内容服务：公开查询、后台分页和保存生命周期。 */
final class MicroStatuses
{
    public const DRAFT = 'DRAFT';
    public const PUBLISHED = 'PUBLISHED';
    private const STATUSES = [self::DRAFT, self::PUBLISHED];
    private const MAX_CONTENT = 160000;
    private const MAX_MEDIA = 12;

    /**
     * 公开微语分页。$viewerId 为当前登录用户时附带其本人的私密微语
     * （前台"仅自己可见"语义）；不传或游客只看公开内容。
     * @return array{items:array,total:int,page:int,totalPages:int,perPage:int}
     */
    public static function publicPage(int $page = 1, int $perPage = 20, ?int $viewerId = null): array
    {
        $perPage = min(50, max(1, $perPage));
        $visible = $viewerId !== null && $viewerId > 0
            ? '(m.is_private = 0 OR m.author_id = ?)'
            : 'm.is_private = 0';
        $params = $viewerId !== null && $viewerId > 0 ? [$viewerId] : [];
        $total = (int) DB::value(
            "SELECT COUNT(*) FROM micro_statuses m WHERE m.status = 'PUBLISHED' AND {$visible}
             AND m.deleted_at IS NULL AND m.published_at IS NOT NULL AND m.published_at <= NOW()",
            $params
        );
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;
        $rows = DB::fetchAll(
            "SELECT m.*, COALESCE(NULLIF(u.nickname, ''), u.username) AS author_name, u.avatar_url AS author_avatar
             FROM micro_statuses m LEFT JOIN users u ON u.id = m.author_id
             WHERE m.status = 'PUBLISHED' AND {$visible} AND m.deleted_at IS NULL
               AND m.published_at IS NOT NULL AND m.published_at <= NOW()
             ORDER BY m.is_pinned DESC, m.published_at DESC, m.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return [
            'items' => array_map([self::class, 'publicPayload'], $rows),
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
        ];
    }

    /** 最新公开微语，供侧边栏使用。 */
    public static function latest(int $limit = 5): array
    {
        $limit = min(20, max(1, $limit));
        $cacheKey = 'micro.latest.' . $limit;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $rows = DB::fetchAll(
            "SELECT m.*, COALESCE(NULLIF(u.nickname, ''), u.username) AS author_name, u.avatar_url AS author_avatar
             FROM micro_statuses m LEFT JOIN users u ON u.id = m.author_id
             WHERE m.status = 'PUBLISHED' AND m.is_private = 0 AND m.deleted_at IS NULL
               AND m.published_at IS NOT NULL AND m.published_at <= NOW()
             ORDER BY m.is_pinned DESC, m.published_at DESC, m.id DESC
             LIMIT {$limit}"
        );
        $items = array_map([self::class, 'publicPayload'], $rows);
        Cache::set($cacheKey, $items, 60);
        return $items;
    }

    /** @return array{items:array,total:int,page:int,totalPages:int,perPage:int} */
    public static function adminPage(int $page = 1, int $perPage = 20, string $status = '', string $q = ''): array
    {
        $perPage = min(100, max(1, $perPage));
        $where = ['m.deleted_at IS NULL'];
        $params = [];
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'm.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            // LIKE 通配符转义：%/_ 按字面匹配；用显式 ESCAPE 字符避免依赖 sql_mode 的反斜杠行为。
            $keyword = str_replace('|', '||', mb_substr($q, 0, 100));
            $keyword = str_replace(['%', '_'], ['|%', '|_'], $keyword);
            $where[] = "m.content LIKE ? ESCAPE '|'";
            $params[] = '%' . $keyword . '%';
        }
        $condition = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM micro_statuses m WHERE {$condition}", $params);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;
        $rows = DB::fetchAll(
            "SELECT m.*, COALESCE(NULLIF(u.nickname, ''), u.username) AS author_name
             FROM micro_statuses m LEFT JOIN users u ON u.id = m.author_id
             WHERE {$condition} ORDER BY m.updated_at DESC, m.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        foreach ($rows as &$row) {
            $row['media'] = self::decodeMedia($row['media_json'] ?? null);
        }
        unset($row);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages, 'perPage' => $perPage];
    }

    public static function find(int $id): ?array
    {
        $row = DB::fetchOne(
            "SELECT m.*, COALESCE(NULLIF(u.nickname, ''), u.username) AS author_name
             FROM micro_statuses m LEFT JOIN users u ON u.id = m.author_id
             WHERE m.id = ? AND m.deleted_at IS NULL",
            [$id]
        );
        if ($row !== null) {
            $row['media'] = self::decodeMedia($row['media_json'] ?? null);
        }
        return $row;
    }

    /** 保存新建/编辑微语；返回规范化后的记录。 */
    public static function save(array $input, ?int $id = null, ?int $authorId = null): array
    {
        $existing = $id !== null ? self::find($id) : null;
        if ($id !== null && $existing === null) {
            throw new \RuntimeException('微语不存在');
        }
        $authorId ??= Auth::id();
        if ($authorId === null) {
            throw new \RuntimeException('请先登录');
        }

        $payload = [
            'id' => $id !== null ? (string) $id : null,
            'content' => trim((string) ($input['content'] ?? '')),
            'status' => ($input['status'] ?? self::DRAFT) === self::PUBLISHED ? self::PUBLISHED : self::DRAFT,
            'publishedAt' => self::normalizeDate($input['published_at'] ?? $input['publishedAt'] ?? null),
            'isPinned' => self::truthy($input['is_pinned'] ?? $input['isPinned'] ?? false),
            'isPrivate' => self::truthy($input['is_private'] ?? $input['isPrivate'] ?? false),
            'media' => self::normalizeMedia($input['media'] ?? $input['media_json'] ?? []),
            'authorId' => (string) ($existing['author_id'] ?? $authorId),
        ];
        $decision = apply_decision_filters('before_micro_save', $payload, [
            'id' => $payload['id'],
            'created' => $id === null,
            // 原始提交数组：扩展读自己的表单字段（核心不认识的键）时从这里取。
            'input' => $input,
        ]);
        if ($decision === false || (is_array($decision) && ($decision['allowed'] ?? true) === false)) {
            $message = is_array($decision) ? trim((string) ($decision['error'] ?? '微语保存被扩展拒绝')) : '微语保存被扩展拒绝';
            throw new \RuntimeException($message !== '' ? $message : '微语保存被扩展拒绝');
        }
        if (is_array($decision)) {
            $payload = array_replace($payload, $decision);
        }
        $payload['content'] = trim((string) ($payload['content'] ?? ''));
        if ($payload['content'] === '') {
            throw new \RuntimeException('内容不能为空');
        }
        if (mb_strlen($payload['content']) > self::MAX_CONTENT) {
            throw new \RuntimeException('内容不能超过 ' . self::MAX_CONTENT . ' 个字符');
        }
        $payload['status'] = ($payload['status'] ?? self::DRAFT) === self::PUBLISHED ? self::PUBLISHED : self::DRAFT;
        $payload['publishedAt'] = self::normalizeDate($payload['publishedAt'] ?? null);
        $payload['isPinned'] = self::truthy($payload['isPinned'] ?? false);
        $payload['isPrivate'] = self::truthy($payload['isPrivate'] ?? false);
        $payload['media'] = self::normalizeMedia($payload['media'] ?? []);
        if ($payload['status'] === self::PUBLISHED && $payload['publishedAt'] === null) {
            $payload['publishedAt'] = date('Y-m-d H:i:s');
        }
        if ($payload['status'] === self::DRAFT) {
            $payload['publishedAt'] = null;
        }
        $mediaJson = $payload['media'] === [] ? null : json_encode($payload['media'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($id !== null) {
            DB::execute(
                'UPDATE micro_statuses SET content = ?, media_json = ?, status = ?, published_at = ?, is_pinned = ?, is_private = ?, updated_at = NOW() WHERE id = ?',
                [$payload['content'], $mediaJson, $payload['status'], $payload['publishedAt'], $payload['isPinned'] ? 1 : 0, $payload['isPrivate'] ? 1 : 0, $id]
            );
        } else {
            DB::execute(
                'INSERT INTO micro_statuses (content, media_json, status, published_at, is_pinned, is_private, author_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$payload['content'], $mediaJson, $payload['status'], $payload['publishedAt'], $payload['isPinned'] ? 1 : 0, $payload['isPrivate'] ? 1 : 0, $authorId]
            );
            $id = DB::lastInsertId();
            $payload['id'] = (string) $id;
        }
        Cache::clearPrefix('micro.latest.');
        $row = self::find((int) $id);
        if ($row === null) {
            throw new \RuntimeException('微语保存后读取失败');
        }
        if ($payload['status'] === self::PUBLISHED && (($existing['status'] ?? null) !== self::PUBLISHED)) {
            do_action('after_micro_publish', self::eventPayload($row));
        }
        // 每次保存都触发（含草稿与更新），扩展在这里同步自己的字段。
        // payload 与 after_micro_publish 同构，额外带原始提交数组，便于读扩展自己的表单键。
        do_action('after_micro_save', self::eventPayload($row) + ['input' => $input, 'created' => $existing === null]);
        return $row;
    }

    public static function delete(int $id): bool
    {
        $row = self::find($id);
        if ($row === null) {
            return false;
        }
        DB::execute('UPDATE micro_statuses SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?', [$id]);
        Cache::clearPrefix('micro.latest.');
        do_action('after_micro_delete', self::eventPayload($row));
        return true;
    }

    /** @return array<string,mixed> */
    public static function publicPayload(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'content' => (string) $row['content'],
            'publishedAt' => (string) ($row['published_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
            'isPinned' => (bool) $row['is_pinned'],
            'isPrivate' => (bool) ($row['is_private'] ?? 0),
            'author' => [
                'id' => isset($row['author_id']) ? (string) $row['author_id'] : null,
                'name' => (string) ($row['author_name'] ?? ''),
                'avatarUrl' => $row['author_avatar'] ?? null,
            ],
            'media' => self::decodeMedia($row['media_json'] ?? null),
        ];
    }

    private static function eventPayload(array $row): array
    {
        $payload = self::publicPayload($row);
        $payload['status'] = (string) $row['status'];
        $payload['isPrivate'] = (bool) $row['is_private'];
        $payload['authorId'] = (string) $row['author_id'];
        return $payload;
    }

    private static function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \RuntimeException('发布时间格式无效');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private static function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /** @return list<string> */
    private static function normalizeMedia(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/\r?\n/', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $media = [];
        foreach ($value as $url) {
            $url = trim((string) $url);
            if ($url === '' || mb_strlen($url) > 500 || !preg_match('#^(https?://|/|uploads/)#i', $url)) {
                continue;
            }
            if (!in_array($url, $media, true)) {
                $media[] = $url;
            }
            if (count($media) >= self::MAX_MEDIA) {
                break;
            }
        }
        return $media;
    }

    /** @return list<string> */
    private static function decodeMedia(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $value = json_decode($json, true);
        return self::normalizeMedia(is_array($value) ? $value : []);
    }
}
