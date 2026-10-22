<?php

declare(strict_types=1);

use Pafish\Core\DB;
use Pafish\Services\ExtensionContext;
use Pafish\Services\ExtensionRequest;

require_once __DIR__ . '/src/ModelGateway.php';
require_once __DIR__ . '/src/Exceptions.php';
require_once __DIR__ . '/src/HealthCheck.php';
require_once __DIR__ . '/src/FunctionRegistry.php';

return static function (ExtensionContext $ctx): void {
    $migrateLegacy = static function () use ($ctx): void {
        $marker = 'legacy_tables_migrated';
        if (\Pafish\Services\ExtensionStorage::get('plugin', 'ai_foundation', $marker, '') === '1') return;
        $targets = [
            'models' => ['name', 'provider', 'type', 'purpose', 'base_url', 'model', 'api_key', 'is_default'],
            'usage_log' => ['model_id', 'caller_plugin', 'prompt_tokens', 'completion_tokens', 'total_tokens'],
            'quota_settings' => ['id', 'hourly_calls_limit', 'daily_tokens_limit', 'max_tokens_per_call'],
        ];
        $prefixes = ['pafish_plugin_ai_foundation_', 'pafish_ai_foundation_', 'pf_ai_foundation_'];
        foreach ($targets as $logical => $columns) {
            $target = '`' . $ctx->table($logical) . '`';
            foreach ($prefixes as $prefix) {
                $sourceName = $prefix . $logical;
                $exists = (int) DB::value('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$sourceName]);
                if ($exists < 1) continue;
                $sourceColumns = DB::fetchAll('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$sourceName]);
                $available = array_fill_keys(array_map(static fn (array $row): string => (string) $row['COLUMN_NAME'], $sourceColumns), true);
                if (count(array_diff($columns, array_keys($available))) > 0) continue;
                $columnSql = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
                DB::execute("INSERT IGNORE INTO {$target} ({$columnSql}) SELECT {$columnSql} FROM `{$sourceName}`");
                break;
            }
        }
        \Pafish\Services\ExtensionStorage::set('plugin', 'ai_foundation', $marker, '1');
    };
    $ctx->lifecycle('onActivate', static function () use ($migrateLegacy): void { $migrateLegacy(); });
    $ctx->lifecycle('onUpgrade', static function () use ($migrateLegacy): void { $migrateLegacy(); });

    $ctx->provider('home', static fn (): array => ['redirect' => '/admin/plugins/ai_foundation']);

    $ctx->inject('plugin_setting', static function (array $context, ExtensionContext $ctx): string {
        if (($context['pluginName'] ?? '') !== 'ai_foundation') return '';
        return ai_foundation_render($ctx);
    });

    $ctx->provider('manage', static function (ExtensionRequest $request, ExtensionContext $ctx): array {
        $table = '`' . $ctx->table('models') . '`';
        $action = $request->bodyString('action');
        $result = '';

        try {
            if ($action === 'save') {
                $id = max(0, $request->bodyInt('id'));
                $name = trim($request->bodyString('name'));
                $provider = trim($request->bodyString('provider', 'openai-compatible'));
                $type = trim($request->bodyString('type', 'chat'));
                $purpose = trim($request->bodyString('purpose', 'general'));
                $baseUrl = rtrim(trim($request->bodyString('base_url')), '/');
                $model = trim($request->bodyString('model'));
                $apiKey = trim($request->bodyString('api_key'));
                if ($name === '' || mb_strlen($name) > 100 || $model === '' || mb_strlen($model) > 160) {
                    throw new RuntimeException('请填写模型名称和模型 ID。');
                }
                if (!in_array($type, ['chat', 'embedding', 'image', 'audio'], true)) {
                    throw new RuntimeException('模型类型无效。');
                }
                if (!in_array($purpose, ['general', 'moderation', 'generation', 'search'], true)) {
                    throw new RuntimeException('模型用途无效。');
                }
                if ($type !== 'chat') $purpose = 'general';
                if (!in_array($provider, ['openai-compatible', 'openai', 'deepseek', '通义千问', '智谱', '硅基流动', '自定义'], true)) {
                    throw new RuntimeException('模型服务商无效。');
                }
                $baseUrl = AiFoundation\ModelGateway::normalizeBaseUrl($baseUrl);
                $existing = $id > 0 ? DB::fetchOne("SELECT id, api_key FROM {$table} WHERE id = ?", [$id]) : null;
                if ($id > 0 && $existing === null) {
                    throw new RuntimeException('模型配置不存在。');
                }
                if ($apiKey === '') {
                    $apiKey = (string) ($existing['api_key'] ?? '');
                } else {
                    $apiKey = AiFoundation\ModelGateway::encryptSecret($apiKey);
                }
                $isDefault = $request->bodyBool('is_default') || $existing === null;
                DB::transaction(static function () use ($table, $id, $name, $provider, $type, $purpose, $baseUrl, $model, $apiKey, $isDefault, $existing): void {
                    if ($isDefault) DB::execute("UPDATE {$table} SET is_default = 0 WHERE type = ?", [$type]);
                    if ($existing === null) {
                        DB::execute("INSERT INTO {$table} (name, provider, type, purpose, base_url, model, api_key, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [$name, $provider, $type, $purpose, $baseUrl, $model, $apiKey, $isDefault ? 1 : 0]);
                    } else {
                        DB::execute("UPDATE {$table} SET name = ?, provider = ?, type = ?, purpose = ?, base_url = ?, model = ?, api_key = ?, is_default = ? WHERE id = ?", [$name, $provider, $type, $purpose, $baseUrl, $model, $apiKey, $isDefault ? 1 : 0, $id]);
                    }
                });
                return ['ok' => true, 'message' => '模型配置已保存。'];
            } elseif ($action === 'check') {
                $id = max(0, $request->bodyInt('id'));
                if ($id < 1) throw new RuntimeException('请选择要检查的聊天模型。');
                $model = ai_foundation_find_model($ctx, $id);
                $health = AiFoundation\HealthCheck::check($model, true);
                return ['ok' => true, 'health' => $health];
            } elseif ($action === 'quota') {
                $hourly = max(1, min(1000000, $request->bodyInt('hourly_calls_limit', 1000)));
                $daily = max(1000, min(10000000, $request->bodyInt('daily_tokens_limit', 100000)));
                $perCall = max(100, min(128000, $request->bodyInt('max_tokens_per_call', 4000)));
                $quotaTable = '`' . $ctx->table('quota_settings') . '`';
                DB::execute("INSERT INTO {$quotaTable} (id, hourly_calls_limit, daily_tokens_limit, max_tokens_per_call) VALUES (1, ?, ?, ?) ON DUPLICATE KEY UPDATE hourly_calls_limit = VALUES(hourly_calls_limit), daily_tokens_limit = VALUES(daily_tokens_limit), max_tokens_per_call = VALUES(max_tokens_per_call)", [$hourly, $daily, $perCall]);
                return ['ok' => true, 'message' => '配额已更新。'];
            } elseif ($action === 'default') {
                $id = max(0, $request->bodyInt('id'));
                $model = DB::fetchOne("SELECT id, type FROM {$table} WHERE id = ?", [$id]);
                if ($model === null) {
                    throw new RuntimeException('模型配置不存在。');
                }
                DB::transaction(static function () use ($table, $id, $model): void {
                    DB::execute("UPDATE {$table} SET is_default = 0 WHERE type = ?", [(string) $model['type']]);
                    DB::execute("UPDATE {$table} SET is_default = 1 WHERE id = ?", [$id]);
                });
                return ['ok' => true, 'message' => '默认模型已更新。'];
            } elseif ($action === 'delete') {
                $id = max(0, $request->bodyInt('id'));
                $wasDefault = (int) DB::value("SELECT is_default FROM {$table} WHERE id = ?", [$id]) === 1;
                DB::execute("DELETE FROM {$table} WHERE id = ?", [$id]);
                if ($wasDefault) {
                    $next = DB::value("SELECT id FROM {$table} ORDER BY id LIMIT 1");
                    if ($next !== null) DB::execute("UPDATE {$table} SET is_default = 1 WHERE id = ?", [(int) $next]);
                }
                return ['ok' => true, 'message' => '模型配置已删除。'];
            } elseif (in_array($action, ['test', 'chat'], true)) {
                $id = max(0, $request->bodyInt('id'));
                $model = ai_foundation_find_model($ctx, $id, 'chat', 'general');
                $prompt = $action === 'test'
                    ? 'Reply with exactly: pafish AI is ready.'
                    : trim($request->bodyString('prompt'));
                if ($prompt === '') throw new RuntimeException('请输入测试内容。');
                $result = AiFoundation\ModelGateway::complete($model, [['role' => 'user', 'content' => $prompt]], ['callerPlugin' => 'ai_foundation']);
                return ['ok' => true, 'message' => $action === 'test' ? '连接成功。' : '模型已回复。', 'result' => $result['text']];
            } elseif ($action === 'toggle_function') {
                $funcId = max(0, $request->bodyInt('id'));
                $funcsTable = '`' . $ctx->table('registered_functions') . '`';
                $current = (int) DB::value("SELECT is_enabled FROM {$funcsTable} WHERE id = ?", [$funcId]);
                DB::execute("UPDATE {$funcsTable} SET is_enabled = ? WHERE id = ?", [$current === 1 ? 0 : 1, $funcId]);
                return ['ok' => true, 'message' => '函数状态已更新。'];
            } elseif ($action === 'delete_function') {
                $funcId = max(0, $request->bodyInt('id'));
                $funcsTable = '`' . $ctx->table('registered_functions') . '`';
                DB::execute("DELETE FROM {$funcsTable} WHERE id = ?", [$funcId]);
                return ['ok' => true, 'message' => '函数已删除。'];
            } else {
                throw new RuntimeException('不支持的操作。');
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 400];
        }
    });

    $ctx->provider('completeText', static function (array $messages, array $options = []) use ($ctx): array {
        $caller = ai_foundation_extract_caller_plugin();
        $options['callerPlugin'] = $caller;
        unset($options['tools'], $options['allowedTools']);
        
        $model = ai_foundation_find_model($ctx, (int) ($options['modelId'] ?? 0), 'chat', (string) ($options['purpose'] ?? 'general'));
        $result = AiFoundation\ModelGateway::complete($model, $messages, $options);
        return [
            'text' => $result['text'],
            'model' => (string) $model['model'],
            'provider' => (string) $model['provider'],
            'usage' => $result['usage'],
        ];
    });

    $ctx->provider('completeTextStream', static function (array $messages, array $options, callable $onChunk) use ($ctx): array {
        $caller = ai_foundation_extract_caller_plugin();
        $options['callerPlugin'] = $caller;
        
        $model = ai_foundation_find_model($ctx, (int) ($options['modelId'] ?? 0), 'chat', (string) ($options['purpose'] ?? 'general'));
        $result = AiFoundation\ModelGateway::completeStream($model, $messages, $options, $onChunk);
        return [
            'model' => (string) $model['model'],
            'provider' => (string) $model['provider'],
            'usage' => $result['usage'],
        ];
    });

    $ctx->provider('generateEmbedding', static function (string $text, array $options = []) use ($ctx): array {
        $caller = ai_foundation_extract_caller_plugin();
        $options['callerPlugin'] = $caller;
        
        $model = ai_foundation_find_model($ctx, (int) ($options['modelId'] ?? 0), 'embedding', 'general');
        $result = AiFoundation\ModelGateway::embed($model, $text, $options);
        return [
            'vector' => $result['vector'],
            'dimensions' => $result['dimensions'],
            'model' => (string) $model['model'],
            'usage' => $result['usage'],
        ];
    });

    $ctx->provider('generateImage', static function (string $prompt, array $options = []) use ($ctx): array {
        $caller = ai_foundation_extract_caller_plugin();
        $options['callerPlugin'] = $caller;
        
        $model = ai_foundation_find_model($ctx, (int) ($options['modelId'] ?? 0), 'image', 'general');
        $result = AiFoundation\ModelGateway::generateImage($model, $prompt, $options);
        return [
            'url' => $result['url'],
            'revised_prompt' => $result['revised_prompt'],
            'model' => (string) $model['model'],
            'usage' => $result['usage'],
        ];
    });

    $ctx->provider('transcribeAudio', static function (string $audioPath, array $options = []) use ($ctx): array {
        $caller = ai_foundation_extract_caller_plugin();
        $options['callerPlugin'] = $caller;
        
        $model = ai_foundation_find_model($ctx, (int) ($options['modelId'] ?? 0), 'audio', 'general');
        $result = AiFoundation\ModelGateway::transcribeAudio($model, $audioPath, $options);
        return [
            'text' => $result['text'],
            'model' => (string) $model['model'],
            'usage' => $result['usage'],
        ];
    });

    $ctx->provider('registerAiFunction', static function (string $name, string $description, array $parametersSchema, string $providerName, bool $sideEffect = false) use ($ctx): void {
        $pluginName = $ctx->pluginName();
        AiFoundation\FunctionRegistry::register($name, $description, $parametersSchema, $pluginName, $providerName, true, $sideEffect);
    });

    $ctx->provider('completeWithTools', static function (array $messages, array $options = []) use ($ctx): array {
        $caller = ai_foundation_extract_caller_plugin();
        $options['callerPlugin'] = $caller;
        
        // 自动附加所有已注册的工具
        $allowedTools = is_array($options['allowedTools'] ?? null)
            ? array_values(array_filter($options['allowedTools'], static fn ($name): bool => is_string($name) && preg_match('/^[a-z][a-z0-9_]*$/', $name) === 1))
            : [];
        $allowSideEffects = ($options['allowSideEffects'] ?? false) === true;
        $options['tools'] = $allowedTools !== []
            ? AiFoundation\FunctionRegistry::getEnabledTools(null, $allowedTools, $allowSideEffects)
            : AiFoundation\FunctionRegistry::getEnabledTools($caller, null, $allowSideEffects);
        // Cross-plugin tools require explicit opt-in and side effects require a second opt-in.
        $options['allowAnyTool'] = $allowedTools !== [];
        $options['allowedTools'] = $allowedTools;
        
        $model = ai_foundation_find_model($ctx, (int) ($options['modelId'] ?? 0), 'chat', (string) ($options['purpose'] ?? 'general'));
        $result = AiFoundation\ModelGateway::complete($model, $messages, $options);
        
        return [
            'text' => $result['text'],
            'model' => (string) $model['model'],
            'provider' => (string) $model['provider'],
            'usage' => $result['usage'],
            'tool_calls' => $result['tool_calls'] ?? [],
        ];
    });
};

function ai_foundation_find_model(ExtensionContext $ctx, int $id = 0, string $type = 'chat', string $purpose = 'general'): array
{
    $table = '`' . $ctx->table('models') . '`';
    
    if ($id > 0) {
        $model = DB::fetchOne("SELECT * FROM {$table} WHERE id = ?", [$id]);
        if ($model === null) throw new AiFoundation\ConfigurationException('模型配置不存在。');
        if ((string) $model['type'] !== $type) {
            throw new AiFoundation\ConfigurationException($type === 'chat' ? '检查和聊天测试仅支持聊天类型模型。' : '所选模型类型与调用接口不匹配。');
        }
        return $model;
    }
    
    $model = DB::fetchOne("SELECT * FROM {$table} WHERE type = ? AND purpose = ? ORDER BY is_default DESC, id ASC LIMIT 1", [$type, $purpose]);
    if ($model !== null) return $model;
    
    $model = DB::fetchOne("SELECT * FROM {$table} WHERE type = ? AND purpose = 'general' ORDER BY is_default DESC, id ASC LIMIT 1", [$type]);
    if ($model !== null) return $model;
    
    $model = DB::fetchOne("SELECT * FROM {$table} WHERE type = ? ORDER BY is_default DESC, id ASC LIMIT 1", [$type]);
    if ($model !== null) return $model;
    
    throw new AiFoundation\ConfigurationException('请先配置一个' . ($type === 'embedding' ? '向量' : '聊天') . '模型。');
}

function ai_foundation_extract_caller_plugin(): string
{
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
    foreach ($trace as $frame) {
        $file = (string) ($frame['file'] ?? '');
        if (preg_match('#[/\\\\]plugins[/\\\\]([^/\\\\]+)[/\\\\]#', $file, $m)) {
            if ($m[1] === 'ai_foundation') continue;
            return $m[1];
        }
    }
    return 'core';
}

function ai_foundation_render(ExtensionContext $ctx): string
{
    $table = '`' . $ctx->table('models') . '`';
    $models = DB::fetchAll("SELECT id, name, provider, type, purpose, base_url, model, api_key, is_default FROM {$table} ORDER BY is_default DESC, id ASC");
    $activeTab = is_string($_GET['tab'] ?? null) ? trim($_GET['tab']) : 'models';
    $tabs = ['models', 'usage', 'quota', 'functions', 'test'];
    if (!in_array($activeTab, $tabs, true)) $activeTab = 'models';
    $edit = is_string($_GET['edit'] ?? null) ? $_GET['edit'] : '';
    $editingId = max(0, (int) $edit);
    $editing = $activeTab === 'models' && $edit === '0'
        ? ['id' => 0, 'type' => 'chat', 'purpose' => 'general']
        : null;
    foreach ($models as $item) if ((int) $item['id'] === $editingId) $editing = $item;
    ob_start();
    require __DIR__ . '/views/settings.php';
    return (string) ob_get_clean();
}
