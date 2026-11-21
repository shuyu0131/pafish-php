<?php

declare(strict_types=1);

namespace Pafish\Http;

use Psr\Http\Message\StreamInterface;

/**
 * 扩展路由 response:stream 的响应体：真正的流式输出。
 *
 * Slim 先发完响应头，再进入 emitBody() 的读取循环；第一次 read() 时才执行回调，
 * 回调自己 echo + flush，所以模型产出的每个片段都能立刻到达浏览器。
 * 回调返回值会被忽略，因此这里始终读出空字符串。
 */
final class StreamedBody implements StreamInterface
{
    /** @var callable():void */
    private $callback;
    private bool $done = false;

    /** @param callable():void $callback */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    public function detach()
    {
        $this->done = true;
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        // 不能在 eof() 里执行回调：ResponseEmitter 会先用 eof() 判断响应是否为空，
        // 那时响应头还没发出去。真正的工作只放在 read()/getContents()。
        return $this->done;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('流式响应体不可定位');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('流式响应体不可重放');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('流式响应体不可写入');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if (!$this->done) {
            $this->run();
        }
        return '';
    }

    public function getContents(): string
    {
        if (!$this->done) {
            $this->run();
        }
        return '';
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }

    /** 回调内部已经负责输出与刷新，这里只保证它只跑一次并交出缓冲区。 */
    private function run(): void
    {
        $this->done = true;
        $callback = $this->callback;
        $this->callback = static function (): void {
        };
        $callback();
    }
}
