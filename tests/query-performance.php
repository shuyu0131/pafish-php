<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require PAFISH_ROOT . '/app/Core/helpers.php';
require __DIR__ . '/Fixtures/MySql.php';

use Pafish\Core\{Config, DB, SqlScript};
use Pafish\Tests\MySql;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
$passed = $failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$indexes = [
    ['posts', 'idx_posts_public', 'status,deleted_at,is_pinned,published_at,id'],
    ['posts', 'idx_posts_list', 'deleted_at,published_at,id'],
];
$probe = static function (string $sql, array $params = []): array {
    $plan = DB::fetchAll('EXPLAIN ' . $sql, $params);
    // Two warm runs; timings are evidence, never a machine-dependent test assertion.
    DB::fetchAll($sql, $params);
    $start = hrtime(true); $rows = DB::fetchAll($sql, $params);
    $ms = round((hrtime(true) - $start) / 1e6, 3);
    $analyze = null;
    try { $analyze = DB::fetchAll('EXPLAIN ANALYZE ' . $sql, $params); } catch (PDOException) {}
    return ['plan' => $plan, 'ms_warm' => $ms, 'returned_rows' => count($rows),
        'result_ids' => array_column($rows, 'id'), 'analyze' => $analyze];
};
$report = ['server' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'fixtures' => [
    'posts' => 50000, 'comments' => 150000, 'micro_statuses' => 30000, 'uploads' => 20000,
    'distribution' => '80% published, scattered pinned/private/trash/future; 40k extra comments on post 1',
], 'before' => [], 'after' => []];
try {
    foreach (SqlScript::split(file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $sql) $pdo->query($sql);
    // Recreate the old index definitions even when the current baseline is already optimized.
    foreach ($indexes as [$table,$name]) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute([$table, $name]);
        if ($stmt->fetchColumn()) $pdo->query("ALTER TABLE $table DROP INDEX $name");
    }
    $pdo->query('ALTER TABLE comments DROP INDEX idx_comments_post_status, ADD INDEX idx_comments_post_status(post_id,status)');
    $pdo->query('CREATE TABLE fixture_log (n INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->query('INSERT INTO fixture_log VALUES ' . implode(',', array_map(static fn ($n) => '(' . $n . ')', range(0, 999))));
    $numbers = 'SELECT a.n+b.n*1000+1 AS n FROM fixture_log a CROSS JOIN fixture_log b WHERE b.n<?';
    $insert = static function (string $sql, int $blocks) use ($numbers): void {
        DB::execute($sql . ' FROM (' . $numbers . ') seq', [$blocks]);
    };
    DB::execute("INSERT INTO users(id,username,email,password_hash,role) VALUES(1,'qa-performance','performance@invalid.test','fixture','ADMIN')");
    DB::execute("INSERT INTO categories(id,name,slug) VALUES(1,'performance','performance')");
    $insert("INSERT INTO posts(id,title,slug,content,status,author_id,category_id,is_pinned,published_at,deleted_at)
        SELECT n,CONCAT('Fixture ',n),CONCAT('perf-',n),REPEAT('content sample ',8),
        CASE WHEN MOD(n,10)<8 THEN 'PUBLISHED' WHEN MOD(n,10)=8 THEN 'DRAFT' ELSE 'SCHEDULED' END,
        1,1,MOD(n,400)=0,
        CASE WHEN MOD(n,11)=0 THEN DATE_ADD('2026-10-01',INTERVAL 3650 DAY) ELSE DATE_SUB('2026-10-01',INTERVAL n MINUTE) END,
        IF(MOD(n,19)=0,'2026-09-01',NULL)", 50);
    $insert("INSERT INTO comments(id,post_id,author_name,author_email,content,status,created_at)
        SELECT n,IF(n>110000,1,MOD(n,50000)+1),'fixture','fixture@invalid.test',CONCAT('Comment ',n),
        IF(MOD(n,7)=0,'PENDING','APPROVED'),DATE_ADD('2026-01-01',INTERVAL n SECOND)", 150);
    $insert("INSERT INTO micro_statuses(id,content,status,author_id,is_pinned,is_private,published_at,deleted_at)
        SELECT n,CONCAT('Micro ',n),IF(MOD(n,10)<8,'PUBLISHED','DRAFT'),1,MOD(n,400)=0,MOD(n,7)=0,
        CASE WHEN MOD(n,11)=0 THEN '2036-01-01' ELSE DATE_SUB('2026-10-01',INTERVAL n MINUTE) END,
        IF(MOD(n,19)=0,'2026-09-01',NULL)", 30);
    $insert("INSERT INTO uploads(id,original_name,url,mime,size,uploader_id,created_at)
        SELECT n,CONCAT('image-',n,'.png'),CONCAT('/uploads/performance-',n,'.png'),'image/png',1024,1,
        DATE_SUB('2026-10-01',INTERVAL n MINUTE)", 20);
    foreach (['posts','comments','micro_statuses','uploads'] as $table) $pdo->query("ANALYZE TABLE $table");
    echo "Seeded isolated 250000-row workload\n"; ob_flush(); flush();

    $queries = [
        'homepage' => ["SELECT p.id,p.title,p.content FROM posts p WHERE " . Pafish\Services\Posts::publicWhere('p') . ' ORDER BY p.is_pinned DESC,p.published_at DESC,p.id DESC LIMIT 10', []],
        'micro_latest' => ["SELECT m.* FROM micro_statuses m WHERE m.status='PUBLISHED' AND m.is_private=0 AND m.deleted_at IS NULL AND m.published_at IS NOT NULL AND m.published_at<=NOW() ORDER BY m.is_pinned DESC,m.published_at DESC,m.id DESC LIMIT 20", []],
        'admin_posts' => ['SELECT p.id,p.title FROM posts p WHERE p.deleted_at IS NULL ORDER BY p.published_at DESC,p.id DESC LIMIT 20', []],
        'comment_review' => ["SELECT c.id,c.content FROM comments c WHERE c.status='PENDING' ORDER BY c.created_at DESC LIMIT 20", []],
        'media_latest' => ['SELECT id,original_name FROM uploads ORDER BY id DESC LIMIT 48', []],
        'category_list' => ["SELECT p.id,p.title FROM posts p WHERE " . Pafish\Services\Posts::publicWhere('p') . ' AND p.category_id=1 ORDER BY p.is_pinned DESC,p.category_pinned DESC,p.published_at DESC LIMIT 10', []],
        'scheduled_due' => ["SELECT id FROM posts WHERE status='SCHEDULED' AND deleted_at IS NULL AND published_at<=NOW()", []],
    ];
    foreach ($queries as $name => [$sql,$params]) $report['before'][$name] = $probe($sql,$params);
    $legacy = "SELECT c.post_id,c.author_name,c.content,c.created_at FROM comments c WHERE c.post_id IN (?,?) AND c.status='APPROVED' AND c.parent_id IS NULL ORDER BY c.created_at DESC";
    $report['before']['recent_comments'] = $probe($legacy,[1,2]);
    foreach ($indexes as [$table,$name,$columns]) $pdo->query("ALTER TABLE $table ADD INDEX $name($columns)");
    $pdo->query('ALTER TABLE comments DROP INDEX idx_comments_post_status, ADD INDEX idx_comments_post_status(post_id,status,parent_id,created_at,id)');
    foreach (['posts','comments','micro_statuses'] as $table) $pdo->query("ANALYZE TABLE $table");
    foreach ($queries as $name => [$sql,$params]) $report['after'][$name] = $probe($sql,$params);
    $observed = [];
    $pdo->afterExecute = static function (string $sql) use (&$observed): void { $observed[] = $sql; };
    $recent = Pafish\Services\Comments::recentForPosts([1,2]);
    $pdo->afterExecute = null;
    $bounded = $observed[0];
    $report['after']['recent_comments'] = $probe($bounded,[1,2]);
    foreach (['homepage'=>'idx_posts_public','admin_posts'=>'idx_posts_list'] as $name=>$index) {
        $before = $report['before'][$name]; $after = $report['after'][$name];
        $check($name . ' uses measured composite index', $after['plan'][0]['key'] === $index);
        $check($name . ' no longer sorts all qualifying rows', !str_contains($after['plan'][0]['Extra'], 'filesort'));
        $check($name . ' returns identical ordered IDs', $before['result_ids'] === $after['result_ids']);
    }
    $check('Micro keeps its existing ordered index without a new index', $report['after']['micro_latest']['plan'][0]['key'] === 'idx_micro_pinned_published'
        && $report['before']['micro_latest']['result_ids'] === $report['after']['micro_latest']['result_ids']);
    $check('Recent comment transfer is bounded per article', $report['before']['recent_comments']['returned_rows'] > 30000
        && $report['after']['recent_comments']['returned_rows'] <= 12);
    $check('Recent comment branches use the existing extended index without filesort', count(array_filter(
        $report['after']['recent_comments']['plan'], static fn ($p) => $p['key'] === 'idx_comments_post_status' && !str_contains($p['Extra'],'filesort'))) === 2);
    $check('Production recent-comment service transfers at most six rows per post', count($observed) === 1
        && count($recent[1] ?? []) <= 6 && count($recent[2] ?? []) <= 6);
    foreach (['comment_review','media_latest','scheduled_due'] as $name) {
        $check($name . ' retains indexed access', $report['after'][$name]['plan'][0]['key'] !== null);
    }
    $report['limits'] = 'Synthetic local MySQL workload, warm timings, not production latency or concurrent load. Admin keyword/sort/deep-offset and descendant-category ordering need separate evaluation. EXPLAIN ANALYZE optional by server version.';
    $report['passed'] = $passed; $report['failed'] = $failed;
    if (is_dir(PAFISH_ROOT.'/.design-review')) file_put_contents(PAFISH_ROOT.'/.design-review/query-performance.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
    foreach (['homepage','admin_posts','micro_latest','recent_comments'] as $name) echo $name . ': ' . json_encode([
        'before_ms'=>$report['before'][$name]['ms_warm'],'after_ms'=>$report['after'][$name]['ms_warm'],
        'before_rows'=>$report['before'][$name]['returned_rows'],'after_rows'=>$report['after'][$name]['returned_rows']]) . "\n";
} finally { $pdo->cleanup(); }
echo json_encode(['passed'=>$passed,'failed'=>$failed]) . "\n";
ob_end_flush();
exit($failed > 0 ? 1 : 0);
