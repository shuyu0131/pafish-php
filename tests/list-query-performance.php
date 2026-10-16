<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require PAFISH_ROOT . '/app/Core/helpers.php';
require __DIR__ . '/Fixtures/MySql.php';

use Pafish\Core\{Config, DB, SqlScript};
use Pafish\Services\{Posts, Settings};
use Pafish\Http\Listings;
use Pafish\Tests\MySql;
use Slim\Psr7\Factory\ServerRequestFactory;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
$passed = $failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$probe = static function (string $sql, array $params): array {
    $plan = DB::fetchAll('EXPLAIN ' . $sql, $params);
    DB::fetchAll($sql, $params);
    $times = [];
    for ($i=0; $i<3; $i++) {
        $start=hrtime(true); $rows=DB::fetchAll($sql,$params);
        $times[]=(hrtime(true)-$start)/1e6;
    }
    sort($times);
    $analyze=null;
    try { $analyze=DB::fetchAll('EXPLAIN ANALYZE '.$sql,$params); } catch (PDOException) {}
    return ['plan'=>$plan, 'ms_warm_median'=>round($times[1],3), 'ids'=>array_column($rows,'id'), 'analyze'=>$analyze];
};
$report=['server'=>$pdo->query('SELECT VERSION()')->fetchColumn(),
    'fixtures'=>['posts'=>50000,'authors'=>10,'categories'=>40,'body_bytes_approx'=>1100,
        'distribution'=>'half in category 1, remainder spread across 39; tied/null/future times, pins, trash, drafts; mixed fulltext and substring matches'],
    'results'=>[]];
try {
    foreach (SqlScript::split(file_get_contents(PAFISH_ROOT.'/app/install/schema.sql')) as $sql) $pdo->query($sql);
    $pdo->query('ALTER TABLE posts DROP INDEX idx_posts_category, ADD INDEX idx_posts_category(category_id)');
    for ($i=1;$i<=10;$i++) DB::execute("INSERT INTO users(id,username,email,password_hash) VALUES(?,?,?,'fixture')",[$i,'perf'.$i,'perf'.$i.'@invalid.test']);
    for ($i=1;$i<=40;$i++) DB::execute('INSERT INTO categories(id,name,slug,parent_id) VALUES(?,?,?,?)',[$i,'Category '.$i,'perf-'.$i,$i>1&&$i<5?1:null]);
    $pdo->query('CREATE TABLE fixture_log(n INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->query('INSERT INTO fixture_log VALUES '.implode(',',array_map(static fn($i)=>'('.$i.')',range(0,999))));
    DB::execute("INSERT INTO posts(id,title,slug,content,excerpt,status,author_id,category_id,is_pinned,category_pinned,published_at,deleted_at)
        SELECT n,CONCAT('Fixture ',n),CONCAT('list-',n),
        CONCAT(REPEAT('正文 sample ',80),IF(MOD(n,31)=0,' needle',''),IF(MOD(n,37)=0,' standaloneq','')),
        IF(MOD(n,43)=0,'needle excerpt',''),
        IF(MOD(n,10)<8,'PUBLISHED','DRAFT'),MOD(n,10)+1,IF(MOD(n,2)=0,1,MOD(FLOOR(n/2),39)+2),
        MOD(n,400)=0,MOD(n,70)=0,
        CASE WHEN MOD(n,113)=0 THEN NULL WHEN MOD(n,97)=0 THEN '2099-01-01' ELSE DATE_SUB('2026-01-01',INTERVAL FLOOR(n/3) MINUTE) END,
        IF(MOD(n,19)=0,'2025-01-01',NULL)
        FROM (SELECT a.n+b.n*1000+1 AS n FROM fixture_log a CROSS JOIN fixture_log b WHERE b.n<50) seq");
    // The real list calculates approved comment counts; include both roots and replies.
    DB::execute("INSERT INTO comments(post_id,author_name,author_email,content,status)
        SELECT id,'fixture','perf@invalid.test','comment',IF(MOD(id,7)=0,'PENDING','APPROVED') FROM posts");
    foreach (['posts','comments'] as $table) $pdo->query('ANALYZE TABLE '.$table);
    echo "Seeded isolated 50000 posts / 50000 comments\n"; ob_flush(); flush();
    $projection="p.id,p.title,p.slug,p.excerpt,p.content,p.content_format,p.cover_url,p.custom_fields,p.published_at,p.author_id,
        p.is_pinned,p.category_pinned,p.password,p.external_url,p.view_count,p.like_count,p.favorite_count,p.allow_comments,p.seo_title,p.meta_description,
        (SELECT COUNT(*) FROM comments c WHERE c.post_id=p.id AND c.status='APPROVED') AS comment_count,
        COALESCE(NULLIF(u.nickname,''),u.username) AS author_name,u.avatar_url AS author_avatar,c.name AS category_name,c.slug AS category_slug";
    $joins=' LEFT JOIN users u ON u.id=p.author_id LEFT JOIN categories c ON c.id=p.category_id';
    $order='p.is_pinned DESC,p.published_at DESC,p.id DESC';
    $categoryOrder='p.is_pinned DESC,p.category_pinned DESC,p.published_at DESC,p.id DESC';
    $search='(MATCH(p.title,p.excerpt,p.content) AGAINST (? IN NATURAL LANGUAGE MODE) OR p.title LIKE ? OR p.excerpt LIKE ? OR p.content LIKE ?)';
    $cases=[
        'home_first'=>['',[],$order,0],
        'home_deep'=>['',[],$order,30000],
        'category_single'=>['p.category_id IN (?)',[1],$categoryOrder,0],
        'category_descendants'=>['p.category_id IN (?,?,?,?)',[1,2,3,4],$categoryOrder,0],
        'category_sparse'=>['p.category_id IN (?,?,?)',[5,6,7],$categoryOrder,0],
        'category_all'=>['p.category_id IN ('.implode(',',array_fill(0,40,'?')).')',range(1,40),$categoryOrder,0],
        'category_deep'=>['p.category_id IN (?,?,?,?)',[1,2,3,4],$categoryOrder,15000],
        'search_common'=>[$search,['sample','%sample%','%sample%','%sample%'],$order,0],
        'search_mixed'=>[$search,['needle','%needle%','%needle%','%needle%'],$order,0],
        'search_substring'=>[$search,['tandalone','%tandalone%','%tandalone%','%tandalone%'],$order,0],
        'search_deep'=>[$search,['sample','%sample%','%sample%','%sample%'],$order,30000],
        'search_empty'=>[$search,['nomatchzz','%nomatchzz%','%nomatchzz%','%nomatchzz%'],$order,0],
    ];
    foreach (['before','deferred','indexed','category_hint'] as $stage) {
        if ($stage==='indexed') {
            $pdo->query('ALTER TABLE posts DROP INDEX idx_posts_category, ADD INDEX idx_posts_category(category_id,status,deleted_at,is_pinned,category_pinned,published_at,id)');
            $pdo->query('ANALYZE TABLE posts');
        }
        foreach ($cases as $name=>[$extra,$params,$sort,$offset]) {
            $where=Posts::publicWhere('p').($extra!==''?' AND '.$extra:'');
            $source='posts p'.($stage==='category_hint' && str_starts_with($name,'category_')?' FORCE INDEX (idx_posts_category)':'');
            $sql=$stage==='before'
                ? "SELECT {$projection} FROM posts p {$joins} WHERE {$where} ORDER BY {$sort} LIMIT 10 OFFSET {$offset}"
                : "SELECT {$projection} FROM (SELECT p.id FROM {$source} WHERE {$where} ORDER BY {$sort} LIMIT 10 OFFSET {$offset}) page_ids JOIN posts p ON p.id=page_ids.id {$joins} ORDER BY {$sort}";
            $report['results'][$name][$stage]=$probe($sql,$params);
        }
        echo 'Measured '.$stage."\n"; ob_flush(); flush();
    }
    foreach ($cases as $name=>$unused) {
        $results=$report['results'][$name];
        $check($name.' retains the complete ordered result set', count(array_filter($results,static fn($r)=>$r['ids']!==$results['before']['ids']))===0);
        echo $name.': '.json_encode(array_map(static fn($r)=>$r['ms_warm_median'],$results))."\n";
    }
    $single=$report['results']['category_single']['indexed']['plan'];
    $check('Single category selects IDs through its covering ordered index', count(array_filter($single,
        static fn($p)=>$p['key']==='idx_posts_category' && str_contains($p['Extra'],'Using index') && !str_contains($p['Extra'],'filesort')))>0);
    $multi=$report['results']['category_descendants']['category_hint']['plan'];
    $check('Descendant candidate scan uses the category covering index', count(array_filter($multi,
        static fn($p)=>$p['key']==='idx_posts_category' && str_contains($p['Extra'],'Using index')))>0);
    $factory=new ServerRequestFactory();
    foreach ($cases as $name=>[$extra,$params,$sort,$offset]) {
        $observed=[];
        $pdo->afterExecute=static function(string $sql) use (&$observed): void { $observed[]=$sql; };
        $start=hrtime(true);
        $category=str_starts_with($name,'category_');
        [$posts,$total]=Listings::published($category?'':$extra,$category?[]:$params,
            $factory->createServerRequest('GET','/')->withQueryParams(['page'=>intdiv($offset,10)+1]),
            perPage:10,categoryIds:$category?$params:[]);
        $report['results'][$name]['production_request_ms']=round((hrtime(true)-$start)/1e6,3);
        $pdo->afterExecute=null;
        $check($name.' production query returns the original ordered IDs',array_column($posts,'id')===$report['results'][$name]['before']['ids']);
        $report['results'][$name]['production_count']=$probe($observed[0],$params);
        $where=Posts::publicWhere('p').($extra!==''?' AND '.$extra:'');
        $report['results'][$name]['before_count']=$probe('SELECT COUNT(*) FROM posts p WHERE '.$where,$params);
        $detail=array_values(array_filter($observed,static fn($sql)=>str_contains($sql,') page_ids')));
        if ($total===0) {
            $check('Empty search stops after COUNT without repeating its content scan',count($observed)===1 && $detail===[]);
            $report['results'][$name]['production']=null;
        } else {
            $check($name.' production limits IDs before fetching details',count($detail)===1);
            $report['results'][$name]['production']=$probe($detail[0],$params);
        }
    }
    $report['limits']='Local synthetic workload; median of three warm main SQL runs, excludes count/render/attachments/concurrency. Substring OR fulltext still scans content; OFFSET still scans earlier index entries. No latency threshold assertions.';
    $report['passed']=$passed; $report['failed']=$failed;
    if (is_dir(PAFISH_ROOT.'/.design-review')) file_put_contents(PAFISH_ROOT.'/.design-review/list-query-performance.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
} finally { $pdo->afterExecute=null; $pdo->cleanup(); Settings::reset(); }
echo json_encode(['passed'=>$passed,'failed'=>$failed])."\n";
ob_end_flush();
exit($failed>0?1:0);
