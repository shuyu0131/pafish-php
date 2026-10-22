<?php

declare(strict_types=1);

namespace Pafish\Services;

/**
 * 一次商店目录读取的结果。
 *
 * 旧接口把「条目 + 是否有问题」压进 `['items', 'base', 'error'?]`，
 * 上层无法区分「远程刚取到」「未过期的缓存」「过期兜底」「内置商店」，
 * 只能靠 error 有没有值去猜。这里把数据来源、新鲜度和提示一起打包，
 * 让调用方按 source 决定怎么渲染、能不能拿去下载。
 */
final class StoreCatalog
{
    /** 本次刚从官方源取到。 */
    public const SOURCE_REMOTE = 'remote';
    /** 未过期的本地缓存。 */
    public const SOURCE_CACHE = 'cache';
    /** 已过期但仍在可接受范围内，且远程本次不可用。 */
    public const SOURCE_STALE = 'stale';
    /** 回退到随包分发的内置商店 public/store。 */
    public const SOURCE_LOCAL = 'local';
    /** 连内置商店都读不到。 */
    public const SOURCE_EMPTY = 'empty';

    /**
     * @param array<int, array<string, mixed>> $items
     * @param int|null $fetchedAt 该批数据的原始获取时间；local/empty 为 null
     * @param int $retryAfter     失败退避剩余秒数，0 表示可立即重试
     */
    private function __construct(
        public readonly array $items,
        public readonly string $source,
        public readonly string $base,
        public readonly ?int $fetchedAt,
        public readonly string $notice,
        public readonly string $reason,
        public readonly int $retryAfter,
    ) {
    }

    public static function remote(array $items, string $base, int $fetchedAt): self
    {
        return new self($items, self::SOURCE_REMOTE, $base, $fetchedAt, '', '', 0);
    }

    public static function cached(array $items, string $base, int $fetchedAt): self
    {
        return new self($items, self::SOURCE_CACHE, $base, $fetchedAt, '', '', 0);
    }

    public static function stale(array $items, string $base, int $fetchedAt, string $reason, int $retryAfter): self
    {
        $notice = '商店目录获取失败，当前显示 ' . self::humanAge(max(0, time() - $fetchedAt)) . '前获取的目录';
        if ($reason !== '') {
            $notice .= '（' . $reason . '）';
        }
        if ($retryAfter > 0) {
            $notice .= '，约 ' . $retryAfter . ' 秒后自动重试';
        }
        return new self($items, self::SOURCE_STALE, $base, $fetchedAt, $notice, $reason, $retryAfter);
    }

    public static function local(array $items, string $reason): self
    {
        $notice = '商店目录获取失败，已回退内置商店';
        if ($reason !== '') {
            $notice .= '（' . $reason . '）';
        }
        return new self($items, self::SOURCE_LOCAL, '', null, $notice, $reason, 0);
    }

    public static function empty(string $reason): self
    {
        $notice = '商店目录暂不可用';
        if ($reason !== '') {
            $notice .= '（' . $reason . '）';
        }
        return new self([], self::SOURCE_EMPTY, '', null, $notice, $reason, 0);
    }

    /** 数据不是本次从官方源取到的：界面应提示「可能不是最新」。 */
    public function isDegraded(): bool
    {
        return in_array($this->source, [self::SOURCE_STALE, self::SOURCE_LOCAL, self::SOURCE_EMPTY], true);
    }

    /**
     * 能否据此执行安装/更新。
     *
     * 过期目录只用于让后台继续展示已知条目，不能作为下载授权来源；否则
     * 官网下架或替换包后，网络故障期间仍会按旧地址执行安装/更新。
     * 随包内置目录没有这个问题，因为包地址由本地文件系统提供。
     */
    public function isInstallable(): bool
    {
        return $this->items !== [] && in_array($this->source, [
            self::SOURCE_REMOTE,
            self::SOURCE_CACHE,
            self::SOURCE_LOCAL,
        ], true);
    }

    /** 按名称查找条目。 */
    public function find(string $name): ?array
    {
        foreach ($this->items as $item) {
            if (is_array($item) && ($item['name'] ?? '') === $name) {
                return $item;
            }
        }
        return null;
    }

    /**
     * 兼容旧的数组消费方式：['items' => …, 'base' => …, 'error' => …]。
     *
     * 仅为过渡期保留；新代码请直接读属性。
     *
     * @return array{items: array, base: string, error?: string}
     */
    public function toArray(): array
    {
        $out = ['items' => $this->items, 'base' => $this->base];
        if ($this->notice !== '') {
            $out['error'] = $this->notice;
        }
        return $out;
    }

    private static function humanAge(int $seconds): string
    {
        if ($seconds < 120) {
            return max(1, $seconds) . ' 秒';
        }
        if ($seconds < 7200) {
            return intdiv($seconds, 60) . ' 分钟';
        }
        if ($seconds < 172800) {
            return intdiv($seconds, 3600) . ' 小时';
        }
        return intdiv($seconds, 86400) . ' 天';
    }
}
