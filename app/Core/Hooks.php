<?php

declare(strict_types=1);

namespace Pafish\Core;

/**
 * 钩子引擎（对标 WordPress / emlog $emHooks）：
 * doAction 广播、applyFilters 管道；priority 排序；tag 批量注销
 * 插件通过 tag="plugin:{name}" 注册，停用时整批移除
 */
final class Hooks
{
    /** @var array<string, array<int, array{priority:int, tag:?string, fn:callable}>> */
    private static array $actions = [];

    /** @var array<string, array<int, array{priority:int, tag:?string, fn:callable}>> */
    private static array $filters = [];

    public static function addAction(string $name, callable $fn, int $priority = 10, ?string $tag = null): void
    {
        self::$actions[$name][] = ['priority' => $priority, 'tag' => $tag, 'fn' => $fn];
    }

    /** 触发事件：按优先级顺序调用全部监听者，单个异常不阻断后续 */
    public static function doAction(string $name, mixed ...$args): void
    {
        if (empty(self::$actions[$name])) {
            return;
        }
        $list = self::sorted(self::$actions[$name]);
        foreach ($list as $entry) {
            try {
                ($entry['fn'])(...$args);
            } catch (\Throwable $e) {
                error_log("[pafish-hook] {$name}: " . $e->getMessage());
            }
        }
    }

    public static function addFilter(string $name, callable $fn, int $priority = 10, ?string $tag = null): void
    {
        self::$filters[$name][] = ['priority' => $priority, 'tag' => $tag, 'fn' => $fn];
    }

    /** 过滤器管道：值依次经各监听者转换后返回 */
    public static function applyFilters(string $name, mixed $value, mixed ...$args): mixed
    {
        if (empty(self::$filters[$name])) {
            return $value;
        }
        $list = self::sorted(self::$filters[$name]);
        foreach ($list as $entry) {
            try {
                $value = ($entry['fn'])($value, ...$args);
            } catch (\Throwable $e) {
                error_log("[pafish-filter] {$name}: " . $e->getMessage());
            }
        }
        return $value;
    }

    public static function hasAction(string $name): bool
    {
        return !empty(self::$actions[$name]);
    }

    public static function hasFilter(string $name): bool
    {
        return !empty(self::$filters[$name]);
    }

    /** 按 tag 批量注销（插件停用/卸载时调用） */
    public static function removeByTag(string $tag): void
    {
        foreach (['actions', 'filters'] as $bucket) {
            foreach (self::$$bucket as $name => $list) {
                self::$$bucket[$name] = array_values(array_filter(
                    $list,
                    static fn (array $entry): bool => $entry['tag'] !== $tag
                ));
            }
        }
    }

    private static function sorted(array $list): array
    {
        usort($list, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
        return $list;
    }
}
