<?php
/**
 * pafish 博客 CMS（PHP 版）安装向导
 * 独立脚本：不依赖 vendor / config.php，纯原生 PHP + PDO
 * 流程：环境检查 → 数据库/站点信息 → 建表 + 种子数据 → 生成 config.php → 完成
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

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
            // FULLTEXT ngram 在老版本不支持等：记录警告继续
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

        // 3. 种子数据
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
        if (file_put_contents($root . '/config.php', $configContent, LOCK_EX) === false) {
            throw new RuntimeException('无法写入 config.php（请检查根目录写权限）');
        }

        return ['ok' => true, 'warnings' => $warnings, 'admin' => $adminUser, 'siteUrl' => $config['site_url']];
    } catch (Throwable $e) {
        return ['errors' => ['安装失败：' . $e->getMessage()]];
    }
}

// ---------- 种子数据（与 Node 版 prisma/seed.ts 一致） ----------
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
    foreach ([['Next.js', 'nextjs'], ['MySQL', 'mysql'], ['设计', 'design']] as [$name, $slug]) {
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
        $insPt->execute([$helloId, $tagIds['nextjs']]);
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
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
         background: #f6f6f4; color: #464646; min-height: 100vh; display: flex;
         justify-content: center; padding: 48px 16px; }
  .wrap { width: 100%; max-width: 640px; }
  h1 { font-size: 1.6rem; letter-spacing: 2px; text-transform: uppercase; color: #5f5f5f; }
  .sub { color: #8f8f8f; font-size: .9rem; margin: 6px 0 28px; }
  .card { background: #fff; border: 1px solid #f2f2f2; border-radius: 14px; padding: 28px 30px;
          box-shadow: 0 1px 2px rgba(16,24,40,.05); }
  .card h2 { font-size: 1.05rem; color: #5f5f5f; margin-bottom: 18px; }
  .row { display: flex; justify-content: space-between; align-items: center; padding: 9px 0;
         border-bottom: 1px dashed #f2f2f2; font-size: .92rem; }
  .row:last-child { border-bottom: none; }
  .ok { color: #2f9e63; font-weight: 600; }
  .bad { color: #b4543f; font-weight: 600; }
  .warn { color: #b98a1f; font-weight: 600; font-size: 13px; }
  .detail { color: #bbbbbb; font-size: .82rem; }
  label { display: block; font-size: .85rem; color: #8f8f8f; margin: 14px 0 6px; }
  input[type=text], input[type=password], input[type=email], input[type=number] {
    width: 100%; padding: 10px 12px; border: 1px solid #e5e5e5; border-radius: 10px;
    font-size: .95rem; outline: none; transition: border .15s, box-shadow .15s;
  }
  input:focus { border-color: #4786d6; box-shadow: 0 0 0 3px rgba(71,134,214,.15); }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 14px; }
  .btn { display: inline-block; margin-top: 22px; padding: 11px 30px; border: none; cursor: pointer;
         border-radius: 999em; background: #424242; color: #fff; font-size: .95rem;
         text-decoration: none; transition: background .15s; }
  .btn:hover { background: #5a5a5a; }
  .btn:disabled { opacity: .5; cursor: not-allowed; }
  .err { background: #fdf3f1; border: 1px solid #f3d4cd; color: #b4543f; border-radius: 10px;
         padding: 12px 16px; font-size: .88rem; margin-bottom: 16px; }
  .warn { background: #fdf8ef; border: 1px solid #f0e2c8; color: #a9772a; border-radius: 10px;
          padding: 12px 16px; font-size: .85rem; margin-top: 16px; white-space: pre-line; }
  .done { text-align: center; padding: 10px 0 4px; }
  .done .big { font-size: 2.4rem; }
  .info { font-size: .88rem; line-height: 1.9; color: #565654; }
  .info b { color: #5f5f5f; }
  .checkbox { display: flex; align-items: center; gap: 8px; margin-top: 16px; font-size: .9rem; color: #565654; }
  .footer { text-align: center; color: #bbbbbb; font-size: .78rem; margin-top: 24px; }
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

if ($installed && $action !== 'install') {
    // 已安装：引导直接使用（不展示安装表单，防误重装）
    echo inst_layout('已安装', <<<HTML
<div class="card">
  <h2>系统已安装</h2>
  <p class="info">检测到 config.php 已存在。请直接访问站点：
  <a href="index.php">前往首页</a>，或 <a href="login.php">登录后台</a>。</p>
  <p class="warn">如需重装：删除根目录 config.php 后重新打开本页（会清空已有数据）。</p>
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
    echo inst_layout('配置', <<<HTML
<form method="post" action="install.php" autocomplete="off">
  <div class="card">
    <h2>数据库连接</h2>
    <div class="grid2">
      <div><label>数据库主机</label><input type="text" name="db_host" value="127.0.0.1" required></div>
      <div><label>端口</label><input type="number" name="db_port" value="3306" required></div>
    </div>
    <label>数据库名</label><input type="text" name="db_name" placeholder="如 pafish（不存在会自动创建）" required>
    <label>数据库用户名</label><input type="text" name="db_user" required>
    <label>数据库密码</label><input type="password" name="db_pass">
  </div>
  <div class="card" style="margin-top:16px">
    <h2>站点信息</h2>
    <label>站点名称</label><input type="text" name="site_name" value="纸鱼博客">
    <label>站点地址（不带结尾斜杠，用于 RSS / 站点地图）</label><input type="text" name="site_url" value="http://{$host}">
    <div class="checkbox"><input type="checkbox" name="pretty_urls" value="1" checked id="pu">
      <label for="pu" style="margin:0">启用伪静态（Apache .htaccess / Nginx try_files 已配置时勾选；否则取消勾选，链接自动用 index.php?p= 形式）</label></div>
  </div>
  <div class="card" style="margin-top:16px">
    <h2>管理员账号</h2>
    <div class="grid2">
      <div><label>用户名</label><input type="text" name="admin_username" value="admin" required></div>
      <div><label>邮箱</label><input type="email" name="admin_email" value="admin@example.com" required></div>
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
        $errs = '<div class="err">' . implode('<br>', array_map('inst_e', $result['errors'])) . '</div>';
        echo inst_layout('安装失败', $errs . '<div class="card"><a class="btn" href="install.php?step=form">返回修改</a></div>');
        exit;
    }
    // 成功
    $warnHtml = '';
    if (!empty($result['warnings'])) {
        $warnHtml = '<div class="warn">部分建表语句未执行（多为全文索引兼容性提示）：<br>' . inst_e(implode("\n", array_slice($result['warnings'], 0, 5))) . '</div>';
    }
    echo inst_layout('安装完成', <<<HTML
<div class="card done">
  <div class="big">🎉</div>
  <h2 style="margin:8px 0 18px">安装完成</h2>
  <p class="info">
    管理员账号：<b>{$result['admin']}</b><br>
    管理员密码：安装时设置的密码<br>
    编辑账号：<b>editor</b> / <b>Editor@12345</b>
  </p>
  {$warnHtml}
  <p class="warn">安全提示：请立即删除根目录的 <b>install.php</b>，防止他人重装系统。</p>
  <a class="btn" href="index.php">前往首页</a>
</div>
HTML);
    exit;
}

// 兜底
inst_redirect('install.php');
