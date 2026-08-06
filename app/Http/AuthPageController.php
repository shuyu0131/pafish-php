<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 认证页面（登录/注册/找回密码/重置密码；独立卡片布局，无博客壳）
 * 对齐 Node 版 app/login|register|forgot-password|reset-password/page.tsx
 */
final class AuthPageController
{
    public function login(Request $request, Response $response): Response
    {
        $from = (string) ($request->getQueryParams()['from'] ?? '/admin');
        if ($from === '' || $from[0] !== '/') {
            $from = '/admin'; // 防开放重定向
        }
        return $this->page($response, 'login', [
            'subtitle' => '登录管理后台',
            'from' => $from,
            'showRegister' => (string) Settings::get('allow_registration', 'true') !== 'false',
        ]);
    }

    public function register(Request $request, Response $response): Response
    {
        return $this->page($response, 'register', [
            'subtitle' => '创建账号',
            'showRegister' => (string) Settings::get('allow_registration', 'true') !== 'false',
            'requireVerify' => (string) Settings::get('require_email_verify', 'true') !== 'false',
        ]);
    }

    public function forgot(Request $request, Response $response): Response
    {
        return $this->page($response, 'forgot', ['subtitle' => '找回密码']);
    }

    public function reset(Request $request, Response $response): Response
    {
        return $this->page($response, 'reset', [
            'subtitle' => '重置密码',
            'token' => (string) ($request->getQueryParams()['token'] ?? ''),
        ]);
    }

    /**
     * 退出登录（GET 兼容路由）：清理会话并回首页。
     * 标准退出走 POST /api/auth/logout（带 CSRF，见后台/移动端表单）；
     * 主题模板里遗留的 <a href="/logout"> 链接不再 404。
     */
    public function logout(Request $request, Response $response): Response
    {
        $user = \Pafish\Core\Auth::user();
        if ($user) {
            \do_action('after_logout', ['id' => (string) $user['id']]);
        }
        \Pafish\Core\Auth::logout();
        return $response
            ->withStatus(302)
            ->withHeader('Location', \url_to('/'));
    }

    private function page(Response $response, string $mode, array $extra = []): Response
    {
        $response->getBody()->write(\render('auth', array_merge([
            'mode' => $mode,
            'siteName' => site_name(),
        ], $extra)));
        return $response;
    }
}
