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
            $bucket = 'api-rate:' . sha1($provided . '|' . ($request->getServerParams()['REMOTE_ADDR'] ?? '')) . ':' . date('YmdHi');
            $used = (int) Cache::get($bucket, 0);
            if ($used >= $limit) {
                return [429, 'API 请求过于频繁，请稍后再试'];
            }
            Cache::set($bucket, $used + 1, 120);
        }
        return null;
    }

    /** 生成新的 API Key（32 位随机十六进制） */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
