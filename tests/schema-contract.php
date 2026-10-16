<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require __DIR__ . '/Fixtures/MySql.php';
require __DIR__ . '/Fixtures/SchemaSnapshot.php';

use Pafish\Core\Config;
use Pafish\Core\DB;
use Pafish\Core\SqlScript;
use Pafish\Services\Migrator;
use Pafish\Tests\MySql;
use Pafish\Tests\SchemaSnapshot;

Config::load(Config::resolveFile(PAFISH_ROOT));
$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $passed++;
    echo 'PASS ' . $label . PHP_EOL;
};
$schema = file_get_contents(PAFISH_ROOT . '/app/install/schema.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', $schema, $matches);
$tables = array_merge($matches[1], ['schema_migrations']);
$fixture = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
try {
    $check('Install and baseline execute the same statements',
        SqlScript::split($schema) === SqlScript::split(file_get_contents(PAFISH_ROOT . '/migrations/baseline.sql')));
    foreach (SqlScript::split($schema) as $sql) $fixture->query($sql);
    Migrator::ensureTable($fixture);
    $expected = SchemaSnapshot::read($fixture, $tables, $fixture->prefix);
    $check('Core contract contains 18 core tables plus migration metadata', count($expected) === 19);
    Migrator::run($fixture, PAFISH_ROOT . '/migrations');
    $upgraded = SchemaSnapshot::read($fixture, $tables, $fixture->prefix);
    $freshDrift = SchemaSnapshot::diff($expected, $upgraded);
    $check('Migrations preserve fresh-install structural contract', $freshDrift === []);
    $check('All core and migration tables have database documentation',
        count(array_filter($upgraded, static fn($t) => $t['table']['comment'] === '')) === 0);
    $undocumented = 0;
    foreach ($upgraded as $table) foreach ($table['columns'] as $column) if ($column['comment'] === '') $undocumented++;
    $check('All 159 core and migration columns have database documentation', $undocumented === 0);
    $check('Contract includes exact binary hash types', $expected['uploads']['columns']['url_hash']['type'] === 'binary(32)');
    $check('Contract includes unique index column order',
        array_column($expected['point_transactions']['indexes']['uk_point_transactions_reference'], 'column_name') === ['user_id','reference_type','reference_id']);
    $check('Contract includes real FK deletion semantics',
        $expected['comments']['foreign_keys']['fk_comments_parent'][0]['on_delete'] === 'CASCADE');
    $check('Implicit FK index names normalize fixture-only prefix',
        isset($expected['comments']['indexes']['fk_comments_parent']));
    $fixture->query('ALTER TABLE users MODIFY nickname VARCHAR(65) NULL DEFAULT NULL');
    $fixture->query('ALTER TABLE posts ADD COLUMN qa_probe INT NULL DEFAULT 7');
    $fixture->query('ALTER TABLE posts ADD INDEX qa_probe_order (published_at,status)');
    $drift = SchemaSnapshot::diff($expected, SchemaSnapshot::read($fixture, $tables, $fixture->prefix));
    $check('Actual widened column is detected', count(array_filter($drift, fn ($d) => $d['table']==='users' && ($d['name']??'')==='nickname'))===1);
    $check('Actual extra column is detected', count(array_filter($drift, fn ($d) => $d['table']==='posts' && ($d['name']??'')==='qa_probe'))===1);
    $check('Actual extra index is detected', count(array_filter($drift, fn ($d) => $d['kind']==='indexes' && ($d['name']??'')==='qa_probe_order'))===1);
    $changed = $expected;
    $changed['comments']['foreign_keys']['fk_comments_parent'][0]['on_delete'] = 'SET NULL';
    $check('FK rule drift is detected', count(SchemaSnapshot::diff($expected,$changed))===1);
    $changed = $expected;
    $changed['users']['columns']['disabled']['default_value'] = '1';
    $check('Default value drift is detected', count(SchemaSnapshot::diff($expected,$changed))===1);
    $changed = $expected;
    $changed['comments']['columns']['post_id']['type'] = 'bigint';
    $check('Unsigned relation type drift is detected', count(SchemaSnapshot::diff($expected,$changed))===1);
    $changed = $expected;
    $changed['comments']['columns']['user_id']['nullable'] = 'NO';
    $check('Nullability drift is detected', count(SchemaSnapshot::diff($expected,$changed))===1);
    $changed = $expected;
    $changed['posts']['table']['collation'] = 'utf8mb4_bin';
    $check('Table collation drift is detected', count(SchemaSnapshot::diff($expected,$changed))===1);
    $changed = $expected;
    $changed['users']['columns'] = array_reverse($changed['users']['columns'], true);
    $check('Historical column position alone does not count as drift', SchemaSnapshot::diff($expected,$changed)===[]);

    // Read-only production-name metadata. Do not bootstrap, migrate or inspect its data.
    $actual = SchemaSnapshot::read(DB::pdo(), $tables);
    $localDrift = SchemaSnapshot::diff($expected, $actual);
    $annotations = ['table_comments'=>0,'column_comments'=>0,'columns'=>0];
    foreach ($actual as $table) {
        if ($table['table']['comment'] !== '') $annotations['table_comments']++;
        foreach ($table['columns'] as $column) {
            $annotations['columns']++;
            if ($column['comment'] !== '') $annotations['column_comments']++;
        }
    }
    $report = ['source'=>'local metadata vs isolated fresh install plus migrations',
        'core_tables'=>count($matches[1]),'migration_tables'=>1,
        'expected_columns_core_and_migrations'=>array_sum(array_map(static fn ($table) => count($table['columns']), $expected)),
        'actual_columns_core_and_migrations'=>$annotations['columns'],
        'excludes'=>'plugin-private business tables','annotations'=>$annotations,
        'fresh_migration_drift'=>$freshDrift,'local_drift'=>$localDrift,
        'limits'=>'No comparison of application data, SQL views, triggers, server privileges, index visibility or fulltext parser configuration. Local drift is reported, never auto-repaired.'];
    if (in_array('--report', $argv, true)) {
        $directory = PAFISH_ROOT . '/.design-review';
        if (!is_dir($directory) && !mkdir($directory,0750,true) && !is_dir($directory)) throw new RuntimeException('Cannot create report directory');
        file_put_contents($directory . '/core-schema-contract.json', json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
    echo $passed . ' passed; local drift is diagnostic and requires review.' . PHP_EOL;
} finally {
    $fixture->cleanup();
}
