<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require __DIR__ . '/Fixtures/MySql.php';
require PAFISH_ROOT . '/app/Core/helpers.php';

use Pafish\Core\{Config, DB, Session, SqlScript};
use Pafish\Http\{CategoryController, HomeController, Listings, SearchController, TagController};
use Pafish\Services\{Content, Settings};
use Pafish\Tests\MySql;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
Session::start();
$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $passed++;
    echo 'PASS ' . $label . PHP_EOL;
};
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
$connection = new ReflectionProperty(DB::class, 'pdo');
$connection->setValue(null, $pdo);
$factory = new ServerRequestFactory();
$ids = static function (array $rows): array {
    $ids = array_map('intval', array_column($rows, 'id'));
    sort($ids);
    return $ids;
};
$render = static function (callable $action): array {
    $GLOBALS['pafish_tpl_ctx'] = [];
    $response = $action();
    return [$response, $GLOBALS['pafish_tpl_ctx']];
};
$formats = static function (string $label, array $posts) use ($check): void {
    $byId = array_column($posts, null, 'id');
    $check($label . ' preserves both persisted formats',
        ($byId[1]['content_format'] ?? null) === 'html'
        && ($byId[2]['content_format'] ?? null) === 'markdown');
    $html = Content::render($byId[1]['content'], $byId[1]['content_format']);
    $markdown = Content::render($byId[2]['content'], $byId[2]['content_format']);
    $check($label . ' allows themes to render the correct format',
        str_contains($html, '<p><strong>html fixture</strong></p>')
        && !str_contains($html, '<script')
        && str_contains($markdown, '<strong>markdown fixture</strong>'));
};
try {
    foreach (SqlScript::split(file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $sql) $pdo->query($sql);
    Settings::reset();
    Settings::setMany([
        'active_theme'=>'default', 'posts_per_page'=>'50', 'home_page_id'=>'',
        'api_enabled'=>'true', 'api_key'=>'fixture-list-key', 'api_rate_limit'=>'0',
    ]);
    DB::execute("INSERT INTO users (id,username,email,password_hash) VALUES (1,'fixture','fixture@example.invalid','unused')");
    DB::execute("INSERT INTO categories (id,name,slug,parent_id) VALUES
        (1,'root','root',NULL),(2,'child','child',1),(3,'grandchild','grandchild',2),(4,'other','other',NULL)");
    $add = static function (int $id, string $format, int $category, string $state='PUBLISHED', ?string $time='2020-01-01 00:00:00', ?string $deleted=null): void {
        $body = $format === 'html' ? '<p><strong>html fixture</strong></p><script>alert(1)</script>' : '**markdown fixture**';
        DB::execute('INSERT INTO posts (id,title,slug,content,content_format,author_id,category_id,status,published_at,deleted_at) VALUES (?,?,?,?,?,1,?,?,?,?)',
            [$id,'fixture '.$id,'fixture-'.$id,$body,$format,$category,$state,$time,$deleted]);
    };
    $add(1,'html',1);
    $add(2,'markdown',3);
    $add(3,'html',2,'PUBLISHED','2099-01-01 00:00:00');
    $add(4,'markdown',2,'DRAFT');
    $add(5,'html',2,'PUBLISHED','2020-01-01 00:00:00','2020-02-01 00:00:00');
    $add(6,'markdown',2,'PUBLISHED',null);
    $add(7,'markdown',4);
    DB::execute("INSERT INTO tags (id,name,slug) VALUES (1,'fixture tag','fixture-tag')");
    DB::execute('INSERT INTO post_tags (post_id,tag_id) VALUES (1,1),(2,1),(3,1)');
    $request = $factory->createServerRequest('GET','/');
    [$posts,$total] = Listings::published('',[],$request);
    $check('Shared list excludes future, draft and soft-deleted rows', $ids($posts)===[1,2,6,7] && $total===4);
    $formats('Shared list',$posts);

    [$response,$context] = $render(fn () => (new HomeController())->index($request,new Response()));
    $check('Homepage renders and uses the same public visibility', $response->getStatusCode()===200 && $ids($context['posts'])===[1,2,6,7]);
    $formats('Homepage',$context['posts']);
    [$response,$context] = $render(fn () => (new CategoryController())->show($request,new Response(),['slug'=>'root']));
    $check('Category includes descendants once, excludes unrelated rows', $response->getStatusCode()===200 && $ids($context['posts'])===[1,2,6] && $context['total']===3);
    $formats('Category',$context['posts']);
    [$response,$context] = $render(fn () => (new TagController())->show($request,new Response(),['slug'=>'fixture-tag']));
    $check('Tag list excludes its future post', $response->getStatusCode()===200 && $ids($context['posts'])===[1,2] && $context['total']===2);
    $formats('Tag',$context['posts']);
    $search = $request->withQueryParams(['q'=>'fixture']);
    [$response,$context] = $render(fn () => (new SearchController())->index($search,new Response()));
    $check('Search preserves visibility with fulltext and LIKE alternatives', $response->getStatusCode()===200 && $ids($context['posts'])===[1,2,6,7] && $context['total']===4);
    $formats('Search',$context['posts']);
    Settings::set('posts_per_page','2');
    [$response,$context] = $render(fn () => (new HomeController())->index($request->withQueryParams(['page'=>'2']),new Response()));
    $check('Homepage pagination preserves total and persisted format', $context['pageNum']===2 && $context['totalPages']===2
        && count($context['posts'])===2 && isset($context['posts'][0]['content_format']));
    Settings::set('posts_per_page','50');

    // Category pinning is scoped to category lists on both public entry points.
    DB::execute('UPDATE posts SET is_pinned=1 WHERE id=1');
    DB::execute('UPDATE posts SET category_pinned=1 WHERE id=2');
    [$webHome] = Listings::published('', [], $request);
    [$webCategory] = Listings::published('', [], $request, categoryIds: [1,2,3]);
    $check('Homepage ignores category-only pinning and resolves date ties',
        array_map('intval', array_column($webHome, 'id')) === [1,7,2,6]);
    $check('Category listing retains both levels of pinning',
        array_map('intval', array_column($webCategory, 'id')) === [1,2,6]);
    $pinApi = new Pafish\Api\V1Controller();
    foreach ([''=>[1,7,2,6], 'root'=>[1,2,6]] as $slug=>$expected) {
        $pinRequest = $request->withHeader('X-API-Key','fixture-list-key')
            ->withQueryParams(['category'=>$slug,'perPage'=>'50']);
        $pinResponse = $pinApi->posts($pinRequest, new Response());
        $pinData = json_decode((string)$pinResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $check('API pinning matches website: ' . ($slug ?: 'home'), $pinResponse->getStatusCode()===200
            && array_map('intval', array_column($pinData['posts'], 'id')) === $expected);
    }

    // Legacy data can contain cycles even with real single-target foreign keys.
    DB::execute("INSERT INTO categories (id,name,slug,parent_id) VALUES (5,'cycle a','cycle-a',NULL),(6,'cycle b','cycle-b',5)");
    DB::execute('UPDATE categories SET parent_id=6 WHERE id=5');
    $add(8,'html',5);
    $add(9,'markdown',6);
    $categoryReads = 0;
    $allCategoryReads = 0;
    $pdo->afterExecute = static function (string $sql) use (&$categoryReads, &$allCategoryReads, $pdo): void {
        if (stripos($sql,'SELECT')!==false && str_contains($sql,$pdo->prefix.'categories')) {
            if (++$allCategoryReads>100) throw new RuntimeException('Cyclic category traversal exceeded query budget');
        }
        if (stripos($sql, 'SELECT id,parent_id FROM')!==false) {
            $categoryReads++;
            if ($categoryReads>2) throw new RuntimeException('Category traversal must not query recursively');
        }
    };
    [$response,$context] = $render(fn () => (new CategoryController())->show($request,new Response(),['slug'=>'cycle-a']));
    $check('Web category terminates on cycles without duplicate posts', $response->getStatusCode()===200 && $ids($context['posts'])===[8,9] && $context['total']===2);
    $api = new Pafish\Api\V1Controller();
    $apiRequest = $request->withHeader('X-API-Key','fixture-list-key')->withQueryParams(['category'=>'cycle-a']);
    $response = $api->posts($apiRequest,new Response());
    $data = json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
    $check('API category terminates on cycles without duplicate posts', $response->getStatusCode()===200 && $ids($data['posts'])===[8,9] && $data['total']===2);
    $check('Both category entry points fetch the hierarchy once', $categoryReads===2);
    $pdo->afterExecute = null;
    $apiRequest = $apiRequest->withQueryParams(['category'=>'root']);
    $response = $api->posts($apiRequest,new Response());
    $data = json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
    $check('API category matches web descendant visibility', $response->getStatusCode()===200 && $ids($data['posts'])===[1,2,6] && $data['total']===3);
} finally {
    $pdo->afterExecute = null;
    $pdo->cleanup();
    $connection->setValue(null,null);
    Settings::reset();
    unset($GLOBALS['pafish_tpl_ctx']);
}
echo $passed . ' passed' . PHP_EOL;
