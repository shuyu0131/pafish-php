<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require PAFISH_ROOT . '/app/Core/helpers.php';
require __DIR__ . '/Fixtures/MySql.php';

use Pafish\Core\Config;
use Pafish\Core\Auth;
use Pafish\Core\DB;
use Pafish\Core\Session;
use Pafish\Core\RateLimiter;
use Pafish\Core\SqlScript;
use Pafish\Services\AppTokens;
use Pafish\Services\Migrator;
use Pafish\Services\Plugin;
use Pafish\Services\Settings;
use Pafish\Tests\MySql;
use Pafish\Http\AppAuthController;
use Pafish\Http\AppTokenMiddleware;
use Pafish\Http\AppContentController;
use Pafish\Admin\AdminAuthMiddleware;
use Pafish\Admin\ApiController;
use Pafish\Admin\PostsController;
use Pafish\Admin\ProfileController;
use Pafish\Core\HttpMiddleware;
use Pafish\Api\V1Controller;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface;

Config::load(Config::resolveFile(PAFISH_ROOT));
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $passed++;
    echo 'PASS ' . $label . PHP_EOL;
};
$pluginName = 'qa_orphan_' . bin2hex(random_bytes(4));
$pluginDir = PAFISH_ROOT . '/plugins/' . $pluginName;
try {
    foreach (SqlScript::split((string) file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $sql) $pdo->query($sql);
    Migrator::run($pdo, PAFISH_ROOT . '/migrations');
    DB::execute('INSERT INTO users (id,username,email,password_hash,role) VALUES
        (1,?,?,?,\'ADMIN\'),(2,?,?,?,\'EDITOR\')', [
        'app-token-admin', 'app-token-admin@example.invalid', password_hash('valid-password', PASSWORD_BCRYPT),
        'app-token-editor', 'app-token-editor@example.invalid', password_hash('valid-password', PASSWORD_BCRYPT),
    ]);

    $factory = new ServerRequestFactory();
    $controller = new AppAuthController();
    $response = $controller->login($factory->createServerRequest('POST', '/api/app/auth/login', ['REMOTE_ADDR' => '198.18.0.1'])
        ->withParsedBody(['username' => 'app-token-admin', 'password' => 'valid-password', 'deviceName' => 'QA phone']), new Response());
    $payload = json_decode((string) $response->getBody(), true);
    $token = (string) ($payload['accessToken'] ?? '');
    $tokenId = (int) DB::value('SELECT id FROM app_tokens WHERE user_id = 1');
    $storedHash = (string) DB::value('SELECT token_hash FROM app_tokens WHERE id = ?', [$tokenId]);
    $check('Password login issues a first-party bearer token', $response->getStatusCode() === 200 && preg_match('/^[a-f0-9]{64}$/D', $token) === 1);
    $check('Database stores only the token hash', $storedHash === hash('sha256', $token) && !hash_equals($storedHash, $token));
    $remaining = (int) DB::value('SELECT TIMESTAMPDIFF(DAY, NOW(), expires_at) FROM app_tokens WHERE id = ?', [$tokenId]);
    $check('Token expiry is generated from database time', $remaining >= 89 && $remaining <= 90);

    $request = static fn (string $bearer) => $factory->createServerRequest('GET', '/api/app/auth/me')
        ->withHeader('Authorization', 'Bearer ' . $bearer);
    $authenticated = AppTokens::authenticate($request($token));
    $check('Bearer authentication returns the current user and token id', $authenticated !== null
        && $authenticated['tokenId'] === $tokenId && $authenticated['user']['id'] === 1);
    DB::execute('UPDATE users SET role = \'EDITOR\' WHERE id = 1');
    $check('Role changes take effect on the next token authentication', AppTokens::authenticate($request($token))['user']['role'] === 'EDITOR');

    $_SESSION = ['user_id' => 2, '_csrf' => 'browser-session-csrf'];
    $browserUserId = Auth::id();
    $bridgeHandler = new class implements RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            $response = new Response();
            $response->getBody()->write(json_encode([
                'userId' => Auth::id(),
                'canManagePosts' => Auth::can('posts.manage'),
                'canManageUsers' => Auth::can('users.manage'),
                'csrfAccepted' => Session::verifyCsrf(null),
            ]));
            return $response;
        }
    };
    $adminRequest = $factory->createServerRequest('POST', '/admin/posts/save')
        ->withHeader('Authorization', 'Bearer ' . $token)
        ->withHeader('X-Requested-With', 'XMLHttpRequest');
    $bridgeResponse = (new AdminAuthMiddleware())($adminRequest, $bridgeHandler);
    $bridge = json_decode((string) $bridgeResponse->getBody(), true);
    $check('Verified app token supplies its user and capabilities to existing admin handlers', $bridge['userId'] === 1
        && $bridge['canManagePosts'] && !$bridge['canManageUsers'] && $bridge['csrfAccepted']);
    $check('Bearer bridge does not overwrite the browser session identity', (int) $_SESSION['user_id'] === 2
        && Auth::id() === $browserUserId);
    $invalidResponse = (new AdminAuthMiddleware())(
        $adminRequest->withHeader('Authorization', 'Bearer ' . str_repeat('0', 64)), $bridgeHandler
    );
    $check('Invalid bearer credentials cannot fall back to a logged-in browser session', $invalidResponse->getStatusCode() === 401);
    $htmlResponse = (new AdminAuthMiddleware())(
        $factory->createServerRequest('GET', '/admin')->withHeader('Authorization', 'Bearer ' . $token), $bridgeHandler
    );
    $check('Bearer credentials cannot be used to fetch browser admin pages', $htmlResponse->getStatusCode() === 405);
    $settingsResponse = (new AdminAuthMiddleware())(
        $adminRequest->withUri($adminRequest->getUri()->withPath('/admin/settings/save')), $bridgeHandler
    );
    $check('Bearer credentials cannot call unrelated high-impact admin routes', $settingsResponse->getStatusCode() === 405);
    $purgeResponse = (new AdminAuthMiddleware())(
        $adminRequest->withUri($adminRequest->getUri()->withPath('/admin/posts/batch'))
            ->withParsedBody(['op' => 'purge', 'ids' => [1]]), $bridgeHandler
    );
    $check('Bearer batch requests cannot permanently purge articles', $purgeResponse->getStatusCode() === 403);

    $apiRequest = $factory->createServerRequest('GET', '/api/uploads')->withHeader('Authorization', 'Bearer ' . $token);
    $apiResponse = (new AppTokenMiddleware())($apiRequest, $bridgeHandler);
    $apiIdentity = json_decode((string) $apiResponse->getBody(), true);
    $check('Selected existing JSON APIs receive the bearer user context', $apiIdentity['userId'] === 1 && $apiIdentity['csrfAccepted']);
    $uploadsResponse = (new AppTokenMiddleware())($apiRequest, new class implements RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return (new ApiController())->uploads($request, new Response());
        }
    });
    $uploadsPayload = json_decode((string) $uploadsResponse->getBody(), true);
    $check('Existing media listing accepts an app token and keeps its JSON contract', $uploadsResponse->getStatusCode() === 200
        && is_array($uploadsPayload['items'] ?? null));
    $previewRequest = $factory->createServerRequest('POST', '/api/md-preview')
        ->withHeader('Authorization', 'Bearer ' . $token)
        ->withParsedBody(['content' => '**preview**', 'content_format' => 'markdown']);
    $previewResponse = (new AppTokenMiddleware())($previewRequest, new class implements RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return (new ApiController())->mdPreview($request, new Response());
        }
    });
    $previewPayload = json_decode((string) $previewResponse->getBody(), true);
    $check('Markdown preview accepts bearer identity without a browser CSRF token', $previewResponse->getStatusCode() === 200
        && str_contains((string) ($previewPayload['html'] ?? ''), '<strong>preview</strong>'));
    RateLimiter::clear('app-token-request', (string) $tokenId);
    for ($i = 0; $i < 120; $i++) (new AppTokenMiddleware())($apiRequest, $bridgeHandler);
    $limitedResponse = (new AppTokenMiddleware())($apiRequest, $bridgeHandler);
    $check('App API bearer requests are rate limited per device token', $limitedResponse->getStatusCode() === 429);
    RateLimiter::clear('app-token-request', (string) $tokenId);

    DB::execute("INSERT INTO posts(id,title,slug,content,author_id,status) VALUES
        (1,'Editor draft','editor-draft','body',2,'DRAFT'),
        (2,'Admin draft','admin-draft','body',1,'DRAFT')");
    DB::execute("INSERT INTO comments(id,post_id,author_name,author_email,content,status,ip) VALUES
        (1,1,'Guest A','guest-a@example.invalid','awaiting review','PENDING','198.18.0.2'),
        (2,2,'Guest B','guest-b@example.invalid','other author','PENDING','198.18.0.3')");
    $appContent = new AppContentController();
    $listResponse = Auth::withBearerUser(2, fn () => $appContent->posts(
        $factory->createServerRequest('GET', '/api/app/posts'), new Response()
    ));
    $list = json_decode((string) $listResponse->getBody(), true);
    $deepListResponse = Auth::withBearerUser(2, fn () => $appContent->posts(
        $factory->createServerRequest('GET', '/api/app/posts')->withQueryParams(['page' => PHP_INT_MAX]), new Response()
    ));
    $deepList = json_decode((string) $deepListResponse->getBody(), true);
    $detailResponse = Auth::withBearerUser(2, fn () => $appContent->post(
        $factory->createServerRequest('GET', '/api/app/posts/1'), new Response(), ['id' => '1']
    ));
    $detail = json_decode((string) $detailResponse->getBody(), true);
    $privateResponse = Auth::withBearerUser(2, fn () => $appContent->post(
        $factory->createServerRequest('GET', '/api/app/posts/2'), new Response(), ['id' => '2']
    ));
    $commentsResponse = Auth::withBearerUser(2, fn () => $appContent->comments(
        $factory->createServerRequest('GET', '/api/app/comments'), new Response()
    ));
    $comments = json_decode((string) $commentsResponse->getBody(), true);
    $check('App post listing includes drafts and remains author scoped', $list['total'] === 1 && $list['posts'][0]['status'] === 'DRAFT');
    $check('App list clamps extreme page values without overflowing SQL offsets', $deepListResponse->getStatusCode() === 200 && $deepList['page'] === 1);
    $check('App post detail supplies editable content for owned articles', ($detail['post']['content'] ?? null) === 'body');
    $check('App post detail hides articles owned by another editor', $privateResponse->getStatusCode() === 404);
    $search = static fn (array $query) => Auth::withBearerUser(2, fn () => $appContent->posts(
        $factory->createServerRequest('GET', '/api/app/posts')->withQueryParams($query), new Response()
    ));
    $titleMatch = json_decode((string) $search(['q' => 'editor'])->getBody(), true);
    $slugMatch = json_decode((string) $search(['q' => 'editor-dr'])->getBody(), true);
    $foreignMatch = json_decode((string) $search(['q' => 'admin'])->getBody(), true);
    $wildcardMatch = json_decode((string) $search(['q' => '%'])->getBody(), true);
    $check('App post listing searches titles and slugs within the author scope', $titleMatch['total'] === 1
        && $slugMatch['total'] === 1 && $foreignMatch['total'] === 0);
    $check('App post search treats LIKE wildcards as literal text', $wildcardMatch['total'] === 0);
    $check('App comment review list is author scoped and excludes private commenter data', $comments['total'] === 1
        && ($comments['comments'][0]['content'] ?? '') === 'awaiting review'
        && !array_key_exists('authorEmail', $comments['comments'][0] ?? []));

    // 连接页靠 /auth/me 一次调用同时验令牌与取站点身份；timezone 决定 App 端日期解析方式。
    $meResponse = Auth::withBearerUser(1, fn () => (new AppAuthController())->me($request($token), new Response()));
    $connect = json_decode((string) $meResponse->getBody(), true);
    $check('Connect payload carries site identity and wall-clock timezone', $meResponse->getStatusCode() === 200
        && ($connect['site']['name'] ?? '') !== ''
        && ($connect['site']['timezone'] ?? '') === (string) Config::get('timezone', 'Asia/Shanghai')
        && !array_key_exists('url', $connect['site'] ?? []));

    // 用户 1 此时是 EDITOR，评论按作者收敛到 post 2，这里给它补上另外两种状态。
    DB::execute("INSERT INTO comments(id,post_id,author_name,author_email,content,status,ip) VALUES
        (3,2,'Guest C','guest-c@example.invalid','already approved','APPROVED','198.18.0.4'),
        (4,2,'Guest D','guest-d@example.invalid','turned down','REJECTED','198.18.0.5')");
    $scopedComments = static fn (array $query) => Auth::withBearerUser(1, fn () => $appContent->comments(
        $factory->createServerRequest('GET', '/api/app/comments')->withQueryParams($query), new Response()
    ));
    $allComments = json_decode((string) $scopedComments(['status' => 'ALL'])->getBody(), true);
    $rejectedComments = json_decode((string) $scopedComments(['status' => 'REJECTED'])->getBody(), true);
    $unknownComments = json_decode((string) $scopedComments(['status' => 'NOPE'])->getBody(), true);
    $check('Comment review can span every status and select rejected on request', $allComments['total'] === 3
        && $rejectedComments['total'] === 1 && ($rejectedComments['comments'][0]['content'] ?? '') === 'turned down');
    $check('Unknown comment status falls back to the review queue', $unknownComments['total'] === 1
        && ($unknownComments['comments'][0]['status'] ?? '') === 'PENDING');

    // 驱动异常只写日志：客户端拿到的是可读中文，不是 SQLSTATE 与列名。
    $fkProbe = Auth::withBearerUser(1, fn () => (new PostsController())->save(
        $factory->createServerRequest('POST', '/admin/posts/save')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withParsedBody(['title' => 'FK violation probe', 'content' => 'body', 'category_id' => '999999']),
        new Response(), []
    ));
    $fkBody = json_decode((string) $fkProbe->getBody(), true);
    $check('Article save failures answer with a generic message instead of SQL internals', $fkProbe->getStatusCode() === 500
        && ($fkBody['error'] ?? '') === '保存失败，请稍后重试');

    // 分类/标签候选：同一路由令牌可达、匿名被拒（fail-closed）。
    AppFactory::setContainer(new \DI\Container());
    $routeApp = AppFactory::create();
    $routeApp->setBasePath('/subdir');
    $routeApp->get('/api/v1/categories', [new V1Controller(), 'categories'])->add(AppTokenMiddleware::class);
    $bearerTaxonomy = json_decode((string) $routeApp->handle($factory->createServerRequest('GET', '/subdir/api/v1/categories')
        ->withHeader('Authorization', 'Bearer ' . $token))->getBody(), true);
    $anonymousTaxonomy = $routeApp->handle($factory->createServerRequest('GET', '/subdir/api/v1/categories'));
    $check('Taxonomy pickers are reachable with an app token', is_array($bearerTaxonomy['categories'] ?? null));
    $check('Taxonomy pickers stay closed to callers without a token', $anonymousTaxonomy->getStatusCode() === 401);

    $other = AppTokens::issue(2, 'Other account phone');
    $otherStoredId = (int) DB::value('SELECT id FROM app_tokens WHERE user_id = 2');
    $check('Token issuance returns its persisted device ID', $other['id'] === $otherStoredId && $otherStoredId > 0);
    $check('A public API key does not authenticate as an app user', AppTokens::authenticate(
        $factory->createServerRequest('GET', '/api/app/auth/me')->withHeader('X-API-Key', $token)
    ) === null);
    $check('A user cannot revoke another user device token', !AppTokens::revoke(1, $other['id'])
        && AppTokens::authenticate($request($other['token'])) !== null);
    DB::execute('UPDATE app_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$tokenId]);
    $check('Expired token is rejected', AppTokens::authenticate($request($token)) === null);
    DB::execute('UPDATE app_tokens SET expires_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = ?', [$tokenId]);
    DB::execute('UPDATE users SET disabled = 1 WHERE id = 1');
    $check('Disabled user token is rejected', AppTokens::authenticate($request($token)) === null);
    DB::execute('UPDATE users SET disabled = 0 WHERE id = 1');
    $check('Single-device revocation invalidates that token', AppTokens::revoke(1, $tokenId)
        && AppTokens::authenticate($request($token)) === null);

    $deviceTokens = [];
    for ($i = 0; $i < 10; $i++) $deviceTokens[] = AppTokens::issue(1, 'Device ' . $i);
    $limitReached = false;
    try { AppTokens::issue(1, 'Overflow device'); } catch (RuntimeException $e) { $limitReached = str_contains($e->getMessage(), '上限'); }
    $check('Per-user active device limit is enforced', $limitReached);
    $check('Bulk revocation invalidates all active tokens', AppTokens::revokeAll(1) === 10
        && AppTokens::authenticate($request($deviceTokens[0]['token'])) === null);

    // Exercise the actual registered browser routes through the shared HTTP stack.
    AppFactory::setContainer(new \DI\Container());
    $app = AppFactory::create();
    require PAFISH_ROOT . '/app/Http/routes.php';
    HttpMiddleware::install($app, static fn (string $path, ?string $query = null): array => [$path], false);
    $_SESSION = ['user_id' => 2, '_csrf' => 'profile-token-csrf'];
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
    RateLimiter::clear('profile-app-token', '2');
    $profileRequest = $factory->createServerRequest('POST', '/admin/profile/app-token/create')
        ->withHeader('X-Requested-With', 'XMLHttpRequest');
    $profileBody = ['_csrf' => 'profile-token-csrf', 'device_name' => 'My phone', 'current_password' => 'valid-password'];
    $tokenCount = (int) DB::value('SELECT COUNT(*) FROM app_tokens');
    $missingCsrf = $app->handle($profileRequest->withParsedBody(array_diff_key($profileBody, ['_csrf' => true])));
    $check('Profile token creation rejects missing CSRF without issuing a token', $missingCsrf->getStatusCode() === 419
        && (int) DB::value('SELECT COUNT(*) FROM app_tokens') === $tokenCount);
    $wrongPassword = $app->handle($profileRequest->withParsedBody(array_replace($profileBody, ['current_password' => 'incorrect'])));
    $wrongPayload = json_decode((string) $wrongPassword->getBody(), true);
    $check('Profile token creation confirms the current password', $wrongPassword->getStatusCode() === 400
        && ($wrongPayload['error'] ?? '') === '当前密码不正确' && (int) DB::value('SELECT COUNT(*) FROM app_tokens') === $tokenCount);
    foreach (['', str_repeat('x', 101), ['nested'], "phone\nname"] as $invalidName) {
        $invalidNameResponse = $app->handle($profileRequest->withParsedBody(array_replace($profileBody, ['device_name' => $invalidName])));
        $check('Profile token creation rejects invalid device names', $invalidNameResponse->getStatusCode() === 400
            && (int) DB::value('SELECT COUNT(*) FROM app_tokens') === $tokenCount);
    }
    $created = $app->handle($profileRequest->withParsedBody($profileBody + ['user_id' => 1, 'role' => 'ADMIN']));
    $createdPayload = json_decode((string) $created->getBody(), true);
    $profileToken = (string) ($createdPayload['accessToken'] ?? '');
    $profileTokenId = (int) ($createdPayload['id'] ?? 0);
    $check('Browser profile can create a usable device token', $created->getStatusCode() === 200
        && ($createdPayload['ok'] ?? false) && preg_match('/^[a-f0-9]{64}$/D', $profileToken) === 1
        && AppTokens::authenticate($request($profileToken))['user']['id'] === 2);
    $check('Profile token owner comes only from the logged-in account', (int) DB::value('SELECT user_id FROM app_tokens WHERE id = ?', [$profileTokenId]) === 2);
    $check('Profile token response is not cacheable and stores only a hash', $created->getHeaderLine('Cache-Control') === 'no-store'
        && DB::value('SELECT token_hash FROM app_tokens WHERE id = ?', [$profileTokenId]) === hash('sha256', $profileToken));
    $listed = $app->handle($factory->createServerRequest('GET', '/api/app/auth/tokens')->withHeader('Authorization', 'Bearer ' . $profileToken));
    $listedBody = (string) $listed->getBody();
    $check('Device list never returns plaintext tokens or their hashes', $listed->getStatusCode() === 200
        && !str_contains($listedBody, $profileToken) && !str_contains($listedBody, hash('sha256', $profileToken))
        && !str_contains($listedBody, 'accessToken') && !str_contains($listedBody, 'token_hash'));
    $profilePage = $app->handle($factory->createServerRequest('GET', '/admin/profile'));
    $profileHtml = (string) $profilePage->getBody();
    $check('Profile renders the creation and copy controls without revealing issued secrets', $profilePage->getStatusCode() === 200
        && str_contains($profileHtml, 'id="appTokenForm"') && str_contains($profileHtml, 'id="appTokenCopy"')
        && str_contains($profileHtml, 'My phone') && !str_contains($profileHtml, $profileToken));
    if (in_array('--snapshots', $argv, true)) {
        $snapshotDir = PAFISH_ROOT . '/.design-review/account-center';
        if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0755, true);
        file_put_contents($snapshotDir . '/profile-app-tokens.html', $profileHtml);
    }
    $bearerCreate = $app->handle($profileRequest->withHeader('Authorization', 'Bearer ' . $profileToken)->withParsedBody($profileBody));
    $directBearerCreate = Auth::withBearerUser(2, fn () => (new ProfileController())->createAppToken(
        $profileRequest->withParsedBody($profileBody), new Response()
    ));
    $check('Existing bearer tokens cannot mint additional device credentials', $bearerCreate->getStatusCode() === 405
        && $directBearerCreate->getStatusCode() === 403 && (int) DB::value('SELECT COUNT(*) FROM app_tokens') === $tokenCount + 1);
    RateLimiter::clear('profile-app-token', '2');
    for ($i = 0; $i < 10; $i++) RateLimiter::allow('profile-app-token', '2', 10, 600);
    $rateLimited = $app->handle($profileRequest->withParsedBody($profileBody));
    $check('Browser token creation has a per-account rate limit', $rateLimited->getStatusCode() === 429
        && (int) DB::value('SELECT COUNT(*) FROM app_tokens') === $tokenCount + 1);
    RateLimiter::clear('profile-app-token', '2');
    $revoked = $app->handle($factory->createServerRequest('POST', '/admin/profile/app-token/' . $profileTokenId . '/revoke')
        ->withParsedBody(['_csrf' => 'profile-token-csrf']));
    $check('Browser profile can revoke its newly created device token', $revoked->getStatusCode() === 200
        && AppTokens::authenticate($request($profileToken)) === null);
    Session::remove('user_id');
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
    $anonymousCreate = $app->handle($profileRequest->withParsedBody($profileBody));
    $check('Anonymous callers cannot create personal device tokens', $anonymousCreate->getStatusCode() === 302
        && (int) DB::value('SELECT COUNT(*) FROM app_tokens') === $tokenCount + 1);

    $deleteRequest = $factory->createServerRequest('POST', '/admin/profile/app-token/' . $profileTokenId . '/delete')
        ->withHeader('X-Requested-With', 'XMLHttpRequest');
    $deleteBody = ['_csrf' => 'profile-token-csrf'];
    $anonymousDelete = $app->handle($deleteRequest->withParsedBody($deleteBody));
    $check('Anonymous callers cannot delete revoked token records', $anonymousDelete->getStatusCode() === 302
        && DB::value('SELECT id FROM app_tokens WHERE id = ?', [$profileTokenId]) !== null);
    $_SESSION = ['user_id' => 2, '_csrf' => 'profile-token-csrf'];
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
    $missingDeleteCsrf = $app->handle($deleteRequest->withParsedBody([]));
    $wrongDeleteCsrf = $app->handle($deleteRequest->withParsedBody(['_csrf' => 'incorrect']));
    $check('Deleting revoked token records requires the browser CSRF token', $missingDeleteCsrf->getStatusCode() === 419
        && $wrongDeleteCsrf->getStatusCode() === 419
        && DB::value('SELECT id FROM app_tokens WHERE id = ?', [$profileTokenId]) !== null);
    $activeDelete = $app->handle($factory->createServerRequest('POST', '/admin/profile/app-token/' . $other['id'] . '/delete')
        ->withParsedBody($deleteBody));
    $check('Active tokens cannot be deleted before revocation', $activeDelete->getStatusCode() === 404
        && AppTokens::authenticate($request($other['token'])) !== null);
    DB::execute('UPDATE app_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$other['id']]);
    $check('Expiration alone does not allow deletion without explicit revocation', !AppTokens::deleteRevoked(2, $other['id'])
        && DB::value('SELECT id FROM app_tokens WHERE id = ?', [$other['id']]) !== null);
    DB::execute('UPDATE app_tokens SET expires_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = ?', [$other['id']]);
    $foreignDelete = $app->handle($factory->createServerRequest('POST', '/admin/profile/app-token/' . $tokenId . '/delete')
        ->withParsedBody($deleteBody + ['user_id' => 1]));
    $check('A user cannot delete another account revoked token record', $foreignDelete->getStatusCode() === 404
        && !AppTokens::deleteRevoked(2, $tokenId) && DB::value('SELECT id FROM app_tokens WHERE id = ?', [$tokenId]) !== null);
    $bearerDelete = $app->handle($deleteRequest->withHeader('Authorization', 'Bearer ' . $other['token'])->withParsedBody($deleteBody));
    $check('App bearer credentials cannot delete browser device history', $bearerDelete->getStatusCode() === 405
        && DB::value('SELECT id FROM app_tokens WHERE id = ?', [$profileTokenId]) !== null);
    $revokedPage = $app->handle($factory->createServerRequest('GET', '/admin/profile'));
    $revokedHtml = (string) $revokedPage->getBody();
    $check('Profile offers deletion only for revoked token rows', str_contains($revokedHtml, 'data-app-token-delete="' . $profileTokenId . '"')
        && !str_contains($revokedHtml, 'data-app-token-delete="' . $other['id'] . '"')
        && str_contains($revokedHtml, 'data-app-token-revoke="' . $other['id'] . '"'));
    if (in_array('--snapshots', $argv, true)) {
        file_put_contents(PAFISH_ROOT . '/.design-review/account-center/profile-app-tokens-revoked.html', $revokedHtml);
    }
    $deleted = $app->handle($deleteRequest->withParsedBody($deleteBody));
    $deletedPayload = json_decode((string) $deleted->getBody(), true);
    $check('Browser profile permanently removes its own revoked record', $deleted->getStatusCode() === 200
        && ($deletedPayload['ok'] ?? false) && DB::value('SELECT id FROM app_tokens WHERE id = ?', [$profileTokenId]) === null
        && AppTokens::authenticate($request($profileToken)) === null);
    $check('Deleting revoked history does not affect other devices', AppTokens::authenticate($request($other['token'])) !== null
        && DB::value('SELECT id FROM app_tokens WHERE id = ?', [$tokenId]) !== null);
    $deleteAgain = $app->handle($deleteRequest->withParsedBody($deleteBody));
    $check('Repeated deletion reports a missing record', $deleteAgain->getStatusCode() === 404);
    $afterDeleteHtml = (string) $app->handle($factory->createServerRequest('GET', '/admin/profile'))->getBody();
    $check('Deleted token record disappears from the profile list', !str_contains($afterDeleteHtml, 'data-app-token-delete="' . $profileTokenId . '"')
        && str_contains($afterDeleteHtml, 'data-app-token-revoke="' . $other['id'] . '"'));

    Settings::set('active_plugins', '[]');
    Settings::set('plugin_data:' . $pluginName, '{"keep":"value"}');
    if (!mkdir($pluginDir, 0755, true)) throw new RuntimeException('Could not create orphan plugin fixture');
    file_put_contents($pluginDir . '/plugin.json', json_encode([
        'name' => $pluginName, 'title' => 'Orphan fixture', 'version' => '1.0.0',
    ], JSON_UNESCAPED_SLASHES));
    Plugin::uninstall($pluginName);
    $check('Plugin without extension.php can be removed when its manifest is valid', !is_dir($pluginDir));
    $check('Removing orphan plugin code preserves extension data by default', Settings::get('plugin_data:' . $pluginName) === '{"keep":"value"}');
} finally {
    RateLimiter::clear('profile-app-token', '2');
    if (is_dir($pluginDir)) {
        foreach (scandir($pluginDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') @unlink($pluginDir . '/' . $entry);
        }
        @rmdir($pluginDir);
    }
    Settings::reset();
    $pdo->cleanup();
}

echo $passed . ' passed, 0 failed' . PHP_EOL;
