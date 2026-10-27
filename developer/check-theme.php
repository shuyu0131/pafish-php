<?php

declare(strict_types=1);

/**
 * 只读主题开发检查器。
 *
 * 用法：
 *   php developer/check-theme.php themes/my-theme
 *   php developer/check-theme.php themes/my-theme --strict-partials
 *   php developer/check-theme.php themes/my-theme --trace
 *   php developer/check-theme.php themes/my-theme --json
 *
 * 这个脚本故意不启动应用、不连接数据库、不修改文件，也不启用主题。
 */

const THEME_PRIMARY_TEMPLATES = ['index', 'post', 'page', 'category', 'tag', 'search', 'archives', 'micro', 'user'];
const THEME_STRICT_REQUIRED_TEMPLATES = ['index', 'post', 'page', 'category', 'tag', 'search'];
const THEME_OPTIONAL_TEMPLATES = ['archives', 'user'];
const THEME_COMPATIBILITY_FALLBACK_TEMPLATES = ['micro'];
const THEME_ALLOWED_FALLBACKS = ['index', 'post', 'page', 'category', 'tag', 'search', 'archives', 'micro', 'user'];
const THEME_SETTING_TYPES = [
    'text', 'textarea', 'checkbox', 'checkboxes', 'select', 'color', 'switcher', 'radio', 'image', 'icon',
    'heading', 'subheading', 'slider', 'spacing', 'typography', 'repeater', 'group', 'button_set',
    'image_select', 'palette', 'background', 'border', 'code',
];
const THEME_INJECTS = ['head', 'footer', 'sidebar', 'comment_form', 'login_form', 'register_form', 'post_editor', 'micro_editor'];
const THEME_ASSET_EXTENSIONS = [
    'css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico',
    'woff', 'woff2', 'ttf', 'eot', 'otf', 'mp3', 'mp4', 'webm', 'ogg', 'wav',
    'json', 'map', 'txt', 'xml', 'webmanifest', 'wasm',
];

/** @return array{path:string, strictPartials:bool, trace:bool, json:bool, help:bool} */
function argumentsFrom(array $argv): array
{
    $path = '';
    $strict = false;
    $trace = false;
    $json = false;
    $help = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--strict-partials') {
            $strict = true;
        } elseif ($arg === '--trace') {
            $trace = true;
        } elseif ($arg === '--json') {
            $json = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            $help = true;
        } elseif (str_starts_with($arg, '-')) {
            fwrite(STDERR, "未知选项：{$arg}\n");
            exit(2);
        } elseif ($path === '') {
            $path = $arg;
        } else {
            fwrite(STDERR, "只接受一个主题目录：{$path}\n");
            exit(2);
        }
    }
    return ['path' => $path, 'strictPartials' => $strict, 'trace' => $trace, 'json' => $json, 'help' => $help];
}

function usage(): void
{
    echo "用法：php developer/check-theme.php <主题目录> [--strict-partials] [--trace] [--json]\n\n";
    echo "检查 manifest、模板数据契约、主体模板、设置 schema、PHP 语法、静态资源和局部模板来源。\n";
    echo "--trace 会列出主体模板的候选顺序、默认解析来源和 page/post 变体。\n";
}

/** @return array<string, mixed> */
function result(string $level, string $message, array $extra = []): array
{
    return ['level' => $level, 'message' => $message] + $extra;
}

/** @return list<string> */
function filesUnder(string $directory): array
{
    if (is_link($directory) || !is_dir($directory)) {
        return [];
    }
    $out = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isLink() && $file->isFile()) {
            $out[] = $file->getPathname();
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

function relativePath(string $root, string $file): string
{
    $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/') . '/';
    $file = str_replace('\\', '/', realpath($file) ?: $file);
    return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
}

function validName(string $name): bool
{
    return preg_match('/^[a-z0-9_-]{1,50}$/D', $name) === 1;
}

function isThemeFile(string $themePath, string $path): bool
{
    $root = realpath($themePath);
    $file = realpath($path);
    if ($root === false || $file === false || !is_file($file)) return false;
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $file = str_replace('\\', '/', $file);
    return str_starts_with($file, $root);
}

/** @param mixed $field */
function checkSetting(mixed $field, string $path, array &$issues, array &$keys): void
{
    if (!is_array($field)) {
        $issues[] = result('error', "{$path} 必须是对象");
        return;
    }
    $key = $field['key'] ?? null;
    $label = $field['label'] ?? null;
    $type = $field['type'] ?? null;
    $hasStoredKey = !in_array($type, ['group', 'heading', 'subheading'], true);
    if (!is_string($key) || preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{0,79}$/D', $key) !== 1) {
        $issues[] = result('error', "{$path}.key 缺失或格式无效");
    } elseif ($hasStoredKey && isset($keys[$key])) {
        $issues[] = result('error', "设置键重复：{$key}（{$path}，已在 {$keys[$key]} 声明）");
    } elseif ($hasStoredKey) {
        $keys[$key] = $path;
    }
    if (!is_string($label) || trim($label) === '') {
        $issues[] = result('error', "{$path}.label 必须是非空字符串");
    }
    if (!is_string($type) || !in_array($type, THEME_SETTING_TYPES, true)) {
        $issues[] = result('error', "{$path}.type 不受支持：" . (is_scalar($type) ? (string) $type : '空值'));
        return;
    }
    if (is_string($type) && \Pafish\Services\ExtensionKernel::describeSetting($field, 'theme') === null) {
        $issues[] = result('error', "核心设置 schema 校验失败：{$path}");
    }
    if ($type === 'password') {
        $issues[] = result('error', "主题不支持 password 字段：{$path}");
    }
    if (in_array($type, ['select', 'radio', 'button_set', 'palette', 'image_select'], true)
        && (!isset($field['options']) || !is_array($field['options']) || $field['options'] === [])) {
        $issues[] = result('error', "{$path}.options 必须是非空对象");
    }
    if (in_array($type, ['repeater', 'group'], true)) {
        if (!isset($field['fields']) || !is_array($field['fields']) || $field['fields'] === []) {
            $issues[] = result('error', "{$path}.fields 必须是非空数组");
        } elseif ($type === 'repeater') {
            $rowKeys = [];
            foreach ($field['fields'] as $index => $child) {
                checkSetting($child, "{$path}.fields[{$index}]", $issues, $rowKeys);
            }
        } else {
            foreach ($field['fields'] as $index => $child) {
                checkSetting($child, "{$path}.fields[{$index}]", $issues, $keys);
            }
        }
    }
    if ($type === 'repeater' && isset($field['max_items']) && (!is_int($field['max_items']) || $field['max_items'] < 1)) {
        $issues[] = result('error', "{$path}.max_items 必须是正整数");
    }
    if (isset($field['pattern']) && (!is_string($field['pattern']) || @preg_match('/' . str_replace('/', '\/', $field['pattern']) . '/u', '') === false)) {
        $issues[] = result('error', "{$path}.pattern 不是可编译的正则");
    }
    if (isset($field['show_if']) && (!is_array($field['show_if']) || !is_string($field['show_if']['key'] ?? null))) {
        $issues[] = result('error', "{$path}.show_if 必须包含 key");
    }
}

/** @return array{manifest:array<string,mixed>|null, issues:list<array<string,mixed>>} */
function readManifest(string $themePath, string $name): array
{
    $issues = [];
    $manifestPath = $themePath . DIRECTORY_SEPARATOR . 'theme.json';
    if (!is_file($manifestPath)) {
        return ['manifest' => null, 'issues' => [result('error', '缺少 theme.json')]];
    }
    try {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['manifest' => null, 'issues' => [result('error', 'theme.json 不是合法 JSON：' . $e->getMessage())]];
    }
    if (!is_array($manifest)) {
        return ['manifest' => null, 'issues' => [result('error', 'theme.json 顶层必须是对象')]];
    }
    if (($manifest['name'] ?? null) !== $name) {
        $issues[] = result('error', "name 必须与目录名一致：{$name}");
    }
    foreach (['title', 'version'] as $optionalString) {
        if (array_key_exists($optionalString, $manifest) && (!is_string($manifest[$optionalString]) || trim($manifest[$optionalString]) === '')) {
            $issues[] = result('error', "{$optionalString} 必须是非空字符串");
        }
    }
    if (isset($manifest['apiVersion']) && (!is_int($manifest['apiVersion']) || $manifest['apiVersion'] < 1 || $manifest['apiVersion'] > 2)) {
        $issues[] = result('error', 'apiVersion 只支持 1 或 2');
    }
    foreach (['injects' => THEME_INJECTS, 'systemTemplateFallback' => THEME_ALLOWED_FALLBACKS] as $key => $allowed) {
        if (array_key_exists($key, $manifest) && (!is_array($manifest[$key]) || array_filter($manifest[$key], static fn ($item): bool => !is_string($item) || !in_array($item, $allowed, true)) !== [])) {
            $issues[] = result('error', "{$key} 包含无效值");
        }
    }
    if (isset($manifest['userData']) && (!is_array($manifest['userData']) || array_filter($manifest['userData'], static fn ($item): bool => !is_string($item) || !in_array($item, ['posts', 'stats', 'reactions', 'comments', 'points'], true)) !== [])) {
        $issues[] = result('error', 'userData 包含无效值');
    }
    if (isset($manifest['settings']) && !is_array($manifest['settings'])) {
        $issues[] = result('error', 'settings 必须是数组');
    } else {
        $keys = [];
        foreach (($manifest['settings'] ?? []) as $index => $field) {
            checkSetting($field, "settings[{$index}]", $issues, $keys);
        }
    }
    if (isset($manifest['pageTemplates'])) {
        if (!is_array($manifest['pageTemplates'])) {
            $issues[] = result('error', 'pageTemplates 必须是数组');
        } else {
            foreach ($manifest['pageTemplates'] as $index => $template) {
                if (!is_array($template) || !is_string($template['name'] ?? null) || $template['name'] === 'default' || preg_match('/^[a-z0-9-]{1,40}$/D', (string) ($template['name'] ?? '')) !== 1 || !is_string($template['title'] ?? null) || trim($template['title']) === '') {
                    $issues[] = result('error', "pageTemplates[{$index}] 声明无效");
                }
            }
        }
    }
    return ['manifest' => $manifest, 'issues' => $issues];
}

/** @return array{contract:array<string,mixed>|null,issues:list<array<string,mixed>>} */
function readThemeDataContract(): array
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'theme-data-contract.json';
    if (!is_file($path)) {
        return ['contract' => null, 'issues' => [result('error', '缺少 developer/theme-data-contract.json')]];
    }
    try {
        $contract = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['contract' => null, 'issues' => [result('error', '主题数据契约 JSON 无效：' . $e->getMessage())]];
    }
    if (!is_array($contract) || !is_int($contract['version'] ?? null) || $contract['version'] < 1
        || !is_array($contract['templates'] ?? null) || !is_array($contract['entities'] ?? null)) {
        return ['contract' => null, 'issues' => [result('error', '主题数据契约缺少 version、templates 或 entities')]];
    }
    $issues = [];
    foreach (array_merge(THEME_PRIMARY_TEMPLATES, ['auth', 'error']) as $template) {
        $fields = $contract['templates'][$template]['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            $issues[] = result('error', "主题数据契约缺少 {$template} 模板字段");
            continue;
        }
        foreach ($fields as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_string($definition) || trim($definition) === '') {
                $issues[] = result('error', "主题数据契约 {$template} 包含无效字段定义");
                break;
            }
        }
    }
    foreach ($contract['entities'] as $entity => $definition) {
        if (!is_string($entity) || !is_array($definition) || !is_array($definition['fields'] ?? null) || $definition['fields'] === []) {
            $issues[] = result('error', '主题数据契约包含无效实体定义：' . (string) $entity);
            continue;
        }
        foreach ($definition['fields'] as $name => $shape) {
            if (!is_string($name) || $name === '' || !is_string($shape) || trim($shape) === '') {
                $issues[] = result('error', "主题数据契约 {$entity} 包含无效字段定义");
                break;
            }
        }
    }
    $requiredPartials = ['header', 'footer', 'post-card', 'pagination', 'related-posts', 'comment-section', 'comment-form', 'comment-thread', 'micro-card'];
    foreach ($requiredPartials as $partial) {
        $fields = $contract['partials'][$partial]['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            $issues[] = result('error', "主题数据契约缺少 {$partial} 局部模板字段");
            continue;
        }
        foreach ($fields as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_string($definition) || trim($definition) === '') {
                $issues[] = result('error', "主题数据契约 {$partial} 包含无效字段定义");
                break;
            }
        }
    }
    return ['contract' => $contract, 'issues' => $issues];
}

/** @return array{output:string,code:int} */
function phpLint(string $file): array
{
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([PHP_BINARY, '-l', $file], $descriptor, $pipes);
    if (!is_resource($process)) {
        return ['output' => '无法启动 PHP 语法检查', 'code' => 1];
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) fclose($pipe);
    $code = proc_close($process);
    return ['output' => trim((string) $output), 'code' => $code];
}

/** @return list<string> */
function referencedPartials(string $file): array
{
    $source = (string) file_get_contents($file);
    preg_match_all('/\b(?:render_partial|render)\s*\(\s*[\'\"]([a-z0-9_-]{1,64})[\'\"]/', $source, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

function callsLayoutHelper(string $file, string $layout): bool
{
    $tokens = token_get_all((string) file_get_contents($file));
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'get_' . $layout) continue;
        $cursor = $index + 1;
        while (isset($tokens[$cursor]) && is_array($tokens[$cursor]) && in_array($tokens[$cursor][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $cursor++;
        if (($tokens[$cursor] ?? null) !== '(') continue;
        $cursor++;
        while (isset($tokens[$cursor]) && is_array($tokens[$cursor]) && in_array($tokens[$cursor][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $cursor++;
        if (($tokens[$cursor] ?? null) === ')') return true;
    }
    return false;
}

/** @return array{candidate:string,source:string,file:?string} */
function resolveCandidate(string $themePath, string $systemRoot, string $candidate): array
{
    $themeFile = $themePath . DIRECTORY_SEPARATOR . $candidate . '.php';
    if (isThemeFile($themePath, $themeFile)) {
        return ['candidate' => $candidate, 'source' => 'theme', 'file' => relativePath($themePath, $themeFile)];
    }
    $systemFile = $systemRoot . DIRECTORY_SEPARATOR . $candidate . '.php';
    if (is_file($systemFile)) {
        return ['candidate' => $candidate, 'source' => 'system', 'file' => $systemFile];
    }
    return ['candidate' => $candidate, 'source' => 'missing', 'file' => null];
}

/**
 * 静态展示模板解析顺序。page/post 的变体需要请求上下文，所以这里同时列出
 * 默认主体解析结果和当前主题目录中可用的变体文件，避免把静态检查误报成运行时结果。
 *
 * @return array<string,mixed>
 */
function templateResolution(string $themePath, ?array $manifest, string $template, string $systemRoot): array
{
    $candidateOrder = [$template];
    $variants = [];
    if ($template === 'page') {
        $candidateOrder = ['page-{template}', 'page'];
        $variants['page-{template}'] = [];
    } elseif ($template === 'post') {
        $candidateOrder = ['post-template-{template}', 'post-{category_slug}', 'post'];
        $variants['post-template-{template}'] = [];
        $variants['post-{category_slug}'] = [];
    }
    foreach (scandir($themePath) ?: [] as $file) {
        if (!is_string($file) || !isThemeFile($themePath, $themePath . DIRECTORY_SEPARATOR . $file)) {
            continue;
        }
        if ($template === 'page' && preg_match('/^page-([a-z0-9-]{1,40})\.php$/D', $file, $match) === 1 && $match[1] !== 'default') {
            $variants['page-{template}'][] = ['name' => $match[1], 'source' => 'theme', 'file' => $file];
        }
        if ($template === 'post' && preg_match('/^post-template-([a-z0-9-]{1,40})\.php$/D', $file, $match) === 1 && $match[1] !== 'default') {
            $variants['post-template-{template}'][] = ['name' => $match[1], 'source' => 'theme', 'file' => $file];
        }
        if ($template === 'post' && preg_match('/^post-([a-z0-9_-]{1,50})\.php$/D', $file, $match) === 1 && $match[1] !== 'template') {
            $variants['post-{category_slug}'][] = ['name' => $match[1], 'source' => 'theme', 'file' => $file];
        }
    }
    foreach ($variants as &$items) {
        usort($items, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);
    }
    unset($items);

    $default = resolveCandidate($themePath, $systemRoot, $template);
    if ($template === 'user' && $default['source'] !== 'theme') {
        $default['source'] = 'unavailable';
        $default['file'] = null;
    }
    $fallbacks = is_array($manifest) ? ($manifest['systemTemplateFallback'] ?? null) : null;
    $explicitFallback = is_array($fallbacks);
    $fallbackAllowed = !$explicitFallback || $template === 'micro' || in_array($template, $fallbacks, true);
    if ($default['source'] !== 'theme' && !$fallbackAllowed) {
        $default['source'] = 'blocked';
        $default['file'] = null;
    }
    return [
        'template' => $template,
        'candidateOrder' => $candidateOrder,
        'variants' => $variants,
        'default' => $default,
        'fallback' => $explicitFallback ? ($fallbackAllowed ? 'declared' : 'blocked') : 'legacy',
        'note' => $template === 'page' || $template === 'post'
            ? 'page/post 变体只在请求数据匹配时插入候选；theme_template_candidates 过滤器还可调整顺序。'
            : '默认上下文直接使用主体模板；theme_template_candidates 过滤器仍可调整候选。',
    ];
}

$args = argumentsFrom($argv);
if ($args['help'] || $args['path'] === '') {
    usage();
    exit($args['help'] ? 0 : 2);
}

$themePath = realpath($args['path']);
if ($themePath === false || !is_dir($themePath)) {
    fwrite(STDERR, "主题目录不存在：{$args['path']}\n");
    exit(2);
}
$themeName = basename(str_replace('\\', '/', $themePath));
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "缺少 vendor/autoload.php；请从项目根目录安装项目依赖后重试。\n");
    exit(2);
}
require_once $autoload;
if (!defined('PAFISH_ROOT')) {
    define('PAFISH_ROOT', dirname(__DIR__));
}
$issues = [];
$contractInfo = readThemeDataContract();
$issues = array_merge($issues, $contractInfo['issues']);
$dataContract = $contractInfo['contract'];
$templateData = [];
$partialData = [];
if (is_array($dataContract)) {
    foreach ($dataContract['templates'] as $template => $definition) {
        if (is_array($definition['fields'] ?? null)) $templateData[$template] = array_keys($definition['fields']);
    }
    foreach ($dataContract['partials'] ?? [] as $partial => $definition) {
        if (is_array($definition['fields'] ?? null)) $partialData[$partial] = array_keys($definition['fields']);
    }
}
$info = [
    'theme' => $themeName,
    'path' => $themePath,
    'strictPartials' => $args['strictPartials'],
    'trace' => $args['trace'],
    'dataContractVersion' => $dataContract['version'] ?? null,
    'templateData' => $templateData,
    'partialData' => $partialData,
    'templatePolicy' => [
        'requiredWhenFallbackListIsExplicit' => THEME_STRICT_REQUIRED_TEMPLATES,
        'optional' => THEME_OPTIONAL_TEMPLATES,
        'compatibilityFallback' => THEME_COMPATIBILITY_FALLBACK_TEMPLATES,
    ],
    // Kept as a presence map for existing checker consumers; it does not mean every
    // listed template is mandatory.
    'primaryTemplates' => [],
    'templateResolution' => [],
    'php' => [],
    'partials' => [],
    'assets' => [],
    'assetReferences' => [],
];
if (!validName($themeName)) {
    $issues[] = result('error', "目录名不符合主题命名规则：{$themeName}");
}

$manifestInfo = readManifest($themePath, $themeName);
$issues = array_merge($issues, $manifestInfo['issues']);
$manifest = $manifestInfo['manifest'];
if (is_array($manifest)) {
    $coreError = \Pafish\Services\Theme::validateManifest($manifest, $themeName);
    if ($coreError !== null) {
        $issues[] = result('error', '核心 manifest 校验失败：' . $coreError);
    }
    foreach (THEME_PRIMARY_TEMPLATES as $template) {
        $file = $themePath . DIRECTORY_SEPARATOR . $template . '.php';
        $exists = isThemeFile($themePath, $file);
        $info['primaryTemplates'][$template] = $exists;
        if (!$exists) {
            $fallbacks = $manifest['systemTemplateFallback'] ?? null;
            if ($template === 'user') {
                $issues[] = result('warning', '没有 user.php：前台用户中心入口将保持关闭');
            } elseif ($template === 'archives' && is_array($fallbacks) && !in_array('archives', $fallbacks, true)) {
                $issues[] = result('warning', '没有 archives.php：归档页是可选路由，/archives 将返回 404');
            } elseif ($template === 'archives' && !is_array($fallbacks)) {
                $issues[] = result('warning', '没有 archives.php：旧主题兼容规则将回退到系统归档模板');
            } elseif ($template === 'micro') {
                $issues[] = result('warning', '没有 micro.php：此入口按运行时兼容规则回退到系统模板');
            } elseif (is_array($fallbacks) && !in_array($template, $fallbacks, true)) {
                $issues[] = result('error', "缺少主体模板 {$template}.php，且严格模式没有声明系统回退");
            } elseif (!is_array($fallbacks)) {
                $issues[] = result('warning', "{$template}.php 将按旧主题兼容规则回退到系统模板");
            }
        }
    }
    if ((array) ($manifest['systemTemplateFallback'] ?? []) !== []) {
        $issues[] = result('warning', 'systemTemplateFallback 非空：页面可能混入系统模板', ['items' => array_values($manifest['systemTemplateFallback'])]);
    }
}

$systemRoot = realpath(__DIR__ . '/../app/Views/theme');
if ($systemRoot !== false) {
    foreach (THEME_PRIMARY_TEMPLATES as $template) {
        $info['templateResolution'][$template] = templateResolution($themePath, $manifest, $template, $systemRoot);
    }
}

$phpFiles = array_values(array_filter(filesUnder($themePath), static fn (string $file): bool => strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php'));
foreach ($phpFiles as $file) {
    $lint = phpLint($file);
    $info['php'][] = ['file' => relativePath($themePath, $file), 'ok' => $lint['code'] === 0, 'output' => $lint['output']];
    if ($lint['code'] !== 0) {
        $issues[] = result('error', 'PHP 语法错误：' . relativePath($themePath, $file), ['detail' => $lint['output']]);
    }
}

$partialRefs = [];
foreach ($phpFiles as $file) {
    foreach (referencedPartials($file) as $partial) {
        $partialRefs[$partial][] = relativePath($themePath, $file);
    }
    foreach (['header', 'footer'] as $layout) {
        if (callsLayoutHelper($file, $layout)) {
            $partialRefs[$layout][] = relativePath($themePath, $file);
        }
    }
}
ksort($partialRefs, SORT_STRING);
foreach ($partialRefs as $partial => $callers) {
    $themeFile = $themePath . DIRECTORY_SEPARATOR . $partial . '.php';
    $systemFile = $systemRoot !== false ? $systemRoot . DIRECTORY_SEPARATOR . $partial . '.php' : '';
    $source = isThemeFile($themePath, $themeFile) ? 'theme' : (is_file($systemFile) ? 'system' : 'missing');
    $info['partials'][$partial] = ['source' => $source, 'callers' => $callers];
    if ($source === 'missing') {
        $issues[] = result('error', "局部模板不存在：{$partial}.php", ['callers' => $callers]);
    } elseif ($source === 'system') {
        $level = $args['strictPartials'] ? 'error' : 'warning';
        $issues[] = result($level, "局部模板 {$partial}.php 来自系统目录", ['callers' => $callers, 'file' => $systemFile]);
    }
    if ($source === 'theme' && preg_match('/^post-([a-z0-9_-]+)$/D', $partial, $match) === 1) {
        $issues[] = result(
            'warning',
            "{$partial}.php 同时会被当作局部模板和分类 slug={$match[1]} 的文章模板；若该分类存在，访问其文章时将优先使用此文件",
            ['callers' => $callers]
        );
    }
}

foreach (filesUnder($themePath . DIRECTORY_SEPARATOR . 'assets') as $asset) {
    $relative = relativePath($themePath, $asset);
    $extension = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
    $ok = in_array($extension, THEME_ASSET_EXTENSIONS, true);
    $info['assets'][] = ['file' => $relative, 'extension' => $extension, 'ok' => $ok];
    if (!$ok) {
        $issues[] = result('warning', "assets 中的文件扩展名未列入主题白名单：{$relative}");
    }
}
foreach (array_merge($phpFiles, filesUnder($themePath . DIRECTORY_SEPARATOR . 'assets')) as $sourceFile) {
    $extension = strtolower(pathinfo($sourceFile, PATHINFO_EXTENSION));
    $source = (string) file_get_contents($sourceFile);
    if ($extension === 'php') {
        preg_match_all('/\btheme_asset_url\s*\(\s*[^,]+,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $source, $matches);
        foreach ($matches[1] ?? [] as $reference) {
            $assetPath = $themePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($reference, '/'));
            $exists = isThemeFile($themePath, $assetPath);
            $info['assetReferences'][] = ['file' => relativePath($themePath, $sourceFile), 'reference' => $reference, 'exists' => $exists];
            if (!$exists) {
                $issues[] = result('error', '主题模板引用的资源不存在：' . $reference, ['file' => relativePath($themePath, $sourceFile)]);
            }
        }
    }
    if ($extension === 'css') {
        preg_match_all('/url\(\s*[\'\"]?([^\'\")]+)[\'\"]?\s*\)/i', $source, $matches);
        foreach ($matches[1] ?? [] as $reference) {
            $reference = trim($reference);
            if ($reference === '' || str_starts_with($reference, '#') || preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $reference) === 1) continue;
            $referencePath = parse_url($reference, PHP_URL_PATH);
            if (!is_string($referencePath) || $referencePath === '') continue;
            $assetPath = dirname($sourceFile) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $referencePath);
            $exists = isThemeFile($themePath, $assetPath);
            $info['assetReferences'][] = ['file' => relativePath($themePath, $sourceFile), 'reference' => $reference, 'exists' => $exists];
            if (!$exists) {
                $issues[] = result('error', 'CSS 引用的本地资源不存在：' . $reference, ['file' => relativePath($themePath, $sourceFile)]);
            }
        }
    }
}
if (!isThemeFile($themePath, $themePath . DIRECTORY_SEPARATOR . 'style.css')
    && !isThemeFile($themePath, $themePath . DIRECTORY_SEPARATOR . 'assets/css/style.css')) {
    $issues[] = result('warning', '主题没有提供 style.css；前台布局样式将回退到 default 主题');
}

$errors = count(array_filter($issues, static fn (array $issue): bool => $issue['level'] === 'error'));
$warnings = count(array_filter($issues, static fn (array $issue): bool => $issue['level'] === 'warning'));
$resultData = ['ok' => $errors === 0, 'errors' => $errors, 'warnings' => $warnings, 'issues' => $issues, 'info' => $info];
if ($args['json']) {
    echo json_encode($resultData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($errors > 0 ? 1 : 0);
}

echo "Pafish 主题检查：{$themeName}\n";
echo "目录：{$themePath}\n";
echo "主体模板：" . implode('  ', array_map(static fn (string $key): string => $key . '=' . ($info['primaryTemplates'][$key] ? '✓' : '—'), array_keys($info['primaryTemplates']))) . "\n";
echo "PHP 文件：" . count($info['php']) . "；局部引用：" . count($info['partials']) . "；资源：" . count($info['assets']) . "\n";
if ($args['trace']) {
    echo "模板解析（默认上下文）：\n";
    foreach ($info['templateResolution'] as $template => $detail) {
        $default = $detail['default'];
        $selected = $default['source'] === 'missing' ? '缺失' : ($default['source'] === 'blocked' ? '已阻止系统回退' : $default['source']);
        $file = $default['file'] !== null ? ' [' . $default['file'] . ']' : '';
        echo "  {$template}: " . implode(' → ', $detail['candidateOrder']) . " => {$selected}{$file}\n";
        foreach ($detail['variants'] as $pattern => $variants) {
            $shown = array_map(static fn (array $variant): string => $variant['name'] . ' [' . $variant['source'] . '/' . $variant['file'] . ']', $variants);
            echo "    {$pattern}: " . ($shown === [] ? '（无）' : implode(', ', $shown)) . "\n";
        }
    }
}
foreach ($info['partials'] as $partial => $detail) {
    echo sprintf("  partial %-24s %-7s (%s)\n", $partial . '.php', $detail['source'], implode(', ', $detail['callers']));
}
foreach ($issues as $issue) {
    $prefix = $issue['level'] === 'error' ? 'ERROR' : 'WARN ';
    echo "{$prefix} {$issue['message']}\n";
    if (!empty($issue['detail'])) echo "      {$issue['detail']}\n";
}
echo $errors === 0 ? "通过：{$warnings} 个提示\n" : "失败：{$errors} 个错误，{$warnings} 个提示\n";
exit($errors > 0 ? 1 : 0);
