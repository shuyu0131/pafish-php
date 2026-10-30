<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Session;
use Pafish\Core\Url;
use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 认证页面（登录/注册/找回密码/重置密码；独立卡片布局，无博客壳）
 * 登录、注册、找回密码和重置密码页面
 */
final class AuthPageController
{
    public function login(Request $request, Response $response): Response
    {
        $flash = $this->takeFlash('login');
        $from = (string) ($flash['from'] ?? $request->getQueryParams()['from'] ?? '/admin');
        $from = Url::localPathOr($from, '/admin');
        return $this->page($response, 'login', [
            'subtitle' => '登录管理后台',
            'from' => $from,
            'fromUrl' => $this->siteUrl($from),
            'showRegister' => (string) Settings::get('allow_registration', 'true') !== 'false',
            'formError' => (string) ($flash['error'] ?? ''),
            'formNotice' => (string) ($flash['notice'] ?? ''),
            'formUsername' => (string) ($flash['username'] ?? ''),
        ]);
    }

    public function register(Request $request, Response $response): Response
    {
        $flash = $this->takeFlash('register');
        $pendingEmail = (string) Session::get('auth_register_email', '');
        if (isset($flash['email']) && is_string($flash['email'])) $pendingEmail = $flash['email'];
        $challenge = Session::get('email_code:register');
        $codeSent = $pendingEmail !== '' && is_array($challenge)
            && strtolower((string) ($challenge['email'] ?? '')) === strtolower($pendingEmail)
            && (int) ($challenge['expires'] ?? 0) > time();
        return $this->page($response, 'register', [
            'subtitle' => '创建账号',
            'showRegister' => (string) Settings::get('allow_registration', 'true') !== 'false',
            'requireVerify' => (string) Settings::get('require_email_verify', 'true') !== 'false',
            'from' => Url::localPathOr((string) ($flash['from'] ?? $request->getQueryParams()['from'] ?? ''), '/admin'),
            'fromUrl' => $this->siteUrl(Url::localPathOr((string) ($flash['from'] ?? $request->getQueryParams()['from'] ?? ''), '/admin')),
            'registerEmail' => $pendingEmail,
            'registerCodeSent' => $codeSent,
            'formError' => (string) ($flash['error'] ?? ''),
            'formNotice' => (string) ($flash['notice'] ?? ''),
            'formUsername' => (string) ($flash['username'] ?? ''),
        ]);
    }

    public function forgot(Request $request, Response $response): Response
    {
        $flash = $this->takeFlash('forgot');
        $email = (string) Session::get('auth_reset_email', '');
        if (isset($flash['email']) && is_string($flash['email'])) $email = $flash['email'];
        $step = (string) ($flash['step'] ?? '');
        if (!in_array($step, ['email', 'reset'], true)) {
            $step = (string) Session::get('auth_reset_email', '') !== '' ? 'reset' : 'email';
        }
        return $this->page($response, 'forgot', [
            'subtitle' => '找回密码',
            'forgotStep' => $step,
            'forgotEmail' => $email,
            'formError' => (string) ($flash['error'] ?? ''),
            'formNotice' => (string) ($flash['notice'] ?? ''),
        ]);
    }

    public function reset(Request $request, Response $response): Response
    {
        $flash = $this->takeFlash('reset');
        return $this->page($response, 'reset', [
            'subtitle' => '重置密码',
            'token' => (string) ($flash['token'] ?? $request->getQueryParams()['token'] ?? ''),
            'formError' => (string) ($flash['error'] ?? ''),
        ]);
    }

    /** Native login POST; the API remains the single authentication implementation. */
    public function submitLogin(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $from = Url::localPathOr((string) ($body['from'] ?? ''), '/admin');
        [$status, $data] = $this->api('login', $request, $body);
        if ($status >= 400 || empty($data['ok'])) {
            $this->setFlash('login', [
                'error' => (string) ($data['error'] ?? '登录失败，请重试'),
                'username' => trim((string) ($body['username'] ?? '')),
                'from' => $from,
            ]);
            return $this->redirect($response, Url::to('/login'));
        }
        return $this->redirect($response, $this->siteUrl($from));
    }

    /** Native registration and verification-code POST. */
    public function submitRegister(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        if (!Session::verifyCsrf(is_string($body['_csrf'] ?? null) ? $body['_csrf'] : null)) {
            $this->setFlash('register', [
                'error' => '请求已过期，请刷新页面重试',
                'from' => Url::localPathOr((string) ($body['from'] ?? ''), '/admin'),
            ]);
            return $this->redirect($response, Url::to('/register'));
        }
        $username = trim((string) ($body['username'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $from = Url::localPathOr((string) ($body['from'] ?? ''), '/admin');
        $intent = !empty($body['_change_email']) ? 'change-email' : (string) ($body['_intent'] ?? 'register');
        if ($intent === 'change-email') {
            \Pafish\Services\EmailCode::forget('register');
            Session::remove('auth_register_email');
            return $this->redirect($response, Url::to('/register'));
        }
        if ($intent === 'send-code') {
            $resend = !empty($body['_resend']);
            [$status, $data] = $this->api('sendCode', $request, [
                '_csrf' => $body['_csrf'] ?? '',
                'email' => $email,
                'purpose' => 'register',
            ]);
            if ($status < 400 && !empty($data['ok'])) {
                Session::set('auth_register_email', $email);
                $this->setFlash('register', ['email' => $email, 'from' => $from, 'notice' => '验证码已发送至 ' . $email . '，10 分钟内有效。']);
            } else {
                $this->setFlash('register', [
                    'error' => (string) ($data['error'] ?? '验证码发送失败，请重试'),
                    'username' => $username,
                    'email' => $email,
                    'from' => $from,
                ]);
            }
            return $this->redirect($response, Url::to('/register'));
        }

        if ((string) ($body['password'] ?? '') !== (string) ($body['confirm_password'] ?? '')) {
            $this->setFlash('register', ['error' => '两次输入的密码不一致', 'username' => $username, 'email' => $email, 'from' => $from]);
            return $this->redirect($response, Url::to('/register'));
        }

        [$status, $data] = $this->api('register', $request, $body);
        if ($status >= 400 || empty($data['ok'])) {
            $this->setFlash('register', [
                'error' => (string) ($data['error'] ?? '注册失败，请重试'),
                'username' => $username,
                'email' => $email,
                'from' => $from,
            ]);
            return $this->redirect($response, Url::to('/register'));
        }

        Session::remove('auth_register_email');
        return $this->redirect($response, $this->siteUrl($from));
    }

    /** Native email-code flow for forgot-password. */
    public function submitForgot(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        if (!Session::verifyCsrf(is_string($body['_csrf'] ?? null) ? $body['_csrf'] : null)) {
            $this->setFlash('forgot', ['error' => '请求已过期，请刷新页面重试']);
            return $this->redirect($response, Url::to('/forgot-password'));
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $resend = !empty($body['_resend']);
        $intent = !empty($body['_change_email']) ? 'change-email'
            : ($resend ? 'send-code' : (string) ($body['_intent'] ?? 'send-code'));

        if ($intent === 'change-email') {
            Session::remove('auth_reset_email');
            \Pafish\Services\EmailCode::forget('reset');
            return $this->redirect($response, Url::to('/forgot-password'));
        }

        if ($intent === 'send-code') {
            [$status, $data] = $this->api('sendCode', $request, [
                '_csrf' => $body['_csrf'] ?? '',
                'email' => $email,
                'purpose' => 'reset',
            ]);
            if ($status < 400 && !empty($data['ok'])) {
                Session::set('auth_reset_email', $email);
                $this->setFlash('forgot', ['email' => $email, 'step' => 'reset', 'notice' => '如果该邮箱对应已注册且可用的账号，验证码会发送至该邮箱。']);
            } else {
                $this->setFlash('forgot', [
                    'error' => (string) ($data['error'] ?? '验证码发送失败，请重试'),
                    'email' => $email,
                    'step' => $resend ? 'reset' : 'email',
                ]);
            }
            return $this->redirect($response, Url::to('/forgot-password'));
        }

        $newPassword = (string) ($body['newPassword'] ?? '');
        if ($newPassword !== (string) ($body['confirm_password'] ?? '')) {
            $this->setFlash('forgot', ['error' => '两次输入的密码不一致', 'email' => $email, 'step' => 'reset']);
            return $this->redirect($response, Url::to('/forgot-password'));
        }
        [$status, $data] = $this->api('resetByCode', $request, [
            '_csrf' => $body['_csrf'] ?? '',
            'email' => $email,
            'code' => $body['code'] ?? '',
            'newPassword' => $newPassword,
        ]);
        if ($status >= 400 || empty($data['ok'])) {
            $this->setFlash('forgot', [
                'error' => (string) ($data['error'] ?? '密码重置失败，请重试'),
                'email' => $email,
                'step' => 'reset',
            ]);
            return $this->redirect($response, Url::to('/forgot-password'));
        }
        Session::remove('auth_reset_email');
        \Pafish\Services\EmailCode::forget('reset');
        $this->setFlash('login', ['notice' => '密码已重置，请使用新密码登录。']);
        return $this->redirect($response, Url::to('/login'));
    }

    /** Native reset-link POST. */
    public function submitReset(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        if (!Session::verifyCsrf(is_string($body['_csrf'] ?? null) ? $body['_csrf'] : null)) {
            $this->setFlash('reset', ['error' => '请求已过期，请刷新页面重试', 'token' => (string) ($body['token'] ?? '')]);
            return $this->redirect($response, $this->siteUrl('/reset-password?token=' . rawurlencode((string) ($body['token'] ?? ''))));
        }
        if ((string) ($body['password'] ?? '') !== (string) ($body['confirm_password'] ?? '')) {
            $this->setFlash('reset', [
                'error' => '两次输入的密码不一致',
                'token' => (string) ($body['token'] ?? ''),
            ]);
            return $this->redirect($response, $this->siteUrl('/reset-password?token=' . rawurlencode((string) ($body['token'] ?? ''))));
        }
        [$status, $data] = $this->api('reset', $request, $body);
        if ($status >= 400 || empty($data['ok'])) {
            $this->setFlash('reset', [
                'error' => (string) ($data['error'] ?? '密码重置失败，请重试'),
                'token' => (string) ($body['token'] ?? ''),
            ]);
            return $this->redirect($response, $this->siteUrl('/reset-password?token=' . rawurlencode((string) ($body['token'] ?? ''))));
        }
        $this->setFlash('login', ['notice' => '密码已重置，请使用新密码登录。']);
        return $this->redirect($response, Url::to('/login'));
    }

    private function api(string $action, Request $request, array $body): array
    {
        $controller = new AuthApiController();
        $apiResponse = $controller->$action($request->withParsedBody($body), new \Slim\Psr7\Response());
        $data = json_decode((string) $apiResponse->getBody(), true);
        return [$apiResponse->getStatusCode(), is_array($data) ? $data : []];
    }

    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function setFlash(string $mode, array $data): void
    {
        Session::set('auth_form_flash', ['mode' => $mode] + $data);
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withStatus(303)->withHeader('Location', $path);
    }

    /** Convert a validated site-local path, including its query, for either URL mode. */
    private function siteUrl(string $path): string
    {
        $path = Url::localPathOr($path, '/');
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        // Front-controller URLs already carry their route in the p query parameter.
        if (preg_match('#(?:^|/)index\.php$#i', $route) === 1) {
            return $path;
        }
        $base = rtrim(Url::base(), '/');
        if ($base !== '' && ($route === $base || str_starts_with($route, $base . '/'))) {
            $route = substr($route, strlen($base)) ?: '/';
        }
        if ($query !== '') {
            $query = implode('&', array_filter(explode('&', $query), static function (string $part): bool {
                return rawurldecode(explode('=', $part, 2)[0]) !== 'p';
            }, ARRAY_FILTER_USE_BOTH));
        }
        $url = Url::to($route);
        return $query === '' ? $url : $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }

    /** Consume a one-request auth form message of the requested page type. */
    private function takeFlash(string $mode): array
    {
        $flash = Session::get('auth_form_flash');
        if (!is_array($flash)) return [];
        Session::remove('auth_form_flash');
        return ($flash['mode'] ?? '') === $mode ? $flash : [];
    }

    private function page(Response $response, string $mode, array $extra = []): Response
    {
        $response->getBody()->write(\render('auth', array_merge([
            'mode' => $mode,
            'siteName' => site_name(),
            'csrf' => Session::csrfToken(),
        ], $extra)));
        return $response;
    }
}
