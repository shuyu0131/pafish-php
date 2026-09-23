<?php

declare(strict_types=1);

namespace Pafish\Http;

use Pafish\Core\Auth;
use Pafish\Services\MicroStatuses;
use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** 微语公开列表；登录作者可看到自己的私密微语。 */
final class MicroController
{
    public function index(Request $request, Response $response): Response
    {
        $page = MicroStatuses::publicPage(
            max(1, (int) ($request->getQueryParams()['page'] ?? 1)),
            (int) Settings::get('posts_per_page', '10'),
            Auth::id()
        );
        $response->getBody()->write(render('micro', [
            'title' => '微语',
            'description' => '站点最新微语',
            'items' => $page['items'],
            'pageNum' => $page['page'],
            'totalPages' => $page['totalPages'],
            'total' => $page['total'],
            'listBaseUrl' => url_to('/micro'),
        ]));
        return $response;
    }
}
