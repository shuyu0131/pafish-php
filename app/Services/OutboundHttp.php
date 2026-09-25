<?php

declare(strict_types=1);

namespace Pafish\Services;

/** 受控服务端 HTTP 出站网关：仅 http(s)，拒绝内网地址；GET 下载安全跟随有限次重定向。 */
final class OutboundHttp
{
    public static function get(string $url, int $maxBytes = 512000, array $headers = []): string
    {
        $currentUrl = trim($url);
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $result = self::request('GET', $currentUrl, '', $headers, $maxBytes, 20, true);
            $status = (int) ($result['status'] ?? 0);
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($redirect >= 3) {
                    throw new \RuntimeException('目标重定向次数过多');
                }
                $location = (string) ($result['headers']['location'] ?? '');
                if ($location === '') {
                    throw new \RuntimeException('目标重定向缺少地址');
                }
                $currentUrl = self::resolveRedirectUrl($currentUrl, $location);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('目标返回 HTTP ' . $status);
            }
            return (string) $result['body'];
        }
        throw new \RuntimeException('目标重定向失败');
    }

    /**
     * 受控 HTTP 请求。调用方可检查 HTTP 状态码；网络错误仍抛出异常。
     * @return array{status:int,body:string,headers?:array<string,string>}
     */
    public static function request(
        string $method,
        string $url,
        string $body = '',
        array $headers = [],
        int $maxBytes = 512000,
        int $timeout = 20,
        bool $captureHeaders = false,
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
        $responseHeaders = [];
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
            CURLOPT_RESOLVE => self::resolveEntries($host, $port, $ips),
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$buffer, $maxBytes): int {
                if (strlen($buffer) + strlen($chunk) > $maxBytes) {
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ]);
        $caBundle = self::caBundle();
        if ($caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
        if ($captureHeaders) {
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $value = trim($line);
                if (preg_match('/^([^:\s]+):\s*(.*?)\s*$/', $value, $match) === 1) {
                    $responseHeaders[strtolower($match[1])] = $match[2];
                }
                return $length;
            });
        }
        if ($body !== '' || !in_array($method, ['GET', 'HEAD'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = (string) curl_error($ch);
        if ($ok === false || $error !== '') {
            throw new \RuntimeException('请求失败，请检查目标地址');
        }
        $result = ['status' => $status, 'body' => $buffer];
        if ($captureHeaders) {
            $result['headers'] = $responseHeaders;
        }
        return $result;
    }

    /** 将 Location 解析为下一跳绝对 URL；每一跳会重新走公网地址校验。 */
    private static function resolveRedirectUrl(string $base, string $location): string
    {
        $location = trim($location);
        if (strlen($location) > 2048 || str_contains($location, "\r") || str_contains($location, "\n")) {
            throw new \RuntimeException('重定向地址不合法');
        }
        $target = parse_url($location);
        if (is_array($target) && isset($target['scheme'])) {
            $scheme = strtolower((string) $target['scheme']);
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new \RuntimeException('重定向协议不受支持');
            }
            $baseScheme = strtolower((string) (parse_url($base)['scheme'] ?? ''));
            if ($baseScheme === 'https' && $scheme !== 'https') {
                throw new \RuntimeException('不允许从 HTTPS 降级到 HTTP');
            }
            return $location;
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || (string) ($baseParts['host'] ?? '') === '') {
            throw new \RuntimeException('重定向基址不合法');
        }
        $scheme = strtolower((string) ($baseParts['scheme'] ?? ''));
        $authority = $scheme . '://' . $baseParts['host'];
        if (isset($baseParts['port'])) {
            $authority .= ':' . (int) $baseParts['port'];
        }
        $parts = parse_url($location);
        if ($location === '' || $location[0] === '#') {
            $path = (string) ($baseParts['path'] ?? '/');
            $query = isset($baseParts['query']) ? '?' . $baseParts['query'] : '';
        } elseif ($location[0] === '/') {
            $path = (string) ($parts['path'] ?? '/');
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        } else {
            $basePath = (string) ($baseParts['path'] ?? '/');
            $dir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
            $path = ($dir === '' ? '' : $dir) . '/' . ltrim((string) ($parts['path'] ?? $location), '/');
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        }
        return $authority . '/' . ltrim($path, '/') . $query;
    }

    /**
     * 证书链文件路径。
     *
     * Windows 上的 PHP 常把 curl.cainfo / openssl.cafile 留空，而 curl 自带的默认路径
     * （C:\Program Files\Common Files\SSL\cert.pem）往往不存在，结果是所有 HTTPS 请求
     * 都以 "SSL certificate problem" 失败。这里按顺序探测可用证书链，找不到就返回 null，
     * 由 curl 用自己的默认值（保持原有行为不变）。
     */
    private static function caBundle(): ?string
    {
        static $resolved = false;
        static $path = null;
        if ($resolved) {
            return $path;
        }
        $resolved = true;

        $candidates = [];
        $ini = (string) ini_get('curl.cainfo');
        if ($ini !== '') {
            $candidates[] = $ini;
        }
        $ini = (string) ini_get('openssl.cafile');
        if ($ini !== '') {
            $candidates[] = $ini;
        }
        // 与 scripts/git-push-api.php 约定一致的用户级证书链
        $home = (string) (getenv('USERPROFILE') ?: getenv('HOME') ?: '');
        if ($home !== '') {
            $candidates[] = rtrim(str_replace('\\', '/', $home), '/') . '/.cacert.pem';
        }
        // 项目内自带的一份（runtime/ 已被 gitignore，不会进仓库）
        if (defined('PAFISH_ROOT')) {
            $candidates[] = rtrim((string) PAFISH_ROOT, '/\\') . '/runtime/cacert.pem';
        }
        $candidates[] = 'C:/Program Files/Common Files/SSL/cert.pem';

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $path = $candidate;
                return $path;
            }
        }
        return null;
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

    /**
     * 构造 CURLOPT_RESOLVE 条目。
     *
     * 同一主机若同时有 A 与 AAAA，只固定 IPv4：把 IPv6 一起写进固定列表后，
     * 在没有 IPv6 出口的机器上 curl 会直接判连接失败（实测 0ms 报
     * "Could not connect to server"），不会回退到 IPv4，导致整站抓取失败。
     * 所有进入列表的地址都已通过 isPrivate() 校验，固定哪一个都不影响防重绑定。
     *
     * @param string[] $ips
     * @return string[]
     */
    private static function resolveEntries(string $host, int $port, array $ips): array
    {
        $v4 = array_values(array_filter($ips, static fn (string $ip): bool => !str_contains($ip, ':')));
        if ($v4 !== []) {
            return array_map(static fn (string $ip): string => $host . ':' . $port . ':' . $ip, $v4);
        }
        // 只有 IPv6 时才用括号形式，这是 curl 的规范写法。
        return array_map(static fn (string $ip): string => $host . ':' . $port . ':[' . $ip . ']', $ips);
    }

    private static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
