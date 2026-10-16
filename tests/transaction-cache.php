<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require PAFISH_ROOT . '/app/Core/helpers.php';
require __DIR__ . '/Fixtures/MySql.php';

use Pafish\Core\{Cache, Config, DB, SqlScript};
use Pafish\Services\{Categories, MicroStatuses};
use Pafish\Tests\MySql;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
$passed = $failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$throws = static function (callable $fn): bool { try { $fn(); } catch (Throwable) { return true; } return false; };
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
$pdoProperty = new ReflectionProperty(DB::class, 'pdo');
$pdoProperty->setValue(null, null);
$check('Cache transaction check does not establish a DB connection', !DB::inTransaction() && $pdoProperty->getValue() === null);
$pdoProperty->setValue(null, $pdo);
$prefix = 'qa_tx.' . bin2hex(random_bytes(6)) . '.';
$key = $prefix . 'snapshot';
$file = static fn (string $key): string => PAFISH_ROOT . '/runtime/cache/' . sha1($key) . '.php';
$categoryKey = 'categories.tree.' . random_int(1000000000, 2000000000);
$microKey = 'micro.latest.19';
try {
    foreach (SqlScript::split(file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $sql) $pdo->query($sql);
    DB::execute("INSERT INTO users (id,username,email,password_hash,role) VALUES (1,'cache-admin','cache@invalid.test','fixture','ADMIN')");
    $events = [];
    DB::afterCommit(static function () use (&$events): void { $events[] = 'immediate'; });
    $check('No-transaction callback runs immediately', $events === ['immediate']);
    $events = [];
    DB::transaction(static function () use (&$events, $check): void {
        DB::afterCommit(static function () use (&$events): void { $events[] = 'outer'; });
        DB::transaction(static function () use (&$events): void {
            DB::afterCommit(static function () use (&$events): void { $events[] = 'inner'; });
        });
        $check('Successful savepoint does not publish before outer commit', $events === []);
    });
    $check('Callbacks run in registration order after root commit', $events === ['outer', 'inner'] && !DB::inTransaction());
    $events = [];
    $throws(static fn () => DB::transaction(static function () use (&$events): void {
        DB::transaction(static function () use (&$events): void {
            DB::afterCommit(static function () use (&$events): void { $events[] = 'leak'; });
        });
        throw new RuntimeException('rollback outer');
    }));
    $check('Outer rollback discards successful child callbacks', $events === []);
    DB::transaction(static function () use (&$events, $throws): void {
        DB::afterCommit(static function () use (&$events): void { $events[] = 'before'; });
        $throws(static fn () => DB::transaction(static function () use (&$events): void {
            DB::afterCommit(static function () use (&$events): void { $events[] = 'rolled_back'; });
            throw new RuntimeException('rollback child');
        }));
        DB::afterCommit(static function () use (&$events): void { $events[] = 'after'; });
    });
    $check('Child rollback discards only its own callbacks', $events === ['before', 'after']);
    $events = [];
    DB::transaction(static function () use (&$events): void {
        DB::execute("INSERT INTO categories(name,slug) VALUES('committed','committed')");
        DB::afterCommit(static function (): void { throw new RuntimeException('qa expected callback failure'); });
        DB::afterCommit(static function () use (&$events): void { $events[] = 'survived'; });
    });
    $check('Post-commit failure leaves committed data and later callbacks intact', $events === ['survived']
        && (int) DB::value('SELECT COUNT(*) FROM categories') === 1);
    Cache::set($key, 'committed');
    DB::transaction(static function () use ($key, $file, $check): void {
        $check('Transaction reads bypass shared cache', Cache::get($key, 'miss') === 'miss');
        Cache::set($key, 'uncommitted');
        $check('Transaction never publishes its snapshot', (include $file($key))['value'] === 'committed');
        Cache::delete($key);
        $check('Invalidation waits until outer commit', is_file($file($key)));
    });
    $check('Commit invalidates shared key', Cache::get($key) === null);
    Cache::set($key, 'keep');
    $throws(static fn () => DB::transaction(static function () use ($prefix): void {
        Cache::clearPrefix($prefix);
        throw new RuntimeException('rollback invalidation');
    }));
    $check('Rollback preserves original cache', Cache::get($key) === 'keep');
    DB::transaction(static function () use ($key, $throws): void {
        $throws(static fn () => DB::transaction(static function () use ($key): void {
            Cache::delete($key); throw new RuntimeException('child');
        }));
    });
    $check('Rolled-back savepoint cannot invalidate cache later', Cache::get($key) === 'keep');
    DB::transaction(static function () use ($prefix): void { DB::transaction(static fn () => Cache::clearPrefix($prefix)); });
    $check('Successful child prefix invalidation follows root commit', Cache::get($key) === null);
    $pdo->beginTransaction();
    $check('Manual PDO transactions reject unobservable post-commit callbacks', $throws(static fn () => DB::afterCommit(static fn () => null)));
    $check('Savepoint inside manual transaction cannot publish callbacks', $throws(static fn () => DB::transaction(static fn () => Cache::clearPrefix($prefix))) && $pdo->inTransaction());
    $beforeManualWrite = is_file($file($key)) ? file_get_contents($file($key)) : false;
    Cache::set($key, 'manual snapshot');
    $check('Manual transaction also suppresses cache publication', (is_file($file($key)) ? file_get_contents($file($key)) : false) === $beforeManualWrite);
    $pdo->rollBack();
    $events = [];
    DB::transaction(static function () use (&$events): void { DB::afterCommit(static function () use (&$events): void { $events[] = 'fresh'; }); });
    $check('Manual transaction rejection leaves next transaction usable', $events === ['fresh']);
    $check('Premature business commit is rejected', $throws(static fn () => DB::transaction(static function () use ($pdo): void { $pdo->commit(); })));
    $events = [];
    $check('Premature commit cannot flush pending or newly registered callbacks', $throws(static fn () => DB::transaction(
        static function () use ($pdo, &$events): void {
            DB::afterCommit(static function () use (&$events): void { $events[] = 'pending'; });
            $pdo->commit();
            DB::afterCommit(static function () use (&$events): void { $events[] = 'late'; });
        }
    )) && $events === []);

    Cache::set($categoryKey, [['name' => 'stale cache']]);
    $throws(static fn () => DB::transaction(static function () use ($categoryKey, $check): void {
        DB::execute("INSERT INTO categories(name,slug) VALUES('uncommitted','uncommitted')");
        $tree = Categories::tree((int) substr($categoryKey, strlen('categories.tree.')));
        $check('Category tree reads its uncommitted rows rather than old cache', in_array('uncommitted', array_column($tree, 'name'), true));
        throw new RuntimeException('category rollback');
    }));
    $check('Rolled-back category tree never replaces shared cache', Cache::get($categoryKey) === [['name' => 'stale cache']]
        && (int) DB::value("SELECT COUNT(*) FROM categories WHERE slug='uncommitted'") === 0);
    Cache::set($microKey, [['content' => 'old micro']]);
    $microInput = ['content' => 'fixture micro', 'status' => 'PUBLISHED', 'published_at' => date('Y-m-d H:i:s', time() - 20)];
    $throws(static fn () => DB::transaction(static function () use ($microInput, $file, $microKey, $check): void {
        MicroStatuses::save($microInput, null, 1);
        $latest = MicroStatuses::latest(19);
        $check('Micro save nested transaction keeps shared cache until commit', (include $file($microKey))['value'] === [['content' => 'old micro']]);
        $check('Micro latest sees own pending save', count($latest) === 1 && $latest[0]['content'] === 'fixture micro');
        throw new RuntimeException('micro rollback');
    }));
    $check('Micro rollback preserves shared cache and discards content', Cache::get($microKey) === [['content' => 'old micro']]
        && (int) DB::value('SELECT COUNT(*) FROM micro_statuses') === 0);
    DB::transaction(static fn () => MicroStatuses::save($microInput, null, 1));
    $check('Micro commit clears shared cache after content is durable', Cache::get($microKey) === null
        && (int) DB::value('SELECT COUNT(*) FROM micro_statuses') === 1);
    $resolveCategory=new ReflectionMethod(Pafish\Admin\PostsController::class,'resolveNewCategory');
    $categoryName='inline-'.$pdo->prefix;
    Cache::set($categoryKey,[['name'=>'old category snapshot']]);
    $throws(static fn()=>DB::transaction(static function() use ($resolveCategory,$categoryName,$categoryKey,$file,$check): void {
        $resolveCategory->invoke(null,$categoryName);
        $check('Inline editor category creation defers cache invalidation',is_file($file($categoryKey)));
        throw new RuntimeException('rollback inline category');
    }));
    $check('Rolled-back inline category preserves cache and leaves no row',Cache::get($categoryKey)===[['name'=>'old category snapshot']]
        && (int)DB::value('SELECT COUNT(*) FROM categories WHERE name=?',[$categoryName])===0);
    DB::transaction(static fn()=>$resolveCategory->invoke(null,$categoryName));
    $check('Committed inline category invalidates category snapshots',Cache::get($categoryKey)===null);
    $tree=DB::transaction(static fn()=>Categories::tree());
    $check('Category tree includes categories created inside the editor',in_array($categoryName,array_column($tree,'name'),true));
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    Cache::clearPrefix($prefix);
    Cache::delete($categoryKey);
    Cache::delete($microKey);
    $pdo->cleanup();
}
echo json_encode(['passed' => $passed, 'failed' => $failed], JSON_UNESCAPED_UNICODE) . PHP_EOL;
ob_end_flush();
exit($failed > 0 ? 1 : 0);
