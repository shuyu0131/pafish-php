<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Services\Mail;
use Pafish\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 站点设置（对齐 Node app/admin/settings/）：
 * - 7 张卡片表单（站点信息/评论与列表/上传/账号注册/SMTP/邮件通知/开放 API）
 * - 保存：22 键白名单整体 upsert，空串覆盖，无校验（对齐 Node updateSettings）
 * - SMTP 测试：用表单当前值直接发信（未保存也能测），收件人=登录账号邮箱或 notify_email
 * - 开放 API：api_enabled 开关 + api_key 重新生成（32 位 hex，X-API-Key 头鉴权）
 * 权限：ADMIN+EDITOR（guardCanManage）；CSRF 由 AdminAuthMiddleware 统一校验
 */
final class SettingsController extends AdminController
{
    /** 保存白名单（对齐 Node updateSettings 的 allowed Set；商店地址已内置官方源不再可配） */
    private const ALLOWED_KEYS = [
        'site_name', 'site_subtitle', 'site_description', 'site_icp',
        'comments_enabled', 'comments_need_review', 'comments_captcha_enabled',
        'posts_per_page', 'blocked_ips',
        'upload_max_mb',
        'allow_registration', 'require_email_verify',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'notify_email_enabled', 'notify_email',
        'api_enabled', 'api_key',
    ];

    /** GET /admin/settings */
    public function index(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $all = Settings::all();
        $response->getBody()->write($this->render('settings', [
            'all' => $all,
        ], '站点设置'));
        return $response;
    }

    /** POST /admin/settings/save：白名单整体 upsert（对齐 Node updateSettings） */
    public function save(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $body = $request->getParsedBody() ?? [];
        $pairs = [];
        foreach (self::ALLOWED_KEYS as $key) {
            if (array_key_exists($key, $body)) {
                $pairs[$key] = (string) $body[$key];
            }
        }
        Settings::setMany($pairs);

        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        $this->flash('success', '设置已保存');
        return $this->redirect($response, '/admin/settings');
    }

    /** POST /admin/settings/test-smtp：用表单当前值发送测试邮件（未保存也能测） */
    public function testSmtp(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $body = $request->getParsedBody() ?? [];
        $cfg = [
            'host' => trim((string) ($body['host'] ?? '')),
            'port' => (int) ($body['port'] ?? 465) ?: 465,
            'user' => trim((string) ($body['user'] ?? '')),
            'pass' => (string) ($body['pass'] ?? ''),
            'from' => trim((string) ($body['from'] ?? '')),
        ];
        if ($cfg['host'] === '' || $cfg['user'] === '' || $cfg['pass'] === '') {
            return $this->json($response, ['error' => '请先填写 SMTP 主机、账号和密码'], 400);
        }

        // 收件人：当前登录账号邮箱，否则站长通知邮箱（对齐 Node sendTestEmailAction）
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
        $this->guardCanManage();
        $key = bin2hex(random_bytes(16));
        Settings::set('api_key', $key);
        return $this->json($response, ['ok' => true, 'key' => $key]);
    }
}
