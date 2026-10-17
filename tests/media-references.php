<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require __DIR__ . '/Fixtures/MySql.php';
require PAFISH_ROOT . '/app/Core/helpers.php';

use Pafish\Core\Auth;
use Pafish\Core\Config;
use Pafish\Core\DB;
use Pafish\Core\Session;
use Pafish\Services\ContentTransfer;
use Pafish\Services\EntityMeta;
use Pafish\Services\ExtensionStorage;
use Pafish\Services\MediaReferences as Media;
use Pafish\Services\MicroStatuses;
use Pafish\Services\Migrator;
use Pafish\Services\Plugin;
use Pafish\Services\Settings;
use Pafish\Services\Theme;
use Pafish\Tests\MySql;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
$passed = $failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$throws = static function (callable $fn): bool {
    try { $fn(); } catch (Throwable) { return true; }
    return false;
};
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
$count = static fn (int $id): int => (int) DB::value('SELECT usage_count FROM uploads WHERE id = ?', [$id]);
$add = static function (string $url): int {
    return Media::transaction(static function () use ($url): int {
        DB::execute("INSERT INTO uploads (original_name,url,mime,size) VALUES ('fixture',?,'image/png',1)", [$url]);
        $id = DB::lastInsertId();
        Media::register($id, $url);
        return $id;
    });
};
$start = static function (array $args) use ($pdo): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/worker.php', $args[0], $pdo->prefix, ...array_slice($args, 1)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PAFISH_ROOT);
    if (!is_resource($process)) throw new RuntimeException('Cannot start media worker');
    fclose($pipes[0]);
    return [$process, $pipes];
};
$finish = static function (array $worker): mixed {
    [$process, $pipes] = $worker;
    $raw = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Media worker failed: ' . $error);
    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['result'];
};
try {
    $legacyAddresses = [
        'plugin_data:qa_media' => ['plugin', 'qa_media', 'data'],
        'theme_data:qa_media' => ['theme', 'qa_media', 'data'],
        'plugin_settings:qa_media' => ['plugin', 'qa_media', 'settings'],
        'theme_settings:qa_media:logo' => ['theme', 'qa_media', 'setting:logo'],
        'extension_version:plugin:qa_media' => ['plugin', 'qa_media', 'version'],
        'extension_schema_fingerprint:theme:qa_media' => ['theme', 'qa_media', 'schema_fingerprint'],
    ];
    $check('All legacy extension storage addresses normalize correctly', count(array_filter($legacyAddresses,
        static fn (array $address, string $key): bool => ExtensionStorage::legacyAddress($key) === $address,
        ARRAY_FILTER_USE_BOTH)) === count($legacyAddresses));
    $split = new ReflectionMethod(Migrator::class, 'splitStatements');
    foreach ($split->invoke(null, file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $sql) $pdo->query($sql);
    Migrator::run($pdo, PAFISH_ROOT . '/migrations');
    $check('Fresh installation starts with an initialized empty media index', Media::ready());
    $freshUpload = $add('/uploads/fresh-empty.png');
    Media::deleteUpload($freshUpload, null);
    $check('Fresh installation can delete an unused upload without rebuilding', DB::value('SELECT id FROM uploads WHERE id=?', [$freshUpload]) === null);
    $site = rtrim(Config::get('site_url'), '/');
    $check('Local absolute public path equals canonical path', Media::normalizeUrl($site . '/public/uploads/a.png?v=1#x') === Media::normalizeUrl('/uploads/a.png'));
    $check('Query routing upload URLs normalize', Media::normalizeUrl('/index.php?p=uploads/a.png') === Media::normalizeUrl('/uploads/a.png'));
    $check('Remote hosts stay distinct', Media::normalizeUrl('https://a.test/a.png') !== Media::normalizeUrl('https://b.test/a.png'));
    $check('Remote query strings stay distinct', Media::normalizeUrl('https://a.test/a.png?key=1') !== Media::normalizeUrl('https://a.test/a.png?key=2'));
    $check('Non-media bare names are not paths', Media::normalizeUrl('a.png') === null);
    $check('Encoded local filenames normalize', Media::normalizeUrl('/uploads/%61.png') === Media::normalizeUrl('/uploads/a.png'));
    $check('Unsupported URL schemes are rejected', Media::normalizeUrl('javascript:alert(1)') === null);

    DB::execute("INSERT INTO users (id,username,email,password_hash,role) VALUES (1,'qa-admin','admin@invalid.test','fixture','ADMIN'),(2,'qa-user','user@invalid.test','fixture','USER')");
    Session::set('user_id', 1);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
    $a = $add('/uploads/a.png');
    $b = $add('/uploads/b.png');
    $remote = $add('https://cdn.invalid.test/one/a.png?key=1&v=2');
    $other = $add('https://cdn.invalid.test/two/a.png');
    $signed = $add('https://cdn.invalid.test/one/a.png?key=3&v=2');
    $posts = new Pafish\Admin\PostsController();
    $saved = $posts->savePost(['title' => 'Media fixture', 'slug' => 'media-fixture', 'content' => '![x](/uploads/a.png) ![again](/public/uploads/a.png)', 'cover_url' => '/uploads/a.png', 'custom_fields' => json_encode([['key' => 'image', 'value' => '/uploads/a.png']])]);
    $id = $saved['id'];
    $check('Repeated body references count once per role', $count($a) === 3);
    $check('Legacy custom fields are stored in ordered entity metadata', EntityMeta::all('post', $id, EntityMeta::POST_FIELDS_SCOPE) === [['key' => 'image', 'value' => '/uploads/a.png']]);
    EntityMeta::replace('post', $id, EntityMeta::POST_FIELDS_SCOPE, [
        ['key' => 'duplicate', 'value' => 'first'], ['key' => 'duplicate', 'value' => 'second'],
    ]);
    $check('Entity metadata preserves repeated keys and caller order', EntityMeta::all('post', $id, EntityMeta::POST_FIELDS_SCOPE) === [
        ['key' => 'duplicate', 'value' => 'first'], ['key' => 'duplicate', 'value' => 'second'],
    ]);
    EntityMeta::set('post', $id, 'plugin:qa_media', 'image', '/uploads/a.png');
    $check('Extension metadata is isolated from core fields and indexed once', EntityMeta::get('post', $id, 'plugin:qa_media', 'image') === '/uploads/a.png'
        && EntityMeta::all('post', $id, EntityMeta::POST_FIELDS_SCOPE)[0]['key'] === 'duplicate' && $count($a) === 4);
    $fixtureName = 'qa_meta_' . bin2hex(random_bytes(4));
    $fixtureDir = Plugin::root() . '/' . $fixtureName;
    if (!mkdir($fixtureDir, 0755, true)) throw new RuntimeException('Cannot create entity metadata plugin fixture');
    $activeCache = new ReflectionProperty(Plugin::class, 'activeCache');
    $manifestCache = new ReflectionProperty(Plugin::class, 'manifests');
    $previousActiveCache = $activeCache->getValue();
    $previousManifestCache = $manifestCache->getValue();
    try {
        file_put_contents($fixtureDir . '/plugin.json', json_encode([
            'name' => $fixtureName,
            'title' => 'Entity metadata fixture',
            'version' => '1.0.0',
            'meta' => ['post' => [
                ['key' => 'subtitle', 'label' => '副标题', 'type' => 'text', 'maxLength' => 100],
                ['key' => 'layout', 'label' => '布局', 'type' => 'select', 'options' => ['wide' => '宽版', 'compact' => '紧凑'], 'default' => 'wide'],
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        file_put_contents($fixtureDir . '/extension.php', "<?php\nreturn static function (\\Pafish\\Services\\ExtensionContext \$ctx): void {};\n");
        $activeCache->setValue(null, [$fixtureName]);
        $manifestCache->setValue(null, []);
        $scope = EntityMeta::scope('plugin', $fixtureName);
        EntityMeta::setForExtension('plugin', $fixtureName, 'post', $id, 'subtitle', '声明字段');
        EntityMeta::setForExtension('plugin', $fixtureName, 'post', $id, 'layout', 'compact');
        $check('Declared extension fields can be read through the extension API',
            EntityMeta::getForExtension('plugin', $fixtureName, 'post', $id, 'subtitle') === '声明字段'
            && EntityMeta::getForExtension('plugin', $fixtureName, 'post', $id, 'layout') === 'compact');
        $check('Extension API rejects undeclared entity keys', $throws(fn () => EntityMeta::setForExtension('plugin', $fixtureName, 'post', $id, 'unlisted', 'x')));
        EntityMeta::saveEditorFields('post', $id, [$scope => ['subtitle' => '编辑器更新']]);
        $check('Editor save updates declared fields and preserves omitted values',
            EntityMeta::getForExtension('plugin', $fixtureName, 'post', $id, 'subtitle') === '编辑器更新'
            && EntityMeta::getForExtension('plugin', $fixtureName, 'post', $id, 'layout') === 'compact');
        $check('Editor save rejects values outside a select declaration',
            $throws(fn () => EntityMeta::saveEditorFields('post', $id, [$scope => ['layout' => 'unknown']]))
            && EntityMeta::getForExtension('plugin', $fixtureName, 'post', $id, 'layout') === 'compact');
        $check('Editor fields are enumerated from active extension declarations',
            count(array_filter(EntityMeta::editorFields('post', $id), static fn (array $field): bool => ($field['scope'] ?? '') === $scope)) === 2);
    } finally {
        $activeCache->setValue(null, $previousActiveCache);
        $manifestCache->setValue(null, $previousManifestCache);
        @unlink($fixtureDir . '/extension.php');
        @unlink($fixtureDir . '/plugin.json');
        @rmdir($fixtureDir);
    }
    EntityMeta::set('post', $id, 'plugin:qa_media', 'image', '/uploads/b.png');
    $check('Updating extension metadata replaces its media reference', $count($a) === 3 && $count($b) === 1);
    EntityMeta::delete('post', $id, 'plugin:qa_media', 'image');
    $check('Deleting extension metadata removes its media reference', $count($b) === 0);
    EntityMeta::set('post', $id, 'plugin:qa_media', 'owner_cleanup', '/uploads/a.png');
    EntityMeta::deleteOwner('plugin', 'qa_media');
    $check('Deleting extension-owned metadata releases its references', EntityMeta::get('post', $id, 'plugin:qa_media', 'owner_cleanup') === null && $count($a) === 3);
    $check('Unknown metadata subjects are rejected', $throws(fn () => EntityMeta::set('unknown', $id, 'plugin:qa_media', 'x', 'y')));

    $legacy = json_encode([['key' => 'legacy', 'value' => 'one'], ['key' => 'legacy', 'value' => 'two']], JSON_THROW_ON_ERROR);
    DB::execute('UPDATE posts SET custom_fields=? WHERE id=?', [$legacy, $id]);
    DB::execute('DELETE FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=?', ['post', $id, EntityMeta::POST_FIELDS_SCOPE]);
    DB::execute('INSERT INTO entity_meta (subject_type,subject_id,scope,meta_key,key_hash,position,meta_value) VALUES (?,?,?,?,?,?,?)',
        ['post', $id, EntityMeta::POST_FIELDS_SCOPE, 'legacy', hash('sha256', 'legacy', true), 0, 'one']);
    $backfill = require PAFISH_ROOT . '/migrations/entity_meta_backfill.php';
    $backfill($pdo);
    $backfill($pdo);
    $check('Legacy backfill repairs a partial import and is idempotent', EntityMeta::all('post', $id, EntityMeta::POST_FIELDS_SCOPE) === [
        ['key' => 'legacy', 'value' => 'one'], ['key' => 'legacy', 'value' => 'two'],
    ] && (int) DB::value('SELECT COUNT(*) FROM entity_meta WHERE subject_type=? AND subject_id=? AND scope=?', ['post', $id, EntityMeta::POST_FIELDS_SCOPE]) === 2);
    EntityMeta::replace('post', $id, EntityMeta::POST_FIELDS_SCOPE, [['key' => 'image', 'value' => '/uploads/a.png']]);
    $check('Core custom-field references are not counted twice during backfill rollout', $count($a) === 3);
    $check('Draft content protects its media', DB::value('SELECT status FROM posts WHERE id=?', [$id]) === 'DRAFT' && $count($a) > 0);
    $check('Reference deletion is rejected', $throws(fn () => Media::deleteUpload($a, null)));
    $posts->savePost(['title' => 'Media fixture', 'slug' => 'media-fixture', 'content' => '<p><img src="/uploads/b.png"></p>', 'content_format' => 'html', 'cover_url' => '', 'auto_cover' => '1'], $id);
    $check('Editing removes old roles and adds HTML media', $count($a) === 0 && $count($b) === 2);
    DB::execute('UPDATE posts SET deleted_at=NOW() WHERE id=?', [$id]);
    $check('Soft deletion retains media protection', $count($b) === 2);
    DB::execute('UPDATE posts SET deleted_at=NULL WHERE id=?', [$id]);
    $check('Restoration retains media protection', $count($b) === 2);
    $m = MicroStatuses::save(['content' => 'micro fixture', 'media' => ['/uploads/a.png']], null, 1);
    $check('Micro media JSON is indexed', $count($a) === 1);
    MicroStatuses::delete((int) $m['id']);
    $check('Deleted micro still protects media for restore', $count($a) === 1);
    Settings::set('fixture_image', json_encode(['url' => '/uploads/a.png']));
    $check('JSON setting is indexed', $count($a) === 2);
    Settings::set('fixture_image', 'https://evil.invalid.test/uploads/a.png');
    $check('Foreign host does not match a local basename', $count($a) === 1);
    Settings::set('fixture_remote', '<img src="https://cdn.invalid.test/one/a.png?key=1&amp;v=2">');
    $check('Remote HTML entity URL matches exact resource', $count($remote) === 1 && $count($other) === 0 && $count($signed) === 0);
    ExtensionStorage::set('theme', 'qa_media', 'setting:logo', '/uploads/a.png');
    ExtensionStorage::setMany([['plugin', 'qa_media', 'data', json_encode(['url' => '/uploads/b.png'])]]);
    $check('Theme and plugin shared storage index media', $count($a) === 2 && $count($b) === 3);
    DB::execute('INSERT INTO settings (`key`,`value`) VALUES (?,?)', ['plugin_data:qa_media', json_encode(['url' => 'legacy'])]);
    $check('New extension storage value takes precedence over legacy settings',
        json_decode((string) Settings::get('plugin_data:qa_media'), true)['url'] === '/uploads/b.png');
    DB::execute('INSERT INTO settings (`key`,`value`) VALUES (?,?)', ['plugin_data:qa_legacy', json_encode(['url' => 'fallback'])]);
    $pluginSnapshot = Plugin::data('qa_media');
    Plugin::log('qa_media', 'after snapshot');
    Plugin::setData('qa_media', $pluginSnapshot);
    $check('Plugin setData preserves logs from an earlier snapshot', count(Plugin::data('qa_media')['logs'] ?? []) === 1);
    $themeSnapshot = Theme::data('qa_media');
    Theme::log('qa_media', 'after snapshot');
    Theme::setData('qa_media', $themeSnapshot);
    $check('Theme setData preserves logs from an earlier snapshot', count(Theme::data('qa_media')['logs'] ?? []) === 1);

    Plugin::setData('qa_media', ['marker' => -1]);
    $setWorker = $start(['extension_set_data', '30']);
    $logWorker = $start(['extension_log', '30']);
    $finish($setWorker);
    $finish($logWorker);
    $raceData = Plugin::data('qa_media');
    $raceLogs = array_values(array_filter($raceData['logs'] ?? [], static fn (array $entry): bool => str_starts_with((string) ($entry['message'] ?? ''), 'concurrent-')));
    $check('Concurrent setData and log preserve every serialized write', (int) ($raceData['marker'] ?? -1) === 29 && count($raceLogs) === 30);

    ExtensionStorage::updateJson('plugin_data:qa_media', static fn ($data) => ['url' => '/uploads/a.png']);
    $check('Atomic extension JSON moves references', $count($a) === 3 && $count($b) === 2);
    Settings::setMany(['fixture_one' => '/uploads/a.png', 'fixture_two' => '/uploads/a.png']);
    Settings::deletePrefix('fixture_');
    $check('Prefix deletion releases only matching core settings', $count($a) === 3 && $count($remote) === 0);
    Settings::deletePrefix('plugin_data:qa_media');
    $check('Legacy extension prefix deletion releases references', $count($a) === 2);
    $before = $count($a);
    $throws(static function () use ($a): void {
        Media::transaction(static function (): void {
            Settings::set('will_rollback', '/uploads/a.png');
            throw new RuntimeException('fixture rollback');
        });
    });
    $check('Outer rollback undoes source and references together', $count($a) === $before && Settings::get('will_rollback') === null);
    ContentTransfer::import(['format' => 'pafish-content', 'pages' => [['slug' => 'fixture-page', 'content' => '![a](/uploads/a.png)']]], 'update');
    $check('Content import indexes page references', $count($a) === $before + 1);

    $factory = new ServerRequestFactory();
    $request = $factory->createServerRequest('POST', '/fixture')->withHeader('X-Requested-With', 'XMLHttpRequest');
    $body = static fn ($data) => $request->withParsedBody($data);
    $links = new Pafish\Admin\LinksController();
    $links->save($body(['name' => 'fixture', 'url' => '/uploads/a.png']), new Response(), []);
    $linkId = (int) DB::value('SELECT MAX(id) FROM links');
    $check('Link save indexes URLs', $count($a) === $before + 2);
    $links->delete($request, new Response(), ['id' => $linkId]);
    $check('Link deletion releases references', $count($a) === $before + 1);
    (new Pafish\Admin\NavController())->save($body(['label' => 'fixture', 'url' => '/uploads/a.png']), new Response(), []);
    $check('Navigation save indexes URLs', $count($a) === $before + 2);
    $profile = (new Pafish\Admin\ProfileController())->save($body(['nickname' => 'qa', 'username' => 'qa-admin', 'email' => 'admin@invalid.test', 'avatar_url' => '/uploads/a.png', '_csrf' => Session::csrfToken()]), new Response());
    $check('Profile save indexes avatar', $profile->getStatusCode() === 200 && $count($a) === $before + 3);
    $widgetResponse = (new Pafish\Admin\WidgetsController())->save($body(['type' => 'custom', 'title' => 'fixture', 'area' => 'sidebar', 'content' => '<img src="/uploads/a.png">']), new Response(), []);
    $check('Custom widget indexes media', $widgetResponse->getStatusCode() === 200 && $count($a) === $before + 4);
    $snapshot = DB::fetchAll('SELECT source_type, source_key, upload_id, role FROM media_references ORDER BY source_type, source_key, upload_id, role');
    Media::rebuild();
    $check('Incremental index equals complete rebuild', $snapshot === DB::fetchAll('SELECT source_type, source_key, upload_id, role FROM media_references ORDER BY source_type, source_key, upload_id, role'));
    $counts = DB::fetchAll('SELECT u.id FROM uploads u WHERE u.usage_count <> (SELECT COUNT(*) FROM media_references r WHERE r.upload_id=u.id)');
    $check('All cached counters equal relation counts', $counts === []);
    $explain = DB::fetchOne('EXPLAIN SELECT id FROM uploads WHERE url_hash = ?', [hash('sha256', Media::normalizeUrl('/uploads/a.png'), true)]);
    $check('Upload URL matching uses hash index', ($explain['key'] ?? '') === 'idx_uploads_url_hash');
    $raceUpload = $add('/uploads/race.png');
    $workers = [];
    for ($i = 0; $i < 4; $i++) $workers[] = $start(['media_setting', 'race_' . $i, '/uploads/race.png']);
    foreach ($workers as $worker) $finish($worker);
    $check('Concurrent independent sources preserve exact counts', $count($raceUpload) === 4);
    $workers = [];
    for ($i = 0; $i < 4; $i++) $workers[] = $start(['media_setting', 'race_same', '/uploads/race.png']);
    foreach ($workers as $worker) $finish($worker);
    $check('Concurrent repeated source saves stay idempotent', $count($raceUpload) === 5);
    // Establish a stale RR snapshot, then let a second connection add the relation.
    $pdo->beginTransaction();
    DB::value('SELECT COUNT(*) FROM media_references');
    $finish($start(['media_setting', 'race_stale', '/uploads/race.png']));
    Media::sync('setting', 'race_stale');
    $pdo->commit();
    $check('Synchronization reads current relations after stale snapshot', $count($raceUpload) === 6);
    $raceDelete = null;
    Media::transaction(static function () use ($start, &$raceDelete): void {
        Settings::set('race_uncommitted', '/uploads/race.png');
        $raceDelete = $start(['media_delete', (string) DB::value("SELECT id FROM uploads WHERE url = '/uploads/race.png'")]);
        usleep(150000);
    });
    $check('Delete waits for uncommitted reference and then rejects', $finish($raceDelete) === 'protected' && $count($raceUpload) === 7);
    $rebuild = null;
    Media::transaction(static function () use ($start, &$rebuild): void {
        Settings::set('race_before_rebuild', '/uploads/race.png');
        $rebuild = $start(['media_rebuild']);
        usleep(150000);
    });
    $finish($rebuild);
    $check('Rebuild waits for source commits and preserves them', $count($raceUpload) === 8 && Media::ready());
    $pageId = (int) DB::value("SELECT id FROM pages WHERE slug='fixture-page'");
    EntityMeta::set('page', $pageId, 'plugin:qa_media', 'marker', 'cleanup');
    $pageCount = $count($a);
    (new Pafish\Admin\PagesController())->delete($request, new Response(), ['id' => $pageId]);
    $check('Permanent page deletion releases media and entity metadata', $count($a) === $pageCount - 1
        && (int) DB::value('SELECT COUNT(*) FROM entity_meta WHERE subject_type=? AND subject_id=?', ['page', $pageId]) === 0);
    DB::execute('UPDATE posts SET deleted_at=NOW() WHERE id=?', [$id]);
    EntityMeta::set('post', $id, 'plugin:qa_media', 'marker', 'cleanup');
    $posts->purge($request, new Response(), ['id' => $id]);
    $check('Permanent post purge releases media and entity metadata', $count($b) === 0
        && (int) DB::value('SELECT COUNT(*) FROM entity_meta WHERE subject_type=? AND subject_id=?', ['post', $id]) === 0);
    $check('Wrong upload owner cannot delete', $throws(fn () => Media::deleteUpload($a, 2, true)));
    Media::deleteUpload($other, null);
    $check('Unreferenced upload can be deleted', DB::value('SELECT id FROM uploads WHERE id=?', [$other]) === null);
    Media::deleteUpload($a, null, true);
    $check('Forced upload deletion cascades its references', (int) DB::value('SELECT COUNT(*) FROM media_references WHERE upload_id=?', [$a]) === 0);
    $check('Force does not delete unrelated references', $count($raceUpload) === 8);
    $duplicate = $add('/uploads/race.png');
    Media::sync('setting', 'race_same');
    $check('Duplicate catalog URL receives its own indexed references', $count($duplicate) === 1);
    $duplicateResult = Media::deleteUpload($duplicate, null, true);
    $check('Deleting duplicate catalog row retains shared physical file', $duplicateResult['keep_file'] === true && $count($raceUpload) === 8);
    Media::catalogTransaction(static fn () => null);
    $check('Cataloguing an arbitrary URL invalidates trusted zero counts', !Media::ready() && $throws(fn () => Media::deleteUpload($signed, null, true)));
    Media::rebuild();
    $check('Explicit rebuild restores deletion readiness', Media::ready());
    $controller = file_get_contents(PAFISH_ROOT . '/app/Admin/MediaController.php');
    $index = substr($controller, strpos($controller, 'public function index'), strpos($controller, 'public function rebuildReferences') - strpos($controller, 'public function index'));
    $check('Media GET has no content scan or reference rebuild', !str_contains($index, 'refreshUsage') && !str_contains($index, '::rebuild(') && !str_contains($index, 'FROM posts'));
    // An old database has uploads/content but neither the new index nor a state row.
    DB::execute('DROP TABLE media_references');
    DB::execute('ALTER TABLE uploads DROP INDEX idx_uploads_url_hash, DROP COLUMN url_hash');
    DB::execute("DELETE FROM extension_storage WHERE extension_type='system' AND extension_name='media_references'");
    DB::execute("DELETE FROM schema_migrations WHERE version='zzz_media_references'");
    $applied = Migrator::run($pdo, PAFISH_ROOT . '/migrations');
    $check('Old installation migrates without losing content', in_array('zzz_media_references', $applied['applied'], true) && $count($raceUpload) === 8 && !Media::ready());
    Media::rebuild();
    $check('Historical sources rebuild to the same exact count', $count($raceUpload) === 8);
    $check('Migrated reference structure is repeatable', Migrator::run($pdo, PAFISH_ROOT . '/migrations') === ['applied' => []]);
    $quote = new ReflectionMethod(Pafish\Services\Backup::class, 'quote');
    $rawHash = str_pad("\0\xff\n;-- fixture\r\n'\\" . random_bytes(12), 32, "\0");
    DB::execute('UPDATE uploads SET url_hash = ' . $quote->invoke(null, $rawHash, true) . ' WHERE id = ?', [$raceUpload]);
    $check('Backup binary literal preserves every byte', DB::value('SELECT url_hash FROM uploads WHERE id=?', [$raceUpload]) === $rawHash);
    $nulText = "text\0tail";
    $check('Backup no longer strips embedded NUL', DB::value('SELECT ' . $quote->invoke(null, $nulText)) === $nulText);
    $storageTable = $pdo->prefix . 'extension_storage';
    $renamedStorageTable = $pdo->prefix . 'extension_storage_missing';
    $pdo->query('RENAME TABLE `' . $storageTable . '` TO `' . $renamedStorageTable . '`');
    try {
        $check('Legacy extension data remains readable when new storage table is absent',
            json_decode((string) Settings::get('plugin_data:qa_legacy'), true)['url'] === 'fallback');
    } finally {
        $pdo->query('RENAME TABLE `' . $renamedStorageTable . '` TO `' . $storageTable . '`');
    }
} catch (Throwable $e) {
    $failed++;
    echo 'ERROR ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
} finally {
    $pdo->cleanup();
    (new ReflectionProperty(DB::class, 'pdo'))->setValue(null, null);
    Settings::reset();
}
echo "$passed passed, $failed failed" . PHP_EOL;
ob_end_flush();
exit($failed === 0 ? 0 : 1);
