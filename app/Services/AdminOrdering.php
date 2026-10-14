<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Cache;
use Pafish\Core\DB;

/** 管理列表排序：同级全量校验、并发检查和事务写入。 */
final class AdminOrdering
{
    private const TABLES = ['categories' => 'categories', 'nav' => 'nav_items', 'links' => 'links'];

    public static function reorder(string $scope, mixed $ids, mixed $expected): void
    {
        $ids = self::ids($ids);
        $expected = self::ids($expected);
        $table = self::TABLES[$scope] ?? throw new \InvalidArgumentException('不支持的排序列表');
        DB::transaction(static function () use ($scope, $table, $ids, $expected): void {
            $rows = DB::fetchAll('SELECT id, sort_order' . ($scope === 'categories' ? ', parent_id' : '')
                . " FROM {$table} ORDER BY sort_order, id FOR UPDATE");
            $siblings = self::siblings($scope, $rows, $ids[0]);
            $current = array_column($siblings, 'id');
            $current = array_map('intval', $current);
            $a = $ids;
            $b = $current;
            sort($a);
            sort($b);
            if ($a !== $b) {
                throw new \InvalidArgumentException('请仅调整同级条目，列表变化后请刷新重试');
            }
            if ($expected !== $current) {
                throw new \RuntimeException('列表顺序已变化，请刷新后重试');
            }
            self::write($table, $ids);
        });
        Cache::clearPrefix($scope . '.');
    }

    /** 按 sort_order、id 的实际顺序移动，兼容重复的历史排序值。 */
    public static function move(string $scope, int $id, string $dir): void
    {
        $table = self::TABLES[$scope] ?? throw new \InvalidArgumentException('不支持的排序列表');
        DB::transaction(static function () use ($scope, $table, $id, $dir): void {
            $rows = DB::fetchAll('SELECT id, sort_order' . ($scope === 'categories' ? ', parent_id' : '')
                . " FROM {$table} ORDER BY sort_order, id FOR UPDATE");
            $ids = array_map('intval', array_column(self::siblings($scope, $rows, $id), 'id'));
            $index = array_search($id, $ids, true);
            $next = $index + ($dir === 'up' ? -1 : 1);
            if (isset($ids[$next])) {
                [$ids[$index], $ids[$next]] = [$ids[$next], $ids[$index]];
                self::write($table, $ids);
            }
        });
        Cache::clearPrefix($scope . '.');
    }

    private static function ids(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 10000) {
            throw new \InvalidArgumentException('排序数据无效');
        }
        foreach ($value as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new \InvalidArgumentException('排序数据无效');
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            throw new \InvalidArgumentException('排序包含重复条目');
        }
        return $value;
    }

    private static function siblings(string $scope, array $rows, int $id): array
    {
        $found = null;
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                $found = $row;
                break;
            }
        }
        if ($found === null) {
            throw new \InvalidArgumentException('条目不存在，请刷新后重试');
        }
        return $scope === 'categories'
            ? array_values(array_filter($rows, static fn (array $row): bool => $row['parent_id'] === $found['parent_id']))
            : $rows;
    }

    private static function write(string $table, array $ids): void
    {
        foreach ($ids as $position => $id) {
            DB::execute("UPDATE {$table} SET sort_order = ? WHERE id = ?", [$position, $id]);
        }
    }
}
