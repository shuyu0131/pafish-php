<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\Session;
use Pafish\Core\Url;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * 后台守卫中间件（对齐 Node src/middleware.ts 的 /admin/:path* matcher）：
 * - 未登录 → 302 /login?from=<原地址>（登录后跳回）
 * - /admin 下所有 POST 请求校验 CSRF 令牌（Node 的 server action 由框架内置防伪，PHP 需显式）
 */
final class AdminAuthMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        if (!Auth::check()) {
            $from = (string) $request->getUri();
            header('Location: ' . Url::to('/login') . '?from=' . urlencode($from));
            exit;
        }

        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $token = is_array($body) ? (string) ($body['_csrf'] ?? '') : '';
            if (!Session::verifyCsrf($token)) {
                http_response_code(419);
                header('Content-Type: text/html; charset=UTF-8');
                $home = e(Url::to('/admin'));
                echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8">'
                    . '<title>CSRF 校验失败</title>'
                    . '<body style="font-family:system-ui, sans-serif;display:grid;place-items:center;'
                    . 'min-height:100vh;margin:0;background:#f6f7f9;color:#333">'
                    . '<div style="text-align:center;max-width:420px;padding:24px">'
                    . '<p style="font-size:44px;margin:0">🛡️</p>'
                    . '<h1 style="font-size:18px;margin:14px 0 8px">CSRF 校验失败</h1>'
                    . '<p style="color:#71717a;font-size:14px;line-height:1.7">表单已过期或令牌无效，'
                    . '请返回上一页刷新后重试。</p>'
                    . '<a href="' . $home . '" style="display:inline-block;margin-top:14px;'
                    . 'padding:8px 20px;border-radius:8px;background:#4786d6;color:#fff;'
                    . 'text-decoration:none;font-size:14px">返回工作台</a>'
                    . '</div></body></html>';
                exit;
            }
        }

        return $handler->handle($request);
    }
}
