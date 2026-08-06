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

    private function page(Response $response, string $mode, array $extra = []): Response
    {
        $response->getBody()->write(\render('auth', array_merge([
            'mode' => $mode,
            'siteName' => site_name(),
        ], $extra)));
        return $response;
    }
}
