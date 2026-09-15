<?php
/**
 * pafish 博客 CMS（PHP 版）安装向导
 * 独立脚本：不依赖 vendor / config.php，纯原生 PHP + PDO
 * 流程：环境检查 → 数据库/站点信息 → 建表 + 种子数据 → 生成 runtime/config.php → 完成
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$root = __DIR__;

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

function inst_runtime_dir(string $root): string
{
    return $root . '/runtime';
}

/** 新安装的配置固定在可单独授权的运行目录中。 */
function inst_config_path(string $root): string
{
    return inst_runtime_dir($root) . '/config.php';
}

/** 旧版本将配置保存在根目录，升级后仍继续支持。 */
function inst_legacy_config_path(string $root): string
{
    return $root . '/config.php';
}

function inst_resolve_config_path(string $root): ?string
{
    foreach ([inst_config_path($root), inst_legacy_config_path($root)] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function inst_is_installed(string $root): bool
{
    return inst_resolve_config_path($root) !== null;
}

function inst_write_state(string $root, string $phase, string $message = ''): void
{
    @file_put_contents(inst_state_path($root), json_encode([
        'phase' => $phase,
        'message' => $message,
        'at' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Verify the permissions available to the PHP process, rather than relying on
 * is_writable(), which can be inaccurate with ACLs and some FPM deployments.
 * The probe follows the same create/write/rename/delete pattern used for
 * runtime/config.php and removes every temporary file before returning.
 *
 * @return array{0: bool, 1: string}
 */
function inst_probe_writable_dir(string $dir): array
{
    if (!is_dir($dir)) {
        return [false, '目录不存在'];
    }

    try {
        $token = bin2hex(random_bytes(8));
    } catch (Throwable) {
        $token = uniqid('', true);
    }
    $probe = rtrim($dir, '/\\') . '/.pafish-write-check-' . str_replace('.', '', $token);
    $renamed = $probe . '.tmp';
    $error = '';
    set_error_handler(static function (int $severity, string $message) use (&$error): bool {
        $error = $message;
        return true;
    });

    try {
        $bytes = file_put_contents($probe, 'pafish permission check', LOCK_EX);
        if ($bytes === false) {
            return [false, $error !== '' ? $error : '无法创建临时文件'];
        }
        if (!rename($probe, $renamed)) {
            return [false, $error !== '' ? $error : '无法重命名临时文件'];
        }
        if (!unlink($renamed)) {
            return [false, $error !== '' ? $error : '无法删除临时文件'];
        }
        return [true, '可写'];
    } finally {
        restore_error_handler();
        if (is_file($probe)) {
            @unlink($probe);
        }
        if (is_file($renamed)) {
            @unlink($renamed);
        }
    }
}

/** @return array{0: bool, 1: string} */
function inst_write_config(string $path, string $content): array
{
    $tmp = $path . '.tmp';
    $error = '';
    set_error_handler(static function (int $severity, string $message) use (&$error): bool {
        $error = $message;
        return true;
    });

    try {
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            return [false, $error !== '' ? $error : '无法创建配置临时文件'];
        }
        if (!rename($tmp, $path)) {
            return [false, $error !== '' ? $error : '无法将临时文件替换为配置文件'];
        }
        return [true, ''];
    } finally {
        restore_error_handler();
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

// ---------- 环境检查 ----------
function inst_checks(string $root): array
{
    $checks = [];
    $checks[] = inst_check_result(PHP_VERSION_ID >= 80100, 'PHP 版本 ≥ 8.1', '当前 ' . PHP_VERSION);
    foreach (['pdo', 'pdo_mysql', 'gd', 'zip', 'mbstring', 'openssl', 'json', 'fileinfo'] as $ext) {
        $checks[] = inst_check_result(extension_loaded($ext), "PHP 扩展 {$ext}", extension_loaded($ext) ? '已启用' : '未启用');
    }
    $dirs = [
        'runtime' => '运行配置与任务状态',
        'public/uploads' => '媒体上传',
        'backups' => '数据备份',
    ];
    foreach ($dirs as $dir => $purpose) {
        $p = $root . '/' . $dir;
        if (!is_dir($p)) {
            @mkdir($p, 0755, true);
        }
        [$ok, $detail] = inst_probe_writable_dir($p);
        $checks[] = inst_check_result(
            $ok,
            "目录可写 {$dir}/（{$purpose}）",
            $ok ? '可写' : '不可写：' . $detail
        );
    }
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

    $runtimeDir = inst_runtime_dir($root);
    if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0755, true) && !is_dir($runtimeDir)) {
        return ['errors' => ['无法创建 runtime/ 目录；请为 PHP 运行账户授予该目录写入权限']];
    }
    [$runtimeWritable, $runtimeDetail] = inst_probe_writable_dir($runtimeDir);
    if (!$runtimeWritable) {
        return ['errors' => ['runtime/ 目录无法写入配置文件：' . $runtimeDetail]];
    }

    $configPath = inst_config_path($root);
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

        // 2. 已有数据检测（上次安装中断 / 运行配置丢失后重装）：提示但不阻断，种子会跳过已存在记录
        $warnings = [];
        try {
            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                $warnings[] = '检测到该数据库已有 pafish 数据（可能上次安装未完成，或运行配置丢失）：将跳过已存在的种子数据，不会覆盖、不会清空任何内容';
            }
        } catch (PDOException $e) {
            // users 表不存在 → 全新安装
        }

        // 3. 建表
        $warnings = array_merge($warnings, inst_exec_schema($pdo, $root . '/app/install/schema.sql'));

        // 3.5 迁移基线登记：schema.sql 已建全部表，标记 baseline。
        // 后续结构演进新增 migrations/ 下的语义迁移文件。
        require_once $root . '/app/Services/Migrator.php';
        try {
            \Pafish\Services\Migrator::ensureTable($pdo);
            \Pafish\Services\Migrator::markApplied($pdo, 'baseline');
            \Pafish\Services\Migrator::run($pdo, $root . '/migrations');
        } catch (Throwable $e) {
            $warnings[] = '增量迁移失败（不影响基础安装，后续可在升级流程重试）：' . $e->getMessage();
        }

        // 3. 种子数据
        inst_write_state($root, 'seeding');
        $pdo->beginTransaction();
        inst_seed($pdo, $siteName, $adminUser, $adminEmail, $adminPass);
        $pdo->commit();

        // 4. 写入运行配置；根目录无需具备写入权限。
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
        [$configWritten, $configError] = inst_write_config($configPath, $configContent);
        if (!$configWritten) {
            throw new RuntimeException('无法写入 runtime/config.php：' . $configError);
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
// 幂等：已存在的记录一律跳过（重复安装 / 中断重试 / 运行配置丢失后重装均不报错，
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
            $designContent, 'PUBLISHED', date('Y-m-d H:i:s', time() - 86400), $adminId, $lifeId,
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
        'active_plugins' => '["sitemap"]',
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
    $phase = match ($title) {
        '环境检查' => 1,
        '配置' => 2,
        '安装完成', '已安装' => 3,
        default => 1,
    };
    $stepClass = static function (int $step) use ($phase): string {
        return $step < $phase ? 'is-done' : ($step === $phase ? 'is-active' : '');
    };
    return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} · pafish 安装向导</title>
<style>
  :root { --page:#f9fafb; --surface:#ffffff; --soft:#f9fafb; --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --ok:#166534; --bad:#b42318; --warn:#8a5a12; }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; padding:32px 16px; background:var(--page); color:var(--ink); font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif; font-size:14px; line-height:1.55; }
  .wrap { width:100%; max-width:672px; margin:0 auto; }
  .install-shell { overflow:hidden; background:var(--surface); border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(17,24,39,.04); }
  .install-head { padding:28px 32px 24px; background:#f9fafb; border-bottom:1px solid var(--line); }
  .brandline { display:block; }
  .brandmark, .head-meta, .rail-kicker { display:none; }
  h1 { margin:0; color:#111827; font-size:20px; line-height:1.35; font-weight:600; letter-spacing:0; }
  .sub { margin-top:4px; color:#6b7280; font-size:14px; }
  .rail { margin-top:28px; }
  .steps { display:flex; align-items:flex-start; justify-content:space-between; margin:0; padding:0; list-style:none; position:relative; }
  .steps::before { content:""; position:absolute; top:20px; left:0; right:0; height:2px; background:#e5e7eb; }
  .step { display:flex; flex:1 1 0; flex-direction:column; align-items:center; position:relative; color:#9ca3af; text-align:center; }
  .step-no { display:grid; place-items:center; width:40px; height:40px; border:2px solid #e5e7eb; border-radius:50%; background:#fff; color:#9ca3af; font-size:14px; font-weight:500; z-index:1; }
  .step.is-active, .step.is-done { color:#111827; }
  .step.is-active .step-no, .step.is-done .step-no { border-color:#111827; background:#111827; color:#fff; box-shadow:0 0 0 4px #f3f4f6; }
  .step strong { display:block; margin-top:8px; font-size:12px; font-weight:500; }
  .step small { display:none; }
  .content-area { min-width:0; padding:30px 32px 0; }
  .card { padding:0 0 28px; }
  .card + .card { margin-top:28px !important; padding-top:28px; border-top:1px solid #f0f1f3; }
  .card h2 { display:flex; align-items:center; min-height:24px; margin:0 0 22px; color:#1f2937; font-size:16px; font-weight:500; line-height:1.5; }
  .row { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:12px 0; border-bottom:1px solid #f3f4f6; color:#374151; }
  .row:last-child { border-bottom:0; }
  .ok { color:var(--ok); font-weight:500; }
  .bad { color:var(--bad); font-weight:500; }
  .detail { color:#9ca3af; font-size:12px; }
  label { display:block; margin:18px 0 6px; color:#374151; font-size:13px; font-weight:500; }
  input[type=text], input[type=password], input[type=email], input[type=number] { width:100%; min-height:42px; padding:9px 12px; border:1px solid #d1d5db; border-radius:6px; outline:0; background:#fff; color:#111827; font:inherit; transition:border-color .2s ease, box-shadow .2s ease; }
  input:hover { border-color:#9ca3af; }
  input:focus { border-color:#374151; box-shadow:0 0 0 3px #f3f4f6; }
  .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 18px; }
  .btn { display:inline-flex; align-items:center; justify-content:center; min-height:42px; margin-top:24px; padding:0 20px; border:1px solid #111827; border-radius:6px; background:#111827; color:#fff; cursor:pointer; font:500 14px inherit; text-decoration:none; transition:background .2s ease, transform .2s ease; }
  form > .btn, .card > .btn { display:flex; width:max-content; margin-left:auto; }
  .btn:hover { background:#374151; }
  .btn:active { transform:translateY(1px); }
  .btn:focus-visible { outline:3px solid #d1d5db; outline-offset:2px; }
  .btn:disabled { opacity:.45; cursor:not-allowed; }
  .err, .warn { margin:0 0 20px; padding:12px 14px; border:1px solid; border-radius:6px; font-size:13px; line-height:1.65; }
  .err { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
  .warn { border-color:#fde68a; background:#fffbeb; color:var(--warn); white-space:pre-line; }
  .row .warn { display:inline; margin:0; padding:0; border:0; background:none; font-size:13px; }
  .done { padding-bottom:34px; text-align:center; }
  .done .big { display:grid; place-items:center; width:52px; height:52px; margin:0 auto 12px; border-radius:50%; background:#f0fdf4; color:var(--ok); font-size:25px; }
  .done h2 { justify-content:center; margin-bottom:14px; }
  .done .btn { display:inline-flex; width:auto; margin-left:0; }
  .info { color:#4b5563; font-size:14px; line-height:1.85; }
  .info b { color:#111827; }
  .checkbox { display:flex; align-items:flex-start; gap:8px; margin-top:18px; color:#4b5563; font-size:13px; line-height:1.55; }
  .checkbox label { margin:0; font-weight:400; }
  .checkbox input { margin-top:4px; accent-color:#111827; }
  .footer { margin:0; padding:12px 20px; background:#f9fafb; border-top:1px solid var(--line); color:#6b7280; font-size:12px; text-align:center; }
  a { color:#111827; text-underline-offset:2px; }
  @media (max-width:620px) { body { padding:16px 12px; } .install-head { padding:24px 20px 20px; } .content-area { padding:24px 20px 0; } }
  @media (max-width:430px) { .install-head { padding:20px 16px 18px; } .content-area { padding:22px 16px 0; } .step strong { max-width:78px; font-size:11px; } .grid2 { grid-template-columns:1fr; } .row { align-items:flex-start; flex-direction:column; gap:4px; } form > .btn, .card > .btn { width:100%; } .done .btn { width:100%; margin-left:0; } .done .btn + .btn { margin-top:10px; } }
</style>
</head>
<body><div class="wrap"><div class="install-shell">
<header class="install-head"><div class="brandline"><h1>纸鱼博客安装</h1><div class="sub">欢迎使用，请按照步骤完成初始配置。</div></div>
  <nav class="rail" aria-label="安装进度"><ol class="steps">
    <li class="step {$stepClass(1)}"><span class="step-no">1</span><strong>环境检查</strong></li>
    <li class="step {$stepClass(2)}"><span class="step-no">2</span><strong>填写配置</strong></li>
    <li class="step {$stepClass(3)}"><span class="step-no">3</span><strong>完成安装</strong></li>
  </ol></nav>
</header>
<main class="content-area">{$extra}{$inner}</main>
<footer class="footer">pafish 安装向导 · 安装完成后请删除 install.php</footer>
</div></div></body></html>
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
    $configPath = inst_resolve_config_path($root);
    if ($configPath !== null) {
        $cfg = (array) @require $configPath;
        $pretty = (bool) ($cfg['pretty_urls'] ?? true);
    }
    return [
        'home'  => './',
        'admin' => $pretty ? './admin/' : './index.php?p=admin',
    ];
}

$installed = inst_is_installed($root);

if ($installed && $action !== 'install') {
    // 已安装：引导直接使用（不展示安装表单，防误重装）
    $links = inst_links($root);
    $configPath = inst_resolve_config_path($root);
    $configLabel = $configPath === inst_legacy_config_path($root) ? '根目录 config.php（旧版兼容）' : 'runtime/config.php';
    echo inst_layout('已安装', <<<HTML
<div class="card">
  <h2>系统已安装</h2>
  <p class="info">检测到 {$configLabel}。请直接访问站点：
  <a href="{$links['home']}">前往首页</a>，或 <a href="{$links['admin']}">登录后台</a>。</p>
  <p class="warn">如需重装：请先完整备份数据库，再删除上述配置文件后重新打开本页。安装向导不会自动清空已有数据；如需全新安装，请手动使用新的数据库或清理旧表。</p>
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
        echo inst_layout('已安装', '<div class="card"><h2>系统已安装</h2><p class="info">运行配置已存在，请勿重复安装。如确认重装请先完整备份数据库，再删除该配置文件。</p></div>');
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
    管理员密码：安装时设置的密码
  </p>
  {$warnHtml}
  <p class="warn"><strong>下一步</strong><br>请删除根目录的 <b>install.php</b>，再登录后台完成站点配置。</p>
  <a class="btn" href="{$links['admin']}">进入后台</a>
  <a class="btn" href="{$links['home']}" style="margin-left:8px;background:#fff;color:var(--ink)">访问首页</a>
</div>
HTML);
    exit;
}

// 兜底
inst_redirect('install.php');
