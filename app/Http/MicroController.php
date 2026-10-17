<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Services\MicroStatuses;
use Pafish\Services\Settings;
use Pafish\Services\Theme;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** 微语公开列表；登录作者可看到自己的私密微语。 */
final class MicroController
{
    public function index(Request $request, Response $response): Response
    {
        // 微语和用户中心一样不是主题必选项：主题既没提供 micro.php、
        // 也没声明系统模板兜底时按页面不存在处理，而不是让模板异常冒成 500。
        $manifest = Theme::manifest(Theme::active()) ?? [];
        $fallback = is_array($manifest['systemTemplateFallback'] ?? null)
            && in_array('micro', $manifest['systemTemplateFallback'], true);
        if (!Theme::hasTemplate('micro') && !$fallback) {
            return Listings::notFound($response, '页面不存在');
        }

        $page = MicroStatuses::publicPage(
            max(1, (int) ($request->getQueryParams()['page'] ?? 1)),
            (int) Settings::get('posts_per_page', '10'),
            Auth::id()
        );
        $response->getBody()->write(render('micro', [
            'title' => '微语',
            'description' => '站点最新微语',
            'og' => Listings::og($request, '微语', '站点最新微语'),
            'items' => $page['items'],
            'pageNum' => $page['page'],
            'totalPages' => $page['totalPages'],
            'total' => $page['total'],
            'listBaseUrl' => url_to('/micro'),
        ]));
        return $response;
    }
}
