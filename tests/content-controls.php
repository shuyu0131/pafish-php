<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT.'/vendor/autoload.php';
require __DIR__.'/Fixtures/MySql.php';
require PAFISH_ROOT.'/app/Core/helpers.php';

use Pafish\Core\{Auth,Config,DB,Hooks,Session,SqlScript};
use Pafish\Services\{Comments,Content,ContentTransfer,MediaReferences,Migrator,Posts,Settings};
use Pafish\Tests\MySql;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
Session::start();
$count=0;
$check=static function(string $label,bool $ok)use(&$count):void{
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    $count++;echo 'PASS '.$label.PHP_EOL;
};
$reject=static function(callable $fn,?int $status=null):bool{
    try{$fn();}catch(Throwable $e){return $status===null||$e->getCode()===$status;}return false;
};
$pdo=new MySql('qa_core_'.bin2hex(random_bytes(6)).'_');
$connection=new ReflectionProperty(DB::class,'pdo');$connection->setValue(null,$pdo);
$factory=new ServerRequestFactory();
$request=$factory->createServerRequest('POST','/')->withHeader('X-Requested-With','XMLHttpRequest');
$call=static function(object $controller,array $body,array $args=[])use($request):array{
    $response=$controller->save($request->withParsedBody($body),new Response(),$args);
    return [$response->getStatusCode(),json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR)];
};
$render=static function(callable $fn):array{
    $GLOBALS['pafish_tpl_ctx']=[];
    $response=$fn();
    $dom=new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.(string)$response->getBody());
    return [new DOMXPath($dom),$GLOBALS['pafish_tpl_ctx'],(string)$response->getBody()];
};
try{
    foreach(SqlScript::split(file_get_contents(PAFISH_ROOT.'/app/install/schema.sql'))as$sql)$pdo->query($sql);
    // Model a partially upgraded real schema, including its existing page description.
    foreach(['posts'=>['seo_title','meta_description','allow_comments'],'pages'=>['seo_title'],
        'categories'=>['seo_title','meta_description','cover_url'],
        'tags'=>['description','seo_title','meta_description'],'links'=>['icon_url']]as$table=>$columns){
        foreach($columns as$column)$pdo->query("ALTER TABLE $table DROP COLUMN $column");
    }
    DB::execute("INSERT INTO pages(title,slug,content,meta_description)VALUES('legacy','legacy','body','legacy SEO description')");
    Migrator::run($pdo,PAFISH_ROOT.'/migrations');
    $check('Upgrade preserves an existing page description',DB::value("SELECT meta_description FROM pages WHERE slug='legacy'")==='legacy SEO description');
    $check('Field migration is repeatable',Migrator::run($pdo,PAFISH_ROOT.'/migrations')===['applied'=>[]]);
    DB::execute("INSERT INTO users(id,username,email,password_hash,role)VALUES(1,'fixture','fixture@example.invalid','unused','ADMIN')");
    Session::set('user_id',1);
    (new ReflectionProperty(Auth::class,'user'))->setValue(null,null);
    Settings::reset();
    Settings::setMany(['active_theme'=>'default','comments_enabled'=>'true','api_enabled'=>'true',
        'api_key'=>'fixture-controls','api_rate_limit'=>'0','comments_captcha_enabled'=>'false']);
    $seo=['seo_title'=>'自定义标题','meta_description'=>'描述 "引号" <文本>'];
    $categories=new Pafish\Admin\CategoriesController();
    [$status,$result]=$call($categories,['name'=>'分类原名','slug'=>'controls-category','cover_url'=>'/uploads/controls.png']+$seo);
    $category=$result['id']??0;
    $check('Category saves cover and independent SEO',$status===200&&$category>0&&DB::value('SELECT seo_title FROM categories WHERE id=?',[$category])===$seo['seo_title']);
    [$status]=$call($categories,['name'=>'分类改名','slug'=>'controls-category'],['id'=>$category]);
    $check('Older category submissions preserve omitted new fields',$status===200&&DB::value('SELECT cover_url FROM categories WHERE id=?',[$category])==='/uploads/controls.png');
    [$status]=$call($categories,['name'=>'分类改名','slug'=>'controls-category','cover_url'=>'javascript:alert(1)'],['id'=>$category]);
    $check('Category rejects executable image URLs',$status===400);
    $tags=new Pafish\Admin\TagsController();
    [$status,$result]=$call($tags,['name'=>'标签原名','slug'=>'controls-tag','description'=>'标签介绍']+$seo);
    $tag=$result['id']??0;
    $check('Tag saves description and independent SEO',$status===200&&DB::value('SELECT description FROM tags WHERE id=?',[$tag])==='标签介绍');
    $posts=new Pafish\Admin\PostsController();
    $observed=[];
    $off=Hooks::addAction('after_post_save',static function(array $payload)use(&$observed):void{$observed=$payload['post'];});
    $body=['title'=>'文章原名','slug'=>'controls-post','content'=>'**正文**','action'=>'publish',
        'allow_comments'=>'0','category_id'=>(string)$category,'tag_ids'=>[$tag]]+$seo;
    $post=$posts->savePost($body)['id'];
    $off();
    $check('Save events include final comment and SEO controls',$observed['allowComments']===false&&$observed['seoTitle']===$seo['seo_title']);
    $check('New article persists disabled comments',DB::value('SELECT allow_comments FROM posts WHERE id=?',[$post])===0);
    $check('Closed article rejects service submissions',$reject(fn()=>Comments::create([
        'post_id'=>$post,'author_name'=>'visitor','author_email'=>'visitor@example.invalid','content'=>'blocked','status'=>'APPROVED'
    ]),403));
    $old=$body;unset($old['allow_comments'],$old['seo_title'],$old['meta_description']);$old['action']='auto';
    $posts->savePost($old,$post);
    $check('Autosave and older clients preserve omitted controls',(int)DB::value('SELECT allow_comments FROM posts WHERE id=?',[$post])===0
        &&DB::value('SELECT meta_description FROM posts WHERE id=?',[$post])===$seo['meta_description']);
    $check('Malformed comment flags are rejected',$reject(fn()=>$posts->savePost(array_replace($body,['allow_comments'=>'false']),$post)));
    $pages=new Pafish\Admin\PagesController();
    [$status,$result]=$call($pages,['title'=>'页面原名','slug'=>'controls-page','content'=>'页面正文','status'=>'PUBLISHED','template'=>'default']+$seo);
    $page=$result['id']??0;
    $check('Page editor saves SEO without changing visible title',$status===200&&DB::value('SELECT title FROM pages WHERE id=?',[$page])==='页面原名');

    DB::execute("INSERT INTO comments(post_id,author_name,author_email,content,status)VALUES(?,'visitor','visitor@example.invalid','historic comment','APPROVED')",[$post]);
    $get=$factory->createServerRequest('GET','/');
    [$xpath,$ctx]=$render(fn()=>(new Pafish\Http\PostController())->show($get,new Response(),['slug'=>'controls-post']));
    $check('Closed article retains historical comments',$ctx['commentTotal']===1&&str_contains($xpath->document->textContent,'historic comment'));
    $check('Closed article has no submission or reply controls',$xpath->query('//form[@data-comment-form]')->length===0
        &&$xpath->query('//button[@data-reply-toggle]')->length===0);
    $check('Article SEO title does not replace the visible heading',str_contains($xpath->evaluate('string(//title)'),$seo['seo_title'])
        &&str_contains($xpath->evaluate('string(//h1)'),'文章原名'));
    $check('Article metadata escapes markup correctly',$xpath->evaluate('string(//meta[@name="description"]/@content)')===$seo['meta_description']);
    $form=$factory->createServerRequest('POST','/', ['REMOTE_ADDR'=>$pdo->prefix])->withHeader('User-Agent','Mozilla/5.0')
        ->withParsedBody(['postId'=>(string)$post,'content'=>'rejected website comment']);
    $response=(new Pafish\Http\CommentApiController())->create($form,new Response());
    $check('Website comment endpoint rejects a closed article',$response->getStatusCode()===403);
    DB::execute('UPDATE posts SET allow_comments=1 WHERE id=?',[$post]);
    $check('Reopening an article permits submissions',Comments::create([
        'post_id'=>$post,'author_name'=>'visitor','author_email'=>'visitor@example.invalid','content'=>'accepted','status'=>'APPROVED'
    ])>0);
    Settings::set('comments_enabled','false');
    $check('Global switch still overrides article setting',!Posts::commentsOpen(DB::fetchOne('SELECT * FROM posts WHERE id=?',[$post]))
        &&$reject(fn()=>Comments::create(['post_id'=>$post,'author_name'=>'v','author_email'=>'v@example.invalid','content'=>'blocked','status'=>'APPROVED']),403));
    Settings::set('comments_enabled','true');DB::execute('UPDATE posts SET allow_comments=0 WHERE id=?',[$post]);
    [$xpath,$ctx]=$render(fn()=>(new Pafish\Http\CategoryController())->show($get,new Response(),['slug'=>'controls-category']));
    $check('Category exposes cover to templates and Open Graph',$ctx['category']['cover_url']==='/uploads/controls.png'
        &&$xpath->query('//img[contains(@class,"listing-cover")]')->length===1
        &&str_ends_with($xpath->evaluate('string(//meta[@property="og:image"]/@content)'),'/uploads/controls.png'));
    $check('Category SEO does not replace its visible name',str_contains($xpath->evaluate('string(//title)'),$seo['seo_title'])
        &&$xpath->evaluate('string(//h1)')==='分类改名');
    [$xpath]=$render(fn()=>(new Pafish\Http\TagController())->show($get,new Response(),['slug'=>'controls-tag']));
    $check('Tag renders its introduction and SEO',str_contains($xpath->document->textContent,'标签介绍')
        &&$xpath->evaluate('string(//meta[@name="description"]/@content)')===$seo['meta_description']);
    [$xpath]=$render(fn()=>(new Pafish\Http\PageController())->show($get,new Response(),['slug'=>'controls-page']));
    $check('Page renders independent metadata',$xpath->evaluate('string(//meta[@name="description"]/@content)')===$seo['meta_description']);
    Settings::set('home_page_id',(string)$page);
    [$xpath]=$render(fn()=>(new Pafish\Http\HomeController())->index($get,new Response()));
    $check('Page configured as homepage retains its metadata',$xpath->evaluate('string(//meta[@name="description"]/@content)')===$seo['meta_description']);
    Settings::remove('home_page_id');
    $api=new Pafish\Api\V1Controller();$apiGet=$get->withHeader('X-API-Key','fixture-controls');
    $data=json_decode((string)$api->categories($apiGet,new Response())->getBody(),true);
    $check('Category API includes cover and SEO',$data['categories'][0]['coverUrl']==='/uploads/controls.png'&&$data['categories'][0]['seoTitle']===$seo['seo_title']);
    $data=json_decode((string)$api->tags($apiGet,new Response())->getBody(),true);
    $check('Tag API includes description and SEO',$data['tags'][0]['description']==='标签介绍'&&$data['tags'][0]['seoTitle']===$seo['seo_title']);
    $data=json_decode((string)$api->pages($apiGet,new Response())->getBody(),true);
    $check('Page API includes independent SEO',$data['pages'][0]['seoTitle']===$seo['seo_title']&&$data['pages'][0]['metaDescription']===$seo['meta_description']);
    $data=json_decode((string)$api->postDetail($apiGet,new Response(),['slug'=>'controls-post'])->getBody(),true);
    $check('Article API reports comment availability and metadata',$data['commentsEnabled']===false&&$data['metaDescription']===$seo['meta_description']);

    DB::execute("INSERT INTO uploads(original_name,url,mime,size)VALUES('controls.png','/uploads/controls.png','image/png',1)");
    $upload=DB::lastInsertId();MediaReferences::rebuild();
    $check('Category cover contributes a protected media reference',(int)DB::value('SELECT usage_count FROM uploads WHERE id=?',[$upload])===1);
    $links=new Pafish\Admin\LinksController();
    [$status]=$call($links,['name'=>'友链','url'=>'https://example.invalid','icon_url'=>'/uploads/controls.png']);
    $link=(int)DB::value('SELECT id FROM links ORDER BY id DESC LIMIT 1');
    $check('Friend link icon shares media protection',$status===200&&(int)DB::value('SELECT usage_count FROM uploads WHERE id=?',[$upload])===2);
    $data=json_decode((string)$api->links($apiGet,new Response())->getBody(),true);
    $check('Friend link API includes its icon',$data['links'][0]['iconUrl']==='/uploads/controls.png');
    $check('Referenced image cannot be deleted',$reject(fn()=>MediaReferences::deleteUpload($upload,null,false),409));
    $export=ContentTransfer::export();
    $check('Content export includes taxonomy and article controls',$export['categories'][0]['cover_url']==='/uploads/controls.png'
        &&$export['tags'][0]['description']==='标签介绍'&&(int)$export['posts'][0]['allow_comments']===0
        &&$export['pages'][1]['meta_description']===$seo['meta_description']);
    DB::execute("UPDATE categories SET cover_url=NULL,seo_title='',meta_description=''");
    DB::execute("UPDATE posts SET allow_comments=1,seo_title='',meta_description=''");
    DB::execute("UPDATE tags SET description='',seo_title='',meta_description=''");
    DB::execute("UPDATE pages SET seo_title='',meta_description=''");
    ContentTransfer::import($export,'update');
    $check('Import restores fields on existing categories and tags',DB::value('SELECT cover_url FROM categories WHERE id=?',[$category])==='/uploads/controls.png'
        &&DB::value('SELECT description FROM tags WHERE id=?',[$tag])==='标签介绍');
    $check('Import restores page and article metadata',(int)DB::value('SELECT allow_comments FROM posts WHERE id=?',[$post])===0
        &&DB::value('SELECT meta_description FROM pages WHERE id=?',[$page])===$seo['meta_description']);
    $legacy=$export;
    foreach(['posts','pages','categories','tags']as$key)foreach($legacy[$key]as&$row){
        unset($row['seo_title'],$row['meta_description'],$row['allow_comments'],$row['cover_url']);
        if($key==='tags')unset($row['description']);
    }unset($row);
    ContentTransfer::import($legacy,'update');
    $check('Old content packages preserve new fields on updates',DB::value('SELECT seo_title FROM posts WHERE id=?',[$post])===$seo['seo_title']
        &&DB::value('SELECT cover_url FROM categories WHERE id=?',[$category])==='/uploads/controls.png');
    $categories->delete($request,new Response(),['id'=>$category]);
    $check('Category deletion removes only its media reference',(int)DB::value('SELECT usage_count FROM uploads WHERE id=?',[$upload])===1);
    $links->delete($request,new Response(),['id'=>$link]);
    $check('Link deletion releases its icon reference',(int)DB::value('SELECT usage_count FROM uploads WHERE id=?',[$upload])===0);
    $check('SEO validation rejects arrays and oversized strings',$reject(fn()=>Content::seoFields(['seo_title'=>[]]))
        &&$reject(fn()=>Content::seoFields(['meta_description'=>str_repeat('字',501)])));
    foreach(['data:image/svg+xml,test','//example.invalid/a.png','https://user:pass@example.invalid/a','/\\example.invalid/a']as$url){
        $check('Unsafe image address is rejected: '.substr($url,0,20),$reject(fn()=>Content::imageUrl($url)));
    }
}finally{
    $pdo->cleanup();$connection->setValue(null,null);Settings::reset();
    Session::remove('user_id');(new ReflectionProperty(Auth::class,'user'))->setValue(null,null);
    unset($GLOBALS['pafish_tpl_ctx']);
}
echo $count.' passed'.PHP_EOL;
