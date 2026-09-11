<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Services\Theme;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * 主题与外观：
 * - 列表（manifest 校验 + 当前主题徽章 + 启用/卸载）/ 独立设置页（SchemaForm 8 类型 + 分组 + show_if）
 * - 安装 zip（上传/URL）/ 卸载（独有键清理）/ 导入导出设置备份
 * - 权限：仅 ADMIN
 */
final class AppearanceController extends AdminController
{
    /** GET /admin/appearance */
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $active = Theme::active();
        $themes = [];
        foreach (Theme::list() as $name) {
            $desc = Theme::describe($name);
            $manifest = $desc['manifest'];
            $themes[] = [
                'name' => $name,
                'title' => $manifest['title'] ?? $name,
                'version' => $manifest['version'] ?? '',
                'description' => $manifest['description'] ?? '',
                'author' => $manifest['author'] ?? '',
                'error' => $desc['error'],
                'settingsCount' => count(Theme::schemaKeys($name)),
                'active' => $name === $active,
            ];
        }
        $response->getBody()->write($this->render('appearance', [
            'themes' => $themes,
            'activeTheme' => $active,
        ], '主题与外观'));
        return $response;
    }

    /** GET /admin/appearance/{name}：主题设置页 */
    public function settings(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $name = (string) ($args['name'] ?? '');
        $desc = Theme::describe($name);
        $response->getBody()->write($this->render('appearance-setting', [
            'themeName' => $name,
            'manifest' => $desc['manifest'],
            'error' => $desc['error'],
            'active' => $name === Theme::active(),
            'values' => $desc['manifest'] !== null ? Theme::valuesFor($name) : [],
        ], '主题设置'));
        return $response;
    }

    /** POST /admin/appearance/save：保存主题设置（只接受 schema 声明的键） */
    public function save(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $desc = Theme::describe($name);
        if ($desc['error'] !== null) {
            return $this->json($response, ['error' => $desc['error'] ?? '主题不存在'], 400);
        }
        // 只保存提交的键，checkbox 由前端全量序列化为 '1'/'0'
        // 先收集并完成全部校验，再统一写库（校验失败不产生部分写入）
        $pairs = [];
        foreach ($desc['manifest']['settings'] as $field) {
            $key = (string) $field['key'];
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $type = (string) ($field['type'] ?? 'text');
            $raw = $body[$key];
            if ($type === 'checkbox' || $type === 'switcher') {
                $value = (is_scalar($raw) && (string) $raw === '1') ? '1' : '0';
            } else {
                $value = is_scalar($raw) ? trim((string) $raw) : '';
            }
            if ($type === 'color' && $value !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                return $this->json($response, ['error' => '颜色值 ' . $value . ' 不合法（需 #rrggbb）'], 400);
            }
            $pairs[$key] = $value;
        }
        foreach ($pairs as $key => $value) {
            Settings::set('theme:' . $key, $value);
        }
        Theme::resetValues();
        return $this->json($response, ['ok' => true, 'saved' => $pairs]);
    }

    /** POST /admin/appearance/activate */
    public function activate(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $name = trim((string) ($request->getParsedBody()['name'] ?? ''));
        try {
            Theme::setActive($name);
            return $this->json($response, ['ok' => true, 'name' => $name]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** POST /admin/appearance/uninstall */
    public function uninstall(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $name = trim((string) ($request->getParsedBody()['name'] ?? ''));
        try {
            Theme::uninstall($name);
            return $this->json($response, ['ok' => true]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** POST /admin/appearance/install：上传 zip 或 URL 下载安装 */
    public function install(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        try {
            $url = trim((string) ($body['url'] ?? ''));
            $files = $request->getUploadedFiles();
            $upload = $files['zip'] ?? null;
            if ($url !== '') {
                $result = Theme::installFromUrl($url);
            } elseif ($upload instanceof UploadedFileInterface) {
                if ($upload->getError() !== UPLOAD_ERR_OK) {
                    return $this->json($response, ['error' => '上传失败'], 400);
                }
                $result = Theme::installFromBuffer((string) $upload->getStream()->getContents());
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

    /** POST /admin/appearance/import：导入设置备份（JSON 文件） */
    public function import(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $files = $request->getUploadedFiles();
        $upload = $files['file'] ?? null;
        if (!$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => '请选择 JSON 备份文件'], 400);
        }
        $filename = strtolower((string) $upload->getClientFilename());
        if (!str_ends_with($filename, '.json')) {
            return $this->json($response, ['error' => '请上传 .json 文件'], 400);
        }
        $json = json_decode((string) $upload->getStream()->getContents(), true);
        if (!is_array($json)) {
            return $this->json($response, ['error' => '备份文件不是合法 JSON'], 400);
        }
        try {
            $result = Theme::importValues($name, $json);
            return $this->json($response, ['ok' => true, 'imported' => $result['imported'], 'total' => $result['total']]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    /** GET /admin/appearance/export?name=：导出设置备份 */
    public function export(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $name = (string) ($_GET['name'] ?? '');
        try {
            $data = Theme::exportValues($name);
        } catch (\Throwable $e) {
            $response->getBody()->write($e->getMessage());
            return $response->withStatus(400);
        }
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="theme-' . $name . '-settings-' . date('Y-m-d') . '.json"');
    }
}
