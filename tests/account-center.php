<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require PAFISH_ROOT . '/app/Core/helpers.php';
require __DIR__ . '/Fixtures/MySql.php';

use Pafish\Core\{Auth, Config, DB, Hooks, RateLimiter, RedirectException, Session, SqlScript, Url};
use Pafish\Services\{Points, Settings, Theme};
use Pafish\Admin\{AccountController, DashboardController, MicroController, ProfileController};
use Pafish\Http\UserController;
use Pafish\Tests\MySql;
use Slim\Psr7\{Response, UploadedFile};
use Slim\Psr7\Factory\ServerRequestFactory;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
Session::start(); Url::init('/index.php');
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $passed++; echo 'PASS ' . $label . PHP_EOL;
};
$login = static function (int $id): void {
    Session::set('user_id', $id);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
};
$factory = new ServerRequestFactory();
$get = $factory->createServerRequest('GET', '/admin');
$post = $factory->createServerRequest('POST', '/admin')->withHeader('X-Requested-With', 'XMLHttpRequest');
$account = new AccountController();
$snapshots = in_array('--snapshots', $argv, true);
$snapshot = static function (string $name, string $html) use ($snapshots): void {
    if (!$snapshots) return;
    $dir = PAFISH_ROOT . '/.design-review/account-center';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/' . $name . '.html', $html);
};
$themeName = 'qa_account_' . bin2hex(random_bytes(4));
$themeDir = PAFISH_ROOT . '/themes/' . $themeName;
$off = null;
try {
    foreach (SqlScript::split(file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $sql) $pdo->query($sql);
    $hash = password_hash('fixture-password', PASSWORD_BCRYPT);
    foreach ([1 => 'ADMIN', 900000001 => 'EDITOR', 900000002 => 'USER', 900000003 => 'EDITOR'] as $id => $role) {
        DB::execute('INSERT INTO users(id,username,nickname,email,password_hash,role) VALUES(?,?,?,?,?,?)',
            [$id, 'qa-' . $id, $role === 'USER' ? '测试读者' : '测试编辑', $id . '@invalid.test', $hash, $role]);
    }
    Settings::setMany(['active_theme' => 'default', 'site_name' => '纸鱼博客', 'role_capabilities' => '{"USER":["settings.manage"],"EDITOR":[]}']);
    $check('Fixed role defaults ignore legacy role JSON', Auth::capabilities('EDITOR') === Auth::defaultRoleCapabilities()['EDITOR']
        && Auth::capabilities('USER') === ['dashboard.view'] && Auth::capabilities('ADMIN') === Auth::CAPABILITIES);
    foreach ([1 => ['公开文章', 'PUBLISHED', '2020-01-01', null, 900000001], 2 => ['秘密草稿', 'DRAFT', null, null, 900000001],
        3 => ['未来文章', 'PUBLISHED', '2099-01-01', null, 900000001], 4 => ['回收站文章', 'PUBLISHED', '2020-01-01', '2020-02-01', 900000001],
        5 => ['其他编辑的文章', 'PUBLISHED', '2020-01-01', null, 900000003]] as $id => [$title, $status, $published, $deleted, $author]) {
        DB::execute('INSERT INTO posts(id,title,slug,content,status,published_at,deleted_at,author_id) VALUES(?,?,?,?,?,?,?,?)',
            [$id, $title, 'account-' . $id, '内容', $status, $published, $deleted, $author]);
        foreach (['like', 'favorite'] as $kind) DB::execute('INSERT INTO post_reactions(user_id,post_id,kind) VALUES(?,?,?)', [900000002, $id, $kind]);
        DB::execute('INSERT INTO comments(post_id,user_id,author_name,author_email,content,status) VALUES(?,?,?,?,?,?)',
            [$id, 900000002, '测试读者', 'reader@invalid.test', $id === 1 ? '<script>private()</script>' : '隐藏评论', 'PENDING']);
    }
    for ($i = 0; $i < 12; $i++) DB::execute("INSERT INTO comments(post_id,user_id,author_name,author_email,content,status) VALUES(1,900000002,'reader','reader@invalid.test',?,'APPROVED')", ['我的评论 ' . $i]);
    DB::execute("INSERT INTO comments(post_id,user_id,author_name,author_email,content,status) VALUES(1,900000003,'other','other@invalid.test','OTHER-USER-SECRET','APPROVED')");
    Points::adjust(900000002, 30, '测试积分');
    Points::adjust(900000003, 999, 'OTHER-USER-POINTS');
    $login(900000002);
    $_SERVER['REQUEST_URI'] = '/admin';
    $html = (string) (new DashboardController())->dashboard($get, new Response())->getBody();
    $check('Ordinary /admin uses the shared admin shell without extra capabilities', str_contains($html, 'class="admin-sidebar"')
        && str_contains($html, '/admin?section=comments') && !str_contains($html, '/admin/posts/new')
        && !str_contains($html, '>文章管理<'));
    $check('Account sections and profile are in the sidebar', str_contains($html, '>我的评论<')
        && str_contains($html, '>我的收藏<') && str_contains($html, '>个人资料<')
        && !str_contains($html, 'admin-account-tabs'));
    $snapshot('member', $html);
    $html = (string) $account->index($get->withQueryParams(['section' => 'comments', 'per' => 10, 'page' => 99]), new Response())->getBody();
    $check('Own comments paginate and escape user text', str_contains($html, '共 14 条') && !str_contains($html, '<script>private()')
        && str_contains($html, '&lt;script&gt;private()&lt;/script&gt;') && !str_contains($html, 'OTHER-USER-SECRET'));
    $check('Hidden content titles are not exposed in comments', !str_contains($html, '秘密草稿') && !str_contains($html, '未来文章') && !str_contains($html, '回收站文章'));
    $snapshot('comments', $html);
    foreach (['favorites', 'likes'] as $section) {
        $html = (string) $account->index($get->withQueryParams(compact('section')), new Response())->getBody();
        $check('Public content only in ' . $section, str_contains($html, '共 2 条') && !str_contains($html, '秘密草稿') && !str_contains($html, '未来文章') && !str_contains($html, '回收站文章'));
    }
    $html = (string) $account->index($get->withQueryParams(['section' => 'points']), new Response())->getBody();
    $check('Points remain scoped to the current account', str_contains($html, '+30') && !str_contains($html, 'OTHER-USER-POINTS'));
    $profile = new ProfileController();
    $_SERVER['REQUEST_URI'] = '/admin/profile';
    $html = (string) $profile->index($get, new Response())->getBody();
    $check('Member profile uses the shared shell and dedicated avatar upload', str_contains($html, 'class="admin-sidebar"') && str_contains($html, '\/admin\/profile\/avatar'));
    $snapshot('profile', $html);
    $req = $post->withParsedBody(['username' => 'qa-reader', 'nickname' => '读者', 'email' => 'reader@invalid.test', 'avatar_url' => 'admin']);
    $check('Profile rejects absent CSRF', $profile->save($req, new Response())->getStatusCode() === 419);
    $body = $req->getParsedBody() + ['_csrf' => Session::csrfToken()];
    $check('Profile rejects editor-relative avatar addresses', $profile->save($post->withParsedBody($body), new Response())->getStatusCode() === 400);
    $body['avatar_url'] = '';
    $check('Member can save own profile without changing role', $profile->save($post->withParsedBody($body + ['role' => 'ADMIN']), new Response())->getStatusCode() === 200
        && DB::value('SELECT role FROM users WHERE id=900000002') === 'USER' && DB::value('SELECT nickname FROM users WHERE id=900000002') === '读者');
    $file = static function (string $data, string $name): UploadedFile {
        $stream = fopen('php://temp', 'w+'); fwrite($stream, $data); rewind($stream);
        return new UploadedFile(new \Slim\Psr7\Stream($stream), $name, null, strlen($data), UPLOAD_ERR_OK);
    };
    $avatarReq = $post->withParsedBody(['_csrf' => Session::csrfToken()]);
    $check('Avatar endpoint also enforces CSRF', $profile->uploadAvatar($post, new Response())->getStatusCode() === 419);
    $check('Avatar endpoint rejects executable and SVG payloads', $profile->uploadAvatar($avatarReq->withUploadedFiles(['file' => $file('<svg onload="alert(1)"/>', 'fake.png')]), new Response())->getStatusCode() === 400);
    $check('Avatar endpoint enforces 2MB', $profile->uploadAvatar($avatarReq->withUploadedFiles(['file' => $file(str_repeat('x', 2097153), 'big.png')]), new Response())->getStatusCode() === 400);
    $off = Hooks::addFilter('upload_store_to_cloud', static fn () => 'https://example.invalid/fixture-avatar.png');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jS1cAAAAASUVORK5CYII=');
    $result = $profile->uploadAvatar($avatarReq->withUploadedFiles(['file' => $file($png, 'user.php')]), new Response());
    $check('Member avatar upload validates bytes and derives a safe image extension', $result->getStatusCode() === 200
        && DB::value('SELECT original_name FROM uploads WHERE uploader_id=900000002') === 'avatar.png');
    $off(); $off = null;
    $login(900000002);
    $public = new UserController();
    $check('Core has no built-in profile template', !is_file(PAFISH_ROOT . '/app/Views/theme/profile.php'));
    $check('Theme user center without user.php returns 404', $public->index($get, new Response(), ['route' => 'profile'])->getStatusCode() === 404);
    mkdir($themeDir);
    file_put_contents($themeDir . '/theme.json', json_encode([
        'name' => $themeName,
        'version' => '1.0.0',
        'systemTemplateFallback' => [],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($themeDir . '/extension.php', '<?php' . PHP_EOL
        . 'return static function (\\Pafish\\Services\\ExtensionContext $ctx): void {};' . PHP_EOL);
    $check('Theme with only manifest and extension entry is not a display theme', Theme::describe($themeName)['manifest'] === null);
    file_put_contents($themeDir . '/user.php', '<?php echo "THEME-OWN-CENTER:" . e($user["username"] ?? "guest") . ":" . e($userRoute);');
    Settings::set('active_theme', $themeName); Theme::reset();
    try { Theme::template('page'); $fallbackBlocked = false; } catch (RuntimeException) { $fallbackBlocked = true; }
    $check('Theme can explicitly reject system page template fallback', $fallbackBlocked);
    $response = $public->index($get, new Response(), ['route' => 'profile']);
    $check('Theme-provided user.php keeps rendering its own template', $response->getStatusCode() === 200 && str_contains((string) $response->getBody(), 'THEME-OWN-CENTER:qa-reader:profile'));
    Auth::logout();
    $response = $public->index($get, new Response(), ['route' => 'profile']);
    $check('Theme user center decides guest behavior', $response->getStatusCode() === 200 && str_contains((string) $response->getBody(), 'THEME-OWN-CENTER:guest:profile'));
    $login(900000002);
    Settings::set('active_theme', 'default'); Theme::reset();
    $configProp = new ReflectionProperty(Config::class, 'data'); $config = Config::all();
    $configProp->setValue(null, array_replace($config, ['pretty_urls' => false])); Url::init('/blog/index.php');
    $html = (string) $account->index($get, new Response())->getBody();
    $check('Account links support subdirectories and query routing', str_contains($html, '/blog/index.php?p=admin&amp;section=comments'));
    $configProp->setValue(null, $config); Url::init('/index.php');
    try { Auth::requireCapability('posts.manage'); $denied = false; } catch (RedirectException $e) { $denied = $e->target === '/admin'; }
    $check('Ordinary users cannot enter content management', $denied);
    DB::execute("INSERT INTO micro_statuses(id,content,author_id) VALUES(1,'OWN-MICRO',900000001),(2,'FOREIGN-MICRO',900000003)");
    $login(900000001); $_SERVER['REQUEST_URI'] = '/admin';
    $html = (string) (new DashboardController())->dashboard($get, new Response())->getBody();
    $check('Editor dashboard uses shared cards without legacy recent lists', str_contains($html, 'admin-overview-card') && !str_contains($html, 'admin-latest') && !str_contains($html, 'admin-stat-card'));
    $snapshot('editor', $html);
    $micro = new MicroController();
    $html = (string) $micro->index($get, new Response())->getBody();
    $check('Editor micro list is author scoped', str_contains($html, 'OWN-MICRO') && !str_contains($html, 'FOREIGN-MICRO'));
    $check('Editor cannot open another author micro', $micro->editEditor($get, new Response(), ['id' => 2])->getStatusCode() === 302);
    $check('Editor cannot overwrite another author micro', $micro->save($post->withParsedBody(['content' => 'stolen']), new Response(), ['id' => 2])->getStatusCode() === 404
        && DB::value('SELECT content FROM micro_statuses WHERE id=2') === 'FOREIGN-MICRO');
    $check('Editor cannot delete another author micro', $micro->delete($post, new Response(), ['id' => 2])->getStatusCode() === 404 && DB::value('SELECT deleted_at FROM micro_statuses WHERE id=2') === null);
    $check('Editor can update own micro', $micro->save($post->withParsedBody(['content' => 'OWN-UPDATED']), new Response(), ['id' => 1])->getStatusCode() === 200);
    $login(1);
    $check('Admin retains access to all authors', str_contains((string) $micro->index($get, new Response())->getBody(), 'FOREIGN-MICRO')
        && $micro->save($post->withParsedBody(['content' => 'ADMIN-UPDATED']), new Response(), ['id' => 2])->getStatusCode() === 200);
    echo $passed . ' passed' . PHP_EOL;
} finally {
    if ($off !== null) $off();
    RateLimiter::clear('profile-avatar', '900000002');
    foreach (['user.php', 'profile.php', 'theme.json', 'extension.php'] as $file) if (is_file($themeDir . '/' . $file)) unlink($themeDir . '/' . $file);
    if (is_dir($themeDir)) rmdir($themeDir);
    $pdo->cleanup(); Settings::reset(); Theme::reset();
    session_destroy();
}
