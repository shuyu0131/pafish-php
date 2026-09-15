<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 评论审核：
 * - 5 Tab：全部 / 待审核 PENDING / 已通过 APPROVED / 垃圾 SPAM / 已删除 TRASH（TRASH 无写入路径，保留 Tab）
 * - 20/页、created_at 倒序；每条显示作者/邮箱/IP/时间/内容/文章链接/父评论作者
 * - 操作：回复（以管理员身份建 APPROVED 子评论）、通过/垃圾（状态流转，驳回即 SPAM）、
 *   置顶/取消、按 IP 删除（物理删，级联子评论）、拉黑 IP（settings blocked_ips JSON）、
 *   删除（物理删，级联子评论）
 * 权限：ADMIN+EDITOR；编辑仅处理自己文章下的评论，CSRF 由 AdminAuthMiddleware 统一校验
 */
final class CommentsController extends AdminController
{
    private const PAGE_SIZE = 20;

    /** 状态常量 */
    private const STATUSES = ['PENDING', 'APPROVED', 'SPAM', 'TRASH'];

    /** GET /admin/comments：评论列表（status + page） */
    public function index(Request $request, Response $response): Response
    {
        $this->guardCapability('comments.manage');
        $status = strtoupper((string) ($_GET['status'] ?? 'PENDING'));
        if ($status !== 'ALL' && !in_array($status, self::STATUSES, true)) {
            $status = 'PENDING';
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $postId = ctype_digit((string) ($_GET['post_id'] ?? '')) ? (int) $_GET['post_id'] : 0;
        $statusFilter = $status === 'ALL' ? '' : ' AND c.status = ?';
        $statusParams = $status === 'ALL' ? [] : [$status];
        $postFilter = $postId > 0 ? ' AND c.post_id = ?' : '';
        $postParams = $postId > 0 ? [$postId] : [];

        $scope = $this->editorScope();
        $total = (int) DB::value(
            'SELECT COUNT(*) FROM comments c JOIN posts scope_post ON scope_post.id = c.post_id WHERE 1=1' . $statusFilter . $postFilter . $scope['sql'],
            array_merge($statusParams, $postParams, $scope['params'])
        );
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);

        // 每条评论带文章标题 + 父评论作者（楼中楼「回复 @xx」徽标）
        $items = DB::fetchAll(
            'SELECT c.*, p.title AS post_title, p.slug AS post_slug, parent.author_name AS parent_name
             FROM comments c
             LEFT JOIN posts p ON p.id = c.post_id
             LEFT JOIN comments parent ON parent.id = c.parent_id
             JOIN posts scope_post ON scope_post.id = c.post_id
             WHERE 1=1' . $statusFilter . $postFilter . $scope['sql'] . '
             ORDER BY c.created_at DESC
             LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            array_merge($statusParams, $postParams, $scope['params'])
        );

        $postTitle = '';
        if ($postId > 0) {
            $postTitle = (string) DB::value(
                'SELECT title FROM posts WHERE id = ?' . (Auth::isAdmin() ? '' : ' AND author_id = ?'),
                Auth::isAdmin() ? [$postId] : [$postId, (int) Auth::id()]
            );
        }

        $response->getBody()->write($this->render('comments', [
            'items' => $items,
            'status' => $status,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'statusCounts' => $this->statusCounts($postId),
            'postId' => $postId,
            'postTitle' => $postTitle,
        ], '评论审核'));
        return $response;
    }

    /** POST /admin/comments/{id}/status。 */
    public function status(Request $request, Response $response, array $args): Response
    {
        $this->guardCapability('comments.manage');
        $id = (int) ($args['id'] ?? 0);
        $body = $request->getParsedBody() ?? [];
        $next = strtoupper((string) ($body['status'] ?? ''));
        if (!in_array($next, self::STATUSES, true)) {
            return $this->json($response, ['error' => '无效的状态'], 400);
        }
        $row = $this->findVisibleComment($id, 'c.id, c.post_id, c.status');
        if ($row === null) {
            return $this->json($response, ['error' => '评论不存在'], 400);
        }
        $from = (string) $row['status'];
        if ($from === $next) {
            return $this->json($response, ['ok' => true]);
        }
        DB::execute('UPDATE comments SET status = ? WHERE id = ?', [$next, $id]);

        // 钩子：评论状态流转
        \do_action('after_comment_status', [
            'id' => (string) $id,
            'postId' => (string) $row['post_id'],
            'from' => $from,
            'to' => $next,
        ]);

        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/comments/{id}/reply：以管理员身份回复（创建 APPROVED 子评论，前台直接显示） */
    public function reply(Request $request, Response $response, array $args): Response
    {
        $this->guardCapability('comments.manage');
        $id = (int) ($args['id'] ?? 0);
        $parent = $this->findVisibleComment($id, 'c.*');
        if ($parent === null) {
            return $this->json($response, ['error' => '评论不存在'], 400);
        }
        $content = mb_substr(trim((string) ($request->getParsedBody()['content'] ?? '')), 0, 2000);
        if ($content === '') {
            return $this->json($response, ['error' => '回复内容不能为空'], 400);
        }

        $user = Auth::user();
        DB::execute(
            'INSERT INTO comments (post_id, author_name, author_email, user_id, content, status, parent_id, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $parent['post_id'],
                (string) $user['username'],
                '',
                (int) $user['id'],
                $content,
                'APPROVED',
                $id,
                null,
            ]
        );
        $commentId = (int) DB::lastInsertId();

        // 钩子：管理员回复
        \do_action('after_comment_reply', [
            'id' => (string) $commentId,
            'postId' => (string) $parent['post_id'],
            'parentId' => (string) $id,
            'content' => $content,
            'author' => (string) $user['username'],
        ]);

        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/comments/{id}/pin：置顶 / 取消置顶 */
    public function pin(Request $request, Response $response, array $args): Response
    {
        $this->guardCapability('comments.manage');
        $id = (int) ($args['id'] ?? 0);
        $row = $this->findVisibleComment($id, 'c.id, c.is_pinned');
        if ($row === null) {
            return $this->json($response, ['error' => '评论不存在'], 400);
        }
        $next = (int) $row['is_pinned'] === 1 ? 0 : 1;
        DB::execute('UPDATE comments SET is_pinned = ? WHERE id = ?', [$next, $id]);
        return $this->json($response, ['ok' => true, 'pinned' => $next === 1]);
    }

    /** POST /admin/comments/{id}/delete。 */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->guardCapability('comments.manage');
        $id = (int) ($args['id'] ?? 0);
        $row = $this->findVisibleComment($id, 'c.id, c.author_name');
        if ($row === null) {
            return $this->json($response, ['error' => '评论不存在'], 400);
        }
        DB::execute('DELETE FROM comments WHERE id = ?', [$id]);

        // 钩子：评论删除
        \do_action('after_comment_delete', [
            'id' => (string) $id,
            'authorName' => (string) $row['author_name'],
        ]);

        return $this->json($response, ['ok' => true]);
    }

    /** POST /admin/comments/delete-by-ip：按 IP 删除全部评论，返回删除条数 */
    public function deleteByIp(Request $request, Response $response): Response
    {
        $this->guardCapability('comments.manage');
        $ip = trim((string) ($request->getParsedBody()['ip'] ?? ''));
        if ($ip === '') {
            return $this->json($response, ['error' => '参数错误'], 400);
        }
        $scope = $this->editorScope();
        if ($scope['sql'] === '') {
            $deleted = DB::execute('DELETE FROM comments WHERE ip = ?', [$ip]);
        } else {
            $ids = DB::fetchAll('SELECT comments.id FROM comments JOIN posts scope_post ON scope_post.id = comments.post_id WHERE comments.ip = ?' . $scope['sql'], array_merge([$ip], $scope['params']));
            $deleted = 0;
            foreach ($ids as $item) {
                $deleted += DB::execute('DELETE FROM comments WHERE id = ?', [(int) $item['id']]);
            }
        }
        return $this->json($response, ['ok' => true, 'deleted' => $deleted]);
    }

    /** POST /admin/comments/block-ip：拉黑 IP（settings blocked_ips JSON 数组，去重追加；不删已有评论） */
    public function blockIp(Request $request, Response $response): Response
    {
        // IP 黑名单影响全站，不应由仅管理自己内容的编辑设置。
        $this->guardAdmin();
        $ip = trim((string) ($request->getParsedBody()['ip'] ?? ''));
        if ($ip === '') {
            return $this->json($response, ['error' => '参数错误'], 400);
        }
        $list = json_decode((string) Settings::get('blocked_ips', '[]'), true);
        if (!is_array($list)) {
            $list = [];
        }
        if (!in_array($ip, $list, true)) {
            $list[] = $ip;
            DB::execute('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?', [
                'blocked_ips', json_encode($list, JSON_UNESCAPED_UNICODE), json_encode($list, JSON_UNESCAPED_UNICODE),
            ]);
        }
        return $this->json($response, ['ok' => true]);
    }

    /** 各状态计数（Tab 徽标） */
    private function statusCounts(int $postId = 0): array
    {
        $out = [];
        $scope = $this->editorScope();
        $postFilter = $postId > 0 ? ' AND c.post_id = ?' : '';
        $postParams = $postId > 0 ? [$postId] : [];
        $rows = DB::fetchAll('SELECT c.status, COUNT(*) AS n FROM comments c JOIN posts scope_post ON scope_post.id = c.post_id WHERE 1=1' . $postFilter . $scope['sql'] . ' GROUP BY c.status', array_merge($postParams, $scope['params']));
        foreach (self::STATUSES as $s) {
            $out[$s] = 0;
        }
        foreach ($rows as $r) {
            if (isset($out[$r['status']])) {
                $out[$r['status']] = (int) $r['n'];
            }
        }
        return $out;
    }

    /** 编辑只能管理自己文章下的评论；管理员管理全站。 */
    private function editorScope(): array
    {
        if (Auth::isAdmin()) return ['sql' => '', 'params' => []];
        return ['sql' => ' AND scope_post.author_id = ?', 'params' => [(int) Auth::id()]];
    }

    private function findVisibleComment(int $id, string $columns): ?array
    {
        $scope = $this->editorScope();
        return DB::fetchOne('SELECT ' . $columns . ' FROM comments c JOIN posts scope_post ON scope_post.id = c.post_id WHERE c.id = ?' . $scope['sql'], array_merge([$id], $scope['params']));
    }
}
