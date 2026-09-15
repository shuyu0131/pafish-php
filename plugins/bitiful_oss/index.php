<?php

declare(strict_types=1);

use Pafish\Core\Url;

function pafish_bitiful_settings(object $ctx): array
{
    return array_merge([
        'access_key' => '', 'secret_key' => '', 'bucket' => '',
        'endpoint' => 'https://s3.bitiful.net', 'region' => 'cn-east-1',
        'cdn_domain' => '', 'path_style' => '1', 'prefix' => 'pafish',
    ], $ctx->getSettings());
}

function pafish_bitiful_config(object $ctx): array
{
    $s = pafish_bitiful_settings($ctx);
    foreach (['access_key', 'secret_key', 'bucket'] as $key) {
        if (trim((string) $s[$key]) === '') {
            throw new RuntimeException('请先配置 AccessKey、SecretKey 和 Bucket');
        }
    }
    $endpoint = rtrim(trim((string) $s['endpoint']), '/');
    if (filter_var($endpoint, FILTER_VALIDATE_URL) === false || preg_match('#^https://#i', $endpoint) !== 1) {
        throw new RuntimeException('S3 Endpoint 必须是 https:// 地址');
    }
    $region = trim((string) $s['region']) ?: 'cn-east-1';
    $prefix = trim((string) $s['prefix'], " /\t\r\n");
    return ['access' => trim((string) $s['access_key']), 'secret' => trim((string) $s['secret_key']), 'bucket' => trim((string) $s['bucket']), 'endpoint' => $endpoint, 'region' => $region, 'cdn' => rtrim(trim((string) $s['cdn_domain']), '/'), 'pathStyle' => (string) $s['path_style'] !== '0', 'prefix' => $prefix];
}

function pafish_bitiful_uri(string $path): string
{
    return implode('/', array_map(static fn(string $part): string => rawurlencode($part), explode('/', '/' . ltrim($path, '/'))));
}

function pafish_bitiful_sign(string $method, string $url, string $body, array $cfg, string $mime = 'application/octet-stream'): array
{
    $parts = parse_url($url);
    $host = (string) ($parts['host'] ?? '');
    $path = (string) ($parts['path'] ?? '/');
    $payloadHash = hash('sha256', $body);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $amzDate = $now->format('Ymd\THis\Z');
    $shortDate = $now->format('Ymd');
    $headers = ['host' => $host, 'content-type' => $mime, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
    ksort($headers);
    $canonicalHeaders = '';
    foreach ($headers as $key => $value) { $canonicalHeaders .= $key . ':' . trim($value) . "\n"; }
    $signed = implode(';', array_keys($headers));
    $canonical = $method . "\n" . pafish_bitiful_uri($path) . "\n\n" . $canonicalHeaders . "\n" . $signed . "\n" . $payloadHash;
    $scope = $shortDate . '/' . $cfg['region'] . '/s3/aws4_request';
    $stringToSign = 'AWS4-HMAC-SHA256' . "\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonical);
    $kDate = hash_hmac('sha256', $shortDate, 'AWS4' . $cfg['secret'], true);
    $kRegion = hash_hmac('sha256', $cfg['region'], $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $cfg['access'] . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $signature;
    return [$headers, $path];
}

function pafish_bitiful_request(string $method, string $url, string $body, array $cfg, string $mime = 'application/octet-stream'): array
{
    [$headers] = pafish_bitiful_sign($method, $url, $body, $cfg, $mime);
    $list = [];
    foreach ($headers as $key => $value) { $list[] = $key . ': ' . $value; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $list, CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 60]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = (string) curl_error($ch);
    return ['status' => $status, 'body' => (string) $response, 'error' => $error];
}

function pafish_bitiful_object_url(array $cfg, string $key): string
{
    if ($cfg['cdn'] !== '') return $cfg['cdn'] . '/' . str_replace('%2F', '/', rawurlencode($key));
    if ($cfg['pathStyle']) return $cfg['endpoint'] . '/' . rawurlencode($cfg['bucket']) . '/' . str_replace('%2F', '/', rawurlencode($key));
    $parts = parse_url($cfg['endpoint']);
    return ($parts['scheme'] ?? 'https') . '://' . $cfg['bucket'] . '.' . ($parts['host'] ?? 's3.bitiful.net') . '/' . str_replace('%2F', '/', rawurlencode($key));
}

function pafish_bitiful_upload_url(array $cfg, string $key): string
{
    return $cfg['pathStyle'] ? $cfg['endpoint'] . '/' . rawurlencode($cfg['bucket']) . '/' . str_replace('%2F', '/', rawurlencode($key)) : pafish_bitiful_object_url($cfg, $key);
}

return [
    'storeFile' => static function (array $file, object $ctx): string {
        $cfg = pafish_bitiful_config($ctx);
        $ext = strtolower((string) ($file['ext'] ?? 'bin'));
        $key = date('Y/m') . '/' . bin2hex(random_bytes(10)) . ($ext !== '' ? '.' . preg_replace('/[^a-z0-9]+/i', '', $ext) : '');
        if ($cfg['prefix'] !== '') $key = $cfg['prefix'] . '/' . $key;
        $url = pafish_bitiful_upload_url($cfg, $key);
        $result = pafish_bitiful_request('PUT', $url, (string) ($file['buffer'] ?? ''), $cfg, (string) ($file['mime'] ?? 'application/octet-stream'));
        if ($result['error'] !== '' || $result['status'] < 200 || $result['status'] >= 300) throw new RuntimeException('上传失败（HTTP ' . $result['status'] . '）' . ($result['error'] !== '' ? '：' . $result['error'] : ''));
        return pafish_bitiful_object_url($cfg, $key);
    },
    'deleteFile' => static function (string $url, object $ctx): void {
        $cfg = pafish_bitiful_config($ctx);
        $parts = parse_url($url);
        $path = ltrim((string) ($parts['path'] ?? ''), '/');
        if ($cfg['pathStyle'] && str_starts_with($path, $cfg['bucket'] . '/')) $path = substr($path, strlen($cfg['bucket']) + 1);
        $result = pafish_bitiful_request('DELETE', pafish_bitiful_upload_url($cfg, $path), '', $cfg);
        if ($result['status'] !== 204 && ($result['status'] < 200 || $result['status'] >= 300)) throw new RuntimeException('删除失败（HTTP ' . $result['status'] . '）');
    },
    'testStorage' => static function (object $ctx): array {
        try {
            $cfg = pafish_bitiful_config($ctx);
            $key = ($cfg['prefix'] !== '' ? $cfg['prefix'] . '/' : '') . '.pafish-connection-test-' . bin2hex(random_bytes(4)) . '.txt';
            $result = pafish_bitiful_request('PUT', pafish_bitiful_upload_url($cfg, $key), 'pafish', $cfg, 'text/plain');
            $ok = $result['status'] >= 200 && $result['status'] < 300;
            if ($ok) { pafish_bitiful_request('DELETE', pafish_bitiful_upload_url($cfg, $key), '', $cfg); }
            return ['ok' => $ok, 'message' => $ok ? '缤纷云连接测试成功' : ('连接失败（HTTP ' . $result['status'] . '）')];
        } catch (Throwable $e) { return ['ok' => false, 'message' => $e->getMessage()]; }
    },
];
