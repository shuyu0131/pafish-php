<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require __DIR__ . '/Fixtures/MySql.php';
require PAFISH_ROOT . '/app/Core/helpers.php';

use Pafish\Admin\AdminAuthMiddleware;
use Pafish\Admin\PostsController;
use Pafish\Core\Auth;
use Pafish\Core\Config;
use Pafish\Core\DB;
use Pafish\Core\HttpMiddleware;
use Pafish\Core\RedirectException;
use Pafish\Core\RequestPath;
use Pafish\Core\Session;
use Pafish\Core\Url;
use Pafish\Services\Migrator;
use Pafish\Services\Settings;
use Pafish\Tests\MySql;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
Session::start();
Url::init('/subdir/index.php');
$passed = $failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
try {
    foreach (\Pafish\Core\SqlScript::split(file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $sql) $pdo->query($sql);
    Migrator::run($pdo, PAFISH_ROOT . '/migrations');
    DB::execute("INSERT INTO users(id,username,email,password_hash,role) VALUES(1,'qa-admin','admin@invalid.test','fixture','ADMIN')");

    AppFactory::setContainer(new \DI\Container());
    $app = AppFactory::create();
    $app->setBasePath('/subdir');
    $app->get('/admin/probe', fn ($request, $response) => $response->withStatus(204))->add(AdminAuthMiddleware::class);
    $app->post('/admin/probe', fn ($request, $response) => $response->withStatus(204))->add(AdminAuthMiddleware::class);
    $app->post('/admin/posts', [new PostsController(), 'save'])->add(AdminAuthMiddleware::class);
    $app->get('/redirect-probe', fn () => throw new RedirectException('/subdir/login'));
    $normalize = static fn (string $path, ?string $query = null): array => RequestPath::normalize($path, '/subdir', $query);
    HttpMiddleware::install($app, $normalize, false);

    $factory = new ServerRequestFactory();
    Session::set('user_id', 1);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
    $response = $app->handle($factory->createServerRequest('GET', '/subdir/index.php/admin/probe'));
    $check('Subdirectory front-controller path reaches its route through the stack', $response->getStatusCode() === 204);

    $request = $factory->createServerRequest('POST', '/subdir/admin/probe')->withParsedBody([]);
    $response = $app->handle($request);
    $check('Admin POST without CSRF returns 419 through the stack', $response->getStatusCode() === 419
        && str_contains((string) $response->getBody(), 'CSRF 校验失败'));

    $token = Session::csrfToken();
    $request = $factory->createServerRequest('POST', '/subdir/admin/probe')->withParsedBody(['_csrf' => $token]);
    $response = $app->handle($request);
    $check('Admin POST with the session CSRF token reaches its route', $response->getStatusCode() === 204);

    $request = $factory->createServerRequest('POST', '/subdir/admin/posts')
        ->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->withParsedBody([
            '_csrf' => $token,
            '_idempotency' => bin2hex(random_bytes(16)),
            'title' => 'Invalid format fixture',
            'content' => 'body',
            'content_format' => 'invalid',
        ]);
    $response = $app->handle($request);
    $error = json_decode((string) $response->getBody(), true);
    $check('Invalid article format returns a handled 400 through the middleware stack', $response->getStatusCode() === 400
        && ($error['error'] ?? null) === '正文格式无效'
        && (int) DB::value('SELECT COUNT(*) FROM posts') === 0);

    Session::remove('user_id');
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
    $response = $app->handle($factory->createServerRequest('GET', '/subdir/admin/probe'));
    $check('Unauthenticated admin request becomes a local 302 through the stack', $response->getStatusCode() === 302
        && str_starts_with((string) $response->getHeaderLine('Location'), '/subdir/login?from='));

    $response = $app->handle($factory->createServerRequest('GET', '/subdir/redirect-probe'));
    $check('RedirectException becomes a 302 through the stack', $response->getStatusCode() === 302
        && $response->getHeaderLine('Location') === '/subdir/login');

    $response = $app->handle($factory->createServerRequest('GET', '/subdir/api/missing'));
    $json = json_decode((string) $response->getBody(), true);
    $check('Stack error handler keeps API 404 responses as JSON', $response->getStatusCode() === 404
        && ($json['error'] ?? null) === 'Not Found');
} catch (Throwable $e) {
    $failed++;
    echo 'ERROR ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
} finally {
    $pdo->cleanup();
    (new ReflectionProperty(DB::class, 'pdo'))->setValue(null, null);
    Settings::reset();
    Session::remove('user_id');
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
}
echo "$passed passed, $failed failed" . PHP_EOL;
ob_end_flush();
exit($failed === 0 ? 0 : 1);
