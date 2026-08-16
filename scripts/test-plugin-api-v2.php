<?php

declare(strict_types=1);

use Pafish\Core\Hooks;
use Pafish\Services\Plugin;

define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';

$failed = 0;
$check = static function (string $label, bool $ok) use (&$failed): void {
    echo ($ok ? '[ok] ' : '[fail] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
};

foreach (['seo-push', 'notify-hub'] as $name) {
    $desc = Plugin::describe($name);
    $check($name . ' manifest', $desc['error'] === null);
    $check($name . ' uses API v2', (int) ($desc['manifest']['apiVersion'] ?? 0) === 2);
    $check($name . ' module loads', is_array(Plugin::module($name)));
}

$check(
    'reject unsupported apiVersion',
    Plugin::validateManifest(['name' => 'bad-api', 'title' => 'x', 'version' => '1', 'apiVersion' => 3], 'bad-api') === 'apiVersion 不受支持'
);

$ctx = Plugin::context('seo-push');
$check('context exposes name', $ctx->name === 'seo-push');
$check('context exposes apiVersion', $ctx->apiVersion === 2);
$ctx->filter('v2_test_filter', static fn (string $value): string => $value . '-filtered');
$check('context filter runs', Hooks::applyFilters('v2_test_filter', 'value') === 'value-filtered');
Plugin::unregisterHooks('seo-push');
$check('context filter unregisters by plugin tag', Hooks::applyFilters('v2_test_filter', 'value') === 'value');

$fakeContext = new class {
    public array $callbacks = [];
    public array $data = [];
    public array $logs = [];

    public function on(string $name, callable $fn, int $priority = 10): callable
    {
        $this->callbacks[$name][] = $fn;
        return static function (): void {};
    }

    public function getSettings(): array
    {
        return [
            'indexnow_enabled' => '0',
            'baidu_enabled' => '0',
            'notify_comments' => '0',
            'notify_posts' => '0',
            'notify_register' => '0',
            'notify_login' => '0',
        ];
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function log(string $message): void
    {
        $this->logs[] = $message;
    }
};

$seoModule = Plugin::module('seo-push');
$seoModule['registerHooks']($fakeContext);
$check('seo-push registers publish hook', isset($fakeContext->callbacks['after_post_published']));
$fakeContext->callbacks['after_post_published'][0]([
    'id' => '1', 'title' => '测试', 'slug' => 'test', 'status' => 'PUBLISHED',
    'externalUrl' => null,
]);
$check('seo-push disabled channels do not emit logs', $fakeContext->logs === []);

$fakeContext->callbacks = [];
$notifyModule = Plugin::module('notify-hub');
$notifyModule['registerHooks']($fakeContext);
$check('notify-hub registers event hooks', isset($fakeContext->callbacks['after_comment_submit'], $fakeContext->callbacks['after_post_published']));
$fakeContext->callbacks['after_post_published'][0](['title' => '测试', 'slug' => 'test']);
$check('notify-hub disabled events do not emit logs', $fakeContext->logs === []);

exit($failed > 0 ? 1 : 0);
