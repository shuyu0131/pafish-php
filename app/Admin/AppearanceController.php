<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Services\Theme;
use Pafish\Services\ExtensionLinks;
use Pafish\Services\Store;
use Pafish\Core\Url;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class AppearanceController extends AdminController
{
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $active = Theme::active();
        $themes = [];
        $themeNames = Theme::list();
        usort($themeNames, static function (string $left, string $right) use ($active): int {
            if ($left === $active) {
                return -1;
            }
            if ($right === $active) {
                return 1;
            }
            return strcmp($left, $right);
        });
        foreach ($themeNames as $name) {
            $desc = Theme::describe($name);
            $manifest = $desc['manifest'];
            $themes[] = [
                'name' => $name,
                'title' => $manifest['title'] ?? $name,
                'version' => $manifest['version'] ?? '',
                'description' => $manifest['description'] ?? '',
                'author' => $manifest['author'] ?? '',
                'authorUrl' => ExtensionLinks::manifestUrl($manifest, 'authorUrl'),
                'homepage' => ExtensionLinks::manifestUrl($manifest, 'homepage'),
                'preview' => self::previewUrl($name),
                'error' => $desc['error'],
                // 统计真实设置项数量：group 容器自身不算，要展平到子字段
                'settingsCount' => count(\Pafish\Services\ExtensionKernel::flattenFields(
                    Theme::settingsFields($name)
                )),
                'active' => $name === $active,
                'parent' => $manifest['parent'] ?? '',
                'dependants' => Theme::dependants($name),
            ];
        }
        $catalogNotice = '';
        try {
            $catalog = Store::catalog('theme', 'read');
            $catalogNotice = $catalog->notice;
            $updates = Store::updatesForInstalled('theme', array_column($themes, 'version', 'name'), $catalog);
            foreach ($themes as &$theme) {
                $theme['update'] = $updates[$theme['name']] ?? null;
            }
            unset($theme);
        } catch (\Throwable) {
            foreach ($themes as &$theme) {
                $theme['update'] = null;
            }
            unset($theme);
        }
        $response->getBody()->write($this->render('appearance', [
            'themes' => $themes,
            'catalogNotice' => $catalogNotice,
        ], t('admin.themes_and_appearance')));
        return $response;
    }

    private static function previewUrl(string $name): string
    {
        // preview.jpg is the canonical installed-theme cover; older aliases remain compatible.
        foreach (['preview.jpg', 'preview.png', 'preview.jpeg', 'preview.webp', 'screenshot.jpg', 'screenshot.png'] as $file) {
            if (Theme::assetFile($name, $file) !== null) {
                return Url::themeAsset($name, $file);
            }
        }
        return '';
    }

    public function settings(Request $request, Response $response, array $args): Response
    {
        $this->guardAdmin();
        $name = (string) ($args['name'] ?? '');
        $desc = Theme::describe($name);
        \Pafish\Services\ExtensionKernel::resetSettingDiagnostics();
        if ($desc['error'] === null) {
            try { Theme::prepareSettings($name); } catch (\RuntimeException | \InvalidArgumentException $e) { $desc['error'] = $e->getMessage(); }
        }
        $settings = $desc['manifest'] !== null ? Theme::settingsFields($name) : [];
        $schemaIssues = array_values(array_filter(\Pafish\Services\ExtensionKernel::settingDiagnostics(),
            static fn(string $message, string $key): bool => str_starts_with($key, 'theme:'), ARRAY_FILTER_USE_BOTH));
        $headExtra = '';
        if (\Pafish\Services\ExtensionKernel::hasSettingType($settings, 'icon')) {
            $headExtra = '<link rel="stylesheet" href="' . e(asset_url('/css/remixicon.min.css')) . '">'
                . '<link rel="stylesheet" href="' . e(asset_url('/css/font-awesome.min.css')) . '">';
        }
        $response->getBody()->write($this->render('appearance-setting', [
            'themeName' => $name,
            'manifest' => $desc['manifest'],
            'error' => $desc['error'],
            'active' => $name === Theme::active(),
            'values' => $desc['manifest'] !== null ? Theme::valuesFor($name) : [],
            'settings' => $settings,
            'schemaIssues' => $schemaIssues,
            'headExtra' => $headExtra,
        ], t('admin.theme_settings')));
        return $response;
    }

    public function save(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $desc = Theme::describe($name);
        if ($desc['error'] !== null) {
            return $this->json($response, ['error' => $desc['error'] ?? t('admin.theme_not_found')], 400);
        }
        try { Theme::prepareSettings($name); } catch (\RuntimeException | \InvalidArgumentException $e) { return $this->json($response, ['error' => $e->getMessage()], 400); }
        // 只保存提交的键，checkbox 由前端全量序列化为 '1'/'0'
        // 先收集并完成全部校验，再统一写库（校验失败不产生部分写入）
        $pairs = [];
        $errors = [];
        // 展平分组字段后统计实际设置项。
        foreach (\Pafish\Services\ExtensionKernel::flattenFields(Theme::settingsFields($name)) as $field) {
            $key = (string) $field['key'];
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $type = (string) ($field['type'] ?? 'text');
            // 展示型字段（heading/subheading）没有值；前端不会提交，
            // 但伪造 POST 也不该在库里凭空造出这些键
            if (\Pafish\Services\ExtensionKernel::isDecorativeType($type)) {
                continue;
            }
            $raw = $body[$key];
            if (!is_scalar($raw)) {
                $errors[$key] = ($field['label'] ?? $key) . ' 的取值格式不正确';
                continue;
            }
            if ($type === 'checkbox' || $type === 'switcher') {
                $value = (is_scalar($raw) && (string) $raw === '1') ? '1' : '0';
            } else {
                $value = is_scalar($raw) ? trim((string) $raw) : '';
            }
            // 使用 schema 字段校验值。
            $error = \Pafish\Services\ExtensionKernel::validateSettingValue($field, $value);
            if ($error !== null) {
                $errors[$key] = $error;
                continue;
            }
            $pairs[$key] = $value;
        }
        if ($errors !== []) {
            return $this->json($response, [
                'error' => t('admin.there_are') . count($errors) . t('admin.settings_failed_validation_no_changes_were_saved'),
                'fields' => $errors,
            ], 400);
        }
        try {
            Theme::setExtensionSettings($name, $pairs);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
        return $this->json($response, ['ok' => true, 'saved' => $pairs]);
    }

    public function activate(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $name = trim((string) ($request->getParsedBody()['name'] ?? ''));
        try {
            \Pafish\Services\ThemePreview::stop();
            Theme::setActive($name);
            return $this->json($response, ['ok' => true, 'name' => $name]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    public function preview(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $name = $request->getParsedBody()['name'] ?? '';
        try {
            if (!is_string($name)) throw new \InvalidArgumentException('主题名不合法');
            \Pafish\Services\ThemePreview::start($name);
        } catch (\DomainException | \InvalidArgumentException | \RuntimeException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
        return $response->withStatus(303)->withHeader('Location', Url::to('/'))->withHeader('Cache-Control', 'no-store');
    }

    public function stopPreview(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        \Pafish\Services\ThemePreview::stop();
        return $response->withStatus(303)->withHeader('Location', Url::to('/'))->withHeader('Cache-Control', 'no-store');
    }

    public function uninstall(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $deleteData = (($body['delete_data'] ?? '') === '1');
        try {
            Theme::uninstall($name, $deleteData);
            return $this->json($response, ['ok' => true, 'deletedData' => $deleteData]);
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
                $result = Theme::installFromUrl($url);
            } elseif ($upload instanceof UploadedFileInterface) {
                if ($upload->getError() !== UPLOAD_ERR_OK) {
                    return $this->json($response, ['error' => t('admin.upload_failed_a6f805')], 400);
                }
                $result = Theme::installFromBuffer((string) $upload->getStream()->getContents());
            } else {
                return $this->json($response, ['error' => t('admin.choose_a_zip_file')], 400);
            }
            return $this->json($response, [
                'ok' => true,
                'name' => $result['name'],
                'title' => $result['title'],
                'version' => $result['version'],
                'updated' => !empty($result['updated']),
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    public function import(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $body = $request->getParsedBody() ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $files = $request->getUploadedFiles();
        $upload = $files['file'] ?? null;
        if (!$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => t('admin.choose_a_json_backup_file')], 400);
        }
        $filename = strtolower((string) $upload->getClientFilename());
        if (!str_ends_with($filename, '.json')) {
            return $this->json($response, ['error' => t('admin.upload_a_json_file')], 400);
        }
        $limit = \Pafish\Services\ExtensionKernel::MAX_SETTINGS_BYTES * 8;
        $raw = $upload->getStream()->read($limit + 1);
        if (strlen($raw) > $limit) return $this->json($response, ['error' => t('admin.theme_settings_backup_too_large')], 400);
        $json = json_decode($raw, true, 32);
        if (!is_array($json)) {
            return $this->json($response, ['error' => t('admin.the_backup_file_is_not_valid_json')], 400);
        }
        try {
            Theme::prepareSettings($name);
            $result = Theme::importValues($name, $json);
            return $this->json($response, ['ok' => true, 'imported' => $result['imported'], 'total' => $result['total']]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    public function reset(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $name = trim((string) (($request->getParsedBody() ?? [])['name'] ?? ''));
        try {
            $cleared = Theme::resetToDefaults($name);
            return $this->json($response, ['ok' => true, 'cleared' => $cleared]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    public function export(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $name = (string) ($request->getQueryParams()['name'] ?? '');
        try {
            Theme::prepareSettings($name);
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
