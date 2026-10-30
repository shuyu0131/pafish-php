<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Services\Mail;
use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 站点设置：
 * - 分组展示设置，整页表单按白名单整体保存
 * - 密码与商城令牌不回显，留空保留原值
 * - SMTP 测试：用表单当前值直接发信（未保存也能测），收件人=登录账号邮箱或 notify_email
 * - 开放 API：api_enabled 开关 + api_key 重新生成（32 位 hex，X-API-Key 头鉴权）
 * 权限：仅 ADMIN；CSRF 由 AdminAuthMiddleware 统一校验
 */
final class SettingsController extends AdminController
{
    /** 保存白名单（商店地址使用内置官方源） */
    private const ALLOWED_KEYS = [
        'site_name', 'site_subtitle', 'site_description', 'site_icp',
        'seo_title_order', 'seo_title_separator',
        'comments_enabled', 'comments_need_review', 'comments_captcha_enabled', 'comments_honeypot_enabled',
        'submissions_enabled', 'submissions_need_review',
        'posts_per_page', 'blocked_ips', 'blocked_emails', 'comment_spam_keywords',
        'md_allow_raw_html',
        'upload_max_mb',
        'allow_registration', 'require_email_verify',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'notify_email_enabled', 'notify_email',
        'api_enabled', 'api_key',
        'api_rate_limit', 'api_cors', 'api_max_limit',
        'permalink_structure',
        'page_permalink_structure', 'category_permalink_structure', 'tag_permalink_structure',
        'store_account_token',
    ];

    /** GET /admin/settings */
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $all = Settings::core();
        $response->getBody()->write($this->render('settings', [
            'all' => $all,
        ], '站点设置'));
        return $response;
    }

    /** POST /admin/settings/save：白名单整体 upsert */
    public function save(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        // 主机或账号变化时不能把旧密码沿用到新服务。
        if ((string) Settings::get('smtp_pass', '') !== ''
            && trim((string) ($body['smtp_pass'] ?? '')) === ''
            && ((array_key_exists('smtp_host', $body) && (string) $body['smtp_host'] !== (string) Settings::get('smtp_host', ''))
                || (array_key_exists('smtp_user', $body) && (string) $body['smtp_user'] !== (string) Settings::get('smtp_user', '')))) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => '更换 SMTP 主机或账号时，请重新输入密码 / 授权码'], 400);
            }
            $this->flash('error', '更换 SMTP 主机或账号时，请重新输入密码 / 授权码');
            return $this->redirect($response, '/admin/settings#settings-mail');
        }
        $pairs = [];
        $permalinkTypes = [
            'permalink_structure' => 'post',
            'page_permalink_structure' => 'page',
            'category_permalink_structure' => 'category',
            'tag_permalink_structure' => 'tag',
        ];
        foreach (self::ALLOWED_KEYS as $key) {
            if (array_key_exists($key, $body)) {
                $value = (string) $body[$key];
                if (isset($permalinkTypes[$key]) && !\Pafish\Services\Permalinks::validate($permalinkTypes[$key], $value)) {
                    $message = '固定链接模板无效，请从支持的选项中选择';
                    if ($this->isAjax($request)) return $this->json($response, ['error' => $message], 400);
                    $this->flash('error', $message);
                    return $this->redirect($response, '/admin/settings#settings-basic');
                }
                if ($key === 'permalink_structure' && $value === '/%postname%') {
                    try {
                        \Pafish\Services\ExtensionRoutes::assertFlatPostTemplateAvailable();
                    } catch (\RuntimeException $e) {
                        if ($this->isAjax($request)) return $this->json($response, ['error' => $e->getMessage()], 400);
                        $this->flash('error', $e->getMessage());
                        return $this->redirect($response, '/admin/settings#settings-basic');
                    }
                }
                if (in_array($key, ['blocked_ips', 'blocked_emails', 'comment_spam_keywords'], true)) {
                    try {
                        $value = json_encode(self::normalizeCommentList($value, $key), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    } catch (\InvalidArgumentException $e) {
                        if ($this->isAjax($request)) return $this->json($response, ['error' => $e->getMessage()], 400);
                        $this->flash('error', $e->getMessage());
                        return $this->redirect($response, '/admin/settings#settings-content');
                    }
                }
                // 敏感值不回显到页面；空值表示保持现有值。
                if (in_array($key, ['store_account_token', 'smtp_pass'], true) && trim($value) === '') {
                    continue;
                }
                $pairs[$key] = $value;
            }
        }
        \Pafish\Core\DB::transaction(static function () use ($pairs): void {
            Settings::setMany($pairs);
            foreach (['post', 'page', 'category', 'tag'] as $type) {
                $key = \Pafish\Services\Permalinks::settingKey($type);
                if ($key !== null && array_key_exists($key, $pairs)) {
                    \Pafish\Services\PermalinkRedirects::reconcileSupportedPaths($type);
                }
            }
        });
        if (array_intersect(array_keys($pairs), array_keys($permalinkTypes)) !== []) {
            \Pafish\Core\Cache::clearPrefix('nav.');
        }

        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        $this->flash('success', '设置已保存');
        return $this->redirect($response, '/admin/settings');
    }

    private static function normalizeCommentList(string $raw, string $key): array
    {
        $decoded = json_decode($raw, true);
        $items = is_array($decoded)
            ? $decoded
            : preg_split($key === 'comment_spam_keywords' ? '/[,，\r\n]+/' : '/[,，\s]+/', $raw);
        if (!is_array($items)) $items = [];
        if (count($items) > 500) throw new \InvalidArgumentException('单个评论规则列表最多包含 500 项');

        $normalized = [];
        foreach ($items as $item) {
            if (!is_string($item) && !is_numeric($item)) throw new \InvalidArgumentException('评论规则列表格式无效');
            $item = trim((string) $item);
            if ($item === '') continue;
            if ($key === 'blocked_ips') {
                if (filter_var($item, FILTER_VALIDATE_IP) === false) throw new \InvalidArgumentException('IP 黑名单中包含无效地址');
                $item = strtolower($item);
            } elseif ($key === 'blocked_emails') {
                $item = strtolower($item);
                if (str_starts_with($item, '@')) {
                    $domain = substr($item, 1);
                    if (filter_var('check@' . $domain, FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('邮箱黑名单只接受完整邮箱或 @example.com 形式的域名');
                } elseif (filter_var($item, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \InvalidArgumentException('邮箱黑名单中包含无效地址');
                }
            } else {
                if (mb_strlen($item, 'UTF-8') > 120) throw new \InvalidArgumentException('单条关键词最多 120 个字符');
            }
            $normalized[] = $item;
        }
        return array_values(array_unique($normalized));
    }

    /** POST /admin/settings/test-smtp：用表单当前值发送测试邮件（未保存也能测） */
    public function testSmtp(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $cfg = [
            'host' => trim((string) ($body['host'] ?? '')),
            'port' => (int) ($body['port'] ?? 465) ?: 465,
            'user' => trim((string) ($body['user'] ?? '')),
            'pass' => (string) ($body['pass'] ?? ''),
            'from' => trim((string) ($body['from'] ?? '')),
        ];
        // 仅在主机和账号未变更时使用已保存密码，避免误发到新服务器。
        if ($cfg['pass'] === ''
            && $cfg['host'] === (string) Settings::get('smtp_host', '')
            && $cfg['user'] === (string) Settings::get('smtp_user', '')) {
            $cfg['pass'] = (string) Settings::get('smtp_pass', '');
        }
        if ($cfg['host'] === '' || $cfg['user'] === '' || $cfg['pass'] === '') {
            return $this->json($response, ['error' => '请先填写 SMTP 主机、账号和密码'], 400);
        }

        // 收件人：当前登录账号邮箱，否则站长通知邮箱
        $user = Auth::user();
        $to = (string) ($user['email'] ?? '');
        if ($to === '') {
            $to = (string) Settings::get('notify_email', '');
        }
        if ($to === '') {
            return $this->json($response, ['error' => '当前账号未设置邮箱，且站长通知邮箱为空，无法发送'], 400);
        }

        $siteName = (string) (Settings::get('site_name', '') ?: '纸鱼博客');
        try {
            Mail::sendWith($cfg, $to, 'SMTP 配置测试', "你好：\n\n这是一封来自「{$siteName}」的测试邮件，说明 SMTP 配置已生效。\n\n如果你收到这封邮件，无需回复。");
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
        return $this->json($response, ['ok' => true, 'to' => $to]);
    }

    /** POST /admin/settings/regenerate-key：重新生成 API Key（32 位 hex，旧 Key 作废） */
    public function regenerateApiKey(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $key = bin2hex(random_bytes(16));
        Settings::set('api_key', $key);
        return $this->json($response, ['ok' => true, 'key' => $key]);
    }
}
