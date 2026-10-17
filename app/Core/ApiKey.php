<?php

declare(strict_types=1);

namespace Pafish\Core;

use Pafish\Services\Settings;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 开放 API 鉴权：
 * - api_enabled 未开启或未生成 Key → 401（提示开启）
 * - X-API-Key 头与 api_key 常量时间比较（hash_equals 等价 timingSafeEqual，长度不同直接拒绝）
 */
final class ApiKey
{
    /** 校验请求；通过返回 null，失败返回 [status, error] */
    public static function check(Request $request): ?array
    {
        // 一方客户端令牌（AppTokenMiddleware 验证、仅作用于本次请求）高于公开 API Key：
        // 令牌持有者本身已是登录用户，而公开只读接口没有额外可泄露的数据。
        if (Auth::isBearerAuthenticated()) {
            return null;
        }
        if (Settings::get('api_enabled', 'false') !== 'true') {
            return [401, '开放 API 未启用，请在后台站点设置中开启'];
        }
        $expected = (string) Settings::get('api_key', '');
        if ($expected === '') {
            return [401, '开放 API 未启用，请在后台站点设置中开启'];
        }
        $provided = trim((string) ($request->getHeaderLine('X-API-Key')));
            // hash_equals 要求等长字符串，长度不同直接返回 false
        if (!hash_equals($expected, $provided)) {
            return [401, '无效的 API Key'];
        }
        $limit = max(0, (int) Settings::get('api_rate_limit', '120'));
        if ($limit > 0) {
            $identity = hash('sha256', $provided) . '|' . ClientIp::fromRequest($request);
            if (!RateLimiter::allow('api-request', $identity, $limit, 60)) return [429, 'API 请求过于频繁，请稍后再试'];
        }
        return null;
    }

    /** 生成新的 API Key（32 位随机十六进制） */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
