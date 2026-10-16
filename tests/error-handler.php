<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';

use Pafish\Core\{ErrorHandler, Url};
use Slim\CallableResolver;
use Slim\Exception\{HttpNotFoundException, HttpMethodNotAllowedException};
use Slim\Psr7\Factory\{ResponseFactory, ServerRequestFactory};

// Isolate logging from the site's real runtime and avoid loading a theme/database.
define('PAFISH_ROOT', sys_get_temp_dir() . '/pafish-error-qa-' . bin2hex(random_bytes(6)));
function render(string $template, array $data): string { return '<h1>' . (int) $data['status'] . '</h1>'; }
$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $passed++; echo 'PASS ' . $label . PHP_EOL;
};
$handler = new ErrorHandler(new CallableResolver(), new ResponseFactory());
$requests = new ServerRequestFactory();
$log = PAFISH_ROOT . '/runtime/error.log';
try {
    Url::init('/index.php');
    foreach (['/missing', '/api/missing', '/wp-json/gravitysmtp/v1/tests/mock-data'] as $path) {
        $req = $requests->createServerRequest('GET', $path);
        $response = $handler($req, new HttpNotFoundException($req), false, true, true);
        $check('404 status and no application log: ' . $path, $response->getStatusCode() === 404 && !is_file($log));
        if (str_starts_with($path, '/api')) $check('API 404 remains JSON', json_decode((string) $response->getBody(), true)['error'] === 'Not Found');
    }
    $req = $requests->createServerRequest('POST', '/method?token=private&section=account');
    $handler($req, new HttpMethodNotAllowedException($req), false, true, true);
    $text = file_get_contents($log);
    $check('Other HTTP errors remain logged with sensitive query redacted', str_contains($text, '405 POST /method') && !str_contains($text, 'private'));
    $req = $requests->createServerRequest('GET', '/broken');
    $response = $handler($req, new RuntimeException('fixture internal failure'), false, true, true);
    $check('500 retains diagnostic logs without exposing details', $response->getStatusCode() === 500
        && str_contains(file_get_contents($log), 'fixture internal failure') && !str_contains((string) $response->getBody(), 'fixture internal failure'));
    Url::init('/blog/index.php');
    $req = $requests->createServerRequest('GET', '/blog/api/missing');
    $size = filesize($log);
    $response = $handler($req, new HttpNotFoundException($req), false, true, true);
    clearstatcache(true, $log);
    $check('Subdirectory API 404 does not append to the error log', $size === filesize($log) && str_contains($response->getHeaderLine('Content-Type'), 'application/json'));
    echo $passed . ' passed' . PHP_EOL;
} finally {
    foreach (glob(PAFISH_ROOT . '/runtime/*') ?: [] as $file) if (is_file($file)) unlink($file);
    if (is_dir(PAFISH_ROOT . '/runtime')) rmdir(PAFISH_ROOT . '/runtime');
    if (is_dir(PAFISH_ROOT)) rmdir(PAFISH_ROOT);
}
