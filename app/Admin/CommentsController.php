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
 * - 4 Tab：待审核 PENDING / 已通过 APPROVED / 垃圾 SPAM / 已删除 TRASH（TRASH 无写入路径，保留 Tab）
 * - 20/页、created_at 倒序；每条显示作者/邮箱/IP/时间/内容/文章链接/父评论作者
 * - 操作：回复（以管理员身份建 APPROVED 子评论）、通过/垃圾（状态流转，驳回即 SPAM）、
 *   置顶/取消、按 IP 删除（物理删，级联子评论）、拉黑 IP（settings blocked_ips JSON）、
 *   删除（物理删，级联子评论）
 * 权限：ADMIN+EDITOR（guardCanManage）；CSRF 由 AdminAuthMiddleware 统一校验
 */
final class CommentsController extends AdminController
{
    private const PAGE_SIZE = 20;

    /** 状态常量 */
    private const STATUSES = ['PENDING', 'APPROVED', 'SPAM', 'TRASH'];

    /** GET /admin/comments：评论列表（status + page） */
    public function index(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $status = strtoupper((string) ($_GET['status'] ?? 'PENDING'));
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'PENDING';
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $total = (int) DB::value('SELECT COUNT(*) FROM comments WHERE status = ?', [$status]);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);

        // 每条评论带文章标题 + 父评论作者（楼中楼「回复 @xx」徽标）
        $items = DB::fetchAll(
            "SELECT c.*, p.title AS post_title, p.slug AS post_slug, parent.author_name AS parent_name
             FROM comments c
             LEFT JOIN posts p ON p.id = c.post_id
             LEFT JOIN comments parent ON parent.id = c.parent_id
             WHERE c.status = ?
             ORDER BY c.created_at DESC
             LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            [$status]
        );

        $response->getBody()->write($this->render('comments', [
            'items' => $items,
            'status' => $status,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'statusCounts' => $this->statusCounts(),
        ], '评论审核'));
        return $response;
    }

    /** POST /admin/comments/{id}/status。 */
    public function status(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $id = (int) ($args['id'] ?? 0);
        $body = $request->getParsedBody() ?? [];
        $next = strtoupper((string) ($body['status'] ?? ''));
        if (!in_array($next, self::STATUSES, true)) {
            return $this->json($response, ['error' => '无效的状态'], 400);
        }
        $row = DB::fetchOne('SELECT id, post_id, status FROM comments WHERE id = ?', [$id]);
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
        $this->guardCanManage();
        $id = (int) ($args['id'] ?? 0);
        $parent = DB::fetchOne('SELECT * FROM comments WHERE id = ?', [$id]);
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
        $this->guardCanManage();
        $id = (int) ($args['id'] ?? 0);
        $row = DB::fetchOne('SELECT id, is_pinned FROM comments WHERE id = ?', [$id]);
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
        $this->guardCanManage();
        $id = (int) ($args['id'] ?? 0);
        $row = DB::fetchOne('SELECT id, author_name FROM comments WHERE id = ?', [$id]);
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
        $this->guardCanManage();
        $ip = trim((string) ($request->getParsedBody()['ip'] ?? ''));
        if ($ip === '') {
            return $this->json($response, ['error' => '参数错误'], 400);
        }
        $deleted = DB::execute('DELETE FROM comments WHERE ip = ?', [$ip]);
        return $this->json($response, ['ok' => true, 'deleted' => $deleted]);
    }

    /** POST /admin/comments/block-ip：拉黑 IP（settings blocked_ips JSON 数组，去重追加；不删已有评论） */
    public function blockIp(Request $request, Response $response): Response
    {
        $this->guardCanManage();
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
    private function statusCounts(): array
    {
        $out = [];
        $rows = DB::fetchAll('SELECT status, COUNT(*) AS n FROM comments GROUP BY status');
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
}
