<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$includeInstaller = in_array('--include-installer', $argv, true);
$includePerformance = in_array('--include-performance', $argv, true);
$known = ['--include-installer', '--include-performance'];
$unknown = array_values(array_diff(array_slice($argv, 1), $known));
if ($unknown !== []) {
    fwrite(STDERR, 'Unknown option: ' . implode(', ', $unknown) . PHP_EOL);
    exit(2);
}

$scripts = [];
$skipped = [];
foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
    $name = basename($file);
    if (in_array($name, ['run.php'], true)) continue;
    if ($name === 'installer.php' && !$includeInstaller) {
        $skipped[] = [$name, 'requires permission to create an isolated database'];
        continue;
    }
    if (str_ends_with($name, '-performance.php') && !$includePerformance) {
        $skipped[] = [$name, 'optional performance suite; use --include-performance'];
        continue;
    }
    $scripts[] = $file;
}
sort($scripts);

$passed = 0;
$failed = 0;
foreach ($scripts as $file) {
    $name = basename($file);
    echo "=== {$name} ===" . PHP_EOL;
    $process = proc_open([PHP_BINARY, $file], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        echo "FAIL could not start PHP process" . PHP_EOL;
        $failed++;
        continue;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($stdout !== '') echo $stdout;
    if ($stderr !== '') fwrite(STDERR, $stderr);
    if ($status === 0) {
        $passed++;
    } else {
        echo "FAIL exit code {$status}" . PHP_EOL;
        $failed++;
    }
}

echo PHP_EOL . "{$passed} scripts passed, {$failed} failed" . PHP_EOL;
foreach ($skipped as [$name, $reason]) echo "SKIP {$name}: {$reason}" . PHP_EOL;
if (!$includeInstaller) echo 'Run with --include-installer when the database account can create isolated databases.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
