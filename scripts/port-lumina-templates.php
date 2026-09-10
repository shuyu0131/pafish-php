<?php
// Port remaining lumina templates 1:1 (HTML 一字不改，只换数据源)
// Desktop -> themes/lumina: log_list.php -> listing.php, echo_log.php -> post.php, page.php, 404.php
$desktop = 'C:/Users/yt/Desktop/lumina';
$themeDir = __DIR__ . '/../themes/lumina';

function port_src(string $src): string {
    $src = str_replace("defined('EMLOG_ROOT') || exit('access denied!');", "// pafish: emlog guard removed", $src);
    $src = str_replace('TEMPLATE_PATH', '__LUMINA_DIR__', $src);
    $src = str_replace('TEMPLATE_URL', 'lumina_tpl_base()', $src);
    $src = str_replace('BLOG_URL', 'lumina_blog_base()', $src);
    $src = str_replace('EMLOG_ROOT', 'dirname(__DIR__, 2)', $src);
    $src = str_replace("_vam(", "theme_value(", $src);
    $src = str_replace("_g(", "theme_value(", $src);
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
    $src = str_replace('Database::getInstance()', '\\Pafish\\Core\\DB', $src);
    $src = str_replace("defined('ISLOGIN') && ISLOGIN", 'is_logged_in()', $src);
    $src = str_replace('ISLOGIN', 'is_logged_in()', $src);
    $src = str_replace("defined('UID') && (int)UID", "(int)(current_user()['id'] ?? 0)", $src);
    $src = str_replace('(int)UID', "(int)(current_user()['id'] ?? 0)", $src);
    $src = preg_replace('/\bUID\b/', "(int)(current_user()['id'] ?? 0)", $src);
    $src = preg_replace("/Url::author\(([^)]+)\)/", "url_to('/author/' . rawurlencode((string)(\$1)))", $src);
    $src = preg_replace("/Url::sort\(([^)]+)\)/", "url_to('/category/' . rawurlencode((string)(\$1)))", $src);
    $src = preg_replace("/Url::tag\(([^)]+)\)/", "url_to('/tag/' . rawurlencode((string)(\$1)))", $src);
    $src = preg_replace("/View::getView\('([a-z_]+)'\)/", "__DIR__ . '/\$1.php'", $src);
    $src = str_replace('show_404_page();', 'http_response_code(404); echo render("error", ["status" => 404, "message" => "内容不存在", "backUrl" => url_to("/")]); exit;', $src);
    $src = str_replace('doAction(', 'do_action(', $src);
    return $src;
}

$maps = [
    'log_list.php' => 'listing.php',
    'echo_log.php' => 'post.php',
    'page.php'     => 'page.php',
    '404.php'      => '404.php',
    'user.php'     => 'profile.php',
];
foreach ($maps as $srcName => $dstName) {
    $p = $desktop . '/' . $srcName;
    if (!is_file($p)) { echo "skip $srcName not found\n"; continue; }
    $src = file_get_contents($p);
    $src = port_src($src);
    // 头部 require View -> header 由 pafish 框架统一加载，保留原 header 引用为 helpers 兼容
    // 将原版的 require_once View::getView('header') 替换为 helpers 已加载，移除重复 require
    // 但保留逻辑：原模板以 View::getView('header') 开头，pafish 已由外层 get_header() 加载，故改为注释
    $src = preg_replace("/require_once __DIR__ \. '\/header\.php';/", "// pafish: header already loaded by get_header()", $src);
    $dst = $themeDir . '/' . $dstName;
    // 若目标是 listing.php / post.php 等由 header/footer 包裹的模板，需去掉原版的 footer 闭合由 get_footer 统一
    // 保留原样，footer.php 会被外层调用
    file_put_contents($dst, $src);
    exec('php -l ' . escapeshellarg($dst) . ' 2>&1', $out, $code);
    echo "$srcName -> $dstName " . implode(';', $out) . " code=$code bytes=" . strlen($src) . "\n";
    if ($code !== 0) exit(1);
}
echo "done templates\n";
