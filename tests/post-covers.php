<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require PAFISH_ROOT . '/app/Core/helpers.php';
require __DIR__ . '/Fixtures/MySql.php';

use Pafish\Core\{Auth, Config, DB, Session, SqlScript, Url};
use Pafish\Services\{Settings, Theme, MediaReferences};
use Pafish\Admin\PostsController;
use Pafish\Http\{HomeController, PostController};
use Pafish\Tests\MySql;
use Slim\Psr7\Response;
use Slim\Psr7\Factory\ServerRequestFactory;

Config::load(Config::resolveFile(PAFISH_ROOT));
Session::start(); Url::init('/index.php');
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $passed++; echo 'PASS ' . $label . PHP_EOL;
};
$factory = new ServerRequestFactory();
$get = $factory->createServerRequest('GET', 'https://example.invalid/post/cover-fixture');
$post = $factory->createServerRequest('POST', '/admin/posts/save')->withHeader('X-Requested-With', 'XMLHttpRequest');
$snapshotDir = PAFISH_ROOT . '/.design-review/post-covers';
try {
    foreach (SqlScript::split(file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $sql) $pdo->query($sql);
    Settings::setMany(['active_theme' => 'default', 'active_plugins' => '[]']); Theme::reset();
    DB::execute("INSERT INTO users(id,username,email,password_hash,role) VALUES(1,'fixture-admin','cover@invalid.test','unused','ADMIN')");
    Session::set('user_id', 1); (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
    $controller = new PostsController();
    $save = static fn (array $body, ?int $id = null) => $controller->save($post->withParsedBody($body), new Response(), $id === null ? [] : ['id' => $id]);
    $body = ['title' => '文章封面测试', 'slug' => 'cover-fixture', 'content' => "正文内容\n\n![正文图片](/public/images/default-avatar.svg)", 'action' => 'publish'];
    $first = $save($body);
    $id = (int) (json_decode((string) $first->getBody(), true)['id'] ?? 0);
    $cover = static fn () => DB::value('SELECT cover_url FROM posts WHERE id=?', [$id]);
    $check('Body images do not create an unrequested cover', $first->getStatusCode() === 200 && $id > 0 && $cover() === null);
    $auto = array_replace($body, ['auto_cover' => '1', 'cover_url' => '']);
    $response = $save($auto, $id);
    $check('Explicit auto-cover extracts rendered first image', $response->getStatusCode() === 200 && $cover() === '/public/images/default-avatar.svg');
    $check('Save response reports the actual stored cover', (json_decode((string) $response->getBody(), true)['coverUrl'] ?? null) === $cover());
    $save(array_replace($auto, ['cover_url' => '/public/favicon.svg']), $id);
    $check('Explicit cover takes precedence over automatic image', $cover() === '/public/favicon.svg');
    Auth::withBearerUser(1, static fn () => $save($body, $id));
    $check('App save omitting cover preserves explicit image', $cover() === '/public/favicon.svg');
    $save(array_replace($body, ['cover_url' => '', 'auto_cover' => '0']), $id);
    $check('Explicit removal stays empty even with image in body', $cover() === null);
    $save(array_replace($body, ['action' => 'auto']), $id);
    $check('Autosave does not restore removed cover', $cover() === null);
    $save(array_replace($auto, ['action' => 'auto']), $id);
    $check('Autosave supports explicit automatic cover', $cover() === '/public/images/default-avatar.svg');
    $check('Invalid auto-cover input is a form error', $save(array_replace($auto, ['auto_cover' => ['1']]), $id)->getStatusCode() === 400);
    $save(array_replace($auto, ['content' => "```md\n![code](/uploads/ignored.png)\n```\n\n![real](/public/favicon.svg)"]), $id);
    $check('Extraction ignores image syntax in code blocks', $cover() === '/public/favicon.svg');
    $save(array_replace($auto, ['content' => '![relative](admin)']), $id);
    $check('Relative body paths cannot become admin-relative cover requests', $cover() === null);
    $save(array_replace($auto, ['content_format' => 'html', 'content' => '<p>正文</p><img src="/public/favicon.svg" alt="">']), $id);
    $check('HTML editor uses the same extraction rule', $cover() === '/public/favicon.svg');
    $save(array_replace($body, ['cover_url' => '/public/favicon.svg']), $id);
    $show = static fn () => (string) (new PostController())->show($get, new Response(), ['slug' => 'cover-fixture'])->getBody();
    $detail = $show();
    $check('Default detail does not add a separate cover', !str_contains($detail, 'class="post-cover"') && str_contains($detail, '正文图片'));
    $check('Cover remains available for sharing metadata', str_contains($detail, 'property="og:image"') && str_contains($detail, '/public/favicon.svg'));
    Settings::set('theme_settings:default:show_post_cover', '1'); Theme::reset();
    $enabled = $show();
    $check('Theme detail switch enables cover independently of body', str_contains($enabled, 'class="post-cover"') && str_contains($enabled, '正文图片'));
    Settings::set('theme_settings:default:show_post_cover', '0'); Theme::reset();
    $home = (string) (new HomeController())->index($get, new Response())->getBody();
    $check('Default list displays explicit article cover', str_contains($home, 'class="post-card-cover"') && str_contains($home, 'src="/public/favicon.svg"'));
    $card = render_partial('post-card', ['post' => ['id' => $id, 'title' => '无封面', 'slug' => 'empty', 'cover_url' => null]]);
    $check('List has no image placeholder when cover is absent', !str_contains($card, 'post-card-cover') && !str_contains($card, '<img'));
    $card = render_partial('post-card', ['post' => ['id' => $id, 'title' => '外链', 'slug' => 'external', 'cover_url' => '/public/favicon.svg', 'external_url' => 'https://example.invalid/source']]);
    $check('External cover follows title destination with opener isolation', str_contains($card, 'href="https://example.invalid/source" target="_blank" rel="noopener noreferrer"'));
    $card = render_partial('post-card', ['post' => ['id' => $id, 'title' => '旧地址', 'slug' => 'unsafe', 'cover_url' => 'javascript:alert(1)']]);
    $check('Historical unsafe covers are excluded from list HTML', !str_contains($card, '<img'));
    if (in_array('--snapshots', $argv, true)) {
        if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0755, true);
        file_put_contents($snapshotDir . '/list.html', $home);
        file_put_contents($snapshotDir . '/detail.html', $detail);
        file_put_contents($snapshotDir . '/detail-enabled.html', $enabled);
        file_put_contents($snapshotDir . '/editor.html', (string) $controller->editEditor($get, new Response(), ['id' => $id])->getBody());
    }
    DB::execute("INSERT INTO uploads(original_name,url,mime,size) VALUES('cover.png','/uploads/cover-fixture.png','image/png',1)");
    $uploadId = DB::lastInsertId();
    MediaReferences::register($uploadId, '/uploads/cover-fixture.png');
    $save(array_replace($body, ['cover_url' => '/uploads/cover-fixture.png']), $id);
    $check('Stored cover protects its media reference', (int) DB::value('SELECT usage_count FROM uploads WHERE id=?', [$uploadId]) === 1);
    $save(array_replace($body, ['cover_url' => '', 'auto_cover' => '0']), $id);
    $check('Removing cover releases only its reference', (int) DB::value('SELECT usage_count FROM uploads WHERE id=?', [$uploadId]) === 0);
    echo $passed . ' checks passed' . PHP_EOL;
} finally {
    Settings::reset(); Theme::reset(); $pdo->cleanup(); ob_end_flush();
}
