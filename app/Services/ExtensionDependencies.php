<?php

declare(strict_types=1);

namespace Pafish\Services;

/**
 * 扩展依赖的统一声明与检查。
 *
 * 清单中的 requires 只描述本地扩展依赖，不与商店目录的 requiresPhp 混用。
 * 支持字符串 `plugin:name` / `theme:name`，以及带版本约束的
 * `plugin:name >=1.0.0`；对象形式用于需要更明确的清单：
 * {"type":"plugin","name":"foo","version":">=1.0.0"}。
 */
final class ExtensionDependencies
{
    private const NAME_PATTERN = '/^[a-z0-9_-]{1,50}$/D';
    private const CONSTRAINT_PATTERN = '/^(?:[<>=!~^]*\s*)?[0-9]+(?:\.[0-9]+){0,2}(?:[-+][0-9A-Za-z.-]+)?$/D';

    /** @return array<int, array{kind:string,name:string,constraint:string}> */
    public static function normalize(mixed $requires): array
    {
        if ($requires === null) {
            return [];
        }
        if (!is_array($requires)) {
            throw new \InvalidArgumentException('requires 必须是数组');
        }
        $out = [];
        foreach ($requires as $key => $value) {
            $kind = $name = $constraint = '';
            if (is_string($key) && !is_int($key)) {
                [$kind, $name] = self::splitName($key);
                $constraint = is_scalar($value) ? trim((string) $value) : '';
            } elseif (is_string($value)) {
                [$kind, $name, $constraint] = self::parseString($value);
            } elseif (is_array($value)) {
                $kind = strtolower(trim((string) ($value['type'] ?? $value['kind'] ?? '')));
                $name = strtolower(trim((string) ($value['name'] ?? '')));
                $constraint = trim((string) ($value['version'] ?? $value['constraint'] ?? ''));
            }
            if (!in_array($kind, ['plugin', 'theme'], true)
                || preg_match(self::NAME_PATTERN, $name) !== 1
                || ($constraint !== '' && preg_match(self::CONSTRAINT_PATTERN, $constraint) !== 1)) {
                throw new \InvalidArgumentException('requires 包含无效依赖');
            }
            $out[] = ['kind' => $kind, 'name' => $name, 'constraint' => $constraint];
        }
        $unique = [];
        foreach ($out as $dependency) {
            $key = $dependency['kind'] . ':' . $dependency['name'];
            if (isset($unique[$key])) {
                throw new \InvalidArgumentException('requires 包含重复依赖：' . $key);
            }
            $unique[$key] = true;
        }
        return $out;
    }

    public static function validate(mixed $requires): ?string
    {
        try {
            self::normalize($requires);
            return null;
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /** 启用或启动前检查所有依赖，失败时给出可读的缺失原因。 */
    public static function assertSatisfied(string $kind, string $name, mixed $requires): void
    {
        $root = $kind . ':' . $name;
        $visited = [];
        self::walk($requires, [$root], $visited);
    }

    /** @param array<int, string> $stack @param array<string, bool> $visited */
    private static function walk(mixed $requires, array $stack, array &$visited): void
    {
        foreach (self::normalize($requires) as $dependency) {
            $id = $dependency['kind'] . ':' . $dependency['name'];
            if (in_array($id, $stack, true)) {
                $path = array_merge($stack, [$id]);
                throw new \RuntimeException('扩展依赖存在循环：' . implode(' -> ', $path));
            }
            $manifest = $dependency['kind'] === 'plugin'
                ? Plugin::manifest($dependency['name'])
                : Theme::manifest($dependency['name']);
            if ($manifest === null) {
                throw new \RuntimeException('缺少依赖扩展：' . $id);
            }
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            self::walk($manifest['requires'] ?? [], array_merge($stack, [$id]), $visited);
            $constraint = $dependency['constraint'];
            $version = (string) ($manifest['version'] ?? '');
            if ($constraint !== '' && !self::matches($version, $constraint)) {
                throw new \RuntimeException('依赖扩展版本不满足：' . $id . ' ' . $constraint . '（当前 ' . $version . '）');
            }
            if ($dependency['kind'] === 'plugin' && !Plugin::isActive($dependency['name'])) {
                throw new \RuntimeException('依赖插件未启用：' . $dependency['name']);
            }
            if ($dependency['kind'] === 'theme' && Theme::active() !== $dependency['name']) {
                throw new \RuntimeException('依赖主题未激活：' . $dependency['name']);
            }
        }
    }

    private static function parseString(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^(plugin|theme):([a-z0-9_-]{1,50})(?:\s*(.*))?$/D', $value, $m) !== 1) {
            return ['', '', ''];
        }
        return [$m[1], $m[2], trim((string) ($m[3] ?? ''))];
    }

    private static function splitName(string $value): array
    {
        if (preg_match('/^(plugin|theme):([a-z0-9_-]{1,50})$/D', trim($value), $m) !== 1) {
            return ['', ''];
        }
        return [$m[1], $m[2]];
    }

    private static function matches(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        $operator = '=';
        $target = $constraint;
        if (preg_match('/^(<=|>=|!=|==|=|<|>|~|\^)?\s*(.+)$/D', $constraint, $m) === 1) {
            $operator = $m[1] !== '' ? $m[1] : '=';
            $target = $m[2];
        }
        $cmp = version_compare($version, $target);
        if ($operator === '^') {
            $parts = array_pad(array_map('intval', explode('.', $target)), 3, 0);
            $upper = ($parts[0] > 0) ? ($parts[0] + 1) . '.0.0' : (($parts[1] > 0) ? '0.' . ($parts[1] + 1) . '.0' : '0.0.' . ($parts[2] + 1));
            return version_compare($version, $target, '>=') && version_compare($version, $upper, '<');
        }
        if ($operator === '~') {
            $parts = array_pad(array_map('intval', explode('.', $target)), 3, 0);
            $upper = $parts[0] . '.' . ($parts[1] + 1) . '.0';
            return version_compare($version, $target, '>=') && version_compare($version, $upper, '<');
        }
        return match ($operator) {
            '!=' => $cmp !== 0,
            '>' => $cmp > 0,
            '>=' => $cmp >= 0,
            '<' => $cmp < 0,
            '<=' => $cmp <= 0,
            default => $cmp === 0,
        };
    }
}
