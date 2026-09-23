<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Core\Session;
use Pafish\Core\Url;
use Pafish\Services\ExtensionRequest;
use Pafish\Services\ExtensionRoutes;
use Pafish\Services\Plugin;
use Pafish\Services\Theme;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** 主题与插件的声明式前台路由分发器。 */
final class ExtensionController
{
    /** /plugin/{name}/... 的私有插件入口，兼容旧 pages。 */
    public function plugin(Request $request, Response $response, array $args): Response
    {
        $name = (string) ($args['name'] ?? '');
        $path = '/plugin/' . $name;
        $tail = trim((string) ($args['path'] ?? ''), '/');
        if ($tail !== '') {
            $path .= '/' . $tail;
        }
        $result = $this->dispatchRoute($request, $response, $path);
        if ($result !== null) {
            return $result;
        }

        // v1/v2 的 pages 入口只支持 GET，保持已发布插件兼容。
        if (strtoupper($request->getMethod()) === 'GET' && preg_match('/^[a-z0-9_-]{1,50}$/', $name) === 1) {
            $pagePath = $tail === '' ? 'index' : $tail;
            if (preg_match('/^[a-z0-9_-]{1,50}$/', $pagePath) === 1) {
                $legacy = Plugin::renderPluginPage($name, $pagePath);
                if ($legacy !== null) {
                    $response->getBody()->write(\render('plugin-page', [
                        'title' => $legacy['title'],
                        'pluginName' => $name,
                        'pluginPageHtml' => $legacy['html'],
                    ]));
                    return $response;
                }
            }
        }
        return Listings::notFound($response, '页面不存在');
    }

    /** /admin/plugin/{name}/... 的声明式后台插件入口。 */
    public function adminPlugin(Request $request, Response $response, array $args): Response
    {
        $name = (string) ($args['name'] ?? '');
        $path = '/admin/plugin/' . $name;
        $tail = trim((string) ($args['path'] ?? ''), '/');
        if ($tail !== '') {
            $path .= '/' . $tail;
        }
        $result = $this->dispatchRoute($request, $response, $path);
        return $result ?? Listings::notFound($response, '页面不存在');
    }

    /** 主题自然路径与声明 public:true 的插件路径。 */
    public function publicRoute(Request $request, Response $response, array $args, ?array $registeredRoute = null): Response
    {
        $path = $request->getUri()->getPath();
        $base = Url::base();
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim($path, '/');
        // routes.php 已经用同一份 publicRoutes() 结果注册到 Slim；直接使用捕获的规范化路由，
        // 避免参数化自然路径在 Slim 与 ExtensionRoutes.match() 之间发生双重解析偏差。
        $result = $this->dispatchRoute($request, $response, $path, $registeredRoute, $args);
        return $result ?? Listings::notFound($response, '页面不存在');
    }

    private function dispatchRoute(Request $request, Response $response, string $path, ?array $registeredRoute = null, array $routeParams = []): ?Response
    {
        $matched = $registeredRoute !== null
            ? [
                'kind' => (string) ($registeredRoute['kind'] ?? ''),
                'name' => (string) ($registeredRoute['name'] ?? ''),
                'route' => $registeredRoute,
                'params' => $routeParams,
            ]
            : ExtensionRoutes::match($request->getMethod(), $path);
        if ($matched === null) {
            return null;
        }
        // 长驻进程中扩展可能在路由表建立后被停用；捕获的公共路由不能绕过当前激活状态。
        if (($matched['kind'] ?? '') === 'theme' && Theme::active() !== (string) ($matched['name'] ?? '')) {
            return null;
        }
        if (($matched['kind'] ?? '') === 'plugin' && !Plugin::isActive((string) ($matched['name'] ?? ''))) {
            return null;
        }
        $route = $matched['route'];
        $user = Auth::user();
        if (!$this->authorized((string) $route['auth'], $user)) {
            return $this->error($response, $route, '没有访问此扩展功能的权限', $user === null ? 401 : 403);
        }
        $capability = $route['capability'] ?? null;
        if (is_string($capability) && $capability !== '' && !Auth::can($capability)) {
            return $this->error($response, $route, '没有访问此扩展功能的权限', $user === null ? 401 : 403);
        }

        if (strtoupper($request->getMethod()) === 'POST') {
            $body = $request->getParsedBody();
            $token = is_array($body) ? ($body['_csrf'] ?? null) : null;
            $token = is_string($token) ? $token : $request->getHeaderLine('X-CSRF-Token');
            if (!Session::verifyCsrf($token)) {
                return $this->error($response, $route, '请求已过期，请刷新页面重试', 419);
            }
            if ($user === null && !ExtensionRoutes::allowAnonymousPost($matched['kind'], $matched['name'], $path)) {
                return $this->error($response, $route, '请求过于频繁，请稍后再试', 429);
            }
        }

        $module = $matched['kind'] === 'plugin' ? Plugin::module($matched['name']) : Theme::module($matched['name']);
        $handler = $module[$route['handler']] ?? null;
        if (!is_callable($handler)) {
            error_log('[pafish-extension] ' . $matched['kind'] . ':' . $matched['name'] . ' 缺少路由处理器 ' . $route['handler']);
            return $this->error($response, $route, '扩展功能暂时不可用', 503);
        }

        $requestId = bin2hex(random_bytes(8));
        try {
            $result = $handler(
                new ExtensionRequest($request, $matched['params'], $user, $matched['kind'], $matched['name']),
                $matched['kind'] === 'plugin' ? Plugin::context($matched['name']) : Theme::context($matched['name'])
            );
            return $this->respond($response, $route, $result);
        } catch (\Throwable $e) {
            error_log('[pafish-extension] request=' . $requestId . ' ' . $matched['kind'] . ':' . $matched['name'] . ' handler=' . $route['handler'] . ' failed: ' . $e->getMessage());
            return $this->error($response, $route, '扩展处理失败，请稍后重试', 500, $requestId);
        }
    }

    private function authorized(string $auth, ?array $user): bool
    {
        if ($auth === 'guest') {
            return true;
        }
        if ($user === null) {
            return false;
        }
        return match ($auth) {
            'login' => true,
            'editor' => in_array((string) ($user['role'] ?? ''), ['ADMIN', 'EDITOR'], true),
            'admin' => (string) ($user['role'] ?? '') === 'ADMIN',
            default => false,
        };
    }

    private function respond(Response $response, array $route, mixed $result): Response
    {
        $type = (string) $route['response'];
        if ($type === 'html') {
            $html = is_string($result) ? $result : (is_array($result) ? ($result['html'] ?? null) : null);
            if (!is_string($html)) {
                throw new \RuntimeException('HTML 路由处理器必须返回 HTML 字符串或 [html => string]');
            }
            $status = is_array($result) && isset($result['status']) ? (int) $result['status'] : 200;
            $response->getBody()->write($html);
            return $response->withStatus($this->safeStatus($status))->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        if ($type === 'json') {
            if (!is_array($result)) {
                throw new \RuntimeException('JSON 路由处理器必须返回数组');
            }
            $status = isset($result['status']) ? (int) $result['status'] : 200;
            unset($result['status']);
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $response->withStatus($this->safeStatus($status))->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        $target = is_string($result) ? $result : (is_array($result) ? ($result['redirect'] ?? null) : null);
        if (!is_string($target) || !str_starts_with($target, '/') || str_starts_with($target, '//') || str_contains($target, "\r") || str_contains($target, "\n")) {
            throw new \RuntimeException('重定向路由处理器必须返回站内相对路径');
        }
        return $response->withStatus(302)->withHeader('Location', Url::to($target));
    }

    private function error(Response $response, array $route, string $message, int $status, ?string $requestId = null): Response
    {
        if (($route['response'] ?? '') === 'json') {
            $payload = ['ok' => false, 'error' => $message];
            if ($requestId !== null) {
                $payload['requestId'] = $requestId;
            }
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        if ($status === 401) {
            $from = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            return $response->withStatus(302)->withHeader('Location', Url::to('/login') . '?from=' . rawurlencode($from));
        }
        $response->getBody()->write('<!doctype html><meta charset="utf-8"><title>请求失败</title><p>' . \e($message) . '</p>');
        return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function safeStatus(int $status): int
    {
        return $status >= 200 && $status <= 599 ? $status : 200;
    }
}
