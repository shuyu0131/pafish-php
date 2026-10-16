<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require __DIR__ . '/Fixtures/MySql.php';
require PAFISH_ROOT . '/app/Core/helpers.php';

use Pafish\Core\{Auth, Cache, ClientIp, Config, DB, Hooks, RateLimiter, Session, SqlScript};
use Pafish\Services\{Backup, Comments, ContentTransfer, Migrator, Posts, Scheduler, Settings};
use Pafish\Tests\MySql;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
Session::start();
$passed = $failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$reject = static function (callable $fn, ?int $code=null): bool {
    try { $fn(); } catch (Throwable $e) { return $code === null || $e->getCode() === $code; }
    return false;
};
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
$property = new ReflectionProperty(DB::class, 'pdo');
$property->setValue(null, $pdo);
$temp = tempnam(sys_get_temp_dir(), 'pf_lifecycle_');
$restore = new ReflectionMethod(Backup::class, 'restorePhp');
$quote = new ReflectionMethod(Backup::class, 'quote');
$dump = new ReflectionMethod(Backup::class, 'exportTo');
$factory = new ServerRequestFactory();
$login = static function (?int $id): void {
    Session::set('user_id', $id);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
    (new ReflectionProperty(Auth::class, 'activityTouched'))->setValue(null, false);
};
$add = static function (int $post, ?int $parent=null, string $status='APPROVED'): int {
    DB::execute('INSERT INTO comments (post_id,parent_id,author_name,author_email,content,status) VALUES (?,?,\'fixture\',\'fixture@example.invalid\',\'fixture\',?)', [$post,$parent,$status]);
    return DB::lastInsertId();
};
$nodeCount = static function (array $nodes) use (&$nodeCount): int {
    $n=0; foreach ($nodes as $node) $n+=1+$nodeCount($node['replies']); return $n;
};
$nodeDepth = static function (array $nodes) use (&$nodeDepth): int {
    $n=0; foreach ($nodes as $node) $n=max($n,1+$nodeDepth($node['replies'])); return $n;
};
try {
    foreach (SqlScript::split(file_get_contents(PAFISH_ROOT.'/app/install/schema.sql')) as $sql) $pdo->query($sql);
    Settings::reset();
    Settings::setMany(['api_enabled'=>'true','api_key'=>'fixture-key','api_rate_limit'=>'0','comments_captcha_enabled'=>'false','comments_need_review'=>'true']);
    DB::execute("INSERT INTO users (id,username,email,password_hash,role) VALUES (1,'fixture-admin','admin@example.invalid','unused','ADMIN'),(2,'fixture-editor','editor@example.invalid','unused','EDITOR')");
    foreach ([1=>['PUBLISHED','2000-01-01 00:00:00',null,null],2=>['PUBLISHED','2000-01-01 00:00:00','fixture-hash',null],3=>['PUBLISHED','2099-01-01 00:00:00',null,null],4=>['DRAFT',null,null,null],5=>['PUBLISHED',null,null,'2000-01-01 00:00:00'],6=>['PUBLISHED',null,null,null]] as $id=>$v) {
        DB::execute('INSERT INTO posts (id,title,slug,content,status,published_at,password,deleted_at,author_id) VALUES (?,?,?,\'body\',?,?,?,?,1)',[$id,'fixture-title-'.$id,'fixture-'.$id,...$v]);
        $add($id);
    }
    $api = new Pafish\Api\V1Controller();
    foreach ([1=>200,2=>403,3=>404,4=>404,5=>404,6=>200] as $id=>$expected) {
        $req=$factory->createServerRequest('GET','/api/v1/comments')->withHeader('X-API-Key','fixture-key')->withQueryParams(['postId'=>(string)$id]);
        $response=$api->comments($req,new Response());
        $check('Comment API reading gate '.$id,$response->getStatusCode()===$expected);
        $response=$api->postDetail($req,new Response(),['slug'=>'fixture-'.$id]);
        $check('Post API uses same reading gate '.$id,$response->getStatusCode()===$expected);
    }
    $check('Password session starts locked',$reject(fn()=>Posts::findReadable(2,true),403));
    Session::unlockPost(2,'fixture-hash',time()+60);
    $check('Website session unlock is accepted',Posts::findReadable(2,true)['id']===2);
    $check('API never treats session as API password grant',$reject(fn()=>Posts::findReadable(2),403));
    DB::execute("UPDATE posts SET password='new-hash' WHERE id=2");
    $check('Password change invalidates old session grant',$reject(fn()=>Posts::findReadable(2,true),403));

    $commentHttp=new Pafish\Http\CommentApiController();
    foreach ([2=>403,3=>404,1=>200] as $id=>$expected) {
        $req=$factory->createServerRequest('POST','/api/comment',['REMOTE_ADDR'=>'198.51.100.'.(10+$id)])
            ->withHeader('User-Agent','Fixture Browser')->withParsedBody(['postId'=>(string)$id,'name'=>'fixture','email'=>'fixture@example.invalid','content'=>'HTTP fixture '.$id]);
        $check('Website comment submit respects article gate '.$id,$commentHttp->create($req,new Response())->getStatusCode()===$expected);
    }
    $login(1);
    $root=$add(1);$parent=$root;
    for($i=0;$i<22;$i++) $parent=$add(1,$parent);
    $tree=Pafish\Http\Comments::section(1,1);
    $check('Deep replies are all retained',$nodeCount($tree['roots'])===$tree['total']+1); // one own/admin pending echo
    $check('Display nesting stays bounded',$nodeDepth($tree['roots'])<=6);
    $events=Comments::moderate([$root],'SPAM');
    $check('Hiding root includes entire reply tree',count($events)===23 && DB::value('SELECT status FROM comments WHERE id=?',[$parent])==='SPAM');
    $events=Comments::moderate([$parent],'APPROVED');
    $check('Approving reply approves all ancestors',count($events)===23 && DB::value('SELECT status FROM comments WHERE id=?',[$root])==='APPROVED');
    $check('Repeated approval emits no duplicate events',Comments::moderate([$parent],'APPROVED')===[]);
    $pending=$add(1,null,'PENDING');
    $events=Comments::reply($pending,'admin reply');
    $check('Admin reply publishes parent and reply',count($events)===2 && DB::value('SELECT status FROM comments WHERE id=?',[$pending])==='APPROVED');
    DB::execute("UPDATE comments SET status='REJECTED' WHERE id=?",[$root]);
    $tree=Pafish\Http\Comments::section(1,1);
    $check('Historical approved descendants survive hidden parent',$nodeCount($tree['roots'])===$tree['total']+1);
    $login(null);
    $check('Reply under hidden ancestor is rejected',$reject(fn()=>Comments::create(['post_id'=>1,'parent_id'=>$parent,'author_name'=>'fixture','author_email'=>'fixture@example.invalid','content'=>'reply','status'=>'APPROVED']),400));
    $other=$add(6);
    $check('Cross article reply rejected',$reject(fn()=>Comments::create(['post_id'=>1,'parent_id'=>$other,'author_name'=>'fixture','author_email'=>'fixture@example.invalid','content'=>'reply','status'=>'APPROVED']),400));
    $login(2);
    $check('Editor cannot moderate another author comments',$reject(fn()=>Comments::moderate([$other],'SPAM'),403));
    $login(1);
    $seen=[];
    $off=Hooks::addAction('after_comment_delete',static function(array $data) use (&$seen):void { $seen[]=$data['id']; if(DB::pdo()->inTransaction()) throw new RuntimeException('event before commit'); });
    $events=Comments::delete([$root,$parent]);Comments::emit($events);$off();
    $check('Deep subtree deletes explicitly and emits each child',count($events)===23 && count($seen)===23 && DB::value('SELECT id FROM comments WHERE id=?',[$parent])===null);
    $root=$add(6);for($i=0;$i<22;$i++) $add(6);
    $first=Pafish\Http\Comments::section(6,1);$second=Pafish\Http\Comments::section(6,2);
    $check('Root pagination totals include all comments',$first['totalPages']===2 && $nodeCount($first['roots'])+$nodeCount($second['roots'])===$first['total']);

    $adminComments = new Pafish\Admin\CommentsController();
    $request = static fn (array $body) => $factory->createServerRequest('POST','/admin/comments')->withParsedBody($body);
    $httpRoot=$add(1,null,'PENDING');$httpChild=$add(1,$httpRoot,'PENDING');
    $r=$adminComments->status($request(['status'=>'APPROVED']),new Response(),['id'=>$httpChild]);
    $check('Admin status endpoint publishes ancestors',$r->getStatusCode()===200 && DB::value('SELECT status FROM comments WHERE id=?',[$httpRoot])==='APPROVED');
    $r=$adminComments->batch($request(['ids'=>[$httpRoot],'op'=>'spam']),new Response());
    $check('Admin batch endpoint hides descendants',$r->getStatusCode()===200 && DB::value('SELECT status FROM comments WHERE id=?',[$httpChild])==='SPAM');
    $r=$adminComments->reply($request(['content'=>'HTTP admin reply']),new Response(),['id'=>$httpChild]);
    $check('Admin reply endpoint restores ancestor chain',$r->getStatusCode()===200 && DB::value('SELECT status FROM comments WHERE id=?',[$httpRoot])==='APPROVED');
    $r=$adminComments->delete($request([]),new Response(),['id'=>$httpRoot]);
    $check('Admin delete endpoint removes complete subtree',$r->getStatusCode()===200 && DB::value('SELECT id FROM comments WHERE id=?',[$httpChild])===null);
    $cycleA=$add(1,null,'PENDING');$cycleB=$add(1,$cycleA,'PENDING');
    DB::execute('UPDATE comments SET parent_id=? WHERE id=?',[$cycleB,$cycleA]);
    $tree=Pafish\Http\Comments::section(1,1);
    $check('Historical pending cycle cannot produce a recursive response',json_encode($tree)!==false && $nodeDepth($tree['roots'])<=6);
    DB::execute('UPDATE comments SET parent_id=NULL WHERE id=?',[$cycleA]);Comments::delete([$cycleA]);

    // A separate connection tries to reply while moderation/deletion holds the article lock.
    foreach (['hide','delete'] as $operation) {
        $target=$add(1);$signal=tempnam(sys_get_temp_dir(),'pf_comment_');unlink($signal);
        $pdo->beginTransaction();DB::fetchOne('SELECT id FROM posts WHERE id=1 FOR UPDATE');
        $proc=proc_open([PHP_BINARY,__DIR__.'/Fixtures/worker.php','comment_create',$pdo->prefix,(string)$target,$signal],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,PAFISH_ROOT);
        fclose($pipes[0]);
        try {
            $deadline=microtime(true)+5;
            while(!is_file($signal) && microtime(true)<$deadline) usleep(20000);
            if(!is_file($signal)) throw new RuntimeException('Comment worker did not start');
            $operation==='hide' ? Comments::moderate([$target],'SPAM') : Comments::delete([$target]);
            $pdo->commit();
            $result=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
        } finally {
            if($pdo->inTransaction())$pdo->rollBack();
            fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
            if(is_file($signal))unlink($signal);
        }
        if($exit!==0)throw new RuntimeException($error);
        $check('Concurrent reply cannot bypass '.$operation,json_decode($result,true,512,JSON_THROW_ON_ERROR)['result']==='rejected' && (int)DB::value('SELECT COUNT(*) FROM comments WHERE parent_id=?',[$target])===0);
    }

    foreach([7,8] as $id) {
        DB::execute("INSERT INTO posts (id,title,slug,content,status,deleted_at,author_id) VALUES (?,? ,?,'fixture','PUBLISHED',NOW(),1)",[$id,'purge-'.$id,'purge-'.$id]);
        $purgeRoot=$add($id);$parent=$purgeRoot;for($i=0;$i<22;$i++)$parent=$add($id,$parent);
    }
    $purged=[];$off=Hooks::addAction('after_comment_delete',static function(array $data) use(&$purged):void{ if(DB::pdo()->inTransaction())throw new RuntimeException('purge event before commit');$purged[]=$data['id']; });
    $adminPosts=new Pafish\Admin\PostsController();
    $r=$adminPosts->purge($request([])->withHeader('X-Requested-With','XMLHttpRequest'),new Response(),['id'=>7]);
    $check('Single article purge handles deeper than InnoDB cascade limit',$r->getStatusCode()===200 && count($purged)===23 && DB::value('SELECT id FROM posts WHERE id=7')===null);
    $r=$adminPosts->batch($request(['ids'=>[8],'op'=>'purge'])->withHeader('X-Requested-With','XMLHttpRequest'),new Response());$off();
    $check('Batch article purge uses same comment lifecycle',$r->getStatusCode()===200 && count($purged)===46 && DB::value('SELECT id FROM posts WHERE id=8')===null);

    $pdo->query('CREATE TABLE fixture_log (id INT PRIMARY KEY,value MEDIUMTEXT,bin VARBINARY(32)) ENGINE=InnoDB');
    $text="first\n--正文\n\n/*正文*/\nDELIMITER $$\n#不是注释\n引号'和反斜线\\;last\0";
    file_put_contents($temp,'INSERT INTO fixture_log VALUES (1,'.$quote->invoke(null,$text).",X'0001ff');\n");
    $restore->invoke(null,$temp);
    $check('Backup quote preserves NUL and binary',DB::value('SELECT value FROM fixture_log WHERE id=1')===$text && DB::value('SELECT bin FROM fixture_log WHERE id=1')==="\0\1\xff");
    $raw="first\n-- literal content\n\n/* literal content */\nDELIMITER $$\nlast";
    file_put_contents($temp,"# header\nINSERT INTO fixture_log VALUES (2,'".$raw."',NULL); INSERT INTO fixture_log VALUES (3,'it''s;ok',NULL); /* multi\nline */\n");
    $restore->invoke(null,$temp);
    $check('Restore retains comment and directive shaped body lines',DB::value('SELECT value FROM fixture_log WHERE id=2')===$raw);
    $check('Restore handles multiple statements and doubled quotes',DB::value('SELECT value FROM fixture_log WHERE id=3')==="it's;ok");
    file_put_contents($temp,"DELIMITER $$\nINSERT INTO fixture_log VALUES (4,'dollar$',NULL)$$\nDELIMITER ;\n/*!40101 SET @QA_SQL=1 */;\n");
    $restore->invoke(null,$temp);
    $check('Restore retains custom delimiter and executable comments',DB::value('SELECT value FROM fixture_log WHERE id=4')==='dollar$' && (int) DB::value('SELECT @QA_SQL')===1);
    $mode=DB::value('SELECT @@SESSION.sql_mode');
    file_put_contents($temp,"SET FOREIGN_KEY_CHECKS=0; SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'; INSERT INTO missing_fixture VALUES (1);\n");
    $check('Restore failure is surfaced',$reject(fn()=>$restore->invoke(null,$temp)));
    $check('Restore failure resets connection FK and SQL mode',(int)DB::value('SELECT @@SESSION.FOREIGN_KEY_CHECKS')===1 && DB::value('SELECT @@SESSION.sql_mode')===$mode && !$pdo->inTransaction());
    file_put_contents($temp,"SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'; INSERT INTO fixture_log VALUES (5,'back\\',NULL);\n");
    $restore->invoke(null,$temp);
    $check('Restore tracks SQL mode changes per statement',DB::value('SELECT value FROM fixture_log WHERE id=5')==="back\\");
    file_put_contents($temp,"INSERT INTO fixture_log VALUES (6,'broken\n");
    $check('Unterminated SQL is rejected',$reject(fn()=>$restore->invoke(null,$temp)));
    $stream=fopen('php://temp','w+');$dump->invoke(null,$stream,['fixture_log']);rewind($stream);$sql=stream_get_contents($stream);fclose($stream);
    file_put_contents($temp,$sql);$restore->invoke(null,$temp);
    $check('Streamed backup restores every byte',DB::value('SELECT value FROM fixture_log WHERE id=2')===$raw && DB::value('SELECT bin FROM fixture_log WHERE id=1')==="\0\1\xff");
    $peer=new MySql($pdo->prefix);$interleaved=false;
    $pdo->afterQuery=static function(string $sql) use($peer,&$interleaved):void {
        if(!$interleaved && str_starts_with($sql,'SELECT ') && str_contains($sql,chr(96).'fixture_log'.chr(96))){
            $interleaved=true;
            $peer->query("UPDATE posts SET title='snapshot-new' WHERE id=1");
        }
    };
    $stream=fopen('php://temp','w+');$dump->invoke(null,$stream,['fixture_log','posts']);rewind($stream);$sql=stream_get_contents($stream);fclose($stream);$pdo->afterQuery=null;
    $check('Backup keeps one snapshot across concurrent table changes',$interleaved && str_contains($sql,'fixture-title-1') && !str_contains($sql,'snapshot-new') && DB::value('SELECT title FROM posts WHERE id=1')==='snapshot-new');
    $names=(new ReflectionMethod(Backup::class,'tableNames'))->invoke(null);
    $check('Backup scope includes disabled declared plugins',in_array('pf_plugin_xhs_notes_comments',$names,true));
    $check('Backup scope excludes unrelated shared database table',!in_array('fixture_log',$names,true));
    $backupFile=Backup::create();$backupPath=Backup::resolve($backupFile);
    try {
        $check('Create publishes a complete backup file',filesize($backupPath)>0 && str_ends_with(file_get_contents($backupPath),'SET SESSION sql_mode=@PAFISH_OLD_SQL_MODE;'."\n"));
    } finally { unlink($backupPath); }
    $beforeFiles=glob(Backup::dir().'/backup-*')?:[];
    $pdo->afterQuery=static function(string $sql):void {
        if(str_starts_with($sql,'SELECT ') && str_contains($sql,chr(96).'categories'.chr(96))) throw new RuntimeException('simulated export failure');
    };
    $check('Failed backup creation is reported',$reject(fn()=>Backup::create()));$pdo->afterQuery=null;
    $bufferAttribute=defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')?constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY'):constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    $check('Failed backup leaves no partial file or active transaction',array_diff(glob(Backup::dir().'/backup-*')?:[],$beforeFiles)===[] && !$pdo->inTransaction() && $pdo->getAttribute($bufferAttribute));
    $pdo->beginTransaction();$stream=fopen('php://temp','w+');
    $check('Backup refuses caller transaction',$reject(fn()=>$dump->invoke(null,$stream,['fixture_log'])) && $pdo->inTransaction());
    fclose($stream);$pdo->rollBack();

    $payload=['format'=>'pafish-content','categories'=>[
        ['id'=>31,'slug'=>'child','name'=>'child','parent_id'=>30],['id'=>30,'slug'=>'parent','name'=>'parent','parent_id'=>null],
    ]];
    ContentTransfer::import($payload);
    $check('Import resolves old parent IDs even with child first',DB::value("SELECT p.slug FROM categories c JOIN categories p ON p.id=c.parent_id WHERE c.slug='child'")==='parent');
    $export=ContentTransfer::export();
    $check('Content export includes stable parent slug',array_values(array_filter($export['categories'],fn($c)=>$c['slug']==='child'))[0]['parent_slug']==='parent');
    $cycle=['format'=>'pafish-content','categories'=>[['slug'=>'cycle-a','name'=>'a','parent_slug'=>'cycle-b'],['slug'=>'cycle-b','name'=>'b','parent_slug'=>'cycle-a']]];
    $check('Cyclic import rolls back whole package',$reject(fn()=>ContentTransfer::import($cycle)) && (int)DB::value("SELECT COUNT(*) FROM categories WHERE slug IN ('cycle-a','cycle-b')")===0);
    $new=['format'=>'pafish-content','categories'=>[['slug'=>'child','name'=>'child','parent_slug'=>null]]];
    ContentTransfer::import($new,'skip');
    $check('Skip mode preserves existing category hierarchy',DB::value("SELECT parent_id FROM categories WHERE slug='child'")!==null);
    ContentTransfer::import($new,'update');
    $check('Update mode can detach category',DB::value("SELECT parent_id FROM categories WHERE slug='child'")===null);
    $pdo->beginTransaction();
    $check('Import refuses caller transaction before cache invalidation',$reject(fn()=>ContentTransfer::import($new)) && $pdo->inTransaction());
    $pdo->rollBack();
    DB::execute("INSERT INTO tags (name,slug) VALUES ('fixture-tag','fixture-tag')");$tag=DB::lastInsertId();
    $cat=(int)DB::value("SELECT id FROM categories WHERE slug='child'");
    DB::execute('UPDATE posts SET category_id=? WHERE id IN (1,3,4,5,6)',[$cat]);
    foreach([1,3,4,5,6] as $id)DB::execute('INSERT INTO post_tags (post_id,tag_id) VALUES (?,?)',[$id,$tag]);
    $req=$factory->createServerRequest('GET','/api/v1/categories')->withHeader('X-API-Key','fixture-key');
    $r=$api->categories($req,new Response());$data=json_decode((string)$r->getBody(),true);
    $check('Category API count excludes future draft and deleted articles',array_values(array_filter($data['categories'],fn($c)=>(int)$c['id']===$cat))[0]['postCount']===2);
    $r=$api->tags($req,new Response());$data=json_decode((string)$r->getBody(),true);
    $check('Tag API count matches same public visibility',$data['tags'][0]['postCount']===2);

    foreach([10,11,12] as $id) DB::execute("INSERT INTO posts (id,title,slug,content,status,published_at,author_id) VALUES (?,? ,?,'fixture','SCHEDULED','2000-01-01',1)",[$id,'scheduled-'.$id,'scheduled-'.$id]);
    $pdo->afterExecute=static function(string $sql) use($peer):void {
        if(str_starts_with($sql,'SELECT id, title, slug, published_at')) {
            $peer->query("UPDATE posts SET published_at='2099-01-01' WHERE id=10");
            $peer->query('UPDATE posts SET deleted_at=NOW() WHERE id=11');
        }
    };
    $pub=[];$off=Hooks::addAction('after_post_published',static function(array $data) use(&$pub):void{$pub[]=$data['id'];});
    $n=Scheduler::publishDue();$off();$pdo->afterExecute=null;
    $check('Scheduled publish rechecks postponed/deleted candidates',$n===1 && $pub===['12'] && DB::value('SELECT status FROM posts WHERE id=10')==='SCHEDULED' && DB::value('SELECT status FROM posts WHERE id=11')==='SCHEDULED');

    $req=$factory->createServerRequest('GET','/', ['REMOTE_ADDR'=>'198.51.100.2'])->withHeader('X-Forwarded-For','1.1.1.1');
    $check('Untrusted forwarding header ignored',ClientIp::fromRequest($req)==='198.51.100.2');
    $config=Config::all();$trusted=$config;$trusted['trusted_proxies']=['10.0.0.0/8','2001:db8::/32'];
    (new ReflectionProperty(Config::class,'data'))->setValue(null,$trusted);
    $req=$factory->createServerRequest('GET','/', ['REMOTE_ADDR'=>'10.0.0.1'])->withHeader('X-Forwarded-For','8.8.8.8,198.51.100.3,10.0.0.2');
    $check('Trusted proxy traversal stops at first untrusted hop',ClientIp::fromRequest($req)==='198.51.100.3');
    $check('Malformed forwarded chain falls back to peer',ClientIp::fromRequest($req->withHeader('X-Forwarded-For','garbage'))==='10.0.0.1');
    $req=$factory->createServerRequest('GET','/', ['REMOTE_ADDR'=>'2001:db8::1'])->withHeader('X-Real-IP','2001:4860:4860::8888');
    $check('IPv6 proxy CIDR supported',ClientIp::fromRequest($req)==='2001:4860:4860::8888');
    (new ReflectionProperty(Config::class,'data'))->setValue(null,$config);

    $key=$pdo->prefix.'limit';$workers=[];
    for($i=0;$i<8;$i++){
        $proc=proc_open([PHP_BINARY,__DIR__.'/Fixtures/worker.php','limit',$pdo->prefix,$key,'2'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,PAFISH_ROOT);
        fclose($pipes[0]);$workers[]=[$proc,$pipes];
    }
    $allowed=0;
    foreach($workers as [$proc,$pipes]){
        $result=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($proc)!==0)throw new RuntimeException($error);
        $allowed+=json_decode($result,true,512,JSON_THROW_ON_ERROR)['result']?1:0;
    }
    $check('Eight independent processes cannot exceed shared limit',$allowed===2);
    RateLimiter::clear('qa-lifecycle',$key);
    Settings::set('api_rate_limit','1');
    $req=$factory->createServerRequest('GET','/', ['REMOTE_ADDR'=>'198.51.100.250'])->withHeader('X-API-Key','fixture-key');
    RateLimiter::clear('api-request',hash('sha256','fixture-key').'|198.51.100.250');
    $check('API uses shared atomic limiter',Pafish\Core\ApiKey::check($req)===null && Pafish\Core\ApiKey::check($req)[0]===429);
} finally {
    $pdo->afterQuery=null;
    $pdo->afterExecute=null;
    $pdo->cleanup();$property->setValue(null,null);Settings::reset();
    if(is_file($temp))unlink($temp);
    $login(null);
}
echo "{$passed} passed, {$failed} failed".PHP_EOL;
exit($failed?1:0);
