<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Services\Captcha;
use Pafish\Services\Notify;
use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 评论 API（含验证码校验）
 * 反垃圾：无 UA / 爬虫 UA 拒绝、黑名单 IP 403、同 IP 5 秒限速（文件缓存）、1 小时重复 429
 */
final class CommentApiController
{
    private const MIN_INTERVAL = 5; // 同 IP 两次评论最小间隔（秒）
    private const CAPTCHA_MIN_INTERVAL = 2; // 同一请求身份刷新验证码最小间隔（秒）
    private const MAX_LIKED = 200;  // 每人最多点赞的评论数（防止 cookie 无限膨胀）

    // ---------- 图形验证码 ----------

    public function captcha(Request $request, Response $response): Response
    {
        $identity = $this->captchaIdentity($request);
        $retryAfter = $this->captchaRetryAfter($identity);
        if ($retryAfter > 0) {
            return $this->json($response, [
                'error' => '验证码刷新过于频繁，请稍后再试',
                'retryAfter' => $retryAfter,
            ], 429)->withHeader('Retry-After', (string) $retryAfter);
        }
        [$token, $svg] = Captcha::create($identity);
        return $this->json($response, ['token' => $token, 'svg' => $svg]);
    }

    // ---------- 评论提交 ----------

    public function create(Request $request, Response $response): Response
    {
        if ((string) Settings::get('comments_enabled', 'true') === 'false') {
            return $this->json($response, ['error' => '评论功能已关闭'], 403);
        }

        // 反垃圾前置检查：无 UA 或疑似爬虫一律拒绝；同一 IP 限速
        $ua = (string) ($request->getHeaderLine('User-Agent') ?? '');
        if ($ua === '' || preg_match('/bot|crawler|spider|robot|slurp|crawling/i', $ua)) {
            return $this->json($response, ['error' => '评论提交被拒绝'], 403);
        }
        $ip = self::clientIp($request);
        // 黑名单 IP（后台按 IP 拉黑）直接拒绝；数据损坏时忽略
        try {
            $blockedList = json_decode((string) Settings::get('blocked_ips', '[]'), true);
            if (is_array($blockedList) && in_array($ip, $blockedList, true)) {
                return $this->json($response, ['error' => '评论提交被拒绝'], 403);
            }
        } catch (\Throwable) {
        }
        if (self::isCommentTooFast($ip)) {
            return $this->json($response, ['error' => '评论太频繁，请稍后再试'], 429);
        }

        $body = $request->getParsedBody() ?? [];
        $postId = (string) ($body['postId'] ?? '');

        // 登录检测：已登录用户自动使用登录身份（服务端强制，忽略 body 伪造的昵称/邮箱）
        // 未登录游客必须通过图形验证码（后台可关闭）
        $sessionUser = Auth::user();
        $name = '';
        $email = '';
        $userId = null;
        if ($sessionUser) {
            // 会话失效（用户被删/被禁）按游客处理——此处被禁直接拒绝
            if ((int) $sessionUser['disabled'] === 1) {
                return $this->json($response, ['error' => '登录状态已失效，请重新登录'], 401);
            }
            $userId = (int) $sessionUser['id'];
            $name = mb_substr(trim((string) ($sessionUser['nickname'] ?: $sessionUser['username'])), 0, 50);
            $email = (string) $sessionUser['email'];
        } else {
            // 游客评论验证码：默认开启（未写入设置时按开启处理）
            if ((string) Settings::get('comments_captcha_enabled', 'true') !== 'false') {
                $captchaToken = (string) ($body['captchaToken'] ?? '');
                $captchaAnswer = (string) ($body['captchaAnswer'] ?? '');
                if (!Captcha::verify($captchaToken, $captchaAnswer, $this->captchaIdentity($request))) {
                    return $this->json($response, ['error' => '验证码错误，请重试'], 400);
                }
            }
            $name = mb_substr(trim((string) ($body['name'] ?? '')), 0, 50);
            $email = mb_substr(trim((string) ($body['email'] ?? '')), 0, 255);
        }

        $content = mb_substr(trim((string) ($body['content'] ?? '')), 0, 2000);
        $parentIdRaw = (string) ($body['parentId'] ?? '');
        // 勾选"有新回复邮件通知我"：有人回复这条评论时给评论者发邮件
        $notifyReply = ($body['notifyReply'] ?? false) === true;

        if ($postId === '' || $name === '' || $content === '') {
            return $this->json($response, ['error' => '昵称和评论内容不能为空'], 400);
        }
        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return $this->json($response, ['error' => '邮箱格式不正确'], 400);
        }

        $post = DB::fetchOne(
            "SELECT id, status, title, slug FROM posts WHERE id = ? AND deleted_at IS NULL",
            [(int) ($postId ?: 0)]
        );
        if (!$post || $post['status'] !== 'PUBLISHED') {
            return $this->json($response, ['error' => '文章不存在'], 404);
        }

        // 回复校验：父评论必须存在、属于同一篇文章且已通过审核
        $parentId = null;
        $parentEmail = null;
        if ($parentIdRaw !== '') {
            $parent = DB::fetchOne(
                'SELECT id, post_id, status, author_email FROM comments WHERE id = ?',
                [(int) ($parentIdRaw ?: 0)]
            );
            if (!$parent || (int) $parent['post_id'] !== (int) $post['id']) {
                return $this->json($response, ['error' => '回复的评论不存在'], 400);
            }
            if ($parent['status'] !== 'APPROVED') {
                return $this->json($response, ['error' => '只能回复已通过的评论'], 400);
            }
            $parentId = (int) $parent['id'];
            $parentEmail = $parent['author_email'];
        }

        // 重复检测：同一文章 + 昵称 + 内容在 1 小时内只允许提交一次
        $dup = DB::fetchOne(
            'SELECT id FROM comments
             WHERE post_id = ? AND author_name = ? AND content = ? AND created_at >= ?',
            [(int) $post['id'], $name, $content, date('Y-m-d H:i:s', time() - 3600)]
        );
        if ($dup) {
            return $this->json($response, ['error' => '请勿重复提交相同评论'], 429);
        }

        $needReview = (string) Settings::get('comments_need_review', 'true') !== 'false';
        $commentDecision = \apply_filters('before_comment_submit', [
            'allowed' => true,
            'status' => $needReview ? 'PENDING' : 'APPROVED',
            'httpStatus' => 403,
            'error' => '评论提交被安全策略拒绝',
        ], [
            'postId' => (string) $post['id'],
            'postTitle' => (string) $post['title'],
            'author' => $name,
            'email' => $email,
            'content' => $content,
            'parentId' => $parentId !== null ? (string) $parentId : null,
            'userId' => $userId !== null ? (string) $userId : null,
            'ip' => $ip,
            'plugins' => is_array($body['plugins'] ?? null) ? $body['plugins'] : [],
        ]);
        if (is_array($commentDecision) && ($commentDecision['allowed'] ?? true) === false) {
            $httpStatus = max(400, min(499, (int) ($commentDecision['httpStatus'] ?? 403)));
            return $this->json($response, ['error' => (string) ($commentDecision['error'] ?? '评论提交被安全策略拒绝')], $httpStatus);
        }
        $commentStatus = is_array($commentDecision) ? (string) ($commentDecision['status'] ?? '') : '';
        if (!in_array($commentStatus, ['PENDING', 'APPROVED', 'REJECTED'], true)) {
            $commentStatus = $needReview ? 'PENDING' : 'APPROVED';
        }
        DB::execute(
            'INSERT INTO comments (post_id, author_name, author_email, user_id, content, status, parent_id, notify_reply, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $post['id'],
                $name,
                $email,
                $userId,
                $content,
                $commentStatus,
                $parentId,
                $notifyReply ? 1 : 0,
                $ip === 'unknown' ? null : $ip,
            ]
        );
        $commentId = (int) DB::lastInsertId();

        // 站内通知（后台铃铛）+ 可选邮件通知
        $isReply = $parentId !== null;
        Notify::createNotification(
            $isReply ? 'NEW_REPLY' : 'NEW_COMMENT',
            "{$name}" . ($isReply ? '回复了' : '评论了') . "《{$post['title']}》",
            (int) $post['id'],
            $commentId
        );
        Notify::sendCommentEmail([
            'commenter' => $name,
            'isReply' => $isReply,
            'postTitle' => $post['title'],
            'postSlug' => $post['slug'],
            'commentId' => $commentId,
            'content' => $content,
        ]);

        // 被回复者邮件通知：父评论者勾选了通知且不是自己回复自己时发送
        if ($isReply && $parentEmail !== null && $parentEmail !== $email) {
            Notify::sendReplyEmail([
                'toEmail' => $parentEmail,
                'replier' => $name,
                'replyContent' => $content,
                'postTitle' => $post['title'],
                'postSlug' => $post['slug'],
                'commentId' => $parentId,
            ]);
        }

        // 钩子：评论提交成功
        \do_action('after_comment_submit', [
            'id' => (string) $commentId,
            'postId' => (string) $post['id'],
            'author' => $name,
            'email' => $email,
            'content' => $content,
            'status' => $commentStatus,
            'parentId' => $parentId !== null ? (string) $parentId : null,
            'ip' => $ip,
        ]);

        return $this->json($response, ['ok' => true]);
    }

    // ---------- 评论点赞 ----------

    public function like(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $raw = (string) ($body['commentId'] ?? '');
        if (!preg_match('/^\d+$/', $raw)) {
            return $this->json($response, ['error' => '参数错误'], 400);
        }
        $id = (int) $raw;

        $comment = DB::fetchOne('SELECT status, like_count FROM comments WHERE id = ?', [$id]);
        if (!$comment || $comment['status'] !== 'APPROVED') {
            return $this->json($response, ['error' => '评论不存在'], 404);
        }

        $cookieName = 'liked_comments';
        $liked = array_values(array_filter(explode(',', (string) ($_COOKIE[$cookieName] ?? ''))));
        $key = (string) $id;
        $isLiked = in_array($key, $liked, true);

        if ($isLiked) {
            // 取消点赞（计数不小于 0）
            if ((int) $comment['like_count'] > 0) {
                DB::execute('UPDATE comments SET like_count = like_count - 1 WHERE id = ?', [$id]);
            }
            $liked = array_values(array_filter($liked, static fn (string $x): bool => $x !== $key));
        } else {
            DB::execute('UPDATE comments SET like_count = like_count + 1 WHERE id = ?', [$id]);
            $liked[] = $key;
            if (count($liked) > self::MAX_LIKED) {
                $liked = array_slice($liked, count($liked) - self::MAX_LIKED);
            }
        }
        setcookie($cookieName, implode(',', $liked), time() + 31536000, '/', '', false, false);

        $count = (int) DB::value('SELECT like_count FROM comments WHERE id = ?', [$id]);
        return $this->json($response, ['liked' => !$isLiked, 'count' => $count]);
    }

    // ---------- 内部 ----------

    private function clientIp(Request $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }
        $real = $request->getHeaderLine('X-Real-IP');
        if ($real !== '') {
            return trim($real);
        }
        $serverParams = $request->getServerParams();
        return (string) ($serverParams['REMOTE_ADDR'] ?? 'unknown');
    }

    /** 验证码绑定会话和来源地址，避免 token 被跨会话转用 */
    private function captchaIdentity(Request $request): string
    {
        return $this->clientIp($request) . '|' . (session_id() ?: 'no-session');
    }

    /** 验证码刷新限速；返回剩余等待秒数，0 表示允许 */
    private function captchaRetryAfter(string $identity): int
    {
        $dir = dirname(__DIR__, 2) . '/runtime/rate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/captcha_' . md5($identity) . '.ts';
        $now = time();
        $last = is_file($file) ? (int) @file_get_contents($file) : 0;
        $remaining = self::CAPTCHA_MIN_INTERVAL - ($now - $last);
        if ($remaining > 0) {
            return $remaining;
        }
        @file_put_contents($file, (string) $now, LOCK_EX);

        // 控制长期未清理的来源记录数量，单次最多清理 100 个。
        $files = glob($dir . '/captcha_*.ts');
        if (is_array($files) && count($files) > 1000) {
            $cutoff = $now - 3600;
            $cleaned = 0;
            foreach ($files as $f) {
                if ((int) @file_get_contents($f) < $cutoff && @unlink($f)) {
                    $cleaned++;
                }
                if ($cleaned >= 100) {
                    break;
                }
            }
        }
        return 0;
    }

    /** 同 IP 限速：runtime/rate/comment_{ip}.ts；顺带清理 10 分钟前的记录 */
    private function isCommentTooFast(string $ip): bool
    {
        $dir = dirname(__DIR__, 2) . '/runtime/rate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/comment_' . md5($ip) . '.ts';
        $now = time();
        $last = is_file($file) ? (int) @file_get_contents($file) : 0;
        if ($now - $last < self::MIN_INTERVAL) {
            return true;
        }
        @file_put_contents($file, (string) $now);

        // 顺带清理：文件超过 1000 个时清掉 10 分钟前的
        $files = glob($dir . '/comment_*.ts');
        if (is_array($files) && count($files) > 1000) {
            $cutoff = $now - 600;
            $cleaned = 0;
            foreach ($files as $f) {
                if ((int) @file_get_contents($f) < $cutoff && @unlink($f)) {
                    $cleaned++;
                }
                if ($cleaned >= 200) {
                    break;
                }
            }
        }
        return false;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
