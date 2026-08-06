<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 通知中心（对齐 Node app/admin/notifications/）：
 * - 20/页，未读在前（read asc）再按时间倒序；顶部未读数 + 「全部已读」
 * - 通知类型：NEW_COMMENT 新评论 / NEW_REPLY 新回复（仅评论提交产生，见 Notify::createNotification）
 * - 每条：类型图标、message、时间、「查看文章」+「去审核」（PENDING 列表）链接
 * 权限：ADMIN+EDITOR（guardCanManage）；CSRF 由 AdminAuthMiddleware 统一校验
 */
final class NotificationsController extends AdminController
{
    private const PAGE_SIZE = 20; // 对齐 Node PAGE_SIZE

    /** GET /admin/notifications：通知列表（未读在前） */
    public function index(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $total = (int) DB::value('SELECT COUNT(*) FROM notifications');
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);

        $items = DB::fetchAll(
            'SELECT n.*, p.slug AS post_slug, p.title AS post_title
             FROM notifications n
             LEFT JOIN posts p ON p.id = n.post_id
             ORDER BY n.`read` ASC, n.created_at DESC
             LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE)
        );

        $response->getBody()->write($this->render('notifications', [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'unread' => (int) DB::value('SELECT COUNT(*) FROM notifications WHERE `read` = 0'),
        ], '通知'));
        return $response;
    }

    /** POST /admin/notifications/read-all：全部标已读（对齐 markAllNotificationsRead） */
    public function readAll(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        DB::execute('UPDATE notifications SET `read` = 1 WHERE `read` = 0');
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        $this->flash('success', '全部通知已标记为已读');
        return $this->redirect($response, '/admin/notifications');
    }
}
