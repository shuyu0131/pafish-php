<?php
/**
 * pafish 博客 CMS（PHP 版）安装向导
 * 独立脚本：不依赖 vendor / config.php，纯原生 PHP + PDO
 * 流程：环境检查 → 数据库/站点信息 → 建表 + 种子数据 → 生成 config.php → 完成
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$root = __DIR__;
$installed = is_file($root . '/config.php');

// ---------- 工具 ----------
function inst_e(mixed $s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function inst_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function inst_check_result(bool $ok, string $label, string $detail = '', bool $warn = false): array
{
    return ['ok' => $ok, 'label' => $label, 'detail' => $detail, 'warn' => $warn];
}

function inst_state_path(string $root): string
{
    return $root . '/runtime/install-state.json';
}

function inst_write_state(string $root, string $phase, string $message = ''): void
{
    @file_put_contents(inst_state_path($root), json_encode([
        'phase' => $phase,
        'message' => $message,
        'at' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// ---------- 环境检查 ----------
function inst_checks(string $root): array
{
    $checks = [];
    $checks[] = inst_check_result(PHP_VERSION_ID >= 80100, 'PHP 版本 ≥ 8.1', '当前 ' . PHP_VERSION);
    foreach (['pdo', 'pdo_mysql', 'gd', 'zip', 'mbstring', 'openssl', 'json', 'fileinfo'] as $ext) {
        $checks[] = inst_check_result(extension_loaded($ext), "PHP 扩展 {$ext}", extension_loaded($ext) ? '已启用' : '未启用');
    }
    $dirs = ['runtime', 'public/uploads', 'backups'];
    foreach ($dirs as $dir) {
        $p = $root . '/' . $dir;
        if (!is_dir($p)) {
            @mkdir($p, 0755, true);
        }
        $checks[] = inst_check_result(is_dir($p) && is_writable($p), "目录可写 {$dir}/", is_writable($p) ? '可写' : '不可写（请检查权限）');
    }
    $checks[] = inst_check_result(is_writable($root), '根目录可写（生成 config.php）', is_writable($root) ? '可写' : '不可写');
    // 上传限制（警告级，不阻塞安装）：主题/插件 zip 包上限 10MB，post_max_size 需 ≥ 16M 才能正常上传
    $postMax = (int) (ini_get('post_max_size') ?: 0);
    $uploadMax = (int) (ini_get('upload_max_filesize') ?: 0);
    $checks[] = inst_check_result(
        $postMax >= 16,
        'PHP post_max_size ≥ 16M（应用商店/主题安装）',
        '当前 ' . ini_get('post_max_size') . '（修改 php.ini 后需重启 Web 服务）',
        $postMax < 10
    );
    $checks[] = inst_check_result(
        $uploadMax >= 12,
        'PHP upload_max_filesize ≥ 12M（应用商店/主题安装）',
        '当前 ' . ini_get('upload_max_filesize') . '（修改 php.ini 后需重启 Web 服务）',
        $uploadMax < 10
    );
    return $checks;
}

// ---------- schema 语句拆分执行 ----------
function inst_exec_schema(PDO $pdo, string $sqlFile): array
{
    $sql = (string) file_get_contents($sqlFile);
    // 去掉行注释，按分号拆分为独立语句（无存储过程，安全）
    $lines = [];
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $lines[] = $line;
    }
    $warnings = [];
    foreach (explode(';', implode("\n", $lines)) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // 重复安装时全文索引已存在（MySQL 1061），视为已完成；
            // 旧版 MySQL 不支持 ngram 等其它错误继续记录警告。
            if (($e->errorInfo[1] ?? null) === 1061 && stripos($stmt, 'ft_posts_search') !== false) {
                continue;
            }
            $warnings[] = substr($stmt, 0, 60) . '... → ' . $e->getMessage();
        }
    }
    return $warnings;
}

// ---------- 执行安装 ----------
function inst_run(array $post, string $root): array
{
    $errors = [];

    $db = [
        'host'     => trim($post['db_host'] ?? '127.0.0.1'),
        'port'     => (int) ($post['db_port'] ?? 3306),
        'database' => trim($post['db_name'] ?? ''),
        'username' => trim($post['db_user'] ?? ''),
        'password' => (string) ($post['db_pass'] ?? ''),
    ];
    $siteName = trim($post['site_name'] ?? '');
    $siteUrl  = rtrim(trim($post['site_url'] ?? ''), '/');
    $adminUser = trim($post['admin_username'] ?? '');
    $adminEmail = trim($post['admin_email'] ?? '');
    $adminPass = (string) ($post['admin_password'] ?? '');
    $adminPass2 = (string) ($post['admin_password2'] ?? '');
    $prettyUrls = ($post['pretty_urls'] ?? '1') === '1';

    if ($db['database'] === '' || $adminUser === '' || $adminEmail === '' || $adminPass === '') {
        $errors[] = '请填写全部必填项';
    }
    if (mb_strlen($adminPass) < 8) {
        $errors[] = '管理员密码至少 8 位';
    }
    if ($adminPass !== $adminPass2) {
        $errors[] = '两次输入的密码不一致';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = '管理员邮箱格式不正确';
    }
    if ($errors) {
        return ['errors' => $errors];
    }

    $configPath = $root . '/config.php';
    $configTmp = $configPath . '.tmp';
    inst_write_state($root, 'starting');
    try {
        // 1. 连接（先建库）
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']);
        $pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        // 会话时区对齐应用时区（否则 CURRENT_TIMESTAMP 默认值按 MySQL 时区存，混用两种墙钟）
        $offset = (new DateTimeZone((string) ($db['timezone'] ?? 'Asia/Shanghai')))->getOffset(new DateTimeImmutable());
        $sign = $offset >= 0 ? '+' : '-';
        $pdo->exec(sprintf(
            "SET time_zone = '%s%02d:%02d'",
            $sign,
            intdiv(abs($offset), 3600),
            intdiv(abs($offset) % 3600, 60)
        ));
        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            str_replace('`', '', $db['database'])
        ));
        $pdo->exec('USE `' . str_replace('`', '', $db['database']) . '`');

        // 2. 已有数据检测（上次安装中断 / config.php 丢失后重装）：提示但不阻断，种子会跳过已存在记录
        $warnings = [];
        try {
            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                $warnings[] = '检测到该数据库已有 pafish 数据（可能上次安装未完成，或 config.php 丢失）：将跳过已存在的种子数据，不会覆盖、不会清空任何内容';
            }
        } catch (PDOException $e) {
            // users 表不存在 → 全新安装
        }

        // 3. 建表
        $warnings = array_merge($warnings, inst_exec_schema($pdo, $root . '/app/install/schema.sql'));

        // 3.5 迁移基线登记（v0.1.6 起）：schema.sql 已建全部表，标记 0001_initial
        // 为已应用基线；结构演进优先并入 schema.sql 与 0001 基线（随新版本发布），必要时使用 migrations/ 下增量迁移（YYYYMMDD_语义名）
        require_once $root . '/app/Services/Migrator.php';
        try {
            \Pafish\Services\Migrator::ensureTable($pdo);
            \Pafish\Services\Migrator::markApplied($pdo, '0001_initial');
            \Pafish\Services\Migrator::run($pdo, $root . '/migrations');
        } catch (Throwable $e) {
            $warnings[] = '增量迁移失败（不影响基础安装，后续可在升级流程重试）：' . $e->getMessage();
        }

        // 3. 种子数据
        inst_write_state($root, 'seeding');
        $pdo->beginTransaction();
        inst_seed($pdo, $siteName, $adminUser, $adminEmail, $adminPass);
        $pdo->commit();

        // 4. 写 config.php
        $config = [
            'db' => $db,
            'auth_secret' => bin2hex(random_bytes(32)),
            'site_url' => $siteUrl !== '' ? $siteUrl : 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'pretty_urls' => $prettyUrls,
            'debug' => false,
            'timezone' => 'Asia/Shanghai',
        ];
        $exported = var_export($config, true);
        $configContent = "<?php\n\n// 由安装向导生成（" . date('Y-m-d H:i:s') . "）。如需自定义请参考 config.example.php\nreturn {$exported};\n";
        inst_write_state($root, 'writing_config');
        if (file_put_contents($configTmp, $configContent, LOCK_EX) === false || !@rename($configTmp, $configPath)) {
            @unlink($configTmp);
            throw new RuntimeException('无法写入 config.php（请检查根目录写权限）');
        }

        @unlink(inst_state_path($root));
        return ['ok' => true, 'warnings' => $warnings, 'admin' => $adminUser, 'siteUrl' => $config['site_url']];
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @unlink($configTmp);
        inst_write_state($root, 'failed', $e->getMessage());
        return ['errors' => ['安装失败：' . $e->getMessage()]];
    }
}

// ---------- 安装种子数据 ----------
// 幂等：已存在的记录一律跳过（重复安装 / 中断重试 / config.php 丢失后重装均不报错，
// 不会覆盖用户改过的数据，缺什么补什么）
function inst_seed(PDO $pdo, string $siteName, string $adminUser, string $adminEmail, string $adminPass): void
{
    $hash = static fn (string $pw): string => password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);

    // 用户（已存在则跳过，保留原密码）
    $selUser = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $selUser->execute([$adminUser]);
    $adminId = (int) $selUser->fetchColumn();
    if ($adminId === 0) {
        $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute([$adminUser, $adminEmail, $hash($adminPass), 'ADMIN']);
        $adminId = (int) $pdo->lastInsertId();
    }
    $selUser->execute(['editor']);
    $editorId = (int) $selUser->fetchColumn();
    if ($editorId === 0) {
        $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute(['editor', 'editor@pafish.cn', $hash('Editor@12345'), 'EDITOR']);
        $editorId = (int) $pdo->lastInsertId();
    }

    // 分类（按 slug 判重）
    $selCat = $pdo->prepare('SELECT id FROM categories WHERE slug = ?');
    $selCat->execute(['tech']);
    $techId = (int) $selCat->fetchColumn();
    if ($techId === 0) {
        $pdo->prepare('INSERT INTO categories (name, slug, description) VALUES (?, ?, ?)')
            ->execute(['技术', 'tech', '编程、架构与工程实践']);
        $techId = (int) $pdo->lastInsertId();
    }
    $selCat->execute(['life']);
    $lifeId = (int) $selCat->fetchColumn();
    if ($lifeId === 0) {
        $pdo->prepare('INSERT INTO categories (name, slug, description) VALUES (?, ?, ?)')
            ->execute(['生活', 'life', '日常记录与思考']);
        $lifeId = (int) $pdo->lastInsertId();
    }

    // 标签（按 slug 判重）
    $selTag = $pdo->prepare('SELECT id FROM tags WHERE slug = ?');
    $tagIds = [];
    foreach ([['PHP', 'php'], ['MySQL', 'mysql'], ['设计', 'design']] as [$name, $slug]) {
        $selTag->execute([$slug]);
        $id = (int) $selTag->fetchColumn();
        if ($id === 0) {
            $pdo->prepare('INSERT INTO tags (name, slug) VALUES (?, ?)')->execute([$name, $slug]);
            $id = (int) $pdo->lastInsertId();
        }
        $tagIds[$slug] = $id;
    }

    // 示例文章（按 slug 判重；已存在则整篇跳过——post_tags 是复合主键，仅新文章建立标签关联）
    $helloContent = "# 欢迎来到纸鱼博客\n\n这是一篇由种子脚本创建的示例文章。\n\n## 功能一览\n\n- **Markdown 编辑**：后台使用 Markdown 编辑器\n- **分类与标签**：灵活组织内容\n- **全文搜索**：基于 MySQL ngram 中文分词\n- **评论审核**：游客评论需审核后展示\n\n```ts\nconsole.log(\"Hello, Pafish Blog!\");\n```\n\n感谢阅读！";
    $designContent = "# 极简设计随笔\n\n好的设计是不打扰读者的设计。\n\n## 留白\n\n留白不是浪费，而是呼吸。\n\n## 对比\n\n对比制造层次，层次引导阅读。\n\n> 少即是多。 —— Ludwig Mies van der Rohe";

    $insPost = $pdo->prepare(
        'INSERT INTO posts (title, slug, excerpt, content, status, published_at, author_id, category_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insPt = $pdo->prepare('INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)');
    $selPost = $pdo->prepare('SELECT id FROM posts WHERE slug = ?');

    $selPost->execute(['hello-pafish']);
    $helloId = (int) $selPost->fetchColumn();
    if ($helloId === 0) {
        $insPost->execute([
            '你好，纸鱼博客', 'hello-pafish',
            '欢迎来到纸鱼博客！这是一篇示例文章，介绍本博客系统的能力。',
            $helloContent, 'PUBLISHED', date('Y-m-d H:i:s'), $adminId, $techId,
        ]);
        $helloId = (int) $pdo->lastInsertId();
        $insPt->execute([$helloId, $tagIds['php']]);
        $insPt->execute([$helloId, $tagIds['mysql']]);
    }

    $selPost->execute(['minimalist-design-notes']);
    $designId = (int) $selPost->fetchColumn();
    if ($designId === 0) {
        $insPost->execute([
            '极简设计随笔', 'minimalist-design-notes',
            '关于极简主义设计的一些思考：留白、对比与克制。',
            $designContent, 'PUBLISHED', date('Y-m-d H:i:s', time() - 86400), $editorId, $lifeId,
        ]);
        $designId = (int) $pdo->lastInsertId();
        $insPt->execute([$designId, $tagIds['design']]);
    }

    $selPost->execute(['draft-example']);
    if ((int) $selPost->fetchColumn() === 0) {
        $insPost->execute([
            '一篇未完成的草稿', 'draft-example',
            '这篇文章还在写作中……',
            '草稿内容，尚未发布。', 'DRAFT', null, $adminId, $techId,
        ]);
    }

    // 默认设置（缺失才补，已存在的键不覆盖）
    $selSetting = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE `key` = ?');
    $insSetting = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)');
    $defaults = [
        'site_name' => $siteName !== '' ? $siteName : '纸鱼博客',
        'site_subtitle' => '记录技术、设计与生活',
        'site_description' => '纸鱼博客是一个极简风格的博客系统',
        'comments_enabled' => 'true',
        'comments_need_review' => 'true',
        'posts_per_page' => '10',
        'allow_registration' => 'true',
        'active_theme' => 'default',
    ];
    foreach ($defaults as $k => $v) {
        $selSetting->execute([$k]);
        if ((int) $selSetting->fetchColumn() === 0) {
            $insSetting->execute([$k, $v]);
        }
    }

    // 关于页
    $selPage = $pdo->prepare('SELECT id FROM pages WHERE slug = ?');
    $selPage->execute(['about']);
    if ((int) $selPage->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO pages (title, slug, content, status, published_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([
                '关于', 'about',
                "纸鱼博客是一个极简风格的博客系统，支持 Markdown 写作、全文搜索与评论审核。\n\n在这里记录技术、设计与生活的点滴。",
                'PUBLISHED', date('Y-m-d H:i:s'),
            ]);
    }

    // 默认导航（同 label+url 判重）
    $selNav = $pdo->prepare('SELECT COUNT(*) FROM nav_items WHERE label = ? AND url = ?');
    $insNav = $pdo->prepare('INSERT INTO nav_items (label, url, sort_order) VALUES (?, ?, ?)');
    foreach ([['首页', '/', 1], ['归档', '/archives', 2], ['关于', '/pages/about', 3]] as [$label, $url, $order]) {
        $selNav->execute([$label, $url]);
        if ((int) $selNav->fetchColumn() === 0) {
            $insNav->execute([$label, $url, $order]);
        }
    }

    // 默认侧边栏组件（同 type+sort_order 判重）
    $selWidget = $pdo->prepare('SELECT COUNT(*) FROM widgets WHERE type = ? AND sort_order = ?');
    $insWidget = $pdo->prepare('INSERT INTO widgets (type, sort_order) VALUES (?, ?)');
    foreach ([['categories', 1], ['recent_posts', 2], ['tags', 3]] as [$type, $order]) {
        $selWidget->execute([$type, $order]);
        if ((int) $selWidget->fetchColumn() === 0) {
            $insWidget->execute([$type, $order]);
        }
    }
}

// ---------- 视图 ----------
function inst_layout(string $title, string $inner, string $extra = ''): string
{
    return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} · pafish 安装向导</title>
<style>
  :root { --paper:#f4f1eb; --surface:#fffdfa; --ink:#252522; --muted:#7d7a72; --line:#ded9cf; --accent:#b64b36; --ok:#2f7657; }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: "Segoe UI", "Microsoft YaHei", sans-serif; background:var(--paper); color:var(--ink); min-height:100vh; padding:48px 18px; }
  .wrap { width:100%; max-width:720px; margin:0 auto; }
  h1 { font-family: Georgia, "Times New Roman", serif; font-size:2rem; font-weight:500; letter-spacing:-.04em; }
  .sub { color:var(--muted); font-size:.82rem; letter-spacing:.08em; margin:6px 0 30px; }
  .card { background:var(--surface); border:1px solid var(--line); border-radius:4px; padding:28px 32px; box-shadow:0 3px 12px rgba(55,45,34,.035); }
  .card + .card { margin-top:14px !important; }
  .card h2 { font-family:Georgia, "Times New Roman", serif; font-size:1.15rem; font-weight:500; margin-bottom:20px; padding-bottom:12px; border-bottom:1px solid #eeeae3; }
  .row { display:flex; justify-content:space-between; align-items:center; padding:11px 0; border-bottom:1px solid #eeeae3; font-size:.9rem; }
  .row:last-child { border-bottom:0; }
  .ok { color:var(--ok); font-weight:600; }
  .bad { color:var(--accent); font-weight:600; }
  .detail { color:#aaa59b; font-size:.78rem; }
  label { display:block; color:var(--muted); font-size:.78rem; letter-spacing:.02em; margin:16px 0 7px; }
  input[type=text], input[type=password], input[type=email], input[type=number] { width:100%; padding:11px 12px; color:var(--ink); background:#fff; border:1px solid #d8d3ca; border-radius:3px; font-size:.92rem; outline:0; transition:border-color .15s, box-shadow .15s; }
  input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(182,75,54,.12); }
  .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 18px; }
  .btn { display:inline-block; margin-top:22px; padding:11px 24px; border:1px solid var(--ink); border-radius:3px; cursor:pointer; background:var(--ink); color:#fff; font-size:.88rem; text-decoration:none; transition:background .15s; }
  .btn:hover { background:#44443e; }
  .btn:disabled { opacity:.45; cursor:not-allowed; transform:none; }
  .err { background:#fff4f0; border:1px solid #e7b9aa; color:#9c3e2d; border-radius:3px; padding:12px 14px; font-size:.86rem; margin-bottom:14px; }
  .warn { background:#fbf7ed; border:1px solid #e5d9b9; color:#866b32; border-radius:3px; padding:12px 14px; font-size:.84rem; margin-top:14px; white-space:pre-line; }
  .done { text-align:center; padding:14px 0 6px; }
  .done .big { font-size:2rem; }
  .info { font-size:.88rem; line-height:1.9; color:#5f5c55; }
  .info b { color:var(--ink); }
  .checkbox { display:flex; align-items:center; gap:8px; margin-top:17px; font-size:.84rem; color:#5f5c55; }
  .checkbox label { margin:0; }
  .footer { text-align:center; color:#aaa59b; font-size:.74rem; margin-top:22px; }
  @media (max-width:600px) { body { padding:28px 12px; } .card { padding:22px 18px; } .grid2 { grid-template-columns:1fr; } }
</style>
</head>
<body><div class="wrap">
<h1>pafish</h1>
<div class="sub">博客 CMS 安装向导</div>
{$inner}
<div class="footer">pafish · 轻量博客系统 · 安装完成请删除 install.php</div>
</div></body></html>
HTML;
}

// ---------- 分发 ----------
$action = $_GET['step'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'install' : 'check');

// 入口链接：首页用相对 ./（伪静态下由 Nginx try_files / 目录索引执行 index.php，
// 非伪静态下由 DirectoryIndex 执行，子目录部署同样正确——不能用写死的 index.php，
// 部分 Nginx 对 .php 直连未配置 fastcgi 会 404）；后台按伪静态开关区分路径
function inst_links(string $root): array
{
    $pretty = true;
    if (is_file($root . '/config.php')) {
        $cfg = (array) @require $root . '/config.php';
        $pretty = (bool) ($cfg['pretty_urls'] ?? true);
    }
    return [
        'home'  => './',
        'admin' => $pretty ? './admin/' : './index.php?p=admin',
    ];
}

if ($installed && $action !== 'install') {
    // 已安装：引导直接使用（不展示安装表单，防误重装）
    $links = inst_links($root);
    echo inst_layout('已安装', <<<HTML
<div class="card">
  <h2>系统已安装</h2>
  <p class="info">检测到 config.php 已存在。请直接访问站点：
  <a href="{$links['home']}">前往首页</a>，或 <a href="{$links['admin']}">登录后台</a>。</p>
  <p class="warn">如需重装：请先完整备份数据库，再删除根目录 config.php 后重新打开本页。安装向导不会自动清空已有数据；如需全新安装，请手动使用新的数据库或清理旧表。</p>
</div>
HTML);
    exit;
}

if ($action === 'check') {
    $checks = inst_checks($root);
    $allOk = true;
    foreach ($checks as $c) {
        if (!$c['ok'] && !($c['warn'] ?? false)) {
            $allOk = false;
        }
    }
    $rows = '';
    foreach ($checks as $c) {
        if ($c['ok']) {
            $cell = '<span class="ok">✓</span>';
        } elseif (!empty($c['warn'])) {
            $cell = '<span class="warn">⚠ ' . inst_e($c['detail']) . '</span>';
        } else {
            $cell = '<span class="bad">✗ ' . inst_e($c['detail']) . '</span>';
        }
        $rows .= '<div class="row"><span>' . inst_e($c['label']) . '</span>' . $cell . '</div>';
    }
    $next = $allOk ? '<a class="btn" href="install.php?step=form">下一步：填写配置</a>'
        : '<button class="btn" disabled>请先解决以上问题</button>';
    echo inst_layout('环境检查', <<<HTML
<div class="card">
  <h2>环境检查</h2>
  {$rows}
  {$next}
</div>
HTML);
    exit;
}

if ($action === 'form' && !$installed) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $old = $_SESSION['install_old_input'] ?? [];
    $errorHtml = (string)($_SESSION['install_error'] ?? '');
    unset($_SESSION['install_old_input'], $_SESSION['install_error']);
    $old = is_array($old) ? $old : [];
    $field = static fn(string $key, string $default = ''): string => inst_e(array_key_exists($key, $old) ? $old[$key] : $default);
    $prettyChecked = !array_key_exists('pretty_urls', $old) || (string)$old['pretty_urls'] === '1' ? ' checked' : '';
    $stateNotice = '';
    $stateRaw = @file_get_contents(inst_state_path($root));
    $state = is_string($stateRaw) ? json_decode($stateRaw, true) : null;
    if (is_array($state) && ($state['phase'] ?? '') === 'failed') {
        $stateNotice = '<div class="warn">检测到上次安装未完成。数据库中的已存在内容会保留，修正配置后可以继续重试。上次错误：' . inst_e((string)($state['message'] ?? '未知错误')) . '</div>';
    }
    echo inst_layout('配置', <<<HTML
{$errorHtml}
{$stateNotice}
<form method="post" action="install.php" autocomplete="off">
  <div class="card">
    <h2>数据库连接</h2>
    <div class="grid2">
      <div><label>数据库主机</label><input type="text" name="db_host" value="{$field('db_host', '127.0.0.1')}" required></div>
      <div><label>端口</label><input type="number" name="db_port" value="{$field('db_port', '3306')}" required></div>
    </div>
    <label>数据库名</label><input type="text" name="db_name" value="{$field('db_name')}" placeholder="如 pafish（不存在会自动创建）" required>
    <label>数据库用户名</label><input type="text" name="db_user" value="{$field('db_user')}" required>
    <label>数据库密码</label><input type="password" name="db_pass">
  </div>
  <div class="card" style="margin-top:16px">
    <h2>站点信息</h2>
    <label>站点名称</label><input type="text" name="site_name" value="{$field('site_name', '纸鱼博客')}">
    <label>站点地址（不带结尾斜杠，用于 RSS / 站点地图）</label><input type="text" name="site_url" value="{$field('site_url', 'http://' . $host)}">
    <div class="checkbox"><input type="checkbox" name="pretty_urls" value="1"{$prettyChecked} id="pu">
      <label for="pu" style="margin:0">启用伪静态（Apache .htaccess / Nginx try_files 已配置时勾选；否则取消勾选，链接自动用 index.php?p= 形式）</label></div>
  </div>
  <div class="card" style="margin-top:16px">
    <h2>管理员账号</h2>
    <div class="grid2">
      <div><label>用户名</label><input type="text" name="admin_username" value="{$field('admin_username', 'admin')}" required></div>
      <div><label>邮箱</label><input type="email" name="admin_email" value="{$field('admin_email', 'admin@example.com')}" required></div>
    </div>
    <div class="grid2">
      <div><label>密码（至少 8 位）</label><input type="password" name="admin_password" required></div>
      <div><label>确认密码</label><input type="password" name="admin_password2" required></div>
    </div>
  </div>
  <button class="btn" type="submit">开始安装</button>
</form>
HTML);
    exit;
}

if ($action === 'install') {
    if ($installed) {
        echo inst_layout('已安装', '<div class="card"><h2>系统已安装</h2><p class="info">config.php 已存在，请勿重复安装。如确认重装请先删除该文件。</p></div>');
        exit;
    }
    $result = inst_run($_POST, $root);
    if (!empty($result['errors'])) {
        $keep = $_POST;
        unset($keep['db_pass'], $keep['admin_password'], $keep['admin_password2']);
        $_SESSION['install_old_input'] = $keep;
        $errs = '<div class="err"><strong>安装未完成</strong><br>' . implode('<br>', array_map('inst_e', $result['errors'])) . '<br><span class="detail">已填写的非敏感信息已保留，密码字段需要重新输入。</span></div>';
        $_SESSION['install_error'] = $errs;
        inst_redirect('install.php?step=form');
        exit;
    }
    // 成功
    $warnHtml = '';
    if (!empty($result['warnings'])) {
        $warnHtml = '<div class="warn"><strong>安装已完成，但有兼容性提示</strong><br>' . inst_e(implode("\n", array_slice($result['warnings'], 0, 5))) . '</div>';
    }
    $links = inst_links($root);
    echo inst_layout('安装完成', <<<HTML
<div class="card done">
  <div class="big ok">✓</div>
  <p class="detail" style="margin:8px 0 6px; letter-spacing:.08em">INSTALLATION COMPLETE</p>
  <h2 style="margin:0 0 18px">安装完成</h2>
  <p class="info">
    管理员账号：<b>{$result['admin']}</b><br>
    管理员密码：安装时设置的密码<br>
    编辑账号：<b>editor</b> / <b>Editor@12345</b>
  </p>
  {$warnHtml}
  <p class="warn"><strong>下一步</strong><br>请删除根目录的 <b>install.php</b>，再登录后台修改默认编辑账号密码。</p>
  <a class="btn" href="{$links['admin']}">进入后台</a>
  <a class="btn" href="{$links['home']}" style="margin-left:8px;background:#fff;color:var(--ink)">访问首页</a>
</div>
HTML);
    exit;
}

// 兜底
inst_redirect('install.php');
