<?php

declare(strict_types=1);

use Pafish\Core\Config;
use Pafish\Core\Url;

function pafish_seo_push_plus_settings(object $ctx): array
{
    return array_merge([
        'indexnow_enabled' => '1', 'indexnow_key' => '', 'baidu_enabled' => '0',
        'baidu_site' => '', 'baidu_token' => '', 'push_updates' => '1', 'use_external_url' => '0',
    ], $ctx->getSettings());
}

function pafish_seo_push_plus_request(string $url, array $headers, string $body): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('需要 PHP curl 扩展');
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'pafish-seo-push-plus/1.0', CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = (string) curl_error($curl);
    curl_close($curl);
    if ($response === false) throw new RuntimeException($error ?: '连接失败');
    return ['status' => $status, 'body' => mb_substr((string) $response, 0, 800)];
}

function pafish_seo_push_plus_key_location(string $key): string
{
    if (preg_match('/^[A-Za-z0-9-]{8,128}$/', $key) !== 1) throw new RuntimeException('IndexNow Key 格式不正确');
    $file = PAFISH_ROOT . '/' . $key . '.txt';
    if (!is_file($file) || trim((string) file_get_contents($file)) !== $key) {
        if (@file_put_contents($file, $key, LOCK_EX) === false) throw new RuntimeException('无法写入 IndexNow Key 文件，请检查站点根目录权限');
    }
    $site = rtrim((string) Config::get('site_url', ''), '/');
    if ($site === '') {
        $site = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $site . Url::base() . '/' . rawurlencode($key) . '.txt';
}

function pafish_seo_push_plus_record(object $ctx, array $entry): void
{
    $data = $ctx->getData();
    $history = is_array($data['history'] ?? null) ? $data['history'] : [];
    array_unshift($history, $entry);
    $data['history'] = array_slice($history, 0, 50);
    $ctx->setData($data);
}

function pafish_seo_push_plus_send(array $post, object $ctx, bool $force): void
{
    if (($post['status'] ?? '') !== 'PUBLISHED') return;
    $settings = pafish_seo_push_plus_settings($ctx);
    $url = $settings['use_external_url'] === '1' && !empty($post['externalUrl'])
        ? (string) $post['externalUrl'] : Url::absolute('/post/' . rawurlencode((string) ($post['slug'] ?? '')));
    if (preg_match('#^https?://#i', $url) !== 1) return;

    $data = $ctx->getData();
    $lastPush = is_array($data['lastPush'] ?? null) ? $data['lastPush'] : [];
    $postId = (string) ($post['id'] ?? '');
    $last = is_array($lastPush[$postId] ?? null) ? $lastPush[$postId] : [];
    if (!$force && ($last['url'] ?? '') === $url && time() - (int) ($last['time'] ?? 0) < 300) return;

    $results = [];
    if ($settings['indexnow_enabled'] === '1') {
        $key = trim((string) $settings['indexnow_key']);
        if ($key === '') {
            $results['indexNow'] = ['ok' => false, 'error' => '未配置 Key'];
        } else {
            try {
                $payload = json_encode(['host' => parse_url(Url::absolute('/'), PHP_URL_HOST), 'key' => $key,
                    'keyLocation' => pafish_seo_push_plus_key_location($key), 'urlList' => [$url]], JSON_UNESCAPED_SLASHES);
                $res = pafish_seo_push_plus_request('https://api.indexnow.org/indexnow', ['Content-Type: application/json; charset=utf-8'], (string) $payload);
                $results['indexNow'] = ['ok' => in_array($res['status'], [200, 202], true), ...$res];
            } catch (Throwable $e) { $results['indexNow'] = ['ok' => false, 'error' => $e->getMessage()]; }
        }
    }
    if ($settings['baidu_enabled'] === '1') {
        $site = rtrim(trim((string) $settings['baidu_site']), '/');
        $token = trim((string) $settings['baidu_token']);
        if ($site === '' || $token === '') {
            $results['baidu'] = ['ok' => false, 'error' => '未完整配置站点地址和 Token'];
        } else {
            try {
                $res = pafish_seo_push_plus_request('https://data.zz.baidu.com/urls?site=' . rawurlencode($site) . '&token=' . rawurlencode($token), ['Content-Type: text/plain; charset=utf-8'], $url . "\n");
                $json = json_decode($res['body'], true);
                $results['baidu'] = ['ok' => $res['status'] === 200 && is_array($json) && !isset($json['error']), ...$res];
            } catch (Throwable $e) { $results['baidu'] = ['ok' => false, 'error' => $e->getMessage()]; }
        }
    }
    if ($results === []) return;
    $lastPush[$postId] = ['url' => $url, 'time' => time()];
    $data['lastPush'] = $lastPush;
    $ctx->setData($data);
    $success = !in_array(false, array_map(static fn(array $result): bool => (bool) ($result['ok'] ?? false), $results), true);
    pafish_seo_push_plus_record($ctx, ['time' => date('Y-m-d H:i:s'), 'postId' => $postId, 'title' => (string) ($post['title'] ?? ''), 'url' => $url, 'ok' => $success, 'results' => $results]);
    $ctx->log(($success ? '推送成功：' : '推送存在失败：') . (string) ($post['title'] ?? $url));
}

return [
    'registerHooks' => function (object $ctx): void {
        $ctx->on('after_post_published', static fn(array $post) => pafish_seo_push_plus_send($post, $ctx, true));
        $ctx->on('after_update_post', static function (array $post) use ($ctx): void {
            $settings = pafish_seo_push_plus_settings($ctx);
            if ($settings['push_updates'] === '1' && ($post['action'] ?? '') !== 'auto' && ($post['previousStatus'] ?? null) === 'PUBLISHED') pafish_seo_push_plus_send($post, $ctx, false);
        });
    },
    'onActivate' => static function (object $ctx): void { $ctx->log('SEO 主动推送已启用，请填写 IndexNow Key 或百度 Token。'); },
    'onUninstall' => static function (object $ctx): void {
        $key = trim((string) (pafish_seo_push_plus_settings($ctx)['indexnow_key'] ?? ''));
        $file = PAFISH_ROOT . '/' . $key . '.txt';
        if (preg_match('/^[A-Za-z0-9-]{8,128}$/', $key) === 1 && is_file($file) && trim((string) file_get_contents($file)) === $key) @unlink($file);
    },
];
