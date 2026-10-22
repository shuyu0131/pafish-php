<?php

declare(strict_types=1);

namespace AiFoundation;

use Pafish\Core\Config;
use Pafish\Core\DB;
use Pafish\Services\OutboundHttp;

final class ModelGateway
{
    private const TABLE_PREFIX = 'pf_plugin_ai_foundation_';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = [500, 1000, 2000];
    public static function encryptSecret(string $secret): string
    {
        if (!function_exists('openssl_encrypt') || strlen($secret) > 4096) {
            throw new \RuntimeException('服务器无法安全保存此 API Key。');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new \RuntimeException('API Key 加密失败。');
        }
        return 'v1:' . base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decryptSecret(string $stored): string
    {
        if ($stored === '') return '';
        if (!str_starts_with($stored, 'v1:') || !function_exists('openssl_decrypt')) {
            throw new \RuntimeException('已保存的 API Key 无法解密，请重新保存模型配置。');
        }
        $payload = base64_decode(substr($stored, 3), true);
        if (!is_string($payload) || strlen($payload) < 29) {
            throw new \RuntimeException('已保存的 API Key 数据无效，请重新保存模型配置。');
        }
        $secret = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
        if (!is_string($secret)) throw new \RuntimeException('无法解密 API Key；请检查站点密钥是否已更换。');
        return $secret;
    }

    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new ConfigurationException('请填写有效的模型 API 地址。');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException('模型 API 地址必须是有效的 HTTPS URL。');
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if (str_ends_with($path, '/chat/completions')) {
            $path = substr($path, 0, -strlen('/chat/completions'));
        }
        return rtrim($parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
            . ($path !== '' ? $path : ''), '/');
    }

    /**
     * 同步聊天补全（带自动重试）。
     *
     * @param array<string,mixed> $model 模型配置行
     * @param array<int,array{role:string,content:string}> $messages 对话消息
     * @param array<string,mixed> $options temperature/maxTokens/callerPlugin/tools/maxToolCalls
     * @return array{text:string, usage:array{prompt_tokens:int, completion_tokens:int, total_tokens:int}}
     * @throws ConfigurationException 配置无效
     * @throws AuthenticationException API Key 认证失败
     * @throws RateLimitException 触发频控
     * @throws QuotaExceededException 配额耗尽
     * @throws ServiceException 服务端错误或超时（已重试）
     */
    public static function complete(array $model, array $messages, array $options = []): array
    {
        return self::withQuota($options, static function (string $reservation) use ($model, $messages, $options): array {
            if (!isset($options['tools']) || $options['tools'] === []) {
                return self::doComplete($model, $messages, $options, $reservation);
            }
            return self::completeWithTools($model, $messages, $options, $reservation);
        });
    }

    /** A small, metered chat request that also recognizes truncated reasoning responses. */
    public static function probeChat(array $model): array
    {
        if (($model['type'] ?? '') !== 'chat') throw new ConfigurationException('检查仅支持聊天类型模型。');
        $options = ['callerPlugin' => 'ai_foundation_health', 'maxTokens' => 64];
        return self::withQuota($options, static function (string $reservation) use ($model, $options): array {
            [$baseUrl, , $apiKey, , $payload] = self::prepareRequest($model, [['role' => 'user', 'content' => 'Reply with only: OK']], $options, false);
            $response = self::doRequest($baseUrl . '/chat/completions', $payload, $apiKey, 45);
            $body = json_decode((string) $response['body'], true);
            self::validateResponse((int) $response['status'], is_array($body) ? $body : null);
            $choice = $body['choices'][0] ?? null;
            if (!is_array($choice) || !is_array($choice['message'] ?? null)) {
                throw new ServiceException('服务已响应，但返回的不是有效的聊天接口数据。');
            }
            try {
                self::extractContent($body);
                $detail = '';
            } catch (ServiceException $e) {
                $reasoning = $choice['message']['reasoning_content'] ?? '';
                if (($choice['finish_reason'] ?? '') !== 'length' && (!is_string($reasoning) || trim($reasoning) === '')) throw $e;
                $detail = '连接正常；检查预算内仅返回思考内容或回复被截断，可在测试页验证完整回复。';
            }
            $usage = [
                'prompt_tokens' => (int) ($body['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($body['usage']['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($body['usage']['total_tokens'] ?? 0),
            ];
            self::logUsage($model, $options['callerPlugin'], $usage, $reservation);
            return ['detail' => $detail];
        });
    }

    /**
     * 不带工具的普通补全。
     */
    private static function doComplete(array $model, array $messages, array $options, string $reservation): array
    {
        [$baseUrl, $modelId, $apiKey, $safeMessages, $payload] = self::prepareRequest($model, $messages, $options, false);
        
        $lastException = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = self::doRequest($baseUrl . '/chat/completions', $payload, $apiKey, 45);
                $body = json_decode((string) $response['body'], true);
                self::validateResponse($response['status'], $body);
                
                $content = self::extractContent($body);
                $usage = [
                    'prompt_tokens' => (int) ($body['usage']['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($body['usage']['completion_tokens'] ?? 0),
                    'total_tokens' => (int) ($body['usage']['total_tokens'] ?? 0),
                ];
                
                self::logUsage($model, $options['callerPlugin'] ?? 'unknown', $usage, $reservation);
                return ['text' => $content, 'usage' => $usage];
            } catch (ServiceException $e) {
                $lastException = $e;
                if ($attempt < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY_MS[$attempt - 1] * 1000);
                    continue;
                }
                throw $e;
            }
        }
        throw $lastException ?? new ServiceException('请求失败');
    }

    /**
     * 带工具的补全（支持多轮 Function Calling）。
     *
     * @return array{text:string, usage:array{prompt_tokens:int, completion_tokens:int, total_tokens:int}, tool_calls:array}
     */
    private static function completeWithTools(array $model, array $messages, array $options, string $reservation): array
    {
        $maxToolCalls = max(1, min(10, (int) ($options['maxToolCalls'] ?? 5)));
        $callerPlugin = (string) ($options['callerPlugin'] ?? '');
        $allowAnyTool = ($options['allowAnyTool'] ?? false) === true;
        $allowSideEffects = ($options['allowSideEffects'] ?? false) === true;
        $allowedNames = isset($options['allowedTools']) && is_array($options['allowedTools']) ? $options['allowedTools'] : null;
        $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $allToolCalls = [];
        
        for ($round = 0; $round < $maxToolCalls; $round++) {
            [$baseUrl, $modelId, $apiKey, $safeMessages, $payload] = self::prepareRequest($model, $messages, $options, false);
            
            try {
                $response = self::doRequest($baseUrl . '/chat/completions', $payload, $apiKey, 45);
                $body = json_decode((string) $response['body'], true);
                self::validateResponse($response['status'], $body);
                
                // 累积用量
                $totalUsage['prompt_tokens'] += (int) ($body['usage']['prompt_tokens'] ?? 0);
                $totalUsage['completion_tokens'] += (int) ($body['usage']['completion_tokens'] ?? 0);
                $totalUsage['total_tokens'] += (int) ($body['usage']['total_tokens'] ?? 0);
                
                $choice = $body['choices'][0] ?? [];
                $finishReason = (string) ($choice['finish_reason'] ?? '');
                
                // 如果 AI 决定调用工具
                if ($finishReason === 'tool_calls' && isset($choice['message']['tool_calls'])) {
                    $messages[] = $choice['message']; // 保存 assistant 消息（含 tool_calls）
                    
                    foreach ($choice['message']['tool_calls'] as $toolCall) {
                        $functionName = (string) ($toolCall['function']['name'] ?? '');
                        $arguments = (string) ($toolCall['function']['arguments'] ?? '{}');
                        $toolCallId = (string) ($toolCall['id'] ?? '');
                        
                        if ($functionName === '' || $toolCallId === '') continue;
                        
                        // 查找 provider
                        $provider = FunctionRegistry::lookupProvider($functionName, $allowAnyTool ? null : $callerPlugin, $allowedNames);
                        if ($provider === null) {
                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $toolCallId,
                                'content' => '错误：函数未注册或已禁用。',
                            ];
                            continue;
                        }
                        
                        // 调用函数
                        try {
                            if (($provider['side_effect'] ?? false) && !$allowSideEffects) {
                                throw new ConfigurationException('此工具会修改数据，调用方未明确允许副作用。');
                            }
                            $args = json_decode($arguments, true);
                            if (!is_array($args)) {
                                throw new ConfigurationException('工具参数必须是 JSON 对象。');
                            }
                            self::validateToolArguments($args, (array) ($provider['parameters_schema'] ?? []));
                            
                            $result = \Pafish\Services\ExtensionKernel::providerFor(
                                'plugin',
                                $provider['provider_plugin'],
                                $provider['provider_name']
                            );
                            
                            if (!is_callable($result)) {
                                throw new \RuntimeException('Provider 不可调用。');
                            }
                            
                            $output = $result($args);
                            $content = is_string($output) ? $output : json_encode($output, JSON_UNESCAPED_UNICODE);
                            
                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $toolCallId,
                                'content' => $content,
                            ];
                            
                            $allToolCalls[] = [
                                'name' => $functionName,
                                'arguments' => $args,
                                'result' => $content,
                            ];
                        } catch (\Throwable $e) {
                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $toolCallId,
                                'content' => '错误：' . $e->getMessage(),
                            ];
                        }
                    }
                    
                    // 继续下一轮（让 AI 处理工具返回结果）
                    continue;
                }
                
                // 否则，AI 返回了最终文本
                $content = self::extractContent($body);
                self::logUsage($model, $options['callerPlugin'] ?? 'unknown', $totalUsage, $reservation);
                
                return [
                    'text' => $content,
                    'usage' => $totalUsage,
                    'tool_calls' => $allToolCalls,
                ];
            } catch (ServiceException $e) {
                // Function Calling 不重试（避免重复调用副作用函数）
                throw $e;
            }
        }
        
        // 达到最大轮次
        throw new ServiceException('AI 连续调用工具 ' . $maxToolCalls . ' 次后仍未返回最终答案。');
    }

    /**
     * 流式聊天补全（SSE）。
     *
     * @param callable(string):void $onChunk 每收到一个文本片段时调用
     * @return array{usage:array{prompt_tokens:int,completion_tokens:int,total_tokens:int}}
     */
    public static function completeStream(array $model, array $messages, array $options, callable $onChunk): array
    {
        return self::withQuota($options, static function (string $reservation) use ($model, $messages, $options, $onChunk): array {
        [$baseUrl, $modelId, $apiKey, $safeMessages, $payload] = self::prepareRequest($model, $messages, $options, true);
        
        $buffer = '';
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        
        try {
            $response = OutboundHttp::request(
                'POST',
                $baseUrl . '/chat/completions',
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                [
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                    $apiKey !== '' ? 'Authorization: Bearer ' . $apiKey : 'X-Dummy: 1',
                ],
                2 * 1024 * 1024,
                120,
                false,
                static function (string $data) use (&$buffer, $onChunk, &$usage): void {
                    $buffer .= $data;
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 1);
                        if (!str_starts_with($line, 'data: ')) continue;
                        $json = json_decode(trim(substr($line, 6)), true);
                        if (!is_array($json)) continue;
                        if (isset($json['choices'][0]['delta']['content']) && is_string($json['choices'][0]['delta']['content'])) {
                            $onChunk($json['choices'][0]['delta']['content']);
                        }
                        if (isset($json['usage']) && is_array($json['usage'])) {
                            $usage = [
                                'prompt_tokens' => (int) ($json['usage']['prompt_tokens'] ?? 0),
                                'completion_tokens' => (int) ($json['usage']['completion_tokens'] ?? 0),
                                'total_tokens' => (int) ($json['usage']['total_tokens'] ?? 0),
                            ];
                        }
                    }
                },
            );
        } catch (ServiceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ServiceException('流式请求失败：' . $e->getMessage(), 0, $e);
        }

        $body = json_decode((string) ($response['body'] ?? ''), true);
        self::validateResponse((int) ($response['status'] ?? 0), is_array($body) ? $body : []);
        
        self::logUsage($model, $options['callerPlugin'] ?? 'unknown', $usage, $reservation);
        return ['usage' => $usage];
        });
    }

    /**
     * 生成文本向量（embedding）。
     *
     * @return array{vector:float[], dimensions:int, usage:array{prompt_tokens:int,total_tokens:int}}
     */
    public static function embed(array $model, string $text, array $options = []): array
    {
        return self::withQuota($options, static function (string $reservation) use ($model, $text, $options): array {
        $baseUrl = self::normalizeBaseUrl((string) ($model['base_url'] ?? ''));
        $modelId = trim((string) ($model['model'] ?? ''));
        $apiKey = self::decryptSecret((string) ($model['api_key'] ?? ''));
        
        if ($modelId === '' || strlen($text) > 100000) {
            throw new ConfigurationException('模型配置或输入文本无效。');
        }
        
        $payload = ['model' => $modelId, 'input' => $text];
        $response = self::doRequest($baseUrl . '/embeddings', $payload, $apiKey, 30);
        $body = json_decode((string) $response['body'], true);
        self::validateResponse($response['status'], $body);
        
        $vector = $body['data'][0]['embedding'] ?? [];
        if (!is_array($vector) || $vector === []) {
            throw new ServiceException('模型未返回有效向量。');
        }
        
        $usage = [
            'prompt_tokens' => (int) ($body['usage']['prompt_tokens'] ?? 0),
            'total_tokens' => (int) ($body['usage']['total_tokens'] ?? 0),
            'completion_tokens' => 0,
        ];
        
        self::logUsage($model, $options['callerPlugin'] ?? 'unknown', $usage, $reservation);
        
        return [
            'vector' => array_map('floatval', $vector),
            'dimensions' => count($vector),
            'usage' => $usage,
        ];
        });
    }

    private static function prepareRequest(array $model, array $messages, array $options, bool $stream): array
    {
        $baseUrl = self::normalizeBaseUrl((string) ($model['base_url'] ?? ''));
        $modelId = trim((string) ($model['model'] ?? ''));
        $apiKey = self::decryptSecret((string) ($model['api_key'] ?? ''));
        
        if ($modelId === '' || strlen($modelId) > 160 || $messages === [] || count($messages) > 40) {
            throw new ConfigurationException('模型或对话参数无效。');
        }
        
        $safeMessages = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            
            // 支持 tool 角色（Function Calling）
            if (!in_array($role, ['system', 'user', 'assistant', 'tool'], true)) {
                throw new ConfigurationException('对话消息 role 无效。');
            }
            
            // assistant 可以只有 tool_calls 而没有 content
            if ($role === 'assistant' && isset($message['tool_calls']) && is_array($message['tool_calls'])) {
                $safeMessages[] = [
                    'role' => 'assistant',
                    'content' => (string) ($message['content'] ?? ''),
                    'tool_calls' => $message['tool_calls'],
                ];
                continue;
            }
            
            // tool 角色需要 tool_call_id
            if ($role === 'tool') {
                if (!isset($message['tool_call_id']) || !is_string($message['content'] ?? null)) {
                    throw new ConfigurationException('tool 消息缺少 tool_call_id 或 content。');
                }
                $safeMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $message['tool_call_id'],
                    'content' => $message['content'],
                ];
                continue;
            }
            
            // 普通消息
            if (!is_string($message['content'] ?? null) || strlen($message['content']) > 100000) {
                throw new ConfigurationException('对话消息格式无效。');
            }
            $safeMessages[] = ['role' => $role, 'content' => $message['content']];
        }
        
        $payload = ['model' => $modelId, 'messages' => $safeMessages, 'stream' => $stream];
        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $payload['temperature'] = max(0, min(2, (float) $options['temperature']));
        }
        if (isset($options['maxTokens']) && is_numeric($options['maxTokens'])) {
            $payload['max_tokens'] = max(1, min(32768, (int) $options['maxTokens']));
        }
        
        // 添加工具定义（如果有）
        if (isset($options['tools']) && is_array($options['tools']) && $options['tools'] !== []) {
            $payload['tools'] = $options['tools'];
            $payload['tool_choice'] = 'auto'; // 让 AI 自己决定是否调用
        }
        
        return [$baseUrl, $modelId, $apiKey, $safeMessages, $payload];
    }

    private static function doRequest(string $url, array $payload, string $apiKey, int $timeout): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;
        
        try {
            return OutboundHttp::request(
                'POST',
                $url,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $headers,
                2 * 1024 * 1024,
                $timeout
            );
        } catch (\Throwable $e) {
            throw new ServiceException('网络请求失败：' . $e->getMessage(), 0, $e);
        }
    }

    private static function validateResponse(int $status, ?array $body): void
    {
        if ($status >= 200 && $status < 300) return;
        
        $errorMsg = is_array($body) ? (string) ($body['error']['message'] ?? '') : '';
        $errorCode = is_array($body) ? (string) ($body['error']['code'] ?? '') : '';
        
        if ($status === 401 || $status === 403) {
            throw new AuthenticationException($errorMsg !== '' ? $errorMsg : 'API Key 无效或已过期。', $status);
        }
        if ($status === 429) {
            throw new RateLimitException($errorMsg !== '' ? $errorMsg : '请求过于频繁，请稍后重试。', $status);
        }
        if ($status === 402 || $errorCode === 'insufficient_quota' || str_contains($errorMsg, 'quota') || str_contains($errorMsg, 'balance')) {
            throw new QuotaExceededException($errorMsg !== '' ? $errorMsg : '账户余额不足或配额已用尽。', $status);
        }
        if ($status >= 500) {
            throw new ServiceException($errorMsg !== '' ? $errorMsg : '模型服务暂时不可用（HTTP ' . $status . '）。', $status);
        }
        
        throw new ServiceException($errorMsg !== '' ? $errorMsg : '模型服务返回错误（HTTP ' . $status . '）。', $status);
    }

    private static function extractContent(array $body): string
    {
        $content = $body['choices'][0]['message']['content'] ?? null;
        
        if (is_array($content)) {
            $content = implode("\n", array_map(
                static fn ($part): string => is_array($part) ? (string) ($part['text'] ?? '') : '',
                $content
            ));
        }
        
        if (!is_string($content) || trim($content) === '') {
            throw new ServiceException('模型服务没有返回文本内容。');
        }
        
        return $content;
    }

    private static function logUsage(array $model, string $callerPlugin, array $usage, string $reservation): void
    {
        try {
            $table = self::table('usage_log');
            if ($usage['total_tokens'] > 0) {
                DB::execute(
                    "INSERT INTO {$table} (model_id, caller_plugin, prompt_tokens, completion_tokens, total_tokens) VALUES (?, ?, ?, ?, ?)",
                    [(int) ($model['id'] ?? 0), substr($callerPlugin, 0, 100), $usage['prompt_tokens'], $usage['completion_tokens'], $usage['total_tokens']]
                );
            }
            self::releaseQuota($reservation);
        } catch (\Throwable $e) {
            throw new ServiceException('用量记录失败，已停止本次调用以避免绕过配额。', 0, $e);
        }
    }

    /** Reserve one call and the requested token budget in a short transaction. */
    private static function checkQuota(array $options): string
    {
        try {
            $table = self::table('quota_settings');
            $reservation = bin2hex(random_bytes(16));
            $requested = max(1, (int) ($options['maxTokens'] ?? 0));
            DB::transaction(static function () use ($table, $requested, $reservation, $options): void {
                DB::execute("INSERT INTO {$table} (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id");
                $settings = DB::fetchOne("SELECT * FROM {$table} WHERE id = 1 FOR UPDATE") ?? ['hourly_calls_limit' => 1000, 'daily_tokens_limit' => 100000, 'max_tokens_per_call' => 4000];
                $hourlyLimit = max(0, (int) ($settings['hourly_calls_limit'] ?? 1000));
                $dailyTokenLimit = max(0, (int) ($settings['daily_tokens_limit'] ?? 100000));
                $maxTokensPerCall = max(0, (int) ($settings['max_tokens_per_call'] ?? 4000));
                $explicitMax = (int) ($options['maxTokens'] ?? 0);
                if ($maxTokensPerCall > 0 && $explicitMax > $maxTokensPerCall) {
                    throw new QuotaExceededException('单次请求 Token 数（' . $explicitMax . '）超过配额上限（' . $maxTokensPerCall . '）。');
                }
                $requested = $requested > 1 ? $requested : max(1, $maxTokensPerCall);
                if (isset($options['tools']) && is_array($options['tools']) && $options['tools'] !== []) {
                    $requested = min(2147483647, $requested * max(1, min(10, (int) ($options['maxToolCalls'] ?? 5))));
                }
                $usageTable = self::table('usage_log');
                $reserveTable = self::table('quota_reservations');
                DB::execute("DELETE FROM {$reserveTable} WHERE created_at < NOW() - INTERVAL 2 HOUR");
                if ($hourlyLimit > 0 && (int) DB::value("SELECT COUNT(*) FROM {$usageTable} WHERE created_at > NOW() - INTERVAL 1 HOUR") + (int) DB::value("SELECT COUNT(*) FROM {$reserveTable} WHERE created_at > NOW() - INTERVAL 1 HOUR") >= $hourlyLimit) {
                    throw new QuotaExceededException('已达到每小时调用上限（' . $hourlyLimit . ' 次），请稍后再试。');
                }
                if ($dailyTokenLimit > 0 && (int) DB::value("SELECT COALESCE(SUM(total_tokens),0) FROM {$usageTable} WHERE created_at > NOW() - INTERVAL 24 HOUR") + (int) DB::value("SELECT COALESCE(SUM(reserved_tokens),0) FROM {$reserveTable} WHERE created_at > NOW() - INTERVAL 24 HOUR") + $requested > $dailyTokenLimit) {
                    throw new QuotaExceededException('已达到每日 Token 上限（' . number_format($dailyTokenLimit) . '），请稍后再试。');
                }
                DB::execute("INSERT INTO {$reserveTable} (id, reserved_tokens) VALUES (?, ?)", [$reservation, $requested]);
            });
            return $reservation;
        } catch (QuotaExceededException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ServiceException('配额检查失败，已停止本次调用。', 0, $e);
        }
    }

    private static function releaseQuota(string $reservation): void
    {
        if ($reservation === '') return;
        try { DB::execute('DELETE FROM ' . self::table('quota_reservations') . ' WHERE id = ?', [$reservation]); } catch (\Throwable $e) { throw new ServiceException('配额结算失败，已停止本次调用。', 0, $e); }
    }

    private static function withQuota(array $options, callable $operation): array
    {
        $reservation = self::checkQuota($options);
        try { return $operation($reservation); }
        catch (\Throwable $e) { try { self::releaseQuota($reservation); } catch (\Throwable) {} throw $e; }
    }

    /**
     * 图像生成。
     *
     * @param array<string,mixed> $model 模型配置行（type=image）
     * @param string $prompt 图像描述
     * @param array<string,mixed> $options size/quality/style/callerPlugin
     * @return array{url:string, revised_prompt:string, usage:array{total_tokens:int}}
     * @throws ConfigurationException 配置无效
     * @throws AuthenticationException API Key 认证失败
     * @throws QuotaExceededException 配额耗尽
     * @throws ServiceException 服务端错误
     */
    public static function generateImage(array $model, string $prompt, array $options = []): array
    {
        return self::withQuota($options, static function (string $reservation) use ($model, $prompt, $options): array {
        
        $baseUrl = self::normalizeBaseUrl((string) ($model['base_url'] ?? ''));
        $modelId = trim((string) ($model['model'] ?? ''));
        $apiKey = self::decryptSecret((string) ($model['api_key'] ?? ''));
        
        if ($modelId === '' || strlen($modelId) > 160 || strlen($prompt) === 0 || strlen($prompt) > 4000) {
            throw new ConfigurationException('模型或提示词参数无效。');
        }
        
        $payload = [
            'model' => $modelId,
            'prompt' => $prompt,
            'n' => 1,
        ];
        
        $size = (string) ($options['size'] ?? '1024x1024');
        if (in_array($size, ['256x256', '512x512', '1024x1024', '1792x1024', '1024x1792'], true)) {
            $payload['size'] = $size;
        }
        
        if (isset($options['quality']) && in_array($options['quality'], ['standard', 'hd'], true)) {
            $payload['quality'] = $options['quality'];
        }
        
        if (isset($options['style']) && in_array($options['style'], ['natural', 'vivid'], true)) {
            $payload['style'] = $options['style'];
        }
        
        try {
            $response = self::doRequest($baseUrl . '/images/generations', $payload, $apiKey, 60);
            $body = json_decode((string) $response['body'], true);
            self::validateResponse($response['status'], $body);
            
            $url = (string) ($body['data'][0]['url'] ?? $body['data'][0]['b64_json'] ?? '');
            if ($url === '') {
                throw new ServiceException('模型服务没有返回图像 URL。');
            }
            
            // 图像生成不返回 usage，估算一个 token 值（DALL-E 3 约等于 prompt 长度 * 2）
            $estimatedTokens = (int) (strlen($prompt) / 4 * 2);
            $usage = ['total_tokens' => $estimatedTokens];
            
            self::logUsage($model, $options['callerPlugin'] ?? 'unknown', [
                'prompt_tokens' => $estimatedTokens,
                'completion_tokens' => 0,
                'total_tokens' => $estimatedTokens,
            ], $reservation);
            
            return [
                'url' => $url,
                'revised_prompt' => (string) ($body['data'][0]['revised_prompt'] ?? ''),
                'usage' => $usage,
            ];
        } catch (ConfigurationException | AuthenticationException | RateLimitException | QuotaExceededException | ServiceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ServiceException('图像生成请求失败：' . $e->getMessage());
        }
        });
    }

    /**
     * 语音转文字（Whisper）。
     *
     * @param array<string,mixed> $model 模型配置行（type=audio）
     * @param string $audioPath 音频文件路径（mp3/wav/m4a/...）
     * @param array<string,mixed> $options language/callerPlugin
     * @return array{text:string, usage:array{total_tokens:int}}
     * @throws ConfigurationException 配置无效或文件不存在
     * @throws AuthenticationException API Key 认证失败
     * @throws QuotaExceededException 配额耗尽或文件超过 25MB
     * @throws ServiceException 服务端错误
     */
    public static function transcribeAudio(array $model, string $audioPath, array $options = []): array
    {
        return self::withQuota($options, static function (string $reservation) use ($model, $audioPath, $options): array {
        
        $baseUrl = self::normalizeBaseUrl((string) ($model['base_url'] ?? ''));
        $modelId = trim((string) ($model['model'] ?? ''));
        $apiKey = self::decryptSecret((string) ($model['api_key'] ?? ''));
        
        if ($modelId === '' || strlen($modelId) > 160) {
            throw new ConfigurationException('模型参数无效。');
        }
        
        $uploadRoot = realpath((defined('PAFISH_ROOT') ? PAFISH_ROOT : dirname(__DIR__, 3)) . '/public/uploads');
        $realAudioPath = realpath($audioPath);
        $uploadPrefix = $uploadRoot === false ? '' : rtrim(str_replace('\\', '/', $uploadRoot), '/') . '/';
        if ($realAudioPath === false || $uploadPrefix === ''
            || !str_starts_with(str_replace('\\', '/', $realAudioPath), $uploadPrefix)
            || !is_file($realAudioPath) || !is_readable($realAudioPath)) {
            throw new ConfigurationException('音频文件不存在或不可读：' . basename($audioPath));
        }
        $audioPath = $realAudioPath;
        
        $size = filesize($audioPath);
        if ($size === false || $size === 0) {
            throw new ConfigurationException('音频文件为空。');
        }
        
        if ($size > 25 * 1024 * 1024) {
            throw new QuotaExceededException('音频文件超过 25MB 上限。');
        }
        
        try {
            $headers = ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'];
            $postFields = [
                'file' => new \CURLFile($audioPath),
                'model' => $modelId,
            ];
            
            if (isset($options['language']) && is_string($options['language']) && strlen($options['language']) === 2) {
                $postFields['language'] = $options['language'];
            }
            
            $response = OutboundHttp::request('POST', $baseUrl . '/audio/transcriptions', $postFields, $headers, 2 * 1024 * 1024, 60);
            $body = json_decode((string) ($response['body'] ?? ''), true);
            self::validateResponse((int) ($response['status'] ?? 0), is_array($body) ? $body : []);
            
            $text = (string) ($body['text'] ?? '');
            if ($text === '') {
                throw new ServiceException('模型服务没有返回转写文本。');
            }
            
            // Whisper API 不返回 usage，按文件大小估算（1MB ≈ 1000 tokens）
            $estimatedTokens = (int) ($size / 1024);
            $usage = ['total_tokens' => $estimatedTokens];
            
            self::logUsage($model, $options['callerPlugin'] ?? 'unknown', [
                'prompt_tokens' => 0,
                'completion_tokens' => $estimatedTokens,
                'total_tokens' => $estimatedTokens,
            ], $reservation);
            
            return ['text' => $text, 'usage' => $usage];
        } catch (ConfigurationException | AuthenticationException | RateLimitException | QuotaExceededException | ServiceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ServiceException('音频转写请求失败：' . $e->getMessage());
        }
        });
    }

    private static function encryptionKey(): string
    {
        $secret = (string) Config::get('auth_secret', '');
        if (strlen($secret) < 32 || str_contains($secret, 'CHANGE-ME')) {
            throw new \RuntimeException('站点 auth_secret 尚未设置为有效密钥，无法安全保存 API Key。');
        }
        return hash_hmac('sha256', 'pafish-ai-foundation-key-v1', $secret, true);
    }

    private static function table(string $logicalName): string
    {
        if (!in_array($logicalName, ['usage_log', 'quota_settings', 'quota_reservations'], true)) {
            throw new \InvalidArgumentException('AI Foundation 数据表名无效。');
        }
        return '`' . self::TABLE_PREFIX . $logicalName . '`';
    }

    /** Validate the small JSON-Schema subset used before invoking a plugin provider. */
    private static function validateToolArguments(array $args, array $schema): void
    {
        if (($schema['type'] ?? 'object') !== 'object') {
            throw new ConfigurationException('工具参数 Schema 无效。');
        }
        foreach ((array) ($schema['required'] ?? []) as $key) {
            if (is_string($key) && !array_key_exists($key, $args)) {
                throw new ConfigurationException('工具缺少必要参数：' . $key);
            }
        }
        if (($schema['additionalProperties'] ?? true) === false) {
            $allowed = array_keys((array) ($schema['properties'] ?? []));
            foreach (array_keys($args) as $key) {
                if (!in_array($key, $allowed, true)) {
                    throw new ConfigurationException('工具包含未声明参数：' . $key);
                }
            }
        }
        foreach ((array) ($schema['properties'] ?? []) as $key => $definition) {
            if (!array_key_exists($key, $args) || !is_array($definition)) continue;
            $value = $args[$key];
            $type = (string) ($definition['type'] ?? '');
            $valid = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value),
                'object' => is_array($value),
                default => true,
            };
            if (!$valid) throw new ConfigurationException('工具参数类型无效：' . $key);
        }
    }
}
