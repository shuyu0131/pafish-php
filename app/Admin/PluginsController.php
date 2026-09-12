<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Services\Plugin;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class PluginsController extends AdminController
{
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
                'apiVersion' => $m['apiVersion'] ?? 1,
                'description' => $m['description'] ?? '',
                'author' => $m['author'] ?? '',
                'authorUrl' => self::manifestUrl($m, 'authorUrl'),
                'homepage' => self::manifestUrl($m, 'homepage'),
                'error' => $desc['error'],
                'settingsCount' => count($m['settings'] ?? []),
                'active' => Plugin::isActive($name),
            ];
        }
        $response->getBody()->write($this->render('plugins', [
            'plugins' => $plugins,
        ], '插件管理'));
        return $response;
    }

    private static function manifestUrl(?array $manifest, string $key): string
    {
        if (!is_array($manifest)) return '';
        $value = $manifest[$key] ?? ($key === 'homepage' ? ($manifest['url'] ?? '') : ($manifest['author_url'] ?? ''));
        return is_string($value) && preg_match('/^https?:\\/\\//i', $value) === 1 ? $value : '';
    }

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
