<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root = $argv[1] ?? '';
if (realpath(dirname($root)) !== realpath(sys_get_temp_dir())
    || preg_match('/^pafish-cache-[a-f0-9]{12}$/D', basename($root)) !== 1) {
    throw new RuntimeException('Invalid cache fixture directory');
}
define('PAFISH_ROOT', $root);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Pafish\Core\Cache;
$key = $argv[3];
$signal = PAFISH_ROOT . '/signals';
$wait = static function(string $path): void {
    $deadline = microtime(true) + 8;
    while (true) {
        clearstatcache(true, $path);
        if (is_file($path)) return;
        if (microtime(true) >= $deadline) throw new RuntimeException('Cache barrier timed out');
        usleep(2000);
    }
};
try {
    $value = match($argv[2]) {
        'slow' => Cache::remember($key, static function() use ($signal, $wait): array {
            file_put_contents($signal . '/ready', '1');
            $wait($signal . '/release');
            return ['old'];
        }),
        'herd' => (static function() use ($key, $signal, $wait): mixed {
            file_put_contents($signal . '/started-' . getmypid(), '1');
            $wait($signal . '/go');
            return Cache::remember($key, static function() use ($signal): array {
                file_put_contents($signal . '/loads', "load\n", FILE_APPEND | LOCK_EX);
                usleep(100000);
                return ['fresh'];
            });
        })(),
        'invalidate' => (static function() use ($key): string { Cache::clearPrefix($key); return 'invalidated'; })(),
        'prefix-herd' => (static function() use ($key, $signal, $wait): string {
            file_put_contents($signal . '/invalidate-started-' . getmypid(), '1');
            $wait($signal . '/invalidate-go');
            Cache::clearPrefix($key);
            return 'invalidated';
        })(),
        default => throw new RuntimeException('Unknown fixture action'),
    };
    echo json_encode(['value'=>$value, 'stats'=>Cache::stats()], JSON_THROW_ON_ERROR);
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }
