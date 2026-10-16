<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require PAFISH_ROOT . '/app/Core/helpers.php';
require __DIR__ . '/Fixtures/MySql.php';

use Pafish\Core\Config;
use Pafish\Core\DB;
use Pafish\Core\SqlScript;
use Pafish\Services\AppTokens;
use Pafish\Services\Migrator;
use Pafish\Services\Plugin;
use Pafish\Services\Settings;
use Pafish\Tests\MySql;
use Pafish\Http\AppAuthController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

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
