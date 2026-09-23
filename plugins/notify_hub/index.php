<?php

declare(strict_types=1);

use Pafish\Core\DB;
use Pafish\Core\Url;
use Pafish\Services\OutboundHttp;

function pafish_notify_hub_settings(object $ctx): array
{
    return array_merge([
        'notify_comments' => '1', 'notify_posts' => '0', 'notify_register' => '0', 'notify_login' => '0', 'login_roles' => 'admin',
        'bark_server' => 'https://api.day.app', 'bark_key' => '', 'bark_group' => 'pafish', 'telegram_token' => '', 'telegram_chat_ids' => '',
        'dingtalk_webhook' => '', 'feishu_webhook' => '', 'wecom_webhook' => '', 'generic_webhooks' => '', 'webhook_secret' => '',
    ], $ctx->getSettings());
}

function pafish_notify_hub_lines(string $value): array
{
    return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: []))));
}

function pafish_notify_hub_post(string $url, string $body, array $headers): array
{
    $result = OutboundHttp::request('POST', $url, $body, $headers, 512000, 15);
    return [
        'status' => (int) $result['status'],
        // 第三方机器人返回的错误通常在响应体中；限制长度，避免插件数据无限增长。
        'response' => trim(function_exists('mb_substr') ? mb_substr((string) $result['body'], 0, 500) : substr((string) $result['body'], 0, 500)),
    ];
}

function pafish_notify_hub_save_result(object $ctx, string $event, string $title, array $results): array
{
    $data = $ctx->getData();
    $history = is_array($data['history'] ?? null) ? $data['history'] : [];
    $succeeded = count(array_filter($results, static fn(array $result): bool => (bool) ($result['ok'] ?? false)));
    array_unshift($history, [
        'time' => date('Y-m-d H:i:s'),
        'event' => $event,
        'title' => $title,
        'success' => $succeeded,
        'total' => count($results),
        'results' => $results,
    ]);
    $data['history'] = array_slice($history, 0, 50);
    $ctx->setData($data);
    $ctx->log($title . '：' . $succeeded . '/' . count($results) . ' 个通知通道成功');
    return ['success' => $succeeded, 'total' => count($results), 'results' => $results];
}

function pafish_notify_hub_send(object $ctx, string $event, string $title, string $message, string $url = ''): array
{
    $settings = pafish_notify_hub_settings($ctx);
    $results = [];
    $send = static function (string $channel, callable $callback) use (&$results): void {
        try {
            $result = $callback();
            $status = (int) ($result['status'] ?? 0);
            $entry = ['channel' => $channel, 'ok' => $status >= 200 && $status < 300, 'status' => $status];
            $response = trim((string) ($result['response'] ?? ''));
            if ($response !== '') {
                $entry['response'] = $response;
            }
            $results[] = $entry;
        } catch (Throwable $e) {
            $results[] = ['channel' => $channel, 'ok' => false, 'error' => $e->getMessage()];
        }
};
    $text = $title . "\n" . $message . ($url !== '' ? "\n" . $url : '');

    $barkKey = trim((string) $settings['bark_key']);
    if ($barkKey !== '') {
        $send('Bark', static function () use ($settings, $barkKey, $title, $message, $url): array {
            $body = json_encode(['title' => $title, 'body' => $message, 'group' => trim((string) $settings['bark_group']) ?: 'pafish', 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return pafish_notify_hub_post(rtrim((string) $settings['bark_server'], '/') . '/' . rawurlencode($barkKey), (string) $body, ['Content-Type: application/json']);
        });
    }

    $telegramToken = trim((string) $settings['telegram_token']);
    foreach (pafish_notify_hub_lines((string) $settings['telegram_chat_ids']) as $chatId) {
        if ($telegramToken === '') {
            break;
        }
        $send('Telegram:' . $chatId, static fn(): array => pafish_notify_hub_post(
            'https://api.telegram.org/bot' . $telegramToken . '/sendMessage',
            http_build_query(['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => 'true']),
            ['Content-Type: application/x-www-form-urlencoded']
        ));
    }

    $robots = [
        '钉钉' => ['url' => $settings['dingtalk_webhook'], 'body' => ['msgtype' => 'text', 'text' => ['content' => $text]]],
        '飞书' => ['url' => $settings['feishu_webhook'], 'body' => ['msg_type' => 'text', 'content' => ['text' => $text]]],
        '企业微信' => ['url' => $settings['wecom_webhook'], 'body' => ['msgtype' => 'text', 'text' => ['content' => $text]]],
    ];
    foreach ($robots as $channel => $robot) {
        if (trim((string) $robot['url']) !== '') {
            $send($channel, static fn(): array => pafish_notify_hub_post((string) $robot['url'], (string) json_encode($robot['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['Content-Type: application/json; charset=utf-8']));
        }
    }

    $payload = json_encode(['event' => $event, 'title' => $title, 'message' => $message, 'url' => $url, 'timestamp' => date(DATE_ATOM)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach (pafish_notify_hub_lines((string) $settings['generic_webhooks']) as $webhook) {
        $send('Webhook', static function () use ($settings, $webhook, $payload, $event): array {
            $headers = ['Content-Type: application/json; charset=utf-8', 'X-Pafish-Event: ' . $event];
            if ((string) $settings['webhook_secret'] !== '') {
                $headers[] = 'X-Pafish-Signature: sha256=' . hash_hmac('sha256', (string) $payload, (string) $settings['webhook_secret']);
            }
            return pafish_notify_hub_post($webhook, (string) $payload, $headers);
        });
    }

    if ($results === []) {
        return pafish_notify_hub_save_result($ctx, $event, $title, [[
            'channel' => '配置检查',
            'ok' => false,
            'error' => '未配置可用的通知通道。请填写 Bark Key、Telegram Bot Token 和 Chat ID、机器人 Webhook 或通用 Webhook。',
        ]]);
    }
    return pafish_notify_hub_save_result($ctx, $event, $title, $results);
}

return [
    'registerHooks' => function (object $ctx): void {
        $ctx->on('after_comment_submit', static function (array $comment) use ($ctx): void {
            if (pafish_notify_hub_settings($ctx)['notify_comments'] !== '1') {
                return;
            }
            $post = DB::fetchOne('SELECT title, slug FROM posts WHERE id = ?', [(int) ($comment['postId'] ?? 0)]);
            $postTitle = (string) ($post['title'] ?? ('文章 #' . ($comment['postId'] ?? '')));
            $content = trim((string) ($comment['content'] ?? ''));
            pafish_notify_hub_send($ctx, 'comment.submitted', '收到新评论', (string) ($comment['author'] ?? '访客') . ' 评论《' . $postTitle . '》：' . (function_exists('mb_substr') ? mb_substr($content, 0, 180) : substr($content, 0, 180)), $post ? Url::absolute('/post/' . rawurlencode((string) $post['slug'])) : '');
        });
        $ctx->on('after_post_published', static function (array $post) use ($ctx): void {
            if (pafish_notify_hub_settings($ctx)['notify_posts'] === '1') {
                pafish_notify_hub_send($ctx, 'post.published', '文章已发布', (string) ($post['title'] ?? ''), Url::absolute('/post/' . rawurlencode((string) ($post['slug'] ?? ''))));
            }
        });
        $ctx->on('after_register', static function (array $user) use ($ctx): void {
            if (pafish_notify_hub_settings($ctx)['notify_register'] === '1') {
                pafish_notify_hub_send($ctx, 'user.registered', '新用户注册', (string) ($user['username'] ?? ''));
            }
        });
        $ctx->on('after_login', static function (array $user) use ($ctx): void {
            $settings = pafish_notify_hub_settings($ctx);
            $role = (string) ($user['role'] ?? 'USER');
            if ($settings['notify_login'] !== '1' || ($settings['login_roles'] === 'admin' && $role !== 'ADMIN') || ($settings['login_roles'] === 'manager' && !in_array($role, ['ADMIN', 'EDITOR'], true))) {
                return;
            }
            pafish_notify_hub_send($ctx, 'user.login', '用户登录', (string) ($user['username'] ?? '') . '（' . $role . '）');
        });
    },
    'onActivate' => static function (object $ctx): void {
        $ctx->log('通知中心已启用，请至少配置一个通知通道。');
    },
    'testNotification' => static function (object $ctx): array {
        return pafish_notify_hub_send(
            $ctx,
            'notification.test',
            'pafish 测试通知',
            '通知中心已成功发起测试推送。',
            Url::absolute('/admin/plugins/notify_hub')
        );
    },
];
