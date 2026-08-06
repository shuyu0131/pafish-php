<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Services\Plugin;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * 插件管理（对齐 Node app/admin/plugins/ 系列；仅 ADMIN，对齐 Node requireAdmin）：
 * - 列表卡片（启用徽章/云存储后端/注入/设置项/错误标注/数据查看）
 * - 启用 / 停用 / 卸载（确认删除目录与数据）/ 设置页（SchemaForm）/ zip·URL 安装
 */
final class PluginsController extends AdminController
{
    /** GET /admin/plugins：插件列表 */
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $plugins = [];
        foreach (Plugin::list() as $name) {
            $desc = Plugin::describe($name);
            $m = $desc['manifest'];
            $plugins[] = [
                'name' => $name,
                'title' => $m['title'] ?? $name,
                'version' => $m['version'] ?? '',
                'description' => $m['description'] ?? '',
                'author' => $m['author'] ?? '',
                'error' => $desc['error'],
                'injects' => $m['injects'] ?? [],
                'settingsCount' => count($m['settings'] ?? []),
                'storage' => $m['storage'] ?? null,
                'active' => Plugin::isActive($name),
                'data' => Plugin::data($name),
            ];
        }
        $response->getBody()->write($this->render('plugins', [
            'plugins' => $plugins,
            'activeCount' => count(Plugin::activeNames()),
        ], '插件管理'));
        return $response;
    }

    /** GET /admin/plugins/{name}：插件设置页 */
    public function settings(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $name = (string) ($args['name'] ?? '');
        if (preg_match('/^[a-z0-9_-]{1,50}$/', $name) !== 1 || !is_dir(Plugin::root() . '/' . $name)) {
            $this->flash('error', '插件不存在');
            return $this->redirect($response, '/admin/plugins');
        }
        $desc = Plugin::describe($name);
        $response->getBody()->write($this->render('plugin-setting', [
            'pluginName' => $name,
            'manifest' => $desc['manifest'],
            'error' => $desc['error'],
            'values' => $desc['manifest'] !== null ? Plugin::settings($name) : [],
        ], '插件设置'));
        return $response;
    }

    /** POST /admin/plugins/activate */
    public function activate(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $name = trim((string) ($request->getParsedBody()['name'] ?? ''));
        try {
            Plugin::activate($name);
            return $this->json($response, ['ok' => true, 'name' => $name]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** POST /admin/plugins/deactivate */
    public function deactivate(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $name = trim((string) ($request->getParsedBody()['name'] ?? ''));
        try {
            Plugin::deactivate($name);
            return $this->json($response, ['ok' => true, 'name' => $name]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** POST /admin/plugins/uninstall：卸载（删数据 + 目录，前端二次确认） */
    public function uninstall(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $name = trim((string) ($request->getParsedBody()['name'] ?? ''));
        try {
            Plugin::uninstall($name);
            return $this->json($response, ['ok' => true]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** POST /admin/plugins/save-settings：保存插件设置 */
    public function saveSettings(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        try {
            $saved = Plugin::saveSettings($name, $body);
            return $this->json($response, ['ok' => true, 'saved' => $saved]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** POST /admin/plugins/install：上传 zip 或 URL 下载安装 */
    public function install(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        try {
            $url = trim((string) ($body['url'] ?? ''));
            $files = $request->getUploadedFiles();
            $upload = $files['zip'] ?? null;
            if ($url !== '') {
                $result = Plugin::installFromUrl($url);
            } elseif ($upload instanceof UploadedFileInterface) {
                if ($upload->getError() !== UPLOAD_ERR_OK) {
                    return $this->json($response, ['error' => '上传失败'], 400);
                }
                $filename = strtolower((string) $upload->getClientFilename());
                if (!str_ends_with($filename, '.zip')) {
                    return $this->json($response, ['error' => '请上传 .zip 文件'], 400);
                }
                $result = Plugin::installFromBuffer((string) $upload->getStream()->getContents(), (string) $upload->getClientFilename());
            } else {
                return $this->json($response, ['error' => '请选择 zip 文件'], 400);
            }
            return $this->json($response, [
                'ok' => true,
                'name' => $result['name'],
                'title' => $result['title'],
                'version' => $result['version'],
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }
}
