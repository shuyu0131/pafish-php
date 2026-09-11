<?php

declare(strict_types=1);

namespace Pafish\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 静态资源兜底路由
 * 生产环境静态资源应由 Web 服务器直接映射（Apache .htaccess / Nginx alias），
 * 本路由仅在缺少该配置时兜底：/css/* /js/* /uploads/* → public/ 下对应文件。
 * 白名单 + 禁止路径穿越 + 仅服务存在的真实文件。
 */
final class StaticFileController
{
    /** 允许的静态目录（映射到 public/{dir}） */
    private const ALLOWED_DIRS = ['css', 'js', 'uploads', 'vendor'];

    /** MIME 映射（与 router.php 保持一致） */
    private const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    public function serve(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        $dir = $args['dir'] ?? '';

        // 白名单目录
        if (!in_array($dir, self::ALLOWED_DIRS, true)) {
            return $this->notFound($response);
        }

        // 路径穿越防护：拒绝 ..、绝对路径、空段（//）、NULL 字节
        $path = ltrim($path, '/');
        if (
            $path === ''
            || str_contains($path, '..')
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_contains($path, '//')
        ) {
            return $this->notFound($response);
        }

        // 仅服务 public/ 下真实存在的文件
        $file = PAFISH_ROOT . '/public/' . $dir . '/' . $path;
        if (!is_file($file)) {
            return $this->notFound($response);
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $response->getBody()->write((string) file_get_contents($file));
        return $response
            ->withHeader('Content-Type', self::TYPES[$ext] ?? 'application/octet-stream')
            ->withHeader('Content-Length', (string) filesize($file))
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }

    private function notFound(Response $response): Response
    {
        $response->getBody()->write('404 Not Found');
        return $response
            ->withStatus(404)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
