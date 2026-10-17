<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require PAFISH_ROOT . '/app/Core/helpers.php';
require __DIR__ . '/Fixtures/MySql.php';

use Pafish\Core\{Auth, Config, DB, Session, SqlScript, Url};
use Pafish\Services\{ExtensionKernel, Plugin, Settings, Theme};
use Pafish\Admin\PagesController;
use Pafish\Http\{HomeController, PageController};
use Pafish\Tests\MySql;
use Slim\Psr7\Response;
use Slim\Psr7\Factory\ServerRequestFactory;

Config::load(Config::resolveFile(PAFISH_ROOT));
Session::start(); Url::init('/index.php');
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
$name = 'qa_page_' . bin2hex(random_bytes(4));
$themeDir = Theme::root() . '/' . $name;
$pluginDir = Plugin::root() . '/' . $name;
$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $passed++; echo 'PASS ' . $label . PHP_EOL;
};
$factory = new ServerRequestFactory();
$get = $factory->createServerRequest('GET', 'https://example.invalid/pages/fixture');
$post = $factory->createServerRequest('POST', '/admin/pages/save')->withHeader('X-Requested-With', 'XMLHttpRequest');
try {
    foreach (SqlScript::split(file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $sql) $pdo->query($sql);
    mkdir($themeDir); mkdir($pluginDir);
    file_put_contents($themeDir . '/theme.json', json_encode(['name' => $name, 'pageTemplates' => [
        ['name' => 'landing', 'title' => '清单显示名'], ['name' => 'missing', 'title' => '无文件不出现'],
    ]], JSON_THROW_ON_ERROR));
    file_put_contents($themeDir . '/page.php', '<?php echo "BASE:" . $contentHtml;');
    file_put_contents($themeDir . '/page-landing.php', '<?php /*@name 文件显示名*/ echo "LANDING:" . e($page["template"]) . ":" . $contentHtml;');
    file_put_contents($themeDir . '/page-gallery.php', '<?php declare(strict_types=1); /** @name 图片墙 */ $GLOBALS["page_template_executed"] = true; echo "GALLERY:" . $contentHtml;');
    file_put_contents($themeDir . '/page-plain.php', '<?php echo "PLAIN";');
    file_put_contents($themeDir . '/dynamic-body.php', '<?php if (($page["template"] ?? "") !== "dynamic") throw new RuntimeException("stale page context"); echo "<strong>DYNAMIC BODY</strong>";');
    file_put_contents($themeDir . '/page-default.php', '<?php throw new RuntimeException("reserved");');
    file_put_contents($themeDir . '/page-invalid.php.bak', '<?php throw new RuntimeException("invalid suffix");');
    mkdir($themeDir . '/page-directory.php');
    file_put_contents($pluginDir . '/plugin.json', json_encode(['name' => $name, 'title' => 'Fixture', 'version' => '1.0.0',
        'pageTemplates' => [['name' => 'dynamic', 'title' => '动态正文'], ['name' => 'landing', 'title' => '同名插件']]], JSON_THROW_ON_ERROR));
    file_put_contents($pluginDir . '/extension.php', '<?php return static function ($ctx): void {};');
    Settings::setMany(['active_theme' => $name, 'active_plugins' => json_encode([$name])]);
    Theme::reset(); Plugin::reset();
    DB::execute("INSERT INTO users(id,username,email,password_hash,role) VALUES(1,'fixture-admin','page@invalid.test','unused','ADMIN')");
    Session::set('user_id', 1);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
    $options = PagesController::templateOptions();
    $check('Files are discovered without manifest declarations', ($options['gallery'] ?? '') === '主题 · 图片墙' && ($options['plain'] ?? '') === '主题 · plain');
    $check('Manifest label overrides optional file header', ($options['landing'] ?? '') === '主题 · 清单显示名');
    $check('Missing files, reserved names, directories and backup files are excluded', !isset($options['missing']) && !isset($options['directory']) && count($options) === 5);
    $check('Traversal and directory names never resolve', Theme::pageTemplateFile($name, '../config') === null && Theme::pageTemplateFile('../default', 'gallery') === null && Theme::pageTemplateFile($name, 'directory') === null);
    $check('Discovery reads headers without executing PHP', !isset($GLOBALS['page_template_executed']));
    $outside = tempnam(sys_get_temp_dir(), 'pf_page_');
    file_put_contents($outside, '<?php echo "OUTSIDE";');
    if (@symlink($outside, $themeDir . '/page-outside.php')) {
        $check('Out-of-directory symlink is rejected', Theme::pageTemplateFile($name, 'outside') === null
            && !in_array('outside', array_column(Theme::pageTemplates($name), 'name'), true));
    } else echo 'SKIP symlink fixture unavailable on this host' . PHP_EOL;

    $calls = 0;
    ExtensionKernel::provider('plugin', $name, 'renderPageTemplate', static function ($template) use (&$calls): string {
        $calls++; return $template === 'dynamic' ? render_partial('dynamic-body') : 'UNDECLARED';
    });
    $controller = new PagesController();
    $save = static fn (array $body, array $args = []) => $controller->save($post->withParsedBody($body), new Response(), $args);
    $body = ['title' => 'Fixture page', 'slug' => 'fixture', 'content' => 'SERVER BODY', 'content_format' => 'markdown', 'status' => 'PUBLISHED', 'template' => 'gallery'];
    $response = $save($body);
    $id = json_decode((string) $response->getBody(), true)['id'] ?? 0;
    $check('Discovered file can be saved through admin endpoint', $response->getStatusCode() === 200 && $id > 0 && DB::value('SELECT template FROM pages WHERE id=?', [$id]) === 'gallery');
    $pageController = new PageController();
    $show = static fn () => (string) $pageController->show($get, new Response(), ['slug' => 'fixture'])->getBody();
    $check('Page route automatically includes selected file', str_contains($show(), 'GALLERY:') && str_contains($show(), 'SERVER BODY'));
    Settings::set('home_page_id', (string) $id);
    $check('Custom homepage uses the same file resolver', str_contains((string) (new HomeController())->index($get, new Response())->getBody(), 'GALLERY:'));
    $response = $save(array_replace($body, ['template' => 'invented']));
    $check('Unknown new selection returns 400', $response->getStatusCode() === 400 && str_contains((string) $response->getBody(), '所选页面模板不可用'));
    $check('Traversal selection returns 400', $save(array_replace($body, ['template' => '../config']))->getStatusCode() === 400);
    $check('Plugin dynamic selection remains available', $save(array_replace($body, ['template' => 'dynamic']), ['id' => $id])->getStatusCode() === 200);
    $html = $show();
    $check('Plugin dynamic body works with theme-owned page.php exactly once', str_contains($html, 'BASE:<strong>DYNAMIC BODY</strong>') && $calls === 1);
    $check('Plugin dynamic body works on custom homepage', str_contains((string) (new HomeController())->index($get, new Response())->getBody(), 'BASE:<strong>DYNAMIC BODY</strong>'));
    $calls = 0;
    $save(array_replace($body, ['template' => 'landing']), ['id' => $id]);
    $check('Theme file wins name collisions consistently', str_contains($show(), 'LANDING:landing:') && $calls === 0);
    DB::execute("UPDATE pages SET template='undeclared' WHERE id=?", [$id]);
    $check('Undeclared names do not invoke plugin handlers', str_contains($show(), 'BASE:') && $calls === 0);
    DB::execute("UPDATE pages SET template='gallery' WHERE id=?", [$id]);
    unlink($themeDir . '/page-gallery.php');
    $html = (string) $controller->editEditor($get, new Response(), ['id' => $id])->getBody();
    $check('Editor retains missing template with explicit unavailable option', str_contains($html, 'value="gallery" selected') && str_contains($html, '暂不可用'));
    if (in_array('--snapshots', $argv, true)) {
        $snapshotDir = PAFISH_ROOT . '/.design-review/page-templates';
        if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0755, true);
        file_put_contents($snapshotDir . '/editor-unavailable.html', $html);
    }
    $check('Missing file falls back to base theme page', str_contains($show(), 'BASE:') && !str_contains($show(), 'GALLERY:'));
    $response = $save($body, ['id' => $id]);
    $check('Saving other fields preserves unavailable stored template', $response->getStatusCode() === 200 && DB::value('SELECT template FROM pages WHERE id=?', [$id]) === 'gallery');
    $without = $body; unset($without['template']);
    $save($without, ['id' => $id]);
    $check('Omitted selection preserves stored template', DB::value('SELECT template FROM pages WHERE id=?', [$id]) === 'gallery');
    Settings::set('active_theme', 'default'); Theme::reset();
    $save($body, ['id' => $id]);
    $check('Theme switching does not rewrite historical selection', DB::value('SELECT template FROM pages WHERE id=?', [$id]) === 'gallery');
    $save(array_replace($body, ['template' => 'dynamic']), ['id' => $id]);
    Settings::set('active_plugins', '[]'); Plugin::reset();
    $check('Disabled plugin no longer renders dynamic content', !str_contains($show(), 'DYNAMIC BODY'));
    $save(array_replace($body, ['template' => 'dynamic']), ['id' => $id]);
    $check('Disabled plugin selection can be preserved', DB::value('SELECT template FROM pages WHERE id=?', [$id]) === 'dynamic');
    $check('A new page cannot select disabled plugin template', $save(array_replace($body, ['template' => 'dynamic']))->getStatusCode() === 400);
    $save(array_replace($body, ['template' => 'default']), ['id' => $id]);
    $check('Author can explicitly reset unavailable template', DB::value('SELECT template FROM pages WHERE id=?', [$id]) === 'default');
    // 只提供自定义页面文件的主题也有真实展示层，不依赖占位样式或 page.php。
    file_put_contents($themeDir . '/page-gallery.php', '<?php /** @name 图片墙 */ echo "RESTORED:" . $contentHtml;');
    unlink($themeDir . '/page.php');
    $check('A theme with only custom page files is recognized', Theme::describe($name)['error'] === null);
    Settings::set('active_theme', $name); Theme::reset();
    DB::execute("UPDATE pages SET template='gallery' WHERE id=?", [$id]);
    $check('Restoring the theme file restores the original layout', str_contains($show(), 'RESTORED:'));
    DB::execute("UPDATE pages SET template='missing' WHERE id=?", [$id]);
    $check('Absent custom and base files use permitted system fallback', realpath(Theme::template('page', ['page' => ['template' => 'missing']])) === realpath(PAFISH_ROOT . '/app/Views/theme/page.php'));
    Settings::set('active_plugins', json_encode([$name])); Plugin::reset();
    ExtensionKernel::provider('plugin', $name, 'renderPageTemplate', static function (): string { throw new RuntimeException('fixture dynamic template failure'); });
    $check('Plugin failure returns normal content rather than aborting page', Plugin::renderPageTemplate(['template' => 'dynamic']) === '');
    ExtensionKernel::provider('plugin', $name, 'renderPageTemplate', static fn () => null);
    $check('Empty plugin output retains normal content', Plugin::renderPageTemplate(['template' => 'dynamic']) === '');
    ExtensionKernel::provider('plugin', $name, 'renderPageTemplate', static fn () => '<strong>SYSTEM DYNAMIC</strong>');
    DB::execute("UPDATE pages SET template='dynamic' WHERE id=?", [$id]);
    $html = $show();
    $check('System fallback renders dynamic body once', substr_count($html, 'SYSTEM DYNAMIC') === 1 && str_contains($html, 'class="plugin-page"'));
    echo $passed . ' checks passed' . PHP_EOL;
} finally {
    ExtensionKernel::reset('plugin', $name);
    foreach (glob($themeDir . '/*') ?: [] as $file) { if (is_dir($file) && !is_link($file)) rmdir($file); else unlink($file); }
    if (is_dir($themeDir)) rmdir($themeDir);
    foreach (glob($pluginDir . '/*') ?: [] as $file) unlink($file);
    if (is_dir($pluginDir)) rmdir($pluginDir);
    if (isset($outside) && is_file($outside)) unlink($outside);
    Settings::reset(); Theme::reset();
    $pdo->cleanup();
    ob_end_flush();
}
