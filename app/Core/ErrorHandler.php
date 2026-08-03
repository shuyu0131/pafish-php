<?php

declare(strict_types=1);

namespace Pafish\Core;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpException;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;
use Throwable;

/**
 * 统一错误处理：
 * - 404：前台壳页面（与站点风格一致）
 * - 500：通用错误页，绝不输出异常详情与用户输入（规避 CVE-2026-48157 类风险）
 * - API 请求返回 JSON
 */
final class ErrorHandler extends SlimErrorHandler
{
    protected function respond(): ResponseInterface
    {
        $status = $this->exception instanceof HttpException ? $this->exception->getCode() : 500;
        $isApi = str_starts_with((string) $this->request->getUri()->getPath(), '/api');

        if ($isApi) {
            $response = $this->responseFactory->createResponse($status);
            $response->getBody()->write(json_encode(
                ['error' => $status === 404 ? 'Not Found' : '服务器内部错误'],
                JSON_UNESCAPED_UNICODE
            ));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        // 前台/后台：渲染错误页模板
        try {
            $html = $this->renderErrorPage($status);
        } catch (Throwable) {
            $html = '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>' . $status . '</title>'
                . '<body style="font-family:sans-serif;text-align:center;padding-top:80px">'
                . '<h1>' . $status . '</h1></body></html>';
        }

        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function renderErrorPage(int $status): string
    {
        $data = [
            'status'  => $status,
            'message' => $status === 404 ? '页面不存在' : '服务器内部错误',
            'backUrl' => Url::to('/'),
        ];
        // 全局 render() 在全局命名空间 include 模板（类名/函数均正确解析）
        return \render('error', $data);
    }

    protected function logError(string $error): void
    {
        error_log('[pafish-error] ' . $error);
    }
}
