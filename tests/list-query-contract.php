<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT.'/vendor/autoload.php';
require PAFISH_ROOT.'/app/Core/helpers.php';
require __DIR__.'/Fixtures/MySql.php';

use Pafish\Core\{Config, DB, Session, SqlScript};
use Pafish\Http\{CategoryController, HomeController, Listings, SearchController, TagController};
use Pafish\Services\{Migrator, Posts, Settings, Theme};
use Pafish\Tests\MySql;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
Session::start();
$pdo=new MySql('qa_core_'.bin2hex(random_bytes(6)).'_');
(new ReflectionProperty(DB::class,'pdo'))->setValue(null,$pdo);
$passed=0;
$check=static function(string $label,bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    $passed++; echo 'PASS '.$label.PHP_EOL;
};
$ids=static fn(array $rows): array=>array_map('intval',array_column($rows,'id'));
$request=(new ServerRequestFactory())->createServerRequest('GET','/');
$render=static function(callable $action): array {
    $GLOBALS['pafish_tpl_ctx']=[];
    // Keep real theme fragment caches isolated from test data.
    $response=DB::transaction($action);
    return [$response,$GLOBALS['pafish_tpl_ctx']];
};
try {
    foreach(SqlScript::split(file_get_contents(PAFISH_ROOT.'/app/install/schema.sql')) as $sql) $pdo->query($sql);
    DB::execute("INSERT INTO users(id,username,nickname,email,password_hash) VALUES(1,'fixture','Author','list@invalid.test','fixture')");
    DB::execute("INSERT INTO users(id,username,email,password_hash) VALUES(2,'fallback','fallback@invalid.test','fixture')");
    DB::execute("INSERT INTO categories(id,name,slug,parent_id) VALUES
        (1,'Root','root',NULL),(2,'Child','child',1),(3,'Grandchild','grandchild',2),(4,'Sibling','sibling',1),(5,'Other','other',NULL)");
    for($i=1;$i<=135;$i++) {
        DB::execute('INSERT INTO posts(id,title,slug,content,content_format,status,author_id,category_id,is_pinned,category_pinned,published_at,deleted_at,
            custom_fields,allow_comments,seo_title,meta_description,like_count,favorite_count) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
            $i,'Fixture '.$i,'list-'.$i,($i%2===0?'<p>needle sample</p>':'**standaloneq sample**').($i%11===0?'纸鱼文字':''),$i%2===0?'html':'markdown',
            $i%13===0?'DRAFT':'PUBLISHED',$i%9===0?2:1,$i%5+1,(int)($i%19===0),(int)($i%7===0),
            $i%17===0?null:($i%23===0?'2099-01-01':'2020-01-01'),$i%29===0?'2020-02-01':null,
            '{"label":"fixture"}',0,'SEO '.$i,'Description '.$i,3,4]);
    }
    DB::execute("INSERT INTO tags(id,name,slug) VALUES(1,'Tag','fixture-tag')");
    DB::execute('INSERT INTO post_tags(post_id,tag_id) SELECT id,1 FROM posts WHERE MOD(id,3)=0');
    DB::execute("INSERT INTO comments(id,post_id,author_name,author_email,content,status,parent_id,created_at) VALUES
        (1,19,'One','one@invalid.test','root','APPROVED',NULL,'2020-01-01'),
        (2,19,'Two','two@invalid.test','reply','APPROVED',1,'2020-01-01'),
        (3,19,'Three','three@invalid.test','pending','PENDING',NULL,'2020-01-01')");
    Settings::setMany(['active_theme'=>'default','posts_per_page'=>'10','home_page_id'=>'']);
    Theme::reset();
    $order='p.is_pinned DESC,p.published_at DESC,p.id DESC';
    $categoryOrder='p.is_pinned DESC,p.category_pinned DESC,p.published_at DESC,p.id DESC';
    $public=Posts::publicWhere('p');
    $scenarios=[
        'home'=>['',[],[],$order],
        'single category'=>['',[],[1],$categoryOrder],
        'descendants'=>['',[],[1,2,3,4],$categoryOrder],
        'all categories'=>['',[],[1,2,3,4,5],$categoryOrder],
        'tag'=>['EXISTS (SELECT 1 FROM post_tags pt WHERE pt.post_id=p.id AND pt.tag_id=?)',[1],[],$order],
    ];
    foreach($scenarios as $name=>[$extra,$params,$categories,$sort]) {
        $where=$public.($extra!==''?' AND ('.$extra.')':'');
        $referenceParams=$params;
        if($categories!==[]) {
            $where.=' AND p.category_id IN ('.implode(',',array_fill(0,count($categories),'?')).')';
            $referenceParams=array_merge($params,$categories);
        }
        $expected=$ids(DB::fetchAll('SELECT p.id FROM posts p WHERE '.$where.' ORDER BY '.$sort,$referenceParams));
        $collected=[]; $totals=true;
        for($page=1;$page<=intdiv(count($expected)-1,10)+1;$page++) {
            [$rows,$total,$perPage,$actualPage]=Listings::published($extra,$params,$request->withQueryParams(['page'=>$page]),categoryIds:$categories);
            $totals=$totals && $total===count($expected) && $perPage===10 && $actualPage===$page;
            $collected=array_merge($collected,$ids($rows));
        }
        $check($name.' has no missing or repeated rows across numeric pages',$collected===$expected);
        $check($name.' preserves totals and page numbers',$totals);
    }
    [$rows]=Listings::published('',[],$request,perPage:50);
    $byId=array_column($rows,null,'id');
    $check('Pin levels and same-time IDs have explicit order', $ids(array_slice($rows,0,3))===[133,114,95]);
    $row=$byId[19];
    $check('Approved count includes replies, previews contain only approved roots',(int)$row['comment_count']===2 && array_column($row['recent_comments'],'content')===['root']);
    $check('HTML and Markdown bodies and extension fields remain available',($byId[114]['content_format']??'')==='html'
        && $row['content_format']==='markdown' && $row['content']==='**standaloneq sample**'
        && json_decode($row['custom_fields'],true)===['label'=>'fixture']);
    $check('SEO, comment switch and interaction fields are retained',$row['seo_title']==='SEO 19' && $row['meta_description']==='Description 19'
        && (int)$row['allow_comments']===0 && (int)$row['like_count']===3 && (int)$row['favorite_count']===4);
    $check('Author and category joins remain available',$row['author_name']==='Author' && $row['category_name']==='Other' && array_key_exists('author_avatar',$row));
    [$tagged]=Listings::published('p.id=?',[3],$request);
    $check('Tags remain attached to returned details',($tagged[0]['tags'][0]['slug']??'')==='fixture-tag');
    [$fallbackAuthor]=Listings::published('p.id=?',[9],$request);
    $check('Author without nickname keeps its username fallback',count($fallbackAuthor)===1 && $fallbackAuthor[0]['author_name']==='fallback');
    [$nullTime]=Listings::published('p.id=?',[17],$request);
    $check('NULL publication time remains public',count($nullTime)===1);
    [$response,$context]=$render(fn()=>(new CategoryController())->show($request,new Response(),['slug'=>'root']));
    $expected=$ids(DB::fetchAll('SELECT p.id FROM posts p WHERE '.$public.' AND category_id IN (1,2,3,4) ORDER BY '.$categoryOrder.' LIMIT 10'));
    $check('Real category controller retains descendant pinning order',$response->getStatusCode()===200 && $ids($context['posts'])===$expected);
    [$response,$context]=$render(fn()=>(new TagController())->show($request,new Response(),['slug'=>'fixture-tag']));
    $check('Real tag controller retains attachment and numeric pagination',$response->getStatusCode()===200 && count($context['posts'])===10 && $context['totalPages']>1);

    foreach(['needle','standalone','sample needle','鱼','%','_','nomatchzz',"' OR 1=1 --"] as $keyword) {
        $clause='(MATCH(p.title,p.excerpt,p.content) AGAINST (? IN NATURAL LANGUAGE MODE) OR p.title LIKE ? OR p.excerpt LIKE ? OR p.content LIKE ?)';
        $params=[$keyword,'%'.$keyword.'%','%'.$keyword.'%','%'.$keyword.'%'];
        $expected=$ids(DB::fetchAll('SELECT p.id FROM posts p WHERE '.$public.' AND '.$clause.' ORDER BY '.$order,$params));
        $actual=[];
        for($page=1;$page<=max(1,(int)ceil(count($expected)/10));$page++) {
            [, $context]=$render(fn()=>(new SearchController())->index($request->withQueryParams(['q'=>$keyword,'page'=>$page]),new Response()));
            $actual=array_merge($actual,$ids($context['posts']));
        }
        $check('Search preserves fulltext/substring/wildcard semantics for '.json_encode($keyword),$actual===$expected && $context['total']===count($expected));
        if($keyword==='鱼') $check('One-character Chinese substrings remain searchable',count($actual)>0);
        if($keyword==='sample needle') $check('Fulltext hits survive when the whole phrase has no LIKE hit',count($actual)>0
            && (int)DB::value("SELECT COUNT(*) FROM posts p WHERE {$public} AND (title LIKE ? OR excerpt LIKE ? OR content LIKE ?)",['%sample needle%','%sample needle%','%sample needle%'])===0);
    }
    [$response,$context]=$render(fn()=>(new SearchController())->index($request->withQueryParams(['q'=>'needle']),new Response()));
    $check('Search keeps noindex and canonical keyword',$response->getHeaderLine('X-Robots-Tag')==='noindex, follow'
        && str_contains($context['og']['url'],'q=needle'));
    [$response,$context]=$render(fn()=>(new SearchController())->index($request->withQueryParams(['q'=>['invalid'],'page'=>['invalid']]),new Response()));
    $check('Array search parameters degrade to an empty first page',$response->getStatusCode()===200 && $context['posts']===[] && $context['pageNum']===1);

    foreach([2_000_000,(string)PHP_INT_MAX,str_repeat('9',100)] as $page) {
        $queries=[]; $pdo->afterExecute=static function(string $sql) use (&$queries): void { $queries[]=$sql; };
        [$rows,$total,$perPage,$actualPage]=Listings::published('',[],$request->withQueryParams(['page'=>$page]));
        $pdo->afterExecute=null;
        $check('Beyond-last page '.substr((string)$page,0,20).' returns empty without overflowing OFFSET',$rows===[] && $total>0
            && $actualPage>1 && count(array_filter($queries,static fn($s)=>str_contains($s,') page_ids')))===0);
    }
    foreach([0,-7,'bad',['invalid']] as $page) {
        [$rows,,,$actualPage]=Listings::published('',[],$request->withQueryParams(['page'=>$page]));
        $check('Invalid page '.json_encode($page).' uses page one',$actualPage===1 && count($rows)===10);
    }
    // Simulate an active theme supplying this optional setting; default has no such field.
    Theme::reset();
    (new ReflectionProperty(Theme::class,'values'))->setValue(null,['recent_posts_limit'=>'3']);
    [, $context]=$render(fn()=>(new HomeController())->index($request->withQueryParams(['page'=>2]),new Response()));
    $all=$ids(DB::fetchAll('SELECT p.id FROM posts p WHERE '.$public.' ORDER BY '.$order));
    $check('Homepage keeps the theme limit and page-two position',$ids($context['posts'])===array_slice($all,3,3));
    (new ReflectionProperty(Theme::class,'values'))->setValue(null,['recent_posts_limit'=>'200']);
    [, $context]=$render(fn()=>(new HomeController())->index($request,new Response()));
    $check('Homepage keeps the theme-specific maximum of thirty',count($context['posts'])===30);
    [$rows,, $perPage]=Listings::published('',[],$request,perPage:200);
    $check('Shared list keeps its fifty-row maximum',$perPage===50 && count($rows)===50);
    DB::execute("INSERT INTO pages(id,title,slug,content,content_format,status,published_at) VALUES(1,'Home','home','<p>Static homepage</p>','html','PUBLISHED','2020-01-01')");
    Settings::set('home_page_id','1');
    [, $context]=$render(fn()=>(new HomeController())->index($request,new Response()));
    $check('Configured static homepage still bypasses the article list',($context['page']['id']??0)==1 && str_contains($context['contentHtml'],'Static homepage'));

    // Fresh baseline only records the new index migration, without rebuilding it.
    $ddl=0;
    $pdo->afterQuery=static function(string $sql) use (&$ddl): void {
        if(str_contains($sql,'ALTER TABLE posts') && str_contains($sql,'idx_posts_category')) $ddl++;
    };
    Migrator::run($pdo,PAFISH_ROOT.'/migrations'); $pdo->afterQuery=null;
    $check('Fresh baseline records category migration without DDL',$ddl===0 && in_array('category_query_index',Migrator::appliedVersions($pdo),true));
    DB::execute("DELETE FROM schema_migrations WHERE version='category_query_index'");
    $pdo->query('ALTER TABLE posts DROP INDEX idx_posts_category, ADD INDEX idx_posts_category(category_id)');
    $check('Old category index is upgraded',in_array('category_query_index',Migrator::run($pdo,PAFISH_ROOT.'/migrations')['applied'],true));
    $check('Category index upgrade is repeatable',Migrator::run($pdo,PAFISH_ROOT.'/migrations')===['applied'=>[]]);
    DB::execute("DELETE FROM schema_migrations WHERE version='category_query_index'");
    $pdo->query('ALTER TABLE posts DROP INDEX idx_posts_category, ADD INDEX idx_posts_category(category_id)');
    $pdo->afterQuery=static function(string $sql): void {
        if(str_contains($sql,'ALTER TABLE posts') && str_contains($sql,'idx_posts_category')) throw new RuntimeException('Fixture failure after completed DDL');
    };
    $interrupted=false;
    try { Migrator::run($pdo,PAFISH_ROOT.'/migrations'); } catch(RuntimeException) { $interrupted=true; }
    $pdo->afterQuery=null;
    $check('Failure after committed index DDL leaves the version unrecorded',$interrupted && !in_array('category_query_index',Migrator::appliedVersions($pdo),true));
    $ddl=0;
    $pdo->afterQuery=static function(string $sql) use (&$ddl): void {
        if(str_contains($sql,'ALTER TABLE posts') && str_contains($sql,'idx_posts_category')) $ddl++;
    };
    Migrator::run($pdo,PAFISH_ROOT.'/migrations'); $pdo->afterQuery=null;
    $check('Retry records completed DDL without rebuilding the index',$ddl===0 && in_array('category_query_index',Migrator::appliedVersions($pdo),true));
    $denied=false;
    try { DB::execute('UPDATE posts SET category_id=999999 WHERE id=1'); } catch(PDOException) { $denied=true; }
    $check('Index replacement preserves the true category foreign key',$denied);
    DB::execute("DELETE FROM schema_migrations WHERE version='category_query_index'");
    $pdo->query('ALTER TABLE posts DROP INDEX idx_posts_category, ADD INDEX idx_posts_category(category_id,published_at)');
    $rejected=false;
    try { Migrator::run($pdo,PAFISH_ROOT.'/migrations'); } catch(RuntimeException) { $rejected=true; }
    $check('Unknown same-name category index drift stops migration',$rejected && !in_array('category_query_index',Migrator::appliedVersions($pdo),true));
    $check('Migration and rejection preserve all article records',(int)DB::value('SELECT COUNT(*) FROM posts')===135);
} finally {
    $pdo->afterExecute=$pdo->afterQuery=null; $pdo->cleanup(); Settings::reset(); Theme::reset();
    unset($GLOBALS['pafish_tpl_ctx']);
}
echo json_encode(['passed'=>$passed,'failed'=>0])."\n";
ob_end_flush();
