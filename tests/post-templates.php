<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require PAFISH_ROOT . '/app/Core/helpers.php';
require __DIR__ . '/Fixtures/MySql.php';

use Pafish\Core\{Auth, Config, DB, Session, SqlScript, Url};
use Pafish\Services\{ContentTransfer, Migrator, Settings, Theme};
use Pafish\Admin\PostsController;
use Pafish\Http\{AppContentController, PostController};
use Pafish\Tests\MySql;
use Slim\Psr7\Response;
use Slim\Psr7\Factory\ServerRequestFactory;

Config::load(Config::resolveFile(PAFISH_ROOT));
Session::start(); Url::init('/index.php');
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
$name = 'qa_post_' . bin2hex(random_bytes(4));
$themeDir = Theme::root() . '/' . $name;
$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $passed++; echo 'PASS ' . $label . PHP_EOL;
};
$factory = new ServerRequestFactory();
$get = $factory->createServerRequest('GET', 'https://example.invalid/post/fixture');
$post = $factory->createServerRequest('POST', '/admin/posts/save')->withHeader('X-Requested-With', 'XMLHttpRequest');
try {
    foreach (SqlScript::split(file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $sql) $pdo->query($sql);
    mkdir($themeDir);
    file_put_contents($themeDir . '/theme.json', json_encode(['name' => $name], JSON_THROW_ON_ERROR));
    file_put_contents($themeDir . '/post.php', '<?php echo "BASE:" . $contentHtml;');
    file_put_contents($themeDir . '/post-news.php', '<?php echo "CATEGORY:" . $contentHtml;');
    file_put_contents($themeDir . '/post-template-gallery.php', '<?php declare(strict_types=1); /** @name 图片文章 */ $GLOBALS["post_template_executed"] = true; echo "GALLERY:" . $contentHtml;');
    file_put_contents($themeDir . '/post-template-plain.php', '<?php echo "PLAIN";');
    $long = str_repeat('a', 40);
    file_put_contents($themeDir . '/post-template-' . $long . '.php', '<?php echo "LONG";');
    file_put_contents($themeDir . '/post-template-default.php', '<?php throw new RuntimeException("reserved");');
    file_put_contents($themeDir . '/post-template-backup.php.bak', '<?php throw new RuntimeException("backup");');
    mkdir($themeDir . '/post-template-directory.php');
    Settings::setMany(['active_theme' => $name, 'active_plugins' => '[]']); Theme::reset();
    DB::execute("INSERT INTO users(id,username,email,password_hash,role) VALUES(1,'fixture-admin','post@invalid.test','unused','ADMIN')");
    DB::execute("INSERT INTO users(id,username,email,password_hash,role) VALUES(2,'fixture-editor','editor@invalid.test','unused','EDITOR')");
    DB::execute("INSERT INTO categories(id,name,slug) VALUES(1,'News','news')");
    Session::set('user_id', 1); (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
    $options = PostsController::templateOptions();
    $check('Files discovered with header or slug labels, category overrides excluded', ($options['gallery'] ?? '') === '图片文章' && ($options['plain'] ?? '') === 'plain' && !isset($options['news']));
    $check('Reserved names, directories and backup files excluded', count($options) === 4 && !isset($options['directory']));
    $check('Discovery never executes templates', !isset($GLOBALS['post_template_executed']));
    $check('Traversal rejected in file resolver', Theme::postTemplateFile($name, '../config') === null && Theme::postTemplateFile('../default', 'gallery') === null);
    $outside = tempnam(sys_get_temp_dir(), 'pf_post_'); file_put_contents($outside, '<?php echo "OUTSIDE";');
    if (@symlink($outside, $themeDir . '/post-template-outside.php')) {
        $check('External symlink cannot be discovered or rendered', Theme::postTemplateFile($name, 'outside') === null && !isset(PostsController::templateOptions()['outside'])
            && basename(Theme::template('post', ['post' => ['template' => 'outside']])) === 'post.php');
    } else echo 'SKIP symlink fixture unavailable on this host' . PHP_EOL;
    $controller = new PostsController();
    $save = static fn (array $body, ?int $id = null) => $controller->save($post->withParsedBody($body), new Response(), $id === null ? [] : ['id' => $id]);
    $body = ['title' => 'Fixture post', 'slug' => 'fixture', 'content' => 'SERVER BODY', 'action' => 'publish', 'category_id' => '1', 'template' => 'gallery'];
    $response = $save($body);
    $id = (int) (json_decode((string) $response->getBody(), true)['id'] ?? 0);
    $check('Admin saves a discovered template', $response->getStatusCode() === 200 && $id > 0 && DB::value('SELECT template FROM posts WHERE id=?', [$id]) === 'gallery');
    $show = static fn () => (string) (new PostController())->show($get, new Response(), ['slug' => 'fixture'])->getBody();
    $check('Real article route renders selected layout before category', str_contains($show(), 'GALLERY:') && str_contains($show(), 'SERVER BODY'));
    $check('Maximum length template ID resolves despite filename prefix', basename(Theme::template('post', ['post' => ['template' => $long, 'category_slug' => 'news']])) === 'post-template-' . $long . '.php');
    $response = $save(array_replace($body, ['template' => 'invented']), $id);
    $check('Unknown selection returns 400 and leaves old value', $response->getStatusCode() === 400 && str_contains((string) $response->getBody(), '所选文章模板不可用') && DB::value('SELECT template FROM posts WHERE id=?', [$id]) === 'gallery');
    foreach (['../config', 'Gallery', str_repeat('x', 41), ['gallery']] as $invalid) {
        $check('Malformed template returns 400', $save(array_replace($body, ['template' => $invalid]), $id)->getStatusCode() === 400);
    }
    $save(array_replace($body, ['action' => 'auto', 'template' => 'plain']), $id);
    $check('Autosave persists template while keeping published status', DB::value('SELECT template FROM posts WHERE id=?', [$id]) === 'plain' && DB::value('SELECT status FROM posts WHERE id=?', [$id]) === 'PUBLISHED');
    $save($body, $id);
    $without = $body; unset($without['template']);
    Auth::withBearerUser(1, static function () use ($get, $check, $id, $save, $without): void {
        $detail = json_decode((string) (new AppContentController())->post($get, new Response(), ['id' => $id])->getBody(), true);
        $check('App detail includes current template', ($detail['post']['template'] ?? null) === 'gallery');
        $check('App save omitting template preserves selection', $save($without, $id)->getStatusCode() === 200 && DB::value('SELECT template FROM posts WHERE id=?', [$id]) === 'gallery');
    });
    $check('Editor cannot change another authors template', Auth::withBearerUser(2, static fn () => $save(array_replace($body, ['template' => 'plain']), $id))->getStatusCode() === 400);
    $html = (string) $controller->editEditor($get, new Response(), ['id' => $id])->getBody();
    $check('Editor selects current template', str_contains($html, 'id="fTemplate"') && str_contains($html, 'value="gallery" selected'));
    if (in_array('--snapshots', $argv, true)) {
        $snapshotDir = PAFISH_ROOT . '/.design-review/post-templates';
        if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0755, true);
        file_put_contents($snapshotDir . '/editor.html', $html);
    }
    unlink($themeDir . '/post-template-gallery.php');
    $check('Missing custom layout falls back to category', str_contains($show(), 'CATEGORY:'));
    $check('Other saves preserve unavailable stored choice', $save($body, $id)->getStatusCode() === 200 && DB::value('SELECT template FROM posts WHERE id=?', [$id]) === 'gallery');
    $check('Unavailable choice cannot be assigned to a new article', $save(array_replace($body, ['slug' => 'other']))->getStatusCode() === 400);
    $html = (string) $controller->editEditor($get, new Response(), ['id' => $id])->getBody();
    $check('Unavailable stored option remains visible and selected', str_contains($html, 'value="gallery" selected') && str_contains($html, '暂不可用'));
    if (isset($snapshotDir)) file_put_contents($snapshotDir . '/editor-unavailable.html', $html);
    unlink($themeDir . '/post-news.php');
    $check('Missing category layout falls back to default article', str_contains($show(), 'BASE:'));
    $export = ContentTransfer::export();
    $exported = array_values(array_filter($export['posts'], static fn ($row) => (int) $row['id'] === $id))[0];
    $check('Export includes unavailable template identifier', $exported['template'] === 'gallery');
    // Roundtrip serialized JSON, as a downloaded content package would be submitted.
    $exported['slug'] = 'imported';
    $package = ['format' => 'pafish-content', 'posts' => [$exported]];
    ContentTransfer::import($package);
    $check('Import retains layout from an absent theme', DB::value("SELECT template FROM posts WHERE slug='imported'") === 'gallery');
    unset($package['posts'][0]['template']);
    ContentTransfer::import($package, 'update');
    $check('Old content packages preserve existing choice on update', DB::value("SELECT template FROM posts WHERE slug='imported'") === 'gallery');
    $package['posts'][0]['slug'] = 'legacy-import'; ContentTransfer::import($package);
    $check('Old content packages default on new article', DB::value("SELECT template FROM posts WHERE slug='legacy-import'") === 'default');
    $package['posts'][0]['template'] = '../bad';
    try { ContentTransfer::import($package, 'update'); $rejected = false; } catch (RuntimeException) { $rejected = true; }
    $check('Import rejects traversal template values', $rejected);
    Settings::set('active_theme', 'default'); Theme::reset();
    $check('Theme switch preserves stored choice on save', $save($without, $id)->getStatusCode() === 200 && DB::value('SELECT template FROM posts WHERE id=?', [$id]) === 'gallery');
    $save(array_replace($body, ['template' => 'default']), $id);
    $check('Author can reset to default', DB::value('SELECT template FROM posts WHERE id=?', [$id]) === 'default');
    $html = (string) $controller->editEditor($get, new Response(), ['id' => $id])->getBody();
    $check('No custom templates means no unnecessary selector', !str_contains($html, '<select class="input" id="fTemplate"') && str_contains($html, 'type="hidden" id="fTemplate"'));

    $migrationDir = sys_get_temp_dir() . '/pf_post_migration_' . bin2hex(random_bytes(5)); mkdir($migrationDir);
    copy(PAFISH_ROOT . '/migrations/post_templates.sql', $migrationDir . '/post_templates.sql');
    Migrator::run($pdo, $migrationDir);
    $check('Fresh schema migration only records existing column', in_array('post_templates', Migrator::appliedVersions($pdo), true));
    DB::execute("DELETE FROM schema_migrations WHERE version='post_templates'");
    $pdo->query('ALTER TABLE posts DROP COLUMN template');
    Migrator::run($pdo, $migrationDir);
    $check('Upgrade adds column with safe default for old articles', DB::value('SELECT template FROM posts WHERE id=?', [$id]) === 'default');
    DB::execute("UPDATE posts SET template='historical' WHERE id=?", [$id]);
    DB::execute("DELETE FROM schema_migrations WHERE version='post_templates'");
    Migrator::run($pdo, $migrationDir);
    $check('Interrupted migration retry preserves article data', DB::value('SELECT template FROM posts WHERE id=?', [$id]) === 'historical');
    echo $passed . ' checks passed' . PHP_EOL;
} finally {
    foreach (glob($themeDir . '/*') ?: [] as $file) { if (is_dir($file) && !is_link($file)) rmdir($file); else unlink($file); }
    if (is_dir($themeDir)) rmdir($themeDir);
    if (isset($outside) && is_file($outside)) unlink($outside);
    if (isset($migrationDir)) { foreach (glob($migrationDir . '/*') ?: [] as $file) unlink($file); rmdir($migrationDir); }
    Settings::reset(); Theme::reset(); $pdo->cleanup(); ob_end_flush();
}
