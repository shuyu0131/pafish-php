<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 通知中心：
 * - 20/页，未读在前（read asc）再按时间倒序；顶部未读数 + 「全部已读」
 * - 通知类型：NEW_COMMENT 新评论 / NEW_REPLY 新回复（仅评论提交产生，见 Notify::createNotification）
 * - 每条：类型图标、message、时间、「查看文章」+「去审核」（PENDING 列表）链接
 * 权限：ADMIN+EDITOR（comments.manage）；编辑仅查看自己文章的通知，CSRF 由 AdminAuthMiddleware 统一校验
 */
final class NotificationsController extends AdminController
{
    private const PAGE_SIZE = 20;

    /** GET /admin/notifications：通知列表（未读在前） */
    public function index(Request $request, Response $response): Response
    {
        $this->guardCapability('comments.manage');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $scope = $this->editorScope();
        $from = ' FROM notifications n LEFT JOIN posts p ON p.id = n.post_id WHERE 1=1' . $scope['sql'];
        $total = (int) DB::value('SELECT COUNT(*)' . $from, $scope['params']);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);

        $items = DB::fetchAll(
            'SELECT n.*, p.slug AS post_slug, p.title AS post_title' . $from
             . ' ORDER BY n.`read` ASC, n.created_at DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            $scope['params']
        );

        $response->getBody()->write($this->render('notifications', [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'unread' => (int) DB::value('SELECT COUNT(*)' . $from . ' AND n.`read` = 0', $scope['params']),
        ], '通知'));
        return $response;
    }

    /** POST /admin/notifications/read-all。 */
    public function readAll(Request $request, Response $response): Response
    {
        $this->guardCapability('comments.manage');
        $scope = $this->editorScope();
        if ($scope['sql'] === '') {
            DB::execute('UPDATE notifications SET `read` = 1 WHERE `read` = 0');
        } else {
            DB::execute('UPDATE notifications n JOIN posts p ON p.id = n.post_id SET n.`read` = 1 WHERE n.`read` = 0 AND p.author_id = ?', $scope['params']);
        }
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        $this->flash('success', '全部通知已标记为已读');
        return $this->redirect($response, '/admin/notifications');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->guardCapability('comments.manage');
        $id = (int) ($args['id'] ?? 0);
        $scope = $this->editorScope();
        $deleted = $scope['sql'] === ''
            ? DB::execute('DELETE FROM notifications WHERE id = ?', [$id])
            : DB::execute('DELETE n FROM notifications n JOIN posts p ON p.id = n.post_id WHERE n.id = ? AND p.author_id = ?', array_merge([$id], $scope['params']));
        return $deleted > 0 ? $this->json($response, ['ok' => true]) : $this->json($response, ['error' => '通知不存在'], 404);
    }

    private function editorScope(): array
    {
        return Auth::isAdmin() ? ['sql' => '', 'params' => []] : ['sql' => ' AND p.author_id = ?', 'params' => [(int) Auth::id()]];
    }
}
