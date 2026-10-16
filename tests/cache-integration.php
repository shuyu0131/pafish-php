<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ob_start();
define('PAFISH_ROOT',dirname(__DIR__));
require PAFISH_ROOT.'/vendor/autoload.php';
require PAFISH_ROOT.'/app/Core/helpers.php';
require __DIR__.'/Fixtures/MySql.php';
use Pafish\Core\{Auth,Cache,Config,DB,Session,SqlScript};
use Pafish\Services\{AdminOrdering,Categories,MicroStatuses,Migrator,Settings};
use Pafish\Tests\MySql;
use Slim\Psr7\{Response,Factory\ServerRequestFactory};
Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai'); Session::start();
$pdo=new MySql('qa_core_'.bin2hex(random_bytes(6)).'_');
(new ReflectionProperty(DB::class,'pdo'))->setValue(null,$pdo);
$passed=0;
$check=static function(string $label,bool $ok) use (&$passed): void {
    if(!$ok) throw new RuntimeException('FAIL '.$label);
    $passed++; echo 'PASS '.$label.PHP_EOL;
};
$factory=new ServerRequestFactory();
$request=static fn(array $body=[])=>$factory->createServerRequest('POST','/admin/fixture')
    ->withHeader('X-Requested-With','XMLHttpRequest')->withParsedBody(['_csrf'=>Session::csrfToken()]+$body);
$throws=static function(callable $fn): bool { try{$fn();}catch(RuntimeException){return true;} return false; };
$measure=static function(callable $fn): array {
    $count=0;
    $pdo=DB::pdo(); $old=$pdo->afterExecute;
    $pdo->afterExecute=static function(string $sql) use (&$count): void { $count++; };
    $start=hrtime(true);
    try { $value=$fn(); } finally { $pdo->afterExecute=$old; }
    return ['value'=>$value,'sql_count'=>$count,'elapsed_ms'=>(hrtime(true)-$start)/1000000];
};
try {
    foreach(SqlScript::split(file_get_contents(PAFISH_ROOT.'/app/install/schema.sql')) as $sql) $pdo->query($sql);
    Migrator::run($pdo,PAFISH_ROOT.'/migrations');
    DB::execute("INSERT INTO users(id,username,nickname,email,password_hash,role) VALUES
        (1,'cache-admin','Old Name','cache-admin@invalid.test','fixture','ADMIN'),
        (2,'cache-author','Old Author','cache-author@invalid.test','fixture','USER')");
    Session::set('user_id',1); (new ReflectionProperty(Auth::class,'user'))->setValue(null,null);
    Auth::user();
    foreach(['nav.','links.','widgets.','categories.','micro.latest.'] as $prefix) Cache::clearPrefix($prefix);
    DB::execute("INSERT INTO nav_items(id,label,url,sort_order) VALUES(1,'Home','/',1),(2,'About','/about',2)");
    DB::execute("INSERT INTO links(id,name,url,sort_order) VALUES(1,'Friend','https://example.test',1)");
    DB::execute("INSERT INTO widgets(id,type,title,area,sort_order) VALUES(1,'custom','Widget','sidebar',1)");
    DB::execute("INSERT INTO categories(id,name,slug) VALUES(1,'Old Category','old-category')");
    MicroStatuses::save(['content'=>'first','status'=>'PUBLISHED','published_at'=>date('Y-m-d H:i:s',time()-20)],null,1);
    $queries=static fn(): array=>[nav_items(),friend_links(),widget_items(),Categories::tree(),MicroStatuses::latest(18)];
    Cache::resetStats(); $cold=$measure($queries); $coldStats=Cache::stats();
    Cache::resetStats(); $warm=$measure($queries); $warmStats=Cache::stats();
    $check('Five public helpers populate cache with five source queries',$cold['sql_count']===5 && $coldStats['loads']===5);
    $check('Warm public helpers avoid database queries',$warm['sql_count']===0 && $warmStats['hits']===5);
    $check('Cold and warm public payloads match',$cold['value']===$warm['value']);

    $nav=new Pafish\Admin\NavController();
    $response=$nav->save($request(['label'=>'Updated Home','url'=>'/new']),new Response(),['id'=>1]);
    $check('Navigation save invalidates shared helper',$response->getStatusCode()===200 && nav_items()[0]['label']==='Updated Home');
    $nav->toggle($request(),new Response(),['id'=>1]);
    $check('Navigation visibility invalidates shared helper',array_column(nav_items(),'id')===[2]);
    $nav->toggle($request(),new Response(),['id'=>1]); nav_items();
    AdminOrdering::move('nav',2,'up');
    $check('Navigation move invalidates cached order',array_column(nav_items(),'id')===[2,1]);
    AdminOrdering::reorder('nav',[1,2],[2,1]);
    $check('Navigation reorder invalidates cached order',array_column(nav_items(),'id')===[1,2]);
    $nav->delete($request(),new Response(),['id'=>2]);
    $check('Navigation delete invalidates shared helper',array_column(nav_items(),'id')===[1]);

    $links=new Pafish\Admin\LinksController();
    $response=$links->save($request(['name'=>'New Friend','url'=>'https://example.test/new']),new Response(),['id'=>1]);
    $check('Friend link save invalidates shared helper',$response->getStatusCode()===200 && friend_links()[0]['name']==='New Friend');
    $links->toggle($request(),new Response(),['id'=>1]);
    $check('Friend link visibility invalidates shared helper',friend_links()===[]);
    $links->toggle($request(),new Response(),['id'=>1]); friend_links();
    $links->delete($request(),new Response(),['id'=>1]);
    $check('Friend link deletion invalidates shared helper',friend_links()===[]);

    $widgets=new Pafish\Admin\WidgetsController();
    $response=$widgets->save($request(['type'=>'custom','title'=>'Updated Widget','content'=>'hello','area'=>'sidebar']),new Response(),['id'=>1]);
    $check('Widget save invalidates shared helper',$response->getStatusCode()===200 && widget_items()[0]['title']==='Updated Widget');
    $widgets->toggle($request(),new Response(),['id'=>1]);
    $check('Widget visibility invalidates shared helper',widget_items()===[]);
    $widgets->toggle($request(),new Response(),['id'=>1]); widget_items();
    $widgets->delete($request(),new Response(),['id'=>1]);
    $check('Widget deletion invalidates shared helper',widget_items()===[]);

    $categories=new Pafish\Admin\CategoriesController();
    $categories->save($request(['name'=>'New Category','slug'=>'new-category']),new Response(),['id'=>1]);
    $check('Category save invalidates tree',Categories::tree()[0]['name']==='New Category');
    $categories->delete($request(),new Response(),['id'=>1]);
    $check('Category delete invalidates tree',Categories::tree()===[]);

    MicroStatuses::latest(18);
    $profile=new Pafish\Admin\ProfileController();
    $response=$profile->save($request(['username'=>'cache-admin','nickname'=>'Changed Name','email'=>'cache-admin@invalid.test','avatar_url'=>'/uploads/changed.png']),new Response());
    $latest=MicroStatuses::latest(18)[0];
    $check('Profile save invalidates cached micro author name and avatar',$response->getStatusCode()===200
        && $latest['author']['name']==='Changed Name' && $latest['author']['avatarUrl']==='/uploads/changed.png');
    $users=new Pafish\Admin\UsersController();
    $check('Profile rollback is observable',$throws(static fn()=>DB::transaction(static function() use ($profile,$request): void {
        $profile->save($request(['username'=>'cache-admin','nickname'=>'Must Roll Back','email'=>'cache-admin@invalid.test']),new Response());
        throw new RuntimeException('rollback');
    })));
    $check('Rolled-back profile retains previous public cache',MicroStatuses::latest(18)[0]['author']['name']==='Changed Name');
    $response=$users->update($request(['nickname'=>'Admin Edit']),new Response(),['id'=>1]);
    $check('Admin user edit invalidates cached author',$response->getStatusCode()===200 && MicroStatuses::latest(18)[0]['author']['name']==='Admin Edit');
    $row=MicroStatuses::save(['content'=>'second','status'=>'PUBLISHED','published_at'=>date('Y-m-d H:i:s',time()-10)],null,2);
    MicroStatuses::latest(18);
    $response=$users->delete($request(),new Response(),['id'=>2]);
    $latest=MicroStatuses::latest(18);
    $check('Deleting author invalidates cache after transferring micro content',$response->getStatusCode()===200
        && count($latest)===2 && $latest[0]['author']['name']==='Admin Edit' && (int)$latest[0]['author']['id']===1);
    $check('Micro trash invalidates latest cache',MicroStatuses::delete((int)$row['id']) && count(MicroStatuses::latest(18))===1);
    $report=['passed'=>$passed,'failed'=>0,'cold_sql_count'=>$cold['sql_count'],'warm_sql_count'=>$warm['sql_count'],
        'cold_ms'=>$cold['elapsed_ms'],'warm_ms'=>$warm['elapsed_ms'],'cold_cache'=>$coldStats,'warm_cache'=>$warmStats];
    if(in_array('--report',$argv,true)) file_put_contents(PAFISH_ROOT.'/.design-review/cache-integration.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
} finally {
    if($pdo->inTransaction()) $pdo->rollBack();
    $pdo->afterExecute=null;
    foreach(['nav.','links.','widgets.','categories.','micro.latest.'] as $prefix) Cache::clearPrefix($prefix);
    $pdo->cleanup(); Settings::reset(); Auth::logout();
}
ob_end_flush();
