<?php

declare(strict_types=1);

namespace AiFoundation;

/**
 * AI Foundation 异常基类。
 *
 * 所有具体异常都继承此类，便于调用方统一捕获。
 */
abstract class AiException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * 配置错误：base_url 无效、model 为空、必填字段缺失等。
 *
 * 调用方应提示管理员检查模型配置。
 */
final class ConfigurationException extends AiException {}

/**
 * 认证失败：API Key 无效、过期或无权限。
 *
 * 调用方应提示管理员检查 API Key 或账户状态。
 */
final class AuthenticationException extends AiException {}

/**
 * 频率限制：请求过于频繁，触发服务端 rate limit。
 *
 * 调用方应延迟重试或建议管理员升级配额。
 */
final class RateLimitException extends AiException {}

/**
 * 配额耗尽：余额不足、Token 用尽或达到自定义配额上限。
 *
 * 调用方应提示管理员充值或调整配额设置。
 */
final class QuotaExceededException extends AiException {}

/**
 * 服务异常：模型服务端返回 5xx 或网络超时。
 *
 * 调用方可短暂重试；多次失败应降级或提示用户。
 */
final class ServiceException extends AiException {}
