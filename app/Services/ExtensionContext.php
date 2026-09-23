<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Hooks;

/** 主题和插件共享的扩展上下文；只暴露自身命名空间的持久化与视图能力。 */
final class ExtensionContext
{
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly int $apiVersion = 1,
    ) {
    }

    public function on(string $hook, callable $fn, int $priority = 10): callable
    {
        return Hooks::addAction($hook, $fn, $priority, $this->kind . ':' . $this->name);
    }

    public function filter(string $hook, callable $fn, int $priority = 10): callable
    {
        return Hooks::addFilter($hook, $fn, $priority, $this->kind . ':' . $this->name);
    }

    public function getData(): array
    {
        return $this->kind === 'plugin' ? Plugin::data($this->name) : Theme::data($this->name);
    }

    public function setData(array $data): void
    {
        if ($this->kind === 'plugin') {
            Plugin::setData($this->name, $data);
            return;
        }
        Theme::setData($this->name, $data);
    }

    public function getSettings(): array
    {
        return $this->kind === 'plugin' ? Plugin::settings($this->name) : Theme::valuesFor($this->name);
    }

    public function setSettings(array $partial): void
    {
        if ($this->kind === 'plugin') {
            Plugin::setSettings($this->name, $partial);
            return;
        }
        Theme::setExtensionSettings($this->name, $partial);
    }

    public function log(string $message): void
    {
        if ($this->kind === 'plugin') {
            Plugin::log($this->name, $message);
            return;
        }
        Theme::log($this->name, $message);
    }

    /** 渲染扩展私有 views/{template}.php，禁止用户可控路径参与 include。 */
    public function render(string $template, array $data = []): string
    {
        if (preg_match('/^[a-z0-9_-]{1,60}$/', $template) !== 1) {
            throw new \RuntimeException('扩展视图名不合法');
        }
        $root = $this->kind === 'plugin' ? Plugin::root() : Theme::root();
        $file = $root . '/' . $this->name . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('扩展视图不存在：' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    public function refreshInjections(): void
    {
        // PHP 请求级渲染，不使用跨请求注入缓存。
    }

    /** 返回当前扩展声明的物理表名；只接受 manifest 中的逻辑表名。 */
    public function table(string $logicalName): string
    {
        $manifest = $this->kind === 'plugin' ? Plugin::manifest($this->name) : Theme::manifest($this->name);
        foreach ((array) ($manifest['schema']['tables'] ?? []) as $table) {
            if (is_array($table) && ($table['name'] ?? null) === $logicalName) {
                return ExtensionSchema::physicalName($this->kind, $this->name, $logicalName);
            }
        }
        throw new \RuntimeException('未声明的扩展数据表：' . $logicalName);
    }
}
