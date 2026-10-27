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

    /**
     * 注册统一扩展路由。
     *
     * 仅保留给 API v1 旧扩展兼容；API v2 应在 manifest 声明 path/options，
     * 再用同名 provider() 提供处理器，避免把路由元数据和运行时实现重复维护。
     */
    public function route(
        string $method,
        string $path,
        callable|string $handler,
        array $options = []
    ): string {
        return ExtensionKernel::route($this->kind, $this->name, $method, $path, $handler, $options);
    }

    /** 注册扩展设置声明；普通扩展只声明实际需要的字段。 */
    public function settings(array $fields): void
    {
        ExtensionKernel::settings($this->kind, $this->name, $fields);
    }

    /** 注册前台注入处理器；目标点的权限由 manifest injects 控制。 */
    public function inject(string $target, callable $handler): void
    {
        ExtensionKernel::inject($this->kind, $this->name, $target, $handler);
    }

    /** 注册供核心服务调用的扩展能力。 */
    public function provider(string $key, callable $handler): void
    {
        ExtensionKernel::provider($this->kind, $this->name, $key, $handler);
    }

    /** 注册生命周期回调。 */
    public function lifecycle(string $phase, callable $handler): void
    {
        ExtensionKernel::lifecycle($this->kind, $this->name, $phase, $handler);
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

    /** 在短事务中修改最新数据；回调只处理数据，不执行网络或文件操作。 */
    public function updateData(callable $update): array
    {
        return ExtensionStorage::updateJson($this->kind . '_data:' . $this->name, $update);
    }

    /** Read one field attached to a core entity from this extension's private namespace. */
    public function getEntityMeta(string $subjectType, int $subjectId, string $key, mixed $default = null): mixed
    {
        return EntityMeta::getForExtension($this->kind, $this->name, $subjectType, $subjectId, $key, $default);
    }

    /** Write one field attached to a core entity without replacing other owners' fields. */
    public function setEntityMeta(string $subjectType, int $subjectId, string $key, string $value): void
    {
        EntityMeta::setForExtension($this->kind, $this->name, $subjectType, $subjectId, $key, $value);
    }

    public function deleteEntityMeta(string $subjectType, int $subjectId, string $key): void
    {
        EntityMeta::deleteForExtension($this->kind, $this->name, $subjectType, $subjectId, $key);
    }

    /** Read one structured record owned by this extension. */
    public function getRecord(string $model, string $key): ?array
    {
        return ExtensionRecords::get($this->kind, $this->name, $model, $key);
    }

    /** Create or replace one record; pass its current revision to reject stale writes. */
    public function putRecord(string $model, string $key, array $data, ?int $expectedRevision = null): array
    {
        return ExtensionRecords::put($this->kind, $this->name, $model, $key, $data, $expectedRevision);
    }

    /** List records by a stable ascending ID cursor; page size is limited to 50. */
    public function listRecords(string $model, int $afterId = 0, int $limit = 20): array
    {
        return ExtensionRecords::list($this->kind, $this->name, $model, $afterId, $limit);
    }

    /** Delete a record; pass its current revision to prevent deleting a newer edit. */
    public function deleteRecord(string $model, string $key, ?int $expectedRevision = null): bool
    {
        return ExtensionRecords::delete($this->kind, $this->name, $model, $key, $expectedRevision);
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
                return ExtensionSchema::ownedName($this->kind, $this->name, $logicalName);
            }
        }
        throw new \RuntimeException('未声明的扩展数据表：' . $logicalName);
    }
}
