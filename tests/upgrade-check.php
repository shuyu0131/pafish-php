<?php

declare(strict_types=1);

namespace Pafish\Services {
    /** Isolated process fixture: never calls a release server or downloads an upgrade. */
    final class OutboundHttp
    {
        public static int $calls = 0;
        public static bool $fail = false;

        public static function get(string $url, int $maxBytes = 512000, array $headers = []): string
        {
            self::$calls++;
            if (self::$fail) throw new \RuntimeException('Fixture update server unavailable');
            return json_encode([
                'tag_name' => 'v99.0.0', 'body' => 'Fixture release notes',
                'assets' => [['name' => 'pafish-php-v99.0.0.zip',
                    'browser_download_url' => 'https://gitee.com/shuyugit/pafish-php/releases/download/v99.0.0/pafish-php-v99.0.0.zip']],
            ], JSON_THROW_ON_ERROR);
        }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    $project = dirname(__DIR__);
    $fixture = $project . '/runtime/qa_upgrade_' . bin2hex(random_bytes(6));
    if (!mkdir($fixture . '/runtime', 0777, true)) throw new RuntimeException('Cannot create update fixture');
    define('PAFISH_ROOT', $fixture);
    require $project . '/vendor/autoload.php';
    require $project . '/app/Core/helpers.php';

    use Pafish\Admin\AdminAuthMiddleware;
    use Pafish\Admin\UpgradeController;
    use Pafish\Core\Auth;
    use Pafish\Core\HttpMiddleware;
    use Pafish\Core\Url;
    use Pafish\Services\OutboundHttp;
    use Pafish\Services\Upgrade;
    use Slim\Factory\AppFactory;
    use Slim\Psr7\Factory\ServerRequestFactory;

    $passed = $failed = 0;
    $check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
        $ok ? $passed++ : $failed++;
        echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    };
    $cachePath = $fixture . '/runtime/update_check.json';
    $ageCache = static function (int $age) use ($cachePath): void {
        $cache = json_decode((string) file_get_contents($cachePath), true);
        $cache['at'] = time() - $age;
        file_put_contents($cachePath, json_encode($cache, JSON_THROW_ON_ERROR));
    };
    $originalRepo = getenv('PAFISH_GITEE_REPO');
    putenv('PAFISH_GITEE_REPO=shuyugit/pafish-php');
    Url::init('/index.php');
    $_SESSION = ['_csrf' => 'qa-update-csrf'];
    $user = new ReflectionProperty(Auth::class, 'user');
    try {
        $check('Rendering cached update state never calls the network', !Upgrade::cached()['hasUpdate'] && OutboundHttp::$calls === 0);
        $result = Upgrade::check();
        $check('First automatic check retrieves and caches release metadata', $result['hasUpdate'] && $result['latest'] === '99.0.0' && OutboundHttp::$calls === 1);
        Upgrade::check();
        $check('Repeated automatic checks reuse a successful result', OutboundHttp::$calls === 1);
        $ageCache(86390);
        Upgrade::check();
        $check('Successful release metadata stays cached for 24 hours', OutboundHttp::$calls === 1);
        $ageCache(86401);
        Upgrade::check();
        $check('Automatic check refreshes expired release metadata', OutboundHttp::$calls === 2);
        Upgrade::check(true);
        $check('Manual force check bypasses the successful cache', OutboundHttp::$calls === 3);

        OutboundHttp::$fail = true;
        $result = Upgrade::check(true);
        $check('Network failure returns a handled check error', !$result['hasUpdate'] && str_contains($result['error'], 'Fixture update server unavailable'));
        $calls = OutboundHttp::$calls;
        Upgrade::check();
        $ageCache(890);
        Upgrade::check();
        $check('Automatic checks cache failures for 15 minutes', OutboundHttp::$calls === $calls);
        $ageCache(901);
        Upgrade::check();
        $check('Automatic check retries after the failure cache expires', OutboundHttp::$calls === $calls + 1);
        OutboundHttp::$fail = false;
        $result = Upgrade::check(true);
        $check('Manual force check can recover immediately from a cached failure', $result['hasUpdate']);
        $cache = json_decode((string) file_get_contents($cachePath), true);
        unset($cache['meta']);
        file_put_contents($cachePath, json_encode($cache));
        $calls = OutboundHttp::$calls;
        Upgrade::check();
        $check('Incomplete cache is ignored and safely refreshed', OutboundHttp::$calls === $calls + 1);

        AppFactory::setContainer(new \DI\Container());
        $app = AppFactory::create();
        $app->post('/admin/upgrade/check', [new UpgradeController(), 'check'])->add(AdminAuthMiddleware::class);
        HttpMiddleware::install($app, static fn (string $path, ?string $query = null): array => [$path], false);
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', '/admin/upgrade/check')->withHeader('X-Requested-With', 'XMLHttpRequest');
        $user->setValue(null, ['id' => 1, 'role' => 'ADMIN']);
        $response = $app->handle($request->withParsedBody([]));
        $check('Automatic check endpoint still rejects a missing CSRF token', $response->getStatusCode() === 419);
        $calls = OutboundHttp::$calls;
        $response = $app->handle($request->withParsedBody(['_csrf' => 'qa-update-csrf']));
        $data = json_decode((string) $response->getBody(), true);
        $check('Authenticated automatic POST uses the cache through the real middleware', $response->getStatusCode() === 200 && $data['ok'] && $data['hasUpdate'] && OutboundHttp::$calls === $calls);
        $app->handle($request->withParsedBody(['_csrf' => 'qa-update-csrf', 'force' => '1']));
        $check('Authenticated manual POST forces a fresh metadata request', OutboundHttp::$calls === $calls + 1);
        $user->setValue(null, ['id' => 2, 'role' => 'EDITOR']);
        $calls = OutboundHttp::$calls;
        $response = $app->handle($request->withParsedBody(['_csrf' => 'qa-update-csrf', 'force' => '1']));
        $check('Editor cannot check system upgrades or contact the release server', $response->getStatusCode() === 302 && OutboundHttp::$calls === $calls);

        $render = static function (bool $canUpgrade, array $upgradeInfo = []) use ($project): string {
            $username = '测试站长';
            $current = '1.2.0';
            $stats = [];
            $software = ['PHP' => PHP_VERSION, '数据库' => 'MySQL'];
            ob_start();
            require $project . '/app/Views/admin/dashboard.php';
            return (string) ob_get_clean();
        };
        $adminHtml = $render(true);
        $check('Non-administrator view contains no update controls or script', !str_contains($render(false), 'dashboardUpgrade'));
        $check('Checking releases never starts an installation', !is_file($fixture . '/runtime/upgrade_state.json'));
        if (in_array('--snapshots', $argv, true)) {
            $directory = $project . '/.design-review';
            if (!is_dir($directory)) mkdir($directory, 0777, true);
            file_put_contents($directory . '/upgrade-dashboard.html', $adminHtml);
            file_put_contents($directory . '/upgrade-dashboard-cached.html', $render(true, Upgrade::cached()));
            echo 'Browser fixtures written to .design-review' . PHP_EOL;
        }
    } catch (Throwable $e) {
        $failed++;
        echo 'ERROR ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
    } finally {
        putenv($originalRepo === false ? 'PAFISH_GITEE_REPO' : 'PAFISH_GITEE_REPO=' . $originalRepo);
        $user->setValue(null, null);
        foreach (glob($fixture . '/runtime/*') ?: [] as $file) unlink($file);
        rmdir($fixture . '/runtime');
        rmdir($fixture);
    }
    echo "$passed passed, $failed failed" . PHP_EOL;
    exit($failed === 0 ? 0 : 1);
}
