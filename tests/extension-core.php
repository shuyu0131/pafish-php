<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';

use Pafish\Core\Config;
use Pafish\Core\Hooks;
use Pafish\Services\ExtensionContext;
use Pafish\Services\ExtensionDependencies;
use Pafish\Services\ExtensionKernel;
use Pafish\Services\ExtensionRoutes;
use Pafish\Services\EntityMetaSchema;
use Pafish\Services\Plugin;
use Pafish\Services\Theme;

Config::load(Config::resolveFile(PAFISH_ROOT));

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo 'PASS ' . $label . PHP_EOL;
        return;
    }
    $failed++;
    echo 'FAIL ' . $label . PHP_EOL;
};

$root = sys_get_temp_dir() . '/pafish-extension-' . bin2hex(random_bytes(6));
$context = static fn (string $kind, string $name): ExtensionContext => new ExtensionContext($kind, $name);

try {
    if (!mkdir($root, 0755, true)) {
        throw new RuntimeException('无法创建扩展测试目录');
    }

    $missing = 'missing';
    $check('Missing extension entry returns false', !ExtensionKernel::boot('plugin', $missing, $root, $context('plugin', $missing)));
    $check('Missing extension is not marked booted', !ExtensionKernel::isBooted('plugin', $missing));
    $check('Theme injection declaration uses the shared target contract', Theme::validateManifest([
        'name' => 'fixture-theme', 'apiVersion' => 2, 'injects' => ['post_editor'],
    ], 'fixture-theme') === null);
    $check('Theme rejects undeclared injection targets', Theme::validateManifest([
        'name' => 'fixture-theme', 'apiVersion' => 2, 'injects' => ['database'],
    ], 'fixture-theme') !== null);
    $meta = [
        'post' => [
            ['key' => 'subtitle', 'label' => '副标题', 'type' => 'text', 'maxLength' => 120],
            ['key' => 'visible', 'label' => '显示', 'type' => 'checkbox'],
            ['key' => 'layout', 'label' => '布局', 'type' => 'select', 'options' => ['wide' => '宽版', 'compact' => '紧凑'], 'default' => 'wide'],
        ],
        'user' => [['key' => 'external_id', 'label' => '外部用户 ID', 'type' => 'number']],
    ];
    $check('Plugin manifest accepts declared entity metadata fields', Plugin::validateManifest([
        'name' => 'fixture-plugin', 'title' => 'Fixture', 'version' => '1.0.0', 'meta' => $meta,
    ], 'fixture-plugin') === null);
    $check('Theme manifest accepts declared entity metadata fields', Theme::validateManifest([
        'name' => 'fixture-theme', 'meta' => $meta,
    ], 'fixture-theme') === null);
    $check('Entity metadata rejects invalid checkbox defaults', EntityMetaSchema::validate([
        'post' => [['key' => 'visible', 'label' => '显示', 'type' => 'checkbox', 'default' => 'yes']],
    ]) !== null);
    $check('Entity metadata rejects null declarations instead of treating them as omitted',
        EntityMetaSchema::validate(['post' => [['key' => 'required-null', 'label' => '必填', 'required' => null]]]) !== null
        && EntityMetaSchema::validate(['post' => [['key' => 'length-null', 'label' => '长度', 'maxLength' => null]]]) !== null
        && EntityMetaSchema::validate(['post' => [['key' => 'options-null', 'label' => '字段', 'options' => null]]]) !== null);
    $check('Entity metadata rejects select defaults outside declared options', EntityMetaSchema::validate([
        'post' => [['key' => 'layout', 'label' => '布局', 'type' => 'select', 'options' => ['wide' => '宽版'], 'default' => 'compact']],
    ]) !== null);
    $normalizedMeta = EntityMetaSchema::normalize(['post' => [['key' => 'visible', 'label' => '显示', 'type' => 'checkbox']]]);
    $check('Checkbox metadata defaults to the off value', ($normalizedMeta['post'][0]['default'] ?? null) === '0');
    $falseMeta = EntityMetaSchema::normalize(['post' => [['key' => 'visible', 'label' => '显示', 'type' => 'checkbox', 'default' => false]]]);
    $check('Explicit false checkbox defaults normalize to the off value', ($falseMeta['post'][0]['default'] ?? null) === '0');
    $check('Entity metadata value validation enforces declared field types',
        EntityMetaSchema::validateValue(['key' => 'visible', 'label' => '显示', 'type' => 'checkbox'], '1') === '1'
        && EntityMetaSchema::validateValue(['key' => 'layout', 'label' => '布局', 'type' => 'select', 'options' => ['wide' => '宽版']], 'wide') === 'wide'
        && (static function (): bool { try { EntityMetaSchema::validateValue(['key' => 'visible', 'label' => '显示', 'type' => 'checkbox'], 'yes'); } catch (RuntimeException) { return true; } return false; })());
    $check('Theme validates user center data declarations', Theme::validateManifest([
        'name' => 'fixture-theme', 'apiVersion' => 2, 'userData' => ['posts', 'stats'],
    ], 'fixture-theme') === null && Theme::validateManifest([
        'name' => 'fixture-theme', 'apiVersion' => 2, 'userData' => ['passwords'],
    ], 'fixture-theme') !== null);
    $check('Theme validates explicit system template fallbacks', Theme::validateManifest([
        'name' => 'fixture-theme', 'apiVersion' => 2, 'systemTemplateFallback' => ['page', 'search'],
    ], 'fixture-theme') === null && Theme::validateManifest([
        'name' => 'fixture-theme', 'apiVersion' => 2, 'systemTemplateFallback' => ['dashboard'],
    ], 'fixture-theme') !== null);
    $check('Dependency declaration accepts versioned plugin references', ExtensionDependencies::validate([
        'plugin:notify_hub >= 1.0.0',
        ['type' => 'theme', 'name' => 'default', 'version' => '^1.0.0'],
    ]) === null);
    $check('Dependency declaration rejects ambiguous references', ExtensionDependencies::validate(['notify_hub']) !== null);
    $dependencyBootError = null;
    try { ExtensionDependencies::assertSatisfied('theme', 'fixture-theme', []); } catch (Throwable $e) { $dependencyBootError = $e; }
    $check('Dependency checks accept empty declarations at runtime', $dependencyBootError === null);
    $check('Theme context uses manifest API version', Theme::context('default')->apiVersion === 2);
    $luminaContext = Theme::context('lumina');
    $check('Manifest routes can bind handlers from extension.php', ExtensionKernel::boot('theme', 'lumina', Theme::root(), $luminaContext));
    $check('Manifest-bound theme handler is available', ExtensionKernel::handler('theme', 'lumina', 'publish') !== null);
    ExtensionKernel::reset('theme', 'lumina');
    $check('Manifest route metadata rejects overlapping declarations', ExtensionRoutes::validateDeclaration([
        ['method' => 'GET', 'path' => '/notes/{slug}', 'handler' => 'show'],
        ['method' => 'GET', 'path' => '/notes/latest', 'handler' => 'latest'],
    ], 'plugin', 'fixture-plugin') !== null);

    $broken = 'broken';
    mkdir($root . '/' . $broken, 0755, true);
    file_put_contents($root . '/' . $broken . '/extension.php', <<<'PHP'
<?php
return static function (\Pafish\Services\ExtensionContext $ctx): void {
    $ctx->provider('leaked', static fn (): string => 'must be removed');
    $ctx->on('extension_test_broken', static function (): void {});
    throw new \RuntimeException('fixture failure');
};
PHP
    );
    $check('Broken extension entry returns false', !ExtensionKernel::boot('plugin', $broken, $root, $context('plugin', $broken)));
    $check('Broken extension provider is rolled back', ExtensionKernel::providerFor('plugin', $broken, 'leaked') === null);
    $check('Broken extension hooks are rolled back', !Hooks::hasAction('extension_test_broken'));
    $check('Broken extension is not marked booted', !ExtensionKernel::isBooted('plugin', $broken));

    $working = 'working';
    mkdir($root . '/' . $working, 0755, true);
    file_put_contents($root . '/' . $working . '/extension.php', <<<'PHP'
<?php
return static function (\Pafish\Services\ExtensionContext $ctx): void {
    $ctx->provider('probe', static fn (): string => 'ok');
    $ctx->on('extension_test_working', static function (): void {});
};
PHP
    );
    $check('Valid extension entry boots', ExtensionKernel::boot('theme', $working, $root, $context('theme', $working)));
    $check('Valid extension provider is available', (ExtensionKernel::providerFor('theme', $working, 'probe'))() === 'ok');
    $check('Valid extension hook is available', Hooks::hasAction('extension_test_working'));
    $check('Repeated boot is idempotent', ExtensionKernel::boot('theme', $working, $root, $context('theme', $working)));
    ExtensionKernel::reset('theme', $working);
    $check('Reset clears provider', ExtensionKernel::providerFor('theme', $working, 'probe') === null);
    $check('Reset clears hooks', !Hooks::hasAction('extension_test_working'));
    $check('Reset clears boot marker', !ExtensionKernel::isBooted('theme', $working));
} finally {
    $remove = static function (string $dir) use (&$remove): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $remove($path) : @unlink($path);
        }
        @rmdir($dir);
    };
    $remove($root);
}

echo $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
