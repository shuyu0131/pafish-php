<?php

declare(strict_types=1);

namespace Pafish\Services;

/** 受控服务端 HTTP 出站网关：仅 http(s)，拒绝内网地址，不跟随重定向。 */
final class OutboundHttp
{
    public static function get(string $url, int $maxBytes = 512000): string
    {
        $result = self::request('GET', $url, '', [], $maxBytes);
        $status = (int) ($result['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('目标返回 HTTP ' . $status);
        }
        return (string) $result['body'];
    }

    /**
     * 受控 HTTP 请求。调用方可检查 HTTP 状态码；网络错误仍抛出异常。
     * @return array{status:int,body:string}
     */
    public static function request(
        string $method,
        string $url,
        string $body = '',
        array $headers = [],
        int $maxBytes = 512000,
        int $timeout = 20,
    ): array {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('只支持公开的 http/https 地址');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('URL 端口不合法');
        }
        $method = strtoupper(trim($method));
        if (!preg_match('/^[A-Z]{1,12}$/', $method)) {
            throw new \RuntimeException('HTTP 方法不合法');
        }
        if ($maxBytes < 1 || $maxBytes > 16 * 1024 * 1024) {
            throw new \RuntimeException('响应大小限制不合法');
        }
        $timeout = max(1, min($timeout, 60));
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);
        if ($ips === []) {
            throw new \RuntimeException('无法解析目标地址');
        }
        foreach ($ips as $ip) {
            if (self::isPrivate($ip)) {
                throw new \RuntimeException('目标地址不允许访问内网或本机');
            }
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('服务器未启用 cURL');
        }
        $ch = curl_init(trim($url));
        $buffer = '';
        $curlHeaders = array_values(array_filter(array_map(static function ($header): string {
            $header = (string) $header;
            if (str_contains($header, "\r") || str_contains($header, "\n")) {
                throw new \RuntimeException('请求头不合法');
            }
            return $header;
        }, $headers), static fn(string $header): bool => $header !== ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'pafish-extension-fetch/1.0',
            CURLOPT_HTTPHEADER => array_merge(['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1'], $curlHeaders),
            CURLOPT_RESOLVE => array_map(static fn (string $ip): string => $host . ':' . $port . ':' . $ip, $ips),
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$buffer, $maxBytes): int {
                if (strlen($buffer) + strlen($chunk) > $maxBytes) {
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($body !== '' || !in_array($method, ['GET', 'HEAD'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = (string) curl_error($ch);
        if ($ok === false || $error !== '') {
            throw new \RuntimeException('请求失败，请检查目标地址');
        }
        return ['status' => $status, 'body' => $buffer];
    }

    /** Resolve all A/AAAA answers once so CURLOPT_RESOLVE pins the request. */
    private static function resolve(string $host): array
    {
        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            foreach ($records as $record) {
                $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $ip;
                }
            }
        }
        if ($ips === []) {
            $ips = gethostbynamel($host) ?: [];
        }
        return array_values(array_unique($ips));
    }

    private static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
