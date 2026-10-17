<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Services\EntityMeta;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Private content reads for authenticated first-party management clients. */
final class AppContentController
{
    public function posts(Request $request, Response $response): Response
    {
        if (!Auth::check()) return $this->json($response, ['error' => '未登录'], 401);
        if (!Auth::can('posts.manage')) return $this->json($response, ['error' => '无权管理文章'], 403);
        $query = $request->getQueryParams();
        $status = strtoupper(trim((string) ($query['status'] ?? '')));
        if (!in_array($status, ['', 'DRAFT', 'PUBLISHED', 'SCHEDULED', 'TRASH'], true)) $status = '';
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($query['perPage'] ?? 20)));
        $where = [];
        $params = [];
        if (!Auth::isAdmin()) {
            $where[] = 'author_id = ?';
            $params[] = (int) Auth::id();
        }
        // 关键词：标题/别名模糊匹配；% _ \ 需转义，否则会被当通配符。
        $keyword = trim((string) ($query['q'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($keyword, 0, 100)) . '%';
            $where[] = "(title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\')";
            $params[] = $like;
            $params[] = $like;
        }
        if ($status === 'TRASH') {
            $where[] = 'deleted_at IS NOT NULL';
        } else {
            $where[] = 'deleted_at IS NULL';
            if ($status !== '') {
                $where[] = 'status = ?';
                $params[] = $status;
            }
        }
        $condition = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM posts WHERE {$condition}", $params);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        // 置顶优先：与后台列表的 pinned 排序一致，否则「置顶」在 App 里看不出效果。
        $rows = DB::fetchAll("SELECT id,title,slug,excerpt,cover_url,status,published_at,view_count,like_count,favorite_count,is_pinned,author_id,category_id,created_at,updated_at
            FROM posts WHERE {$condition}
            ORDER BY is_pinned DESC, updated_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}", $params);
        return $this->json($response, [
            'posts' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'title' => (string) $row['title'], 'slug' => (string) $row['slug'],
                'excerpt' => (string) $row['excerpt'], 'coverUrl' => $row['cover_url'], 'status' => (string) $row['status'],
                'publishedAt' => $row['published_at'], 'viewCount' => (int) $row['view_count'],
                'likeCount' => (int) $row['like_count'], 'favoriteCount' => (int) $row['favorite_count'],
                'pinned' => (bool) $row['is_pinned'], 'authorId' => (int) $row['author_id'],
                'categoryId' => $row['category_id'] !== null ? (int) $row['category_id'] : null,
                'createdAt' => (string) $row['created_at'], 'updatedAt' => (string) $row['updated_at'],
            ], $rows),
            'total' => $total, 'page' => $page, 'perPage' => $perPage,
            'totalPages' => $totalPages,
        ]);
    }

    public function post(Request $request, Response $response, array $args): Response
    {
        if (!Auth::check()) return $this->json($response, ['error' => '未登录'], 401);
        if (!Auth::can('posts.manage')) return $this->json($response, ['error' => '无权管理文章'], 403);
        $id = (int) ($args['id'] ?? 0);
        $sql = 'SELECT * FROM posts WHERE id = ?';
        $params = [$id];
        if (!Auth::isAdmin()) {
            $sql .= ' AND author_id = ?';
            $params[] = (int) Auth::id();
        }
        $row = DB::fetchOne($sql, $params);
        if ($row === null) return $this->json($response, ['error' => '文章不存在或无权访问'], 404);
        $row['id'] = (int) $row['id'];
        $row['author_id'] = (int) $row['author_id'];
        $row['category_id'] = $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $row['is_pinned'] = (bool) $row['is_pinned'];
        $row['category_pinned'] = (bool) $row['category_pinned'];
        $row['allow_comments'] = (bool) $row['allow_comments'];
        $row['tags'] = DB::fetchAll('SELECT t.id,t.name FROM post_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.post_id=? ORDER BY t.id', [$id]);
        $row['custom_fields'] = EntityMeta::postCustomFields($id, $row['custom_fields'] ?? null);
        unset($row['password']);
        return $this->json($response, ['post' => $row]);
    }

    public function stats(Request $request, Response $response): Response
    {
        if (!Auth::check()) return $this->json($response, ['error' => '未登录'], 401);
        $scope = Auth::isAdmin() ? '' : ' WHERE author_id = ?';
        $params = Auth::isAdmin() ? [] : [(int) Auth::id()];
        $counts = DB::fetchOne("SELECT
            SUM(deleted_at IS NULL) AS active,
            SUM(deleted_at IS NULL AND status = 'PUBLISHED') AS published,
            SUM(deleted_at IS NULL AND status = 'DRAFT') AS drafts,
            SUM(deleted_at IS NULL AND status = 'SCHEDULED') AS scheduled,
            SUM(deleted_at IS NOT NULL) AS trash
            FROM posts{$scope}", $params) ?? [];
        $commentScope = Auth::isAdmin() ? '' : ' AND p.author_id = ?';
        $commentParams = Auth::isAdmin() ? [] : [(int) Auth::id()];
        $pendingComments = (int) DB::value("SELECT COUNT(*) FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.status='PENDING' AND p.deleted_at IS NULL{$commentScope}", $commentParams);
        return $this->json($response, ['stats' => [
            'posts' => array_map('intval', $counts),
            'pendingComments' => $pendingComments,
        ]]);
    }

    public function comments(Request $request, Response $response): Response
    {
        if (!Auth::check()) return $this->json($response, ['error' => '未登录'], 401);
        if (!Auth::can('comments.manage')) return $this->json($response, ['error' => '无权管理评论'], 403);
        $query = $request->getQueryParams();
        $status = strtoupper(trim((string) ($query['status'] ?? 'PENDING')));
        // ALL = 不过滤状态，与后台评论页的「全部」一致；未知取值回落到待审。
        $statusFilter = $status === 'ALL' ? '' : (in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'SPAM', 'TRASH'], true) ? $status : 'PENDING');
        $statusSql = $statusFilter === '' ? '' : 'c.status = ? AND ';
        $statusParams = $statusFilter === '' ? [] : [$statusFilter];
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($query['perPage'] ?? 20)));
        $scope = Auth::isAdmin() ? '' : ' AND p.author_id = ?';
        $scopeParams = Auth::isAdmin() ? [] : [(int) Auth::id()];
        $total = (int) DB::value("SELECT COUNT(*) FROM comments c JOIN posts p ON p.id=c.post_id WHERE {$statusSql}p.deleted_at IS NULL{$scope}", array_merge($statusParams, $scopeParams));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $rows = DB::fetchAll("SELECT c.id,c.post_id,c.author_name,c.author_email,c.content,c.status,c.is_pinned,c.parent_id,c.created_at,
                p.title AS post_title, u.avatar_url AS author_avatar_url
            FROM comments c
            JOIN posts p ON p.id=c.post_id
            LEFT JOIN users u ON u.id=c.user_id
            WHERE {$statusSql}p.deleted_at IS NULL{$scope}
            ORDER BY c.created_at DESC,c.id DESC LIMIT {$perPage} OFFSET {$offset}", array_merge($statusParams, $scopeParams));
        return $this->json($response, [
            'comments' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'postId' => (int) $row['post_id'], 'postTitle' => (string) $row['post_title'],
                'authorName' => (string) $row['author_name'], 'content' => (string) $row['content'],
                // 头像与网页后台同一套回退：自定义头像 → 站点默认头像，客户端不再自造文字占位。
                'authorAvatar' => \Pafish\Http\Comments::avatarUrl($row['author_avatar_url'] ?? null, (string) ($row['author_email'] ?? '')),
                'status' => (string) $row['status'], 'pinned' => (bool) $row['is_pinned'],
                'parentId' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'createdAt' => (string) $row['created_at'],
            ], $rows),
            'total' => $total, 'page' => $page, 'perPage' => $perPage,
            'totalPages' => $totalPages,
        ]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        return Responses::json($response, $data, $status);
    }
}
