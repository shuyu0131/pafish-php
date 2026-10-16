<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();

define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require __DIR__ . '/Fixtures/MySql.php';
require PAFISH_ROOT . '/app/Core/helpers.php';

use Pafish\Core\Config;
use Pafish\Core\DB;
use Pafish\Core\Version;
use Pafish\Core\Session;
use Pafish\Core\Auth;
use Pafish\Services\Migrator;
use Pafish\Services\ContentRepository;
use Pafish\Services\Store;
use Pafish\Services\EmailCode;
use Pafish\Services\PostReactions;
use Pafish\Services\ExtensionSchema;
use Pafish\Services\ExtensionKernel;
use Pafish\Tests\MySql;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$throws = static function (callable $fn): bool {
    try { $fn(); } catch (Throwable) { return true; }
    return false;
};
$race = static function (array $arguments, int $count = 4): array {
    $workers = [];
    for ($i = 0; $i < $count; $i++) {
        $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/Fixtures/worker.php'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PAFISH_ROOT);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start concurrency fixture'); }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $results = [];
    $errors = [];
    foreach ($workers as [$process, $pipes]) {
        $raw = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) { $errors[] = $error; }
        $results[] = json_decode($raw, true);
    }
    if ($errors !== []) { throw new RuntimeException(implode('; ', $errors)); }
    return array_column($results, 'result');
};

$split = new ReflectionMethod(Migrator::class, 'splitStatements');
foreach ([
    ["INSERT INTO t VALUES ('a;b');", 1],
    ['SELECT "a;b", `semi;colon`;', 1],
    ["SELECT 'it''s;a'; SELECT 'back\\'quote;b';", 2],
    ["-- header\nSELECT 1; # comment ;\n/* block ; */ SELECT 2; -- tail", 2],
    ["SELECT '--not a comment;'; SELECT 1--2;", 2],
] as [$sql, $count]) {
    $check('SQL script preserves strings and comments: ' . $count, count($split->invoke(null, $sql)) === $count);
}
$check('Unclosed SQL is rejected', $throws(fn () => $split->invoke(null, "SELECT 'broken;")));
$check('Unclosed block comment is rejected', $throws(fn () => $split->invoke(null, 'SELECT 1; /* broken')));
$check('Executable SQL comments are rejected', $throws(fn () => $split->invoke(null, '/*!50000 DROP TABLE users */;')));
$check('NO_BACKSLASH_ESCAPES is respected', count($split->invoke(null, "SELECT 'back\\'; SELECT 2;", true)) === 2);
$check('ANSI_QUOTES identifiers use doubled quotes', count($split->invoke(null, 'SELECT "back\\"; SELECT 2;', false, true)) === 2);
foreach ([['v1.1.0', '1.1.0', 0], ['1.1', '1.1.0', 0], ['1.1.10', '1.1.2', 1],
    ['1.2.0-rc.1', '1.2.0', -1], ['1.2.0-beta.2', '1.2.0-rc.1', -1],
    ['1.2.0+build.2', '1.2.0+build.1', 0]] as [$a, $b, $expected]) {
    $check('Unified version comparison ' . $a . ' / ' . $b,
        Version::compare($a, $b) === $expected && Store::compareVersions($a, $b) === $expected);
}

$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
$property = new ReflectionProperty(DB::class, 'pdo');
$property->setValue(null, $pdo);
$fixtureDir = PAFISH_ROOT . '/runtime/' . $pdo->prefix . 'migrations';
mkdir($fixtureDir);
try {
    foreach ($split->invoke(null, file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $sql) {
        $pdo->query($sql);
    }
    $check('Current migration set runs on a fresh baseline', is_array(Migrator::run($pdo, PAFISH_ROOT . '/migrations')['applied']));
    $check('Current migration set is repeatable', Migrator::run($pdo, PAFISH_ROOT . '/migrations') === ['applied' => []]);
    DB::execute('ALTER TABLE pages DROP COLUMN deleted_at');
    DB::execute("DELETE FROM schema_migrations WHERE version = 'pages_deleted_at'");
    $migrationRetry = Migrator::run($pdo, PAFISH_ROOT . '/migrations');
    $pageSoftDeleteColumnExists = (int) DB::value('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['pages', 'deleted_at']) === 1;
    $check('Existing database receives the page soft-delete column', $pageSoftDeleteColumnExists);
    $check('Page soft-delete migration is recorded', in_array('pages_deleted_at', $migrationRetry['applied'], true)
        || in_array('pages_deleted_at', Migrator::appliedVersions($pdo), true));
    // Only retired transient codes are removed; normal application records are untouched.
    DB::execute("DELETE FROM schema_migrations WHERE version IN ('email_code_security','zzzzz_email_codes_session')");
    DB::execute('CREATE TABLE email_codes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,email VARCHAR(255),purpose VARCHAR(20),code VARCHAR(10),expires_at DATETIME,used TINYINT DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    DB::execute("INSERT INTO email_codes (email,purpose,code,expires_at) VALUES ('legacy@invalid.test','reset','123456',DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
    Migrator::run($pdo, PAFISH_ROOT . '/migrations');
    $check('Old transient code table is retired', (int) DB::value('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['email_codes']) === 0);
    $check('Old plaintext codes require reissue', !EmailCode::verify('legacy@invalid.test', 'reset', '123456'));
    DB::execute('CREATE TABLE IF NOT EXISTS fixture_log (id INT PRIMARY KEY, value VARCHAR(255)) ENGINE=InnoDB');
    DB::transaction(function () use ($check, $throws) {
        DB::execute("INSERT INTO fixture_log VALUES (1, 'outer')");
        $check('Nested transaction failure can be caught', $throws(fn () => DB::transaction(function () {
            DB::execute("INSERT INTO fixture_log VALUES (2, 'inner')");
            throw new RuntimeException('rollback inner');
        })));
        $check('Inner rollback preserves outer work', DB::value('SELECT value FROM fixture_log WHERE id=1') === 'outer'
            && DB::value('SELECT value FROM fixture_log WHERE id=2') === null);
        DB::transaction(fn () => DB::execute("INSERT INTO fixture_log VALUES (3, 'nested success')"));
    });
    $check('Outer commit persists nested success', DB::value('SELECT COUNT(*) FROM fixture_log') === 2);
    $throws(fn () => DB::transaction(function () {
        DB::transaction(fn () => DB::execute("INSERT INTO fixture_log VALUES (4, 'must roll back')"));
        throw new RuntimeException('rollback outer');
    }));
    $check('Outer rollback also undoes nested success', DB::value('SELECT value FROM fixture_log WHERE id=4') === null);

    $email = $pdo->prefix . '@invalid.test';
    [$code, $limited] = EmailCode::create($email, 'register');
    $check('New code is generated', !$limited && preg_match('/^\d{6}$/D', $code) === 1);
    $wrongCode = $code === '123456' ? '654321' : '123456';
    $check('Wrong code is rejected', !EmailCode::verify($email, 'register', $wrongCode));
    $stored = (string) Session::get('email_code:register')['hash'];
    $check('Only a password hash is stored', $stored !== $code && password_verify($code, $stored));
    $check('Newest code can be consumed', EmailCode::verify($email, 'register', $code));
    $check('Code cannot be consumed twice', !EmailCode::verify($email, 'register', $code));
    $check('Send interval is enforced', EmailCode::create($email, 'register')[1]);
    $check('Unsupported verification purposes are rejected', $throws(fn () => EmailCode::create($email, 'arbitrary')));
    $check('Purpose isolation is enforced', !EmailCode::verify($email, 'reset', $code));
    [$resetCode] = EmailCode::create($email, 'reset');
    $sessionId = session_id();
    session_write_close();
    $results = $race(['verify', $pdo->prefix, $email, $resetCode, $sessionId]);
    Session::start();
    $check('Concurrent verification consumes a code exactly once', count(array_filter($results)) === 1);
    Session::set('email_code:reset', ['email'=>$email,'hash'=>password_hash($resetCode,PASSWORD_BCRYPT,['cost'=>10]),'expires'=>time()-1,'attempts'=>0]);
    $check('Expired newest code does not revive an older one', !EmailCode::verify($email, 'reset', $resetCode));

    DB::execute("INSERT INTO users (id,username,email,password_hash,role) VALUES (1,'qa-admin','admin@invalid.test','fixture','ADMIN'),(2,'qa-author','author@invalid.test','fixture','USER')");
    DB::execute("INSERT INTO posts (id,title,slug,content,status,author_id) VALUES (1,'Fixture','fixture','body','PUBLISHED',2)");
    $results = $race(['reaction', $pdo->prefix]);
    $check('Concurrent desired-state likes increment once', count(array_filter($results, fn ($r) => $r['changed'])) === 1
        && (int) DB::value('SELECT like_count FROM posts WHERE id=1') === 1
        && (int) DB::value('SELECT COUNT(*) FROM post_reactions WHERE post_id=1') === 1);
    PostReactions::change(1, 'like', 1, false);
    $pdo->beginTransaction();
    DB::value('SELECT COUNT(*) FROM post_reactions');
    $race(['reaction', $pdo->prefix], 1);
    $result = PostReactions::change(1, 'like', 1, false);
    $pdo->commit();
    $check('Relation writes read current data despite an earlier transaction snapshot', $result['changed']
        && $result['count'] === 0 && (int) DB::value('SELECT COUNT(*) FROM post_reactions') === 0);
    PostReactions::change(1, 'like', 1, true);
    $pdo->beginTransaction();
    $check('Anonymous writes cannot outlive a rolled-back outer transaction', $throws(fn () => PostReactions::change(1, 'like', null, true)));
    $pdo->rollBack();
    $_SESSION = [];
    $_COOKIE['liked_posts'] = '1';
    $check('Forged old cookie is not an anonymous record', !PostReactions::state(1, null)['like']);
    $check('Anonymous removal cannot subtract another user like', PostReactions::change(1, 'like', null, false)['count'] === 1);
    $check('Anonymous like adds one', PostReactions::change(1, 'like', null, true)['count'] === 2);
    $check('Anonymous desired-state retry is idempotent', !PostReactions::change(1, 'like', null, true)['changed']
        && (int) DB::value('SELECT like_count FROM posts WHERE id=1') === 2);
    $check('Anonymous unlike removes only its own contribution', PostReactions::change(1, 'like', null, false)['count'] === 1);
    $check('Anonymous favorites are rejected', $throws(fn () => PostReactions::change(1, 'favorite', null, true)));
    $check('Unknown interaction kinds are rejected', $throws(fn () => PostReactions::change(1, 'arbitrary', 1, true)));
    $check('Logged-in favorites still work', PostReactions::change(1, 'favorite', 1, true)['count'] === 1);
    DB::execute("UPDATE posts SET published_at='2099-01-01' WHERE id=1");
    $check('Future posts reject interactions inside the write transaction', $throws(fn () => PostReactions::change(1, 'like', 1, false)));
    DB::execute('UPDATE posts SET published_at=NULL, deleted_at=NOW() WHERE id=1');
    $check('Trashed posts reject interactions', $throws(fn () => PostReactions::change(1, 'like', 1, false)));
    $check('Trash preserves reaction records for restoration', (int) DB::value('SELECT COUNT(*) FROM post_reactions WHERE post_id=1') === 2);
    DB::execute('UPDATE posts SET deleted_at=NULL WHERE id=1');

    $factory = new ServerRequestFactory();
    $controller = new Pafish\Http\PostController();
    $reactionIp = sprintf('198.18.%d.%d', random_int(0,255), random_int(1,254));
    $request = $factory->createServerRequest('POST', '/api/post/1/like', ['REMOTE_ADDR' => $reactionIp]);
    $check('HTTP interactions reject missing CSRF', $controller->toggle($request, new Response(), ['id' => 1, 'kind' => 'like'])->getStatusCode() === 419);
    $valid = $request->withParsedBody(['_csrf' => Session::csrfToken(), 'active' => '1']);
    $response = $controller->toggle($valid, new Response(), ['id' => 1, 'kind' => 'like']);
    $check('HTTP anonymous like works with CSRF', $response->getStatusCode() === 200 && json_decode((string) $response->getBody(), true)['active']);
    $check('HTTP rejects invalid target states', $controller->toggle($valid->withParsedBody(['_csrf' => Session::csrfToken(), 'active' => []]), new Response(), ['id' => 1, 'kind' => 'like'])->getStatusCode() === 400);
    $check('HTTP favorites still require login', $controller->toggle($valid, new Response(), ['id' => 1, 'kind' => 'favorite'])->getStatusCode() === 401);
    for ($i = 0; $i < 65; $i++) {
        $response = $controller->toggle($valid, new Response(), ['id' => 1, 'kind' => 'like']);
        if ($response->getStatusCode() === 429) { break; }
    }
    $check('HTTP interaction rate is bounded', $response->getStatusCode() === 429);
    Pafish\Services\Settings::set('active_theme', 'default');
    $page = $controller->show($factory->createServerRequest('GET', '/post/fixture'), new Response(), ['slug' => 'fixture']);
    $html = (string) $page->getBody();
    $check('Default post template renders interaction CSRF and desired state', $page->getStatusCode() === 200
        && str_contains($html, "body.set('_csrf',") && str_contains($html, Session::csrfToken())
        && str_contains($html, "body.set('active', active ? '0' : '1')"));

    $legacyField = ExtensionKernel::describeSetting([
        'key' => 'legacy_help', 'label' => '旧字段', 'type' => 'text',
        'description' => '旧版插件字段说明',
    ], 'plugin');
    $check('Legacy plugin descriptions remain visible in normalized settings', is_array($legacyField)
        && ($legacyField['desc'] ?? '') === '旧版插件字段说明');

    $schema = ['tables' => [['name' => 'records', 'columns' => [
        ['name' => 'id', 'type' => 'bigint', 'unsigned' => true, 'autoIncrement' => true],
        ['name' => 'user_id', 'type' => 'bigint', 'unsigned' => true, 'default' => 0],
        ['name' => 'amount', 'type' => 'bigint', 'default' => -1],
    ], 'primary' => ['id']]]];
    $check('Unsigned extension schema is valid', ExtensionSchema::validateDeclaration($schema, 'plugin', 'qa_foundation') === null);
    ExtensionSchema::sync('plugin', 'qa_foundation', $schema);
    $columns = DB::fetchAll('SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['pf_plugin_qa_foundation_records']);
    $types = array_column($columns, 'COLUMN_TYPE', 'COLUMN_NAME');
    $check('Extension ID types match core IDs', ($types['user_id'] ?? '') === 'bigint unsigned');
    $check('Signed business integers remain signed', ($types['amount'] ?? '') === 'bigint');
    $bad = $schema;
    $bad['tables'][0]['columns'][1]['type'] = 'text';
    $check('Unsigned is rejected for text', ExtensionSchema::validateDeclaration($bad, 'plugin', 'qa_foundation') !== null);
    $bad = $schema;
    $bad['tables'][0]['columns'][1]['default'] = -1;
    $check('Negative unsigned default is rejected', ExtensionSchema::validateDeclaration($bad, 'plugin', 'qa_foundation') !== null);
    $bad = $schema;
    $bad['tables'][0]['columns'][1]['unsigned'] = false;
    $check('Existing extension signedness is not silently changed', $throws(fn () => ExtensionSchema::sync('plugin', 'qa_foundation', $bad)));

    DB::execute("INSERT INTO micro_statuses (id,content,status,author_id) VALUES (1,'micro fixture','PUBLISHED',2)");
    DB::execute("INSERT INTO pages (id,title,slug,content,status) VALUES (1,'page fixture','page-fixture','body','PUBLISHED')");
    $check('Published page repository returns visible content', ContentRepository::publishedPageBySlug('page-fixture')['id'] === 1
        && count(ContentRepository::publishedPages()) === 1);
    DB::execute('UPDATE pages SET deleted_at=NOW() WHERE id=1');
    $check('Soft-deleted pages are excluded from all shared public reads', ContentRepository::publishedPageBySlug('page-fixture') === null
        && ContentRepository::publishedPageById(1) === null && ContentRepository::publishedPages() === []);
    DB::execute("INSERT INTO comments (post_id,author_name,author_email,content,user_id) VALUES (1,'fixture','fixture@invalid.test','comment fixture',2)");
    PostReactions::change(1, 'like', 2, true);
    Session::set('user_id', 1);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
    $delete = $factory->createServerRequest('POST', '/admin/users/2/delete')->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->withParsedBody(['_csrf' => Session::csrfToken()]);
    $response = (new Pafish\Admin\UsersController())->delete($delete, new Response(), ['id' => 2]);
    $check('User deletion preserves content transfer', $response->getStatusCode() === 200
        && (int) DB::value('SELECT author_id FROM posts WHERE id=1') === 1
        && (int) DB::value('SELECT author_id FROM micro_statuses WHERE id=1') === 1);
    $check('User deletion anonymizes comments', DB::value('SELECT user_id FROM comments WHERE post_id=1') === null
        && (int) DB::value('SELECT COUNT(*) FROM comments WHERE post_id=1') === 1);
    $check('User deletion preserves anonymous and other user counts', (int) DB::value('SELECT like_count FROM posts WHERE id=1') === 2);
    DB::execute('DELETE FROM posts WHERE id=1');
    $check('Real foreign keys purge comments and logged-in reactions', (int) DB::value('SELECT COUNT(*) FROM comments') === 0
        && (int) DB::value('SELECT COUNT(*) FROM post_reactions') === 0);

    file_put_contents($fixtureDir . '/001_dml.sql', "INSERT INTO fixture_log VALUES (10, 'a;b');\nINSERT INTO missing_fixture VALUES (1);");
    $check('Failed DML migration is reported', $throws(fn () => Migrator::run($pdo, $fixtureDir)));
    $check('Failed DML rolls back and is not recorded', DB::value('SELECT value FROM fixture_log WHERE id=10') === null
        && !in_array('001_dml', Migrator::appliedVersions($pdo), true));
    file_put_contents($fixtureDir . '/001_dml.sql', "INSERT INTO fixture_log VALUES (10, 'a;b');");
    $check('Failed migration can be retried', in_array('001_dml', Migrator::run($pdo, $fixtureDir)['applied'], true));
    $check('Successful migration is not repeated', Migrator::run($pdo, $fixtureDir) === ['applied' => []]);
    file_put_contents($fixtureDir . '/002_ddl.sql', "ALTER TABLE fixture_log ADD COLUMN note VARCHAR(40) NULL; INSERT INTO fixture_log (id,value) VALUES (11,'rollback'); INSERT INTO missing_fixture VALUES (1);");
    $check('Failure after DDL is reported', $throws(fn () => Migrator::run($pdo, $fixtureDir)));
    $check('DML following DDL still rolls back', DB::value('SELECT value FROM fixture_log WHERE id=11') === null);
    $check('DDL remains present for retry', $pdo->query('SELECT note FROM fixture_log LIMIT 1') !== false);
    file_put_contents($fixtureDir . '/002_ddl.sql', "ALTER TABLE fixture_log ADD COLUMN note VARCHAR(40) NULL; INSERT INTO fixture_log (id,value) VALUES (11,'retry');");
    $check('Partial DDL migration can be resumed', in_array('002_ddl', Migrator::run($pdo, $fixtureDir)['applied'], true));
    file_put_contents($fixtureDir . '/003_bad.sql', 'SET FOREIGN_KEY_CHECKS=0; INSERT INTO missing_fixture VALUES (1);');
    $throws(fn () => Migrator::run($pdo, $fixtureDir));
    $check('Failed migration restores foreign key session settings', (int) $pdo->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn() === 1);
    file_put_contents($fixtureDir . '/003_bad.sql', "INSERT INTO fixture_log (id,value) VALUES (12,'must not run'); SELECT 'broken;");
    $throws(fn () => Migrator::run($pdo, $fixtureDir));
    $check('Whole script is parsed before any write', DB::value('SELECT value FROM fixture_log WHERE id=12') === null);
    unlink($fixtureDir . '/003_bad.sql');
    file_put_contents($fixtureDir . '/004_php.php', str_replace('fixture_log', $pdo->prefix . 'fixture_log', <<<'PHP'
<?php
return static function (PDO $pdo): void {
    $pdo->exec("INSERT INTO fixture_log (id,value) VALUES (20,'php')");
};
PHP));
    $phpMigration = Migrator::run($pdo, $fixtureDir);
    $check('PHP data migration runs and records its version', in_array('004_php', $phpMigration['applied'], true)
        && DB::value('SELECT value FROM fixture_log WHERE id=20') === 'php');
    $check('Successful PHP migration is not repeated', Migrator::run($pdo, $fixtureDir) === ['applied' => []]
        && (int) DB::value('SELECT COUNT(*) FROM fixture_log WHERE id=20') === 1);
    file_put_contents($fixtureDir . '/005_php_retry.php', str_replace('fixture_log', $pdo->prefix . 'fixture_log', <<<'PHP'
<?php
return static function (PDO $pdo): void {
    throw new RuntimeException('retry');
};
PHP));
    $check('Failed PHP data migration is not recorded', $throws(fn () => Migrator::run($pdo, $fixtureDir))
        && !in_array('005_php_retry', Migrator::appliedVersions($pdo), true));
    file_put_contents($fixtureDir . '/005_php_retry.php', str_replace('fixture_log', $pdo->prefix . 'fixture_log', <<<'PHP'
<?php
return static function (PDO $pdo): void {
    $pdo->exec("INSERT INTO fixture_log (id,value) VALUES (21,'retry')");
};
PHP));
    $check('Failed PHP migration can be retried', in_array('005_php_retry', Migrator::run($pdo, $fixtureDir)['applied'], true)
        && DB::value('SELECT value FROM fixture_log WHERE id=21') === 'retry');
    $pdo->beginTransaction();
    $check('Migration refuses an existing business transaction', $throws(fn () => Migrator::run($pdo, $fixtureDir)) && $pdo->inTransaction());
    $pdo->rollBack();
    file_put_contents($fixtureDir . '/004_race.sql', "INSERT INTO fixture_log (id,value) VALUES (14,'once'); SELECT SLEEP(0.3);");
    $results = $race(['migrate', $pdo->prefix, $fixtureDir], 2);
    $check('Concurrent migrations execute a file only once', count(array_filter($results, fn ($r) => in_array('004_race', $r['applied'], true))) === 1
        && DB::value('SELECT value FROM fixture_log WHERE id=14') === 'once');
} finally {
    foreach (['register','reset'] as $purpose) EmailCode::forget($purpose);
    if (isset($email)) foreach (['register','reset'] as $purpose) \Pafish\Core\RateLimiter::clear('email-code-send',$email . "\0" . $purpose);
    if (isset($reactionIp)) \Pafish\Core\RateLimiter::clear('post-reaction-ip', $reactionIp);
    $pdo->cleanup();
    $property->setValue(null, null);
    foreach (glob($fixtureDir . '/*.sql') ?: [] as $file) { unlink($file); }
    foreach (glob($fixtureDir . '/*.php') ?: [] as $file) { unlink($file); }
    rmdir($fixtureDir);
}
echo "{$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed ? 1 : 0);
