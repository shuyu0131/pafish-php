<?php

declare(strict_types=1);

/**
 * 缤纷云存储插件（Bitiful S4，S3 协议兼容）——PHP 版，1:1 对齐 Node 版 store-src/binfen-storage
 *
 * 作为系统的「存储后端」：媒体上传时系统调用 storeFile()，删除时调用 deleteFile()。
 * 认证采用 AWS SigV4 手写签名（仅 hash_hmac / hash 原生函数，零依赖）；
 * 缤纷云不支持 Multipart Uploads（官方文档明示），单次 PUT 上传恰好。
 *
 * 导出约定（系统侧 app/Services/Plugin.php activeStorage/storeToCloud/deleteFromCloud）：
 *   storeFile($file, $ctx) -> array{url:string} | string    完整公开 URL；抛 Throwable = 拒绝，系统回退本地磁盘
 *   deleteFile($url, $ctx) -> void                           从 URL 反解 key 后签名 DELETE（404 视为成功）
 * ctx->getSettings() 实时读取插件设置（每次操作取最新值，改配置无需重启）。
 *
 * 连接形态：
 *   endpoint 配 path-style（https://s3.bitiful.net，默认）→ 请求 /{bucket}/{key}
 *   endpoint 配桶域名（https://{bucket}.s3.bitiful.net 或自定义域名）→ 请求 /{key}
 *   publicUrl 留空 → 返回 {endpoint}/{bucket}/{key}；含 {key} 占位符则按模板替换
 */

// ---------- SigV4 工具 ----------

/** SigV4 UriEncode：仅转义非 [A-Za-z0-9-._~] 的字符（PHP rawurlencode 与 Node 的 uriEncode 结果一致） */
function pafish_binfen_uri_encode(string $s): string
{
    return rawurlencode($s);
}

/** 读取并校验插件设置；缺关键项直接抛错（系统捕获后回退本地存储） */
function pafish_binfen_load_config(object $ctx): array
{
    $s = $ctx->getSettings();
    $endpoint = rtrim((string) ($s['endpoint'] ?? ''), '/');
    $accessKey = trim((string) ($s['accessKey'] ?? ''));
    $secretKey = (string) ($s['secretKey'] ?? '');
    $bucket = trim((string) ($s['bucket'] ?? ''));
    if ($endpoint === '' || $accessKey === '' || $secretKey === '' || $bucket === '') {
        throw new RuntimeException('缤纷云存储未完整配置（需填写 endpoint / accessKey / secretKey / bucket）');
    }
    $region = trim((string) ($s['region'] ?? '')) !== '' ? trim((string) $s['region']) : 'us-east-1';
    $pathPrefix = trim((string) ($s['pathPrefix'] ?? ''));
    $pathPrefix = trim($pathPrefix, '/');
    return [
        'endpoint' => $endpoint,
        'accessKey' => $accessKey,
        'secretKey' => $secretKey,
        'bucket' => $bucket,
        'region' => $region,
        'pathPrefix' => $pathPrefix !== '' ? $pathPrefix . '/' : '',
        'publicUrl' => trim((string) ($s['publicUrl'] ?? '')),
    ];
}

/** 对象 key：{prefix}{yyyy}/{MM}/{随机8位}.{ext}（按年月归档，便于桶内管理） */
function pafish_binfen_build_key(array $cfg, string $ext): string
{
    return $cfg['pathPrefix'] . gmdate('Y/m') . '/' . bin2hex(random_bytes(4)) . '.' . $ext;
}

/** 公开访问 URL：优先 publicUrl（含 {key} 则替换，否则作为基地址拼接），留空自动取 {endpoint}/{bucket}/{key} */
function pafish_binfen_build_public_url(array $cfg, string $key): string
{
    if ($cfg['publicUrl'] !== '') {
        return str_contains($cfg['publicUrl'], '{key}')
            ? str_replace('{key}', $key, $cfg['publicUrl'])
            : rtrim($cfg['publicUrl'], '/') . '/' . $key;
    }
    return $cfg['endpoint'] . '/' . $cfg['bucket'] . '/' . $key;
}

/** endpoint 是否为桶域名形态（virtual-hosted / 自定义域名）：host 以 {bucket}. 开头或相等 */
function pafish_binfen_endpoint_is_bucket_hosted(string $hostname, string $bucket): bool
{
    return $hostname === $bucket || str_starts_with($hostname, $bucket . '.');
}

/**
 * 发起一次 AWS SigV4 签名请求；2xx 返回响应体，其余抛异常（消息含 "HTTP {code}"，deleteFile 据此判 404）
 * 需 curl 扩展（虚拟主机标配）；失败/超时抛 RuntimeException
 */
function pafish_binfen_request(array $cfg, string $method, string $key, ?string $body = null, ?string $contentType = null): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('缤纷云存储需要 PHP curl 扩展');
    }
    $ep = parse_url($cfg['endpoint']);
    if ($ep === false || !isset($ep['scheme'], $ep['host'])) {
        throw new RuntimeException('缤纷云存储 endpoint 格式不正确');
    }
    $scheme = strtolower((string) $ep['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('缤纷云存储 endpoint 仅支持 http/https');
    }
    $hostname = $ep['host'];
    $host = $hostname . (isset($ep['port']) ? ':' . $ep['port'] : '');
    $bucketHosted = pafish_binfen_endpoint_is_bucket_hosted($hostname, $cfg['bucket']);
    $objectPath = '/' . implode('/', array_map('pafish_binfen_uri_encode', explode('/', $key)));
    $path = $bucketHosted ? $objectPath : '/' . $cfg['bucket'] . $objectPath;

    // 签名一律用 UTC（会话时区可能是 Asia/Shanghai，勿用 date()）
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = substr($amzDate, 0, 8);
    $payloadHash = $body !== null ? hash('sha256', $body) : hash('sha256', '');

    $canonicalHeaders = "host:{$host}\n"
        . "x-amz-content-sha256:{$payloadHash}\n"
        . "x-amz-date:{$amzDate}\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = "{$method}\n{$path}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

    $scope = "{$dateStamp}/{$cfg['region']}/s3/aws4_request";
    $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $cfg['secretKey'], true);
    $kRegion = hash_hmac('sha256', $cfg['region'], $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "AWS4-HMAC-SHA256 Credential={$cfg['accessKey']}/{$scope}, "
        . "SignedHeaders={$signedHeaders}, Signature={$signature}";

    $headers = [
        "host: {$host}",
        "x-amz-date: {$amzDate}",
        "x-amz-content-sha256: {$payloadHash}",
        "authorization: {$authorization}",
        'Connection: close', // 规避 PHP built-in server 的 keep-alive 卡死（真实 Nginx/Apache 无影响）
    ];
    if ($contentType !== null) {
        $headers[] = "content-type: {$contentType}";
    }

    $ch = curl_init($cfg['endpoint'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = (string) curl_error($ch);
    // PHP 8.5 起 curl_close 已是 no-op 且触发弃用警告，不调用
    if ($status === 0) {
        throw new RuntimeException('缤纷云 S4 请求失败：' . ($error !== '' ? $error : '无法连接'));
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('缤纷云 S4 ' . $method . ' 失败 HTTP ' . $status . '：' . mb_substr($res, 0, 300));
    }
    return $res;
}

// ---------- 存储后端接口 ----------

return [
    'storeFile' => function (array $file, object $ctx): array {
        $cfg = pafish_binfen_load_config($ctx);
        $key = pafish_binfen_build_key($cfg, (string) ($file['ext'] ?? 'bin'));
        pafish_binfen_request($cfg, 'PUT', $key, (string) ($file['buffer'] ?? ''), (string) ($file['mime'] ?? null));
        return ['url' => pafish_binfen_build_public_url($cfg, $key)];
    },

    'deleteFile' => function (string $url, object $ctx): void {
        $cfg = pafish_binfen_load_config($ctx);
        $u = parse_url($url);
        if ($u === false || !isset($u['host'])) {
            return; // 非完整 URL 无法反解，静默跳过
        }
        $epHost = (string) parse_url($cfg['endpoint'], PHP_URL_HOST);
        // 反解对象 key：同一 endpoint 域下剥掉 path-style 的 bucket 前缀，其余即 key
        $key = rawurldecode(ltrim((string) ($u['path'] ?? ''), '/'));
        $bucketHosted = pafish_binfen_endpoint_is_bucket_hosted($epHost, $cfg['bucket']);
        if (($u['host'] ?? '') === $epHost && !$bucketHosted) {
            if ($key === $cfg['bucket']) {
                return; // 误传桶根，无需删除
            }
            if (str_starts_with($key, $cfg['bucket'] . '/')) {
                $key = substr($key, strlen($cfg['bucket']) + 1);
            }
        }
        if ($key === '') {
            return;
        }
        try {
            pafish_binfen_request($cfg, 'DELETE', $key);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return; // 云端已不存在，视为删除成功
            }
            throw $e;
        }
    },

    // ---------- 生命周期 ----------
    'onActivate' => function (object $ctx): void {
        $ctx->log('缤纷云存储已激活：媒体上传将自动存入缤纷云 S4，请到「设置」填写接入信息。');
    },

    'onDeactivate' => function (object $ctx): void {
        $ctx->log('缤纷云存储已停用：媒体上传恢复为本地磁盘存储。');
    },
];
