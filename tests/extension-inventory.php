<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require PAFISH_ROOT . '/app/Core/helpers.php';
require __DIR__ . '/Fixtures/MySql.php';

use Pafish\Core\{Auth, Config, DB, RedirectException, Url, SqlScript};
use Pafish\Services\ExtensionSchema as Schema;
use Pafish\Tests\MySql;
use Pafish\Admin\ExtensionDataController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

Config::load(Config::resolveFile(PAFISH_ROOT));
Url::init('/index.php');
$passed = $failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$declaration = static fn (string $name, string $logical = 'items', bool $valid = true): array =>
    ['kind' => 'plugin', 'name' => $name, 'logical' => $logical, 'valid' => $valid];
$physical = static fn (string $name, string $logical = 'items'): string => Schema::physicalName('plugin', $name, $logical);
$metadata = static fn (string $name): array => ['TABLE_NAME' => $name, 'TABLE_ROWS' => 42, 'DATA_LENGTH' => 16384, 'INDEX_LENGTH' => 8192];
$owner = static fn (string $name, string $logical = 'items'): array =>
    ['data_key' => $physical($name, $logical), 'value' => json_encode(['plugin', $name, $logical])];
$assemble = new ReflectionMethod(Schema::class, 'assembleInventory');
$row = static function (array $extensions, array $claims, array $owners, array $tables) use ($assemble): array {
    return $assemble->invoke(null, $extensions, $claims, $owners, $tables)[0];
};
$extensions = ['plugin:qa_notes' => ['title' => '笔记', 'valid' => true]];
$claims = [$physical('qa_notes') => [$declaration('qa_notes')]];
$normal = $row($extensions, $claims, [$owner('qa_notes')], [$metadata($physical('qa_notes'))]);
$check('Registered declared table has estimate, combined bytes and cleanup rule', $normal['statuses'] === ['registered']
    && $normal['rows'] === 42 && $normal['bytes'] === 24576 && $normal['cleanup'] && !$normal['attention']);
$unregistered = $row($extensions, $claims, [], [$metadata($physical('qa_notes'))]);
$check('Declared legacy table is visible before registration', $unregistered['statuses'] === ['unregistered'] && $unregistered['cleanup']);
$unknown = $row([], [], [], [$metadata('pf_plugin_unidentified_records')]);
$check('Unknown table is not assigned by splitting ambiguous underscores', $unknown['identity'] === null && !$unknown['cleanup']);
$missing = $row($extensions, $claims, [$owner('qa_notes')], []);
$check('Missing physical table is not reported as zero rows', $missing['statuses'] === ['missing'] && $missing['rows'] === null);
$pending = $row($extensions, $claims, [], []);
$check('Never-created declarations are distinct from missing registered tables', $pending['statuses'] === ['pending'] && !$pending['attention']);
$retained = $row([], [], [$owner('qa_notes')], [$metadata($physical('qa_notes'))]);
$check('Removed extension retains original owner without enabling cleanup', $retained['statuses'] === ['retained']
    && $retained['identity']['name'] === 'qa_notes' && !$retained['cleanup']);
$undeclared = $row($extensions, [], [$owner('qa_notes')], [$metadata($physical('qa_notes'))]);
$check('Removed declaration is distinct from uninstalled extension', $undeclared['statuses'] === ['undeclared'] && !$undeclared['cleanup']);
$collision = [$physical('a_b', 'c') => [$declaration('a_b', 'c'), $declaration('a', 'b_c')]];
$conflict = $row([], $collision, [$owner('a_b', 'c')], [$metadata($physical('a_b', 'c'))]);
$check('Physical name collision blocks cleanup even with a registry owner', in_array('conflict', $conflict['statuses'], true) && !$conflict['cleanup']);
$disagreement = $row([], [$physical('a_b', 'c') => [$declaration('a', 'b_c')]], [$owner('a_b', 'c')], [$metadata($physical('a_b', 'c'))]);
$check('Single contradictory claim is still a conflict', in_array('conflict', $disagreement['statuses'], true) && !$disagreement['cleanup']);
foreach (['null', '{}', '["plugin","qa_notes"]', '["plugin","qa_notes",[]]', '["plugin","qa_notes","items",0]',
    '["plugin", "qa_notes", "items"]', '["plugin","other","items"]', '["system","qa_notes","items"]'] as $bad) {
    $invalid = $row($extensions, $claims, [['data_key' => $physical('qa_notes'), 'value' => $bad]], [$metadata($physical('qa_notes'))]);
    $check('Invalid owner cannot authorize cleanup: ' . $bad, in_array('invalid_owner', $invalid['statuses'], true) && !$invalid['cleanup']);
}
$core = $row([], [], [['data_key' => 'users', 'value' => '["plugin","qa_notes","items"]']], [$metadata('users')]);
$check('Forged ownership of a core table is flagged', $core['statuses'] === ['invalid_owner'] && !$core['cleanup']);
$invalidManifest = $row($extensions, [$physical('qa_notes') => [$declaration('qa_notes', 'items', false)]], [$owner('qa_notes')], [$metadata($physical('qa_notes'))]);
$check('Invalid manifest is displayed but never authorizes cleanup', in_array('invalid_manifest', $invalidManifest['statuses'], true) && !$invalidManifest['cleanup']);
$check('Unrelated application tables are excluded', $assemble->invoke(null, [], [], [], [$metadata('other_app')]) === []);
$theme = $assemble->invoke(null, ['theme:qa_theme' => ['title' => '测试主题', 'valid' => true]],
    ['pf_theme_qa_theme_items' => [['kind' => 'theme', 'name' => 'qa_theme', 'logical' => 'items', 'valid' => true]]],
    [['data_key' => 'pf_theme_qa_theme_items', 'value' => '["theme","qa_theme","items"]']],
    [['TABLE_NAME' => 'pf_theme_qa_theme_items', 'TABLE_ROWS' => null, 'DATA_LENGTH' => 0, 'INDEX_LENGTH' => 0]])[0];
$check('Theme scope uses the same ownership rules and unknown estimate stays null', $theme['identity']['kind'] === 'theme'
    && $theme['statuses'] === ['registered'] && $theme['rows'] === null && $theme['bytes'] === 0);

// Render hostile metadata through the actual view and both URL modes.
$tables = [$normal]; $tables[0]['table'] = '<img src=x onerror=alert(1)>';
$tables[0]['title'] = '<script>alert(1)</script>';
$existing = 1; $missing = 0; $kind = ''; $state = '';
ob_start(); require PAFISH_ROOT . '/app/Views/admin/extension-data.php'; $html = ob_get_clean();
$check('View escapes table names and extension titles', str_contains($html, '&lt;img') && !str_contains($html, '<script>'));
$check('Scrollable table has accessible region', str_contains($html, 'role="region"') && str_contains($html, 'tabindex="0"'));
// Query mode is configured by Config::load; verify its real configuration API.
$config = new ReflectionProperty(Config::class, 'data');
$original = $config->getValue();
$config->setValue(null, array_replace($original, ['pretty_urls' => false]));
ob_start(); require PAFISH_ROOT . '/app/Views/admin/extension-data.php'; $queryHtml = ob_get_clean();
$check('GET filters preserve query-mode route', str_contains($queryHtml, 'name="p" value="admin/extensions/data"'));
$config->setValue(null, $original);

$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
$auth = new ReflectionProperty(Auth::class, 'user');
$queries = [];
$fixtureDir = PAFISH_ROOT . '/plugins/qa_inventory_' . bin2hex(random_bytes(5));
try {
    foreach (SqlScript::split(file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $sql) $pdo->query($sql);
    $pdo->afterExecute = static function (string $sql) use (&$queries): void { $queries[] = $sql; };
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/extensions/data');
    foreach (['EDITOR', 'USER', 'guest'] as $role) {
        $auth->setValue(null, $role === 'guest' ? [] : ['id' => 1, 'role' => $role]);
        $denied = false; $queries = [];
        try { (new ExtensionDataController())->index($request, new Response()); }
        catch (RedirectException) { $denied = true; }
        $check('Access denied before inventory for ' . $role, $denied && $queries === []);
    }
    $before = DB::fetchAll('SELECT * FROM extension_storage ORDER BY extension_type, extension_name, data_key');
    $queries = [];
    Schema::inventory();
    $check('Inventory uses only two batched SELECTs without table scans', count($queries) === 2
        && count(array_filter($queries, static fn ($sql) => preg_match('/^\s*SELECT\b/i', $sql))) === 2
        && str_contains($queries[1], 'INFORMATION_SCHEMA.TABLES'));
    $check('Inventory preserves every existing storage row', DB::fetchAll('SELECT * FROM extension_storage ORDER BY extension_type, extension_name, data_key') === $before);

    // Invalid manifests still reserve syntactically valid names during collision checks.
    if (is_dir($fixtureDir) || !mkdir($fixtureDir)) throw new RuntimeException('Cannot create isolated manifest fixture');
    $fixtureName = basename($fixtureDir);
    file_put_contents($fixtureDir . '/plugin.json', json_encode(['name' => $fixtureName, 'schema' => ['tables' => [['name' => 'a_b']]]]));
    $claim = new ReflectionMethod(Schema::class, 'claimTables');
    $queries = []; $blocked = false;
    try { $claim->invoke(null, 'plugin', $fixtureName . '_a', ['tables' => [['name' => 'b']]]); }
    catch (RuntimeException) { $blocked = true; }
    $check('Invalid installed declaration still blocks physical collision before writes', $blocked && $queries === []);
    $claim->invoke(null, 'plugin', $fixtureName, ['tables' => [['name' => 'a_b']]]);
    $check('Own declaration is not mistaken for another owner', Pafish\Services\ExtensionStorage::getTableOwner(
        Schema::physicalName('plugin', $fixtureName, 'a_b')) === json_encode(['plugin', $fixtureName, 'a_b']));
    $claim->invoke(null, 'plugin', $fixtureName, ['tables' => [['name' => 'a_b']]]);
    $check('Repeated ownership claim stays idempotent', count(DB::fetchAll('SELECT * FROM extension_storage')) === count($before) + 1);
} finally {
    $pdo->afterExecute = null;
    $auth->setValue(null, null);
    $pdo->cleanup();
    if (is_file($fixtureDir . '/plugin.json')) unlink($fixtureDir . '/plugin.json');
    if (is_dir($fixtureDir)) rmdir($fixtureDir);
}
echo json_encode(['passed' => $passed, 'failed' => $failed], JSON_UNESCAPED_UNICODE) . PHP_EOL;
ob_end_flush();
exit($failed > 0 ? 1 : 0);
