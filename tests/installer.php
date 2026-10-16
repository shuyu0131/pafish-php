<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// This worker intentionally never loads vendor/autoload.php.
if (($argv[1] ?? '') === '--worker') {
    $_POST = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $_GET = ['step' => 'install'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = 'installer.test';
    register_shutdown_function(static function (): void {
        echo "\nINSTALLER_AUTOLOADERS=" . count(spl_autoload_functions()) . "\n";
    });
    require $argv[2] . '/install.php';
    exit;
}

$root = dirname(__DIR__);
$source = ($argv[1] ?? '') === '--source' ? ($argv[2] ?? '') : $root;
if (!is_file($source . '/install.php')) throw new RuntimeException('Installer source directory not found');
$configFile = is_file($root . '/runtime/config.php') ? $root . '/runtime/config.php' : $root . '/config.php';
$config = require $configFile;
$db = $config['db'];
if (getenv('PAFISH_INSTALL_TEST_USER') !== false) $db['username'] = getenv('PAFISH_INSTALL_TEST_USER');
if (getenv('PAFISH_INSTALL_TEST_PASSWORD') !== false) $db['password'] = getenv('PAFISH_INSTALL_TEST_PASSWORD');
$database = 'qa_install_' . bin2hex(random_bytes(6));
if (preg_match('/^qa_install_[a-f0-9]{12}$/D', $database) !== 1) {
    throw new RuntimeException('Invalid fixture database name');
}
$temp = sys_get_temp_dir() . '/' . $database;
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port'] ?? 3306),
    $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$passed = 0;
$failed = 0;
$created = false;
$check = static function (string $name, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
};

try {
    // Fail before starting the installer if the test account cannot create an isolated database.
    $pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    mkdir($temp, 0700);
    foreach (['app/install', 'app/Core', 'app/Services', 'migrations', 'runtime'] as $dir) mkdir($temp . '/' . $dir, 0700, true);
    foreach (['install.php', 'app/install/schema.sql', 'app/Core/SqlScript.php', 'app/Services/Migrator.php'] as $file) {
        copy($source . '/' . $file, $temp . '/' . $file);
    }
    $migrationFiles = array_merge(glob($source . '/migrations/*.sql') ?: [], glob($source . '/migrations/*.php') ?: []);
    foreach ($migrationFiles as $file) copy($file, $temp . '/migrations/' . basename($file));
    $password = bin2hex(random_bytes(12));
    $post = [
        'db_host' => $db['host'], 'db_port' => $db['port'] ?? 3306,
        'db_name' => $database, 'db_user' => $db['username'], 'db_pass' => $db['password'],
        'site_name' => 'Installer fixture', 'site_url' => 'https://installer.test',
        'admin_username' => 'fixture_admin', 'admin_email' => 'fixture@example.test',
        'admin_password' => $password, 'admin_password2' => $password, 'pretty_urls' => '1',
    ];
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', $temp],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $temp);
    if (!is_resource($process)) throw new RuntimeException('Cannot start installer worker');
    fwrite($pipes[0], json_encode($post, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $html = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    $check('Standalone installer completes', $exit === 0 && str_contains($html, 'INSTALLATION COMPLETE'));
    $check('No migration failure warning', !str_contains($html, '增量迁移失败'));
    $check('No PHP runtime errors', $stderr === '');
    $check('Installation runs without Composer or other autoloaders', str_contains($html, 'INSTALLER_AUTOLOADERS=0'));
    $check('Runtime config is written', is_file($temp . '/runtime/config.php'));
    $pdo->exec('USE `' . $database . '`');
    $recorded = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $expected = array_map(static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME), $migrationFiles);
    $check('All migration files are applied', array_diff($expected, $recorded) === []);
    $check('Admin account is seeded', (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username='fixture_admin'")->fetchColumn() === 1);
    $check('Sample posts are seeded', (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn() === 3);
    $check('Retired email code table is absent', (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='email_codes'")->fetchColumn() === 0);
} finally {
    // Only the database and directory with this randomly generated fixture name may be removed.
    if (preg_match('/^qa_install_[a-f0-9]{12}$/D', $database) !== 1) throw new RuntimeException('Invalid fixture name');
    if ($created) $pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    if (is_dir($temp)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($temp);
    }
}

echo json_encode(['passed' => $passed, 'failed' => $failed]) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
