<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Config;

/**
 * 主题系统（对标 Node 版 src/lib/theme.ts + admin/appearance/actions.ts）：
 * - 主题 = themes/{name}/theme.json（manifest + 设置 schema）+ theme.css（语义 CSS 变量）+ 可选 PHP 模板文件
 * - 模板解析优先级：主题目录文件 → 系统内置 fallback（app/Views/theme/）
 * - 安装 zip：唯一顶层目录=主题名 / 10MB 上限 / 穿越防护 / manifest 校验失败回滚
 * - 卸载保护：不能卸载当前主题；只删该主题独有的 theme:{key} 键（跨主题共享键保留）
 */
final class Theme
{
    private const NAME_PATTERN = '/^[a-z0-9_-]{1,50}$/';
    private const TEMPLATE_NAME_PATTERN = '/^[a-z0-9-]{1,40}$/';
    private const FIELD_TYPES = ['text', 'textarea', 'checkbox', 'select', 'color', 'switcher', 'radio', 'image'];
    private const MAX_ZIP_BYTES = 10 * 1024 * 1024;
    private const BACKUP_FORMAT = 'blogcms-theme-settings';

    private static ?array $manifests = [];
    private static ?array $values = null;

    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/themes';
    }

    /** 当前激活主题名（默认 default；非法值回退 default） */
    public static function active(): string
    {
        $name = (string) Settings::get('active_theme', 'default');
        return self::isValidName($name) ? $name : 'default';
    }

    /** 扫描 themes/ 目录下的全部主题名（过滤临时/备份目录：点前缀不扫描；无 theme.json 也列出，由 describe 报错） */
    public static function list(): array
    {
        $names = [];
        foreach (glob(self::root() . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            if (str_starts_with($name, '.')) {
                continue;
            }
            if (self::isValidName($name)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /** 读取主题 manifest（theme.json）；无效返回 null（仅校验 name 一致性） */
    public static function manifest(string $name): ?array
    {
        if (!self::isValidName($name)) {
            return null;
        }
        if (array_key_exists($name, self::$manifests)) {
            return self::$manifests[$name];
        }
        $file = self::root() . '/' . $name . '/theme.json';
        if (!is_file($file)) {
            return self::$manifests[$name] = null;
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json) || ($json['name'] ?? null) !== $name) {
            return self::$manifests[$name] = null;
        }
        return self::$manifests[$name] = $json;
    }

    /**
     * 主题描述（列表页用）：返回 ['manifest' => ?array, 'error' => ?string]
     * error 文案对齐 Node readThemeManifest
     */
    public static function describe(string $name): array
    {
        if (!self::isValidName($name)) {
            return ['manifest' => null, 'error' => '主题名不合法'];
        }
        $file = self::root() . '/' . $name . '/theme.json';
        if (!is_file($file)) {
            return ['manifest' => null, 'error' => '缺少 theme.json'];
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json)) {
            return ['manifest' => null, 'error' => 'theme.json 不是合法 JSON'];
        }
        $error = self::validateManifest($json, $name);
        if ($error !== null) {
            return ['manifest' => null, 'error' => $error];
        }
        return ['manifest' => $json, 'error' => null];
    }

    /**
     * 校验 manifest；返回错误消息或 null。
     * 对齐 Node readThemeManifest：name 一致 + title/version + pageTemplates 过滤（全非法才报错）
     */
    public static function validateManifest(array $json, string $dirName): ?string
    {
        if (($json['name'] ?? null) !== $dirName) {
            return 'name 缺失或与目录名不一致';
        }
        if (!is_string($json['title'] ?? null) || $json['title'] === ''
            || !is_string($json['version'] ?? null) || $json['version'] === '') {
            return '缺少 title 或 version';
        }
        if (array_key_exists('pageTemplates', $json)) {
            if (!is_array($json['pageTemplates'])) {
                return 'pageTemplates 必须是数组';
            }
            $decls = [];
            foreach ($json['pageTemplates'] as $decl) {
                if (!is_array($decl)) {
                    continue;
                }
                $tplName = (string) ($decl['name'] ?? '');
                if ($tplName === '' || $tplName === 'default' || preg_match(self::TEMPLATE_NAME_PATTERN, $tplName) !== 1) {
                    continue;
                }
                if (!is_string($decl['title'] ?? null) || $decl['title'] === '') {
                    continue;
                }
                $decls[] = [
                    'name' => $tplName,
                    'title' => $decl['title'],
                    'description' => is_string($decl['description'] ?? null) ? $decl['description'] : '',
                ];
            }
            if ($json['pageTemplates'] !== [] && $decls === []) {
                return 'pageTemplates 声明无效';
            }
            $json['pageTemplates'] = $decls;
        }
        if (array_key_exists('settings', $json) && !is_array($json['settings'])) {
            return 'settings 必须是数组';
        }
        return null;
    }

    /** 主题设置的 schema 键清单 */
    public static function schemaKeys(string $name): array
    {
        $manifest = self::manifest($name);
        $keys = [];
        foreach (($manifest['settings'] ?? []) as $field) {
            if (is_array($field) && isset($field['key'])) {
                $keys[] = (string) $field['key'];
            }
        }
        return $keys;
    }

    /** 主题声明的页面模板（过滤后，不含 default；对齐 Node page-templates.ts 主题层） */
    public static function pageTemplates(string $name): array
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            return [];
        }
        return $desc['manifest']['pageTemplates'] ?? [];
    }

    /** 主题 CSS（theme.css 内容，前台 <style> 注入）；无文件返回 null */
    public static function css(string $name): ?string
    {
        if (!self::isValidName($name)) {
            return null;
        }
        $file = self::root() . '/' . $name . '/theme.css';
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /**
     * 指定主题的设置值：schema 默认值 + 已保存的 theme:{key} 覆盖
     */
    public static function valuesFor(string $name): array
    {
        $manifest = self::manifest($name);
        $values = [];
        foreach (($manifest['settings'] ?? []) as $field) {
            if (is_array($field) && isset($field['key'])) {
                $type = (string) ($field['type'] ?? '');
                $values[$field['key']] = (string) ($field['default'] ?? (($type === 'checkbox' || $type === 'switcher') ? '0' : ''));
            }
        }
        // 覆盖已保存值
        $all = Settings::all();
        foreach ($all as $key => $value) {
            if (str_starts_with($key, 'theme:') && isset($values[substr($key, 6)])) {
                $values[substr($key, 6)] = $value;
            }
        }
        return $values;
    }

    /** 当前激活主题的设置值（请求内缓存） */
    public static function values(): array
    {
        if (self::$values === null) {
            self::$values = self::valuesFor(self::active());
        }
        return self::$values;
    }

    public static function value(string $key, string $default = ''): string
    {
        $values = self::values();
        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    /**
     * 模板文件解析：主题覆盖优先，系统 fallback 兜底
     * 模板名白名单防路径穿越
     */
    public static function template(string $name): string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new \RuntimeException('非法的模板名：' . $name);
        }
        $themeFile = self::root() . '/' . self::active() . '/' . $name . '.php';
        if (is_file($themeFile)) {
            return $themeFile;
        }
        $systemFile = dirname(__DIR__) . '/Views/theme/' . $name . '.php';
        if (is_file($systemFile)) {
            return $systemFile;
        }
        throw new \RuntimeException('模板不存在：' . $name);
    }

    /** 切换激活主题 */
    public static function setActive(string $name): void
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException($desc['error'] ?? '主题不存在');
        }
        Settings::set('active_theme', $name);
        self::reset();
    }

    /** 从 zip 缓冲安装主题；返回 ['name', 'title', 'version']。校验失败抛 \RuntimeException（已回滚） */
    public static function installFromBuffer(string $buffer): array
    {
        $len = strlen($buffer);
        if ($len <= 0 || $len > self::MAX_ZIP_BYTES) {
            throw new \RuntimeException('zip 包大小不合法（上限 10MB）');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pfzip');
        if ($tmp === false || @file_put_contents($tmp, $buffer) === false) {
            throw new \RuntimeException('zip 包大小不合法（上限 10MB）');
        }
        $zip = new \ZipArchive();
        try {
            if ($zip->open($tmp) !== true) {
                throw new \RuntimeException('不是有效的 zip 压缩包');
            }
            $count = $zip->numFiles;
            if ($count <= 0) {
                throw new \RuntimeException('zip 包为空');
            }
            // 唯一顶层目录 + 穿越防护（对齐 Node installFromBuffer 校验顺序与文案）
            $top = null;
            for ($i = 0; $i < $count; $i++) {
                $entryName = (string) $zip->getNameIndex($i);
                $normalized = str_replace('\\', '/', $entryName);
                $trimmed = rtrim($normalized, '/');
                if ($trimmed === '') {
                    throw new \RuntimeException('主题包含意外路径：' . $entryName);
                }
                $parts = explode('/', $trimmed);
                if ($top === null) {
                    $top = $parts[0];
                } elseif ($top !== $parts[0]) {
                    throw new \RuntimeException('主题包必须只含一个顶层目录（主题名）');
                }
                foreach ($parts as $seg) {
                    if ($seg === '..' || $seg === '') {
                        throw new \RuntimeException('主题包含意外路径：' . $entryName);
                    }
                    if (str_contains($seg, ':')) {
                        throw new \RuntimeException('主题包含非法路径：' . $entryName);
                    }
                }
            }
            if (!self::isValidName((string) $top)) {
                throw new \RuntimeException('主题名不合法（仅限小写字母/数字/下划线/连字符）');
            }
            if (is_dir(self::root() . '/' . $top)) {
                throw new \RuntimeException('主题"' . $top . '"已存在，请先在主题列表卸载后再安装');
            }
            // 解压到临时目录 → manifest 校验 → 原子 rename
            $tmpDir = self::root() . '/.tmp-install-' . bin2hex(random_bytes(4));
            if (!@mkdir($tmpDir, 0755, true) || !@$zip->extractTo($tmpDir)) {
                self::rmDir($tmpDir);
                throw new \RuntimeException('解压失败（已回滚）');
            }
            $zip->close();
            if (!is_dir($tmpDir . '/' . $top)) {
                self::rmDir($tmpDir);
                throw new \RuntimeException('解压失败（已回滚）');
            }
            if (!is_file($tmpDir . '/' . $top . '/theme.json')) {
                self::rmDir($tmpDir);
                throw new \RuntimeException('安装失败：缺少 theme.json（已回滚）');
            }
            $json = json_decode((string) file_get_contents($tmpDir . '/' . $top . '/theme.json'), true);
            $error = is_array($json) ? self::validateManifest($json, (string) $top) : 'theme.json 不是合法 JSON';
            if ($error !== null) {
                self::rmDir($tmpDir);
                throw new \RuntimeException('安装失败：' . $error . '（已回滚）');
            }
            if (!@rename($tmpDir . '/' . $top, self::root() . '/' . $top)) {
                self::rmDir($tmpDir);
                throw new \RuntimeException('解压失败（已回滚）');
            }
            self::rmDir($tmpDir);
            self::reset();
            return [
                'name' => (string) $top,
                'title' => (string) $json['title'],
                'version' => (string) $json['version'],
            ];
        } finally {
            if ($zip->status !== \ZipArchive::ER_OK) {
                @$zip->close();
            }
            @unlink($tmp);
        }
    }

    /** 从 URL 下载并安装（30s 超时） */
    public static function installFromUrl(string $url): array
    {
        if (preg_match('#^https?://#', $url) !== 1) {
            throw new \RuntimeException('仅支持 http(s) 下载地址');
        }
        $ctx = stream_context_create(['http' => [
            'timeout' => 30,
            'follow_location' => 1,
            'user_agent' => 'pafish-php/1.0',
            'header' => "Connection: close\r\n",
        ]]);
        $buffer = @file_get_contents($url, false, $ctx);
        if ($buffer === false) {
            $status = 0;
            if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
            throw new \RuntimeException('下载失败' . ($status > 0 ? '：HTTP ' . $status : ''));
        }
        return self::installFromBuffer($buffer);
    }

    /**
     * 卸载主题：拒绝当前主题；只删除该主题独有的 theme:{key} 键（其他主题共享的保留）
     */
    public static function uninstall(string $name): void
    {
        if (!self::isValidName($name)) {
            throw new \RuntimeException('主题名不合法');
        }
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException('无法卸载：' . ($desc['error'] ?? '主题不存在'));
        }
        if (self::active() === $name) {
            throw new \RuntimeException('不能卸载当前正在使用的主题，请先切换到其他主题');
        }
        // 独有键清理
        $shared = self::sharedSchemaKeys($name);
        foreach (self::schemaKeys($name) as $key) {
            if (!isset($shared[$key])) {
                Settings::remove('theme:' . $key);
            }
        }
        if (!@self::rmDir(self::root() . '/' . $name)) {
            throw new \RuntimeException('删除主题目录失败');
        }
        self::reset();
    }

    /** 导出主题设置备份（JSON 数组；对齐 Node 备份格式） */
    public static function exportValues(string $name): array
    {
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException($desc['error'] ?? '主题不存在');
        }
        // 全量导出（schema 默认 + 已保存覆盖），对齐 Node 设置页 getThemeValues 语义
        return [
            'format' => self::BACKUP_FORMAT,
            'theme' => $name,
            'exportedAt' => date('c'),
            'values' => self::valuesFor($name),
        ];
    }

    /**
     * 导入主题设置备份：只接受当前主题 schema 声明的键，其他自动忽略
     * 返回 ['imported' => 实际导入数, 'total' => 备份总键数]
     */
    public static function importValues(string $name, array $json): array
    {
        if (($json['format'] ?? null) !== self::BACKUP_FORMAT) {
            throw new \RuntimeException('不是本系统的主题设置备份文件');
        }
        if (($json['theme'] ?? null) !== $name) {
            throw new \RuntimeException('备份属于主题"' . (string) ($json['theme'] ?? '') . '"，与当前主题"' . $name . '"不一致');
        }
        if (!isset($json['values']) || !is_array($json['values'])) {
            throw new \RuntimeException('备份缺少 values 字段');
        }
        $desc = self::describe($name);
        if ($desc['error'] !== null) {
            throw new \RuntimeException($desc['error'] ?? '主题不存在');
        }
        $keys = array_flip(self::schemaKeys($name));
        $imported = 0;
        $total = count($json['values']);
        foreach ($json['values'] as $k => $v) {
            $k = (string) $k;
            if (!isset($keys[$k])) {
                continue;
            }
            Settings::set('theme:' . $k, is_scalar($v) ? (string) $v : '');
            $imported++;
        }
        self::resetValues();
        return ['imported' => $imported, 'total' => $total];
    }

    /** 清除静态缓存（安装/卸载/激活/保存设置后调用） */
    public static function reset(): void
    {
        self::$manifests = [];
        self::resetValues();
    }

    public static function resetValues(): void
    {
        self::$values = null;
    }

    /** 其他主题共用的 schema key 集合（卸载时保护） */
    private static function sharedSchemaKeys(string $except): array
    {
        $shared = [];
        foreach (self::list() as $name) {
            if ($name === $except) {
                continue;
            }
            foreach (self::schemaKeys($name) as $key) {
                $shared[$key] = true;
            }
        }
        return $shared;
    }

    /** 递归删除目录（解压回滚/卸载用） */
    private static function rmDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }
        $ok = true;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $ok = self::rmDir($path) && $ok;
            } else {
                $ok = @unlink($path) && $ok;
            }
        }
        return @rmdir($dir) && $ok;
    }

    private static function isValidName(string $name): bool
    {
        return $name !== '' && preg_match(self::NAME_PATTERN, $name) === 1;
    }
}
