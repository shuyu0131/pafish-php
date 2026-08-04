<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/**
 * 分类树（对齐 Node src/lib/category-tree.ts）：
 * 同级按 (sort_order, id) 升序 DFS，返回扁平数组 + depth（顶级为 0），供下拉/树形 UI 使用
 */
final class Categories
{
    /** 全部分类扁平树（含 depth）；可传排除 id（防自引用时隐藏子树） */
    public static function tree(int $excludeId = 0): array
    {
        $rows = DB::fetchAll('SELECT * FROM categories ORDER BY sort_order ASC, id ASC');
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
        }

        // 排除子树收集（excludeId 及其全部后代）
        $excluded = $excludeId > 0 ? self::descendants($rows, $excludeId) : [];
        $excluded[$excludeId] = true;

        $flat = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$flat, $byParent, $excluded): void {
            foreach ($byParent[$parentId] ?? [] as $row) {
                if (isset($excluded[(int) $row['id']])) {
                    continue;
                }
                $row['depth'] = $depth;
                $flat[] = $row;
                $walk((int) $row['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        return $flat;
    }

    /** 某分类及其全部后代的 id 集合 */
    public static function descendants(array $rows, int $rootId): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[(int) ($row['parent_id'] ?? 0)][] = (int) $row['id'];
        }
        $ids = [$rootId => true];
        $queue = [$rootId];
        while ($queue !== []) {
            $pid = array_shift($queue);
            foreach ($byParent[$pid] ?? [] as $cid) {
                if (!isset($ids[$cid])) {
                    $ids[$cid] = true;
                    $queue[] = $cid;
                }
            }
        }
        return $ids;
    }

    /** 校验目标分类可成为某分类的父级（防自引用/循环：目标不能是自身或其子孙） */
    public static function canBeParent(int $categoryId, ?int $parentId): bool
    {
        if ($parentId === null || $parentId === 0) {
            return true;
        }
        if ($parentId === $categoryId) {
            return false;
        }
        $rows = DB::fetchAll('SELECT id, parent_id FROM categories');
        return !isset(self::descendants($rows, $categoryId)[$parentId]);
    }
}
