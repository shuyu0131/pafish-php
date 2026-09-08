<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Services\Plugin;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 插件前台页面（/plugin/{name}/{path}，path 缺省 index）：
 * - name 与每段 path 白名单校验（防穿越），多段路径直接 404
 * - 需激活 + plugin.json 声明该 path + index.php 导出 renderPluginPage 且返回非空 HTML
 * - 渲染结果拼公共 shell（侧边栏/导航/主题/注入，走系统布局模板）
 */
final class PluginPageController
{
    public function show(Request $request, Response $response, array $args): Response
    {
        $name = (string) ($args['name'] ?? '');
        $segs = array_values(array_filter(
            explode('/', (string) ($args['path'] ?? '')),
            static fn (string $s): bool => $s !== ''
        ));

        if (preg_match('/^[a-z0-9_-]{1,50}$/', $name) !== 1) {
            return Listings::notFound($response, '页面不存在');
        }
        if (count($segs) > 1) {
            return Listings::notFound($response, '页面不存在');
        }
        foreach ($segs as $seg) {
            if (preg_match('/^[a-z0-9_-]{1,50}$/', $seg) !== 1) {
                return Listings::notFound($response, '页面不存在');
            }
        }
        $pagePath = $segs[0] ?? 'index';

        $result = Plugin::renderPluginPage($name, $pagePath);
        if ($result === null) {
            return Listings::notFound($response, '页面不存在');
        }

        $response->getBody()->write(\render('plugin-page', [
            'title' => $result['title'],
            'pluginName' => $name,
            'pluginPageHtml' => $result['html'],
        ]));
        return $response;
    }
}
