<?php

declare(strict_types=1);

namespace AiFoundation;

/**
 * 手动模型健康检查；列表仅展示最近检查结果，不触发付费请求。
 */
final class HealthCheck
{
    private const CACHE_TTL_SECONDS = 300;

    public static function cachedStatus(int $modelId): ?array
    {
        $health = self::getCached($modelId);
        if ($health === null || ($health['version'] ?? 0) !== 2 || !in_array($health['status'] ?? '', ['ok', 'warn', 'error'], true)
            || !is_numeric($health['latency_ms'] ?? null) || !is_string($health['error'] ?? null)
            || !is_numeric($health['checked_at'] ?? null)
            || time() - (int) $health['checked_at'] >= self::CACHE_TTL_SECONDS) {
            return null;
        }
        return $health;
    }

    /**
     * 检查指定模型的健康状态（带缓存）。
     *
     * @return array{status:'ok'|'warn'|'error', latency_ms:int, error:string, detail?:string, checked_at:int, version:int}
     */
    public static function check(array $model, bool $refresh = false): array
    {
        if (($model['type'] ?? '') !== 'chat') {
            throw new ConfigurationException('检查仅支持聊天类型模型。');
        }
        $cached = $refresh ? null : self::cachedStatus((int) ($model['id'] ?? 0));
        if ($cached !== null) {
            return $cached;
        }
        
        $start = microtime(true);
        try {
            $result = ModelGateway::probeChat($model);
            $latency = (int) ((microtime(true) - $start) * 1000);
            
            $status = 'ok';
            if ($latency > 2000 || $result['detail'] !== '') $status = 'warn';
            
            $health = ['status' => $status, 'latency_ms' => $latency, 'error' => '', 'detail' => $result['detail'], 'checked_at' => time()];
        } catch (AuthenticationException $e) {
            $health = ['status' => 'error', 'latency_ms' => 0, 'error' => 'API Key 无效', 'checked_at' => time()];
        } catch (RateLimitException $e) {
            $health = ['status' => 'error', 'latency_ms' => 0, 'error' => '服务商限流，请稍后重试。', 'checked_at' => time()];
        } catch (QuotaExceededException $e) {
            $health = ['status' => 'error', 'latency_ms' => 0, 'error' => $e->getMessage(), 'checked_at' => time()];
        } catch (AiException $e) {
            $health = ['status' => 'error', 'latency_ms' => 0, 'error' => $e->getMessage(), 'checked_at' => time()];
        } catch (\Throwable $e) {
            $health = ['status' => 'error', 'latency_ms' => 0, 'error' => '检查失败，请检查模型配置及服务器日志。', 'checked_at' => time()];
        }
        $health['version'] = 2;
        
        self::setCached((int) ($model['id'] ?? 0), $health);
        return $health;
    }

    private static function getCached(int $modelId): ?array
    {
        $cacheFile = __DIR__ . '/../runtime/health_' . $modelId . '.json';
        if (!is_file($cacheFile)) return null;
        
        $data = json_decode((string) file_get_contents($cacheFile), true);
        return is_array($data) ? $data : null;
    }

    private static function setCached(int $modelId, array $health): void
    {
        $runtimeDir = __DIR__ . '/../runtime';
        if (!is_dir($runtimeDir)) @mkdir($runtimeDir, 0755, true);
        
        $cacheFile = $runtimeDir . '/health_' . $modelId . '.json';
        @file_put_contents($cacheFile, json_encode($health, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
