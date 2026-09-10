<?php
// 一次性全量移植：Desktop/lumina -> themes/lumina，1:1 复刻 HTML/CSS/JS，只换数据源。
// 产物：themes/lumina/compat.php + themes/lumina/header.php + themes/lumina/footer.php

$desktop = 'C:/Users/yt/Desktop/lumina';
$themeDir = __DIR__ . '/../themes/lumina';

function port_php_source(string $src): string {
    // 1) emlog guard
    $src = str_replace("defined('EMLOG_ROOT') || exit('access denied!');", "// pafish: emlog guard removed", $src);
    // 2) 常量 -> pafish 函数
    $src = str_replace('TEMPLATE_PATH', '__LUMINA_DIR__', $src);
    $src = str_replace('TEMPLATE_URL', 'lumina_tpl_base()', $src);
    $src = str_replace('BLOG_URL', 'lumina_blog_base()', $src);
    $src = str_replace('EMLOG_ROOT', 'dirname(__DIR__, 2)', $src);
    // 3) _vam / _g -> theme_value
    $src = str_replace("_vam(", "theme_value(", $src);
    $src = str_replace("_g(", "theme_value(", $src);
    // 4) Option / Cache / Url / User / LoginAuth -> pafish
    $replacements = [
        "Option::get('blogname')"      => "site_name()",
        "Option::get('site_key')"      => "settings('site_keywords', '')",
        "Option::get('bloginfo')"      => "settings('site_description', '')",
        "Option::get('icp')"           => "settings('site_icp', '')",
        "Option::get('login_comment')" => "(settings('comments_require_login', 'false') === 'true' ? 'n' : 'y')",
        "Option::get('comment_code')"  => "(settings('comments_captcha_enabled', 'true') !== 'false' ? 'y' : 'n')",
        "Option::get('login_code')"    => "'n'",
        "Option::get('email_code')"    => "'n'",
        "Option::get('is_signup')"     => "(settings('site_allow_register', 'true') !== 'false' ? 'y' : 'n')",
        "Option::get('nonce_templet')" => "'lumina'",
        'User::isVisitor()'            => "(!is_logged_in())",
        "User::haveEditPermission()"   => "in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true)",
        'User::getAvatar('             => 'lumina_user_avatar_of(',
        'LoginAuth::genToken()'        => 'csrf_token()',
        'Cache::getInstance()'         => 'null',
    ];
    $src = str_replace(array_keys($replacements), array_values($replacements), $src);
    // Database::getInstance() -> \Pafish\Core\DB (保留方法调用链，由兼容层处理)
    $src = str_replace('Database::getInstance()', '\\Pafish\\Core\\DB', $src);
    // 5) ISLOGIN / UID
    $src = str_replace("defined('ISLOGIN') && ISLOGIN", 'is_logged_in()', $src);
    $src = str_replace('ISLOGIN', 'is_logged_in()', $src);
    $src = str_replace("defined('UID') && (int)UID", "(int)(current_user()['id'] ?? 0)", $src);
    $src = str_replace('(int)UID', "(int)(current_user()['id'] ?? 0)", $src);
    // 避免把 UID 出现在字符串中误替换，处理独立的 UID 常量（前后非字母数字）
    $src = preg_replace('/\bUID\b/', "(int)(current_user()['id'] ?? 0)", $src);
    // 6) Url::author / sort / tag
    $src = preg_replace("/Url::author\(([^)]+)\)/", "url_to('/author/' . rawurlencode((string)(\$1)))", $src);
    $src = preg_replace("/Url::sort\(([^)]+)\)/", "url_to('/category/' . rawurlencode((string)(\$1)))", $src);
    $src = preg_replace("/Url::tag\(([^)]+)\)/", "url_to('/tag/' . rawurlencode((string)(\$1)))", $src);
    // 7) View::getView
    $src = preg_replace("/View::getView\('([a-z_]+)'\)/", "__DIR__ . '/\$1.php'", $src);
    // 8) show_404_page
    $src = str_replace('show_404_page();', 'http_response_code(404); echo render("error", ["status" => 404, "message" => "内容不存在", "backUrl" => url_to("/")]); exit;', $src);
    // 9) doAction -> do_action
    $src = str_replace('doAction(', 'do_action(', $src);
    return $src;
}

function wrap_functions(string $src): string {
    $lines = explode("\n", $src);
    $out = [];
    $depth = 0;
    $guarding = false;
    foreach ($lines as $line) {
        if (!$guarding && preg_match('/^function\s+([A-Za-z0-9_]+)\s*\(/', $line, $m)) {
            $out[] = 'if (!function_exists(\'' . $m[1] . '\')) {';
            $guarding = true;
            $depth = 0;
        }
        if ($guarding) {
            $depth += substr_count($line, '{') - substr_count($line, '}');
            $out[] = $line;
            if ($depth <= 0 && str_contains($line, '}')) {
                $out[] = '}';
                $guarding = false;
            }
        } else {
            $out[] = $line;
        }
    }
    return implode("\n", $out);
}

// ── 1) compat.php from module.php ──
$src = file_get_contents($desktop . '/module.php');
if ($src === false) { fwrite(STDERR, "read module.php failed\n"); exit(1); }
$src = port_php_source($src);
$src = wrap_functions($src);
$header = <<<'PHP'
<?php
// pafish: 由 scripts/port-lumina.php 生成，基于原版 module.php 1:1 移植，HTML/CSS/JS 一字不改
if (!defined('__LUMINA_DIR__')) define('__LUMINA_DIR__', __DIR__);
if (!function_exists('lumina_tpl_base')) { function lumina_tpl_base(): string { return url_to('/theme-assets/lumina/'); } }
if (!function_exists('lumina_blog_base')) { function lumina_blog_base(): string { return rtrim(url_to('/'), '/') . '/'; } }
if (!function_exists('lumina_user_avatar_of')) { function lumina_user_avatar_of(int $uid, string $email = ''): string { return \Pafish\Http\Comments::avatarUrl(null, $email); } }

PHP;
$src = preg_replace('/^\s*<\?php\s*\n.*?emlog guard removed\s*\n?/s', '', $src);
$src = $header . ltrim($src);
// 修复错误的 defined 替换残留
$src = str_replace("defined('lumina_tpl_base()')", "function_exists('lumina_tpl_base')", $src);
$src = str_replace("defined('(int)(current_user()['id'] ?? 0)')", 'is_logged_in()', $src);
$src = str_replace('defined("(int)(current_user()[\'id\'] ?? 0)")', 'is_logged_in()', $src);
$src = str_replace('is_logged_in() && is_logged_in()', 'is_logged_in()', $src);
$src = str_replace("defined('is_logged_in()')", 'true', $src);
$src = str_replace('!defined(\'is_logged_in()\')', 'false', $src);
// 修 PHP 8.5 弃用
$src = str_replace(
    'if (isset($http_response_header) && is_array($http_response_header)) {',
    '$respHeaders = http_get_last_response_headers(); if (is_array($respHeaders)) {',
    $src
);
$src = str_replace('$http_response_header as $header', '$respHeaders as $header', $src);

$target = $themeDir . '/compat.php';
file_put_contents($target, $src);
echo "written compat.php lines=" . substr_count($src, "\n") . " bytes=" . strlen($src) . "\n";
exec('php -l ' . escapeshellarg($target) . ' 2>&1', $out, $code);
echo implode("\n", $out) . "\n";
if ($code !== 0) exit(1);

// ── 2) header.php ──
$src = file_get_contents($desktop . '/header.php');
$src = port_php_source($src);
// 保留 pafish 的 helpers 加载（Theme::boot 已加载，但 header 独立加载也安全）
$src = str_replace("require_once __DIR__ . '/module.php';", "require_once __DIR__ . '/helpers.php';", $src);
// 保留 pafish 的 lumina_asset_url 版本逻辑（原版 lumina_asset_url 已通过 helpers 提供）
// 不改其他
$target = $themeDir . '/header.php';
file_put_contents($target, $src);
echo "written header.php bytes=" . strlen($src) . "\n";
exec('php -l ' . escapeshellarg($target) . ' 2>&1', $out2, $code2);
echo implode("\n", $out2) . "\n";
if ($code2 !== 0) exit(1);

// ── 3) footer.php ──
$src = file_get_contents($desktop . '/footer.php');
$src = port_php_source($src);
$target = $themeDir . '/footer.php';
file_put_contents($target, $src);
echo "written footer.php bytes=" . strlen($src) . "\n";
exec('php -l ' . escapeshellarg($target) . ' 2>&1', $out3, $code3);
echo implode("\n", $out3) . "\n";
if ($code3 !== 0) exit(1);

// ── 4) style.css 已在外部 xcopy 1:1，无需处理 ──
echo "done 1:1 assets already synced via xcopy\n";
