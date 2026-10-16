<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require PAFISH_ROOT . '/app/Core/helpers.php';
require __DIR__ . '/Fixtures/MySql.php';

use Pafish\Core\{Config, DB, SqlScript, Session};
use Pafish\Services\{Comments, Migrator, Settings};
use Pafish\Http\{HomeController, Listings};
use Pafish\Tests\MySql;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
Session::start();
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
$passed = $failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$queries = [];
try {
    foreach (SqlScript::split(file_get_contents(PAFISH_ROOT.'/app/install/schema.sql')) as $sql) $pdo->query($sql);
    DB::execute("INSERT INTO users(id,username,email,password_hash,role) VALUES(1,'fixture-query','query@invalid.test','fixture','ADMIN')");
    for ($id=1;$id<=60;$id++) {
        DB::execute("INSERT INTO posts(id,title,slug,content,status,published_at,author_id,is_pinned) VALUES(?,? ,?,'body','PUBLISHED','2026-01-01',1,?)",
            [$id,'Post '.$id,'query-'.$id,(int)($id===1)]);
    }
    $add = static function (int $post, string $text, string $status='APPROVED', ?int $parent=null): int {
        DB::execute("INSERT INTO comments(post_id,author_name,author_email,content,status,parent_id,created_at) VALUES(?,'fixture','query@invalid.test',?,?,?,'2026-01-01')",[$post,$text,$status,$parent]);
        return DB::lastInsertId();
    };
    $first = $add(1,'comment-1');
    for ($i=2;$i<=14;$i++) $add(1,'comment-'.$i);
    $add(1,'pending','PENDING'); $add(1,'reply','APPROVED',$first); $add(2,'other-post');
    $recent = Comments::recentForPosts([1,2]);
    $check('Recent preview is limited to six approved roots per article', count($recent[1])===6
        && array_column($recent[1],'content')===['comment-14','comment-13','comment-12','comment-11','comment-10','comment-9']);
    $check('Other articles retain their own recent comments', $recent[2][0]['content']==='other-post');
    $check('Preview contains no email, IP or internal ordering IDs', array_keys($recent[1][0])===['author_name','content','created_at']);
    $pdo->afterExecute = static function (string $sql) use (&$queries): void { $queries[]=$sql; };
    $check('Empty IDs do not query the database', Comments::recentForPosts([])===[] && $queries===[]);
    $queries=[]; $deduped=Comments::recentForPosts([1,1,'1',0,-1,2]);
    $check('Duplicate and invalid IDs do not duplicate results or SQL branches', count($queries)===1
        && substr_count($queries[0],'LIMIT 6')===2 && $deduped===$recent);
    $queries=[]; Comments::recentForPosts(range(1,60));
    $check('Long input uses bounded batches rather than one unbounded UNION', count($queries)===2
        && substr_count($queries[0],'LIMIT 6')===50 && substr_count($queries[1],'LIMIT 6')===10);
    $pdo->afterExecute=null;
    Settings::setMany(['home_page_id'=>'','posts_per_page'=>'10','theme:default:recent_posts_limit'=>'10']);
    $request=(new ServerRequestFactory())->createServerRequest('GET','/');
    [$posts] = Listings::published('',[],$request);
    $check('Shared list retains pinning and has deterministic timestamp ties', array_map('intval',array_column($posts,'id'))===[1,60,59,58,57,56,55,54,53,52]);
    $check('Shared list attaches bounded recent previews', $posts[0]['recent_comments']===$recent[1]);
    $GLOBALS['pafish_tpl_ctx']=[];
    // Render without reading/writing the real site's shared file-cache snapshots.
    DB::transaction(static fn () => (new HomeController())->index($request,new Response()));
    $home=$GLOBALS['pafish_tpl_ctx']['posts']??[];
    $check('Homepage and shared lists use the same recent-comment service', count($home)>0 && $home[0]['recent_comments']===$recent[1]);
    $check('Homepage uses deterministic ID ordering for equal publication times', array_map('intval',array_column($home,'id'))===array_map('intval',array_column($posts,'id')));

    // Real old indexes, then a partial upgrade and retry; content and FKs survive.
    $pdo->query('ALTER TABLE posts DROP INDEX idx_posts_public, DROP INDEX idx_posts_list');
    $pdo->query('ALTER TABLE comments DROP INDEX idx_comments_post_status, ADD INDEX idx_comments_post_status(post_id,status)');
    $applied=Migrator::run($pdo,PAFISH_ROOT.'/migrations')['applied'];
    $check('Old installation receives measured indexes', in_array('query_indexes',$applied,true));
    $check('Migration preserves all content', (int)DB::value('SELECT COUNT(*) FROM posts')===60 && (int)DB::value('SELECT COUNT(*) FROM comments')===17);
    $check('Full migration set is repeatable', Migrator::run($pdo,PAFISH_ROOT.'/migrations')===['applied'=>[]]);
    DB::execute("DELETE FROM schema_migrations WHERE version='query_indexes'");
    $pdo->query('ALTER TABLE posts DROP INDEX idx_posts_list');
    $check('Partial index upgrade can be resumed', in_array('query_indexes',Migrator::run($pdo,PAFISH_ROOT.'/migrations')['applied'],true));
    $denied=false;
    try { DB::execute("INSERT INTO comments(post_id,author_name,author_email,content) VALUES(99999,'x','x@invalid.test','orphan')"); }
    catch (PDOException) { $denied=true; }
    $check('Extended comment index preserves the article foreign key', $denied);
    DB::execute("DELETE FROM schema_migrations WHERE version='query_indexes'");
    $pdo->query('ALTER TABLE posts DROP INDEX idx_posts_public, ADD INDEX idx_posts_public(status)');
    $rejected=false;
    try { Migrator::run($pdo,PAFISH_ROOT.'/migrations'); } catch (RuntimeException) { $rejected=true; }
    $check('Unexpected same-name index drift is rejected rather than silently accepted', $rejected
        && (int)DB::value("SELECT COUNT(*) FROM schema_migrations WHERE version='query_indexes'")===0);
    $check('Rejected index migration preserves content', (int)DB::value('SELECT COUNT(*) FROM posts')===60
        && (int)DB::value('SELECT COUNT(*) FROM comments')===17);
} finally {
    $pdo->afterExecute=null;
    $pdo->cleanup();
}
echo json_encode(['passed'=>$passed,'failed'=>$failed])."\n";
ob_end_flush();
exit($failed>0?1:0);
