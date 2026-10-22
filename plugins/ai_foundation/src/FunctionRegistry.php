<?php

declare(strict_types=1);

namespace AiFoundation;

use Pafish\Core\DB;

/**
 * AI 函数调用注册表。
 * 
 * 允许插件注册可供 AI 调用的函数（工具），并提供安全控制。
 */
final class FunctionRegistry
{
    private const SCHEMA_VERSION = 1;
    
    /**
     * 注册一个可供 AI 调用的函数。
     *
     * @param string $name 函数名（唯一标识符，如 'get_weather'）
     * @param string $description 函数功能描述（告诉 AI 何时调用）
     * @param array<string,mixed> $parametersSchema JSON Schema 格式的参数定义
     * @param string $providerPlugin 提供此函数的插件名
     * @param string $providerName provider 名称（用于后续调用）
     * @param bool $isEnabled 是否启用（默认启用）
     * @throws \RuntimeException 参数无效
     */
    public static function register(
        string $name,
        string $description,
        array $parametersSchema,
        string $providerPlugin,
        string $providerName,
        bool $isEnabled = true,
        bool $sideEffect = false
    ): void {
        $name = trim($name);
        $description = trim($description);
        $providerPlugin = trim($providerPlugin);
        $providerName = trim($providerName);
        
        if ($name === '' || strlen($name) > 100 || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \RuntimeException('函数名必须是小写字母开头的蛇形命名（如 get_weather）。');
        }
        
        if ($description === '' || strlen($description) > 500) {
            throw new \RuntimeException('函数描述必须在 1-500 字符之间。');
        }
        
        if ($providerPlugin === '' || $providerName === '') {
            throw new \RuntimeException('必须指定提供者插件和 provider 名称。');
        }
        
        // 验证 JSON Schema 基本结构
        if (!isset($parametersSchema['type']) || $parametersSchema['type'] !== 'object') {
            throw new \RuntimeException('参数 Schema 必须是 type=object 的 JSON Schema。');
        }
        
        $schemaJson = json_encode($parametersSchema, JSON_UNESCAPED_UNICODE);
        if ($schemaJson === false || strlen($schemaJson) > 10000) {
            throw new \RuntimeException('参数 Schema 过大或无法序列化。');
        }
        
        $table = self::table();
        
        try {
            $existing = DB::fetchOne("SELECT provider_plugin FROM {$table} WHERE name = ?", [$name]);
            if ($existing !== null && (string) $existing['provider_plugin'] !== $providerPlugin) {
                throw new \RuntimeException('函数名已被其他插件注册：' . $name);
            }
            DB::execute(
                "INSERT INTO {$table} (name, description, parameters_schema, provider_plugin, provider_name, side_effect, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description), parameters_schema = VALUES(parameters_schema), provider_plugin = VALUES(provider_plugin), provider_name = VALUES(provider_name), side_effect = VALUES(side_effect), is_enabled = VALUES(is_enabled)",
                [$name, $description, $schemaJson, $providerPlugin, $providerName, $sideEffect ? 1 : 0, $isEnabled ? 1 : 0]
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('函数注册失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取所有已启用的函数列表（OpenAI tools 格式）。
     *
     * @return array<int,array{type:string,function:array{name:string,description:string,parameters:array}}>
     */
    public static function getEnabledTools(?string $providerPlugin = null, ?array $allowedNames = null, bool $includeSideEffects = true): array
    {
        $table = self::table();
        
        try {
            $sql = "SELECT name, description, parameters_schema, side_effect FROM {$table} WHERE is_enabled = 1";
            $params = [];
            if (!$includeSideEffects) {
                $sql .= ' AND side_effect = 0';
            }
            if ($allowedNames !== null) {
                $allowedNames = array_values(array_filter($allowedNames, 'is_string'));
                if ($allowedNames === []) return [];
                $sql .= ' AND name IN (' . implode(',', array_fill(0, count($allowedNames), '?')) . ')';
                $params = $allowedNames;
            } elseif ($providerPlugin !== null && $providerPlugin !== '') {
                $sql .= " AND provider_plugin = ?";
                $params[] = $providerPlugin;
            }
            $rows = DB::fetchAll($sql, $params);
        } catch (\Throwable $e) {
            // 表不存在或 DB 不可用 → 返回空列表
            return [];
        }
        
        $tools = [];
        foreach ($rows as $row) {
            $params = json_decode((string) $row['parameters_schema'], true);
            if (!is_array($params)) continue;
            
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => (string) $row['name'],
                    'description' => (string) $row['description'],
                    'parameters' => $params,
                ],
            ];
        }
        
        return $tools;
    }
    
    /**
     * 根据函数名查找其 provider 信息。
     *
     * @return array{provider_plugin:string, provider_name:string}|null
     */
    public static function lookupProvider(string $name, ?string $providerPlugin = null, ?array $allowedNames = null): ?array
    {
        $table = self::table();
        
        try {
            $sql = "SELECT provider_plugin, provider_name, parameters_schema, side_effect FROM {$table} WHERE name = ? AND is_enabled = 1";
            $params = [$name];
            if ($allowedNames !== null) {
                $allowedNames = array_values(array_unique(array_filter($allowedNames, 'is_string')));
                if (!in_array($name, $allowedNames, true)) return null;
            }
            if ($providerPlugin !== null && $providerPlugin !== '') {
                $sql .= " AND provider_plugin = ?";
                $params[] = $providerPlugin;
            }
            $row = DB::fetchOne($sql, $params);
            if ($row === null) return null;
            
            return [
                'provider_plugin' => (string) $row['provider_plugin'],
                'provider_name' => (string) $row['provider_name'],
                'parameters_schema' => is_array($schema = json_decode((string) $row['parameters_schema'], true)) ? $schema : [],
                'side_effect' => (bool) ($row['side_effect'] ?? false),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 删除指定插件注册的所有函数（插件卸载时调用）。
     */
    public static function unregisterByPlugin(string $pluginName): void
    {
        $table = self::table();
        
        try {
            DB::execute("DELETE FROM {$table} WHERE provider_plugin = ?", [$pluginName]);
        } catch (\Throwable $e) {
            // 忽略错误
        }
    }

    private static function table(): string
    {
        return '`pf_plugin_ai_foundation_registered_functions`';
    }
}
