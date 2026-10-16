<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('PAFISH_ROOT', dirname(__DIR__, 2));
require PAFISH_ROOT . '/vendor/autoload.php';
require PAFISH_ROOT . '/app/Core/helpers.php';
require __DIR__ . '/MySql.php';

use Pafish\Core\Config;
use Pafish\Core\DB;
use Pafish\Services\EmailCode;
use Pafish\Services\PostReactions;
use Pafish\Services\Plugin;
use Pafish\Services\Migrator;
use Pafish\Tests\MySql;

try {
    Config::load(Config::resolveFile(PAFISH_ROOT));
    date_default_timezone_set('Asia/Shanghai');
    $pdo = new MySql($argv[2]);
    (new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
    $result = match ($argv[1]) {
        'limit' => \Pafish\Core\RateLimiter::allow('qa-lifecycle', $argv[3], (int) $argv[4], 60),
        'comment_create' => (static function () use ($argv) {
            if (isset($argv[4])) file_put_contents($argv[4], 'ready');
            try {
                return \Pafish\Services\Comments::create([
                    'post_id'=>1,'parent_id'=>(int) $argv[3], 'author_name'=>'fixture','author_email'=>'fixture@example.invalid',
                    'content'=>'parallel reply','status'=>'APPROVED',
                ]);
            } catch (\DomainException $e) {
                if ($e->getCode() === 400) return 'rejected';
                throw $e;
            }
        })(),
        'verify' => (static function () use ($argv) {
            session_id($argv[5]);
            \Pafish\Core\Session::start();
            $result=EmailCode::verify($argv[3], 'reset', $argv[4]);
            session_write_close();
            return $result;
        })(),
        'reset_race' => (static function () use ($argv,$pdo) {
            // Force every worker to read the valid token before any may consume it.
            $pdo->afterExecute=static function(string $sql) use ($argv): void {
                if (!str_starts_with(ltrim($sql),'SELECT id, disabled FROM') || !str_contains($sql,'reset_token = ?')) return;
                DB::execute('UPDATE fixture_log SET value=CAST(value AS UNSIGNED)+1 WHERE id=1');
                $deadline=microtime(true)+8;
                while ((int)DB::value('SELECT value FROM fixture_log WHERE id=1')<(int)$argv[4]) {
                    if (microtime(true)>$deadline) throw new RuntimeException('Reset race barrier timed out');
                    usleep(10000);
                }
            };
            $request=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/api/auth/reset')
                ->withParsedBody(['token'=>$argv[3],'password'=>'parallel-password']);
            return (new \Pafish\Http\AuthApiController())->reset($request,new \Slim\Psr7\Response())->getStatusCode();
        })(),
        'reaction' => PostReactions::change(1, 'like', 1, true),
        'migrate' => Migrator::run($pdo, $argv[3]),
        'media_setting' => (static function () use ($argv) {
            \Pafish\Services\Settings::set($argv[3], $argv[4]);
            return true;
        })(),
        'extension_set_data' => (static function () use ($argv) {
            for ($i = 0; $i < (int) $argv[3]; $i++) {
                Plugin::setData('qa_media', ['marker' => $i]);
                usleep(1000);
            }
            return true;
        })(),
        'extension_log' => (static function () use ($argv) {
            for ($i = 0; $i < (int) $argv[3]; $i++) {
                Plugin::log('qa_media', 'concurrent-' . $i);
                usleep(1000);
            }
            return true;
        })(),
        'media_sync' => (static function () use ($argv) {
            \Pafish\Services\MediaReferences::sync($argv[3], $argv[4]);
            return true;
        })(),
        'media_rebuild' => \Pafish\Services\MediaReferences::rebuild(),
        'media_delete' => (static function () use ($argv) {
            try {
                \Pafish\Services\MediaReferences::deleteUpload((int) $argv[3], null);
                return 'deleted';
            } catch (\RuntimeException $e) {
                if ($e->getCode() === 409) return 'protected';
                throw $e;
            }
        })(),
        default => throw new RuntimeException('Unknown worker'),
    };
    echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
