<?php

declare(strict_types=1);

namespace Pafish\Core;

/**
 * 站内重定向控制流异常：Auth 守卫抛出后，由 bootstrap 注册的中间件统一转为 302 响应，
 * 使跳转目标只经过 Url::to 拼接站内前缀这一条路径。
 */
final class RedirectException extends \RuntimeException
{
    public function __construct(public readonly string $target)
    {
        parent::__construct('redirect: ' . $target);
    }
}
