<?php

declare(strict_types=1);

namespace Pafish\Core;

/**
 * 应用日志写入器：统一位置、脱敏、锁写和有限轮转。
 * runtime/ 由安装器创建；日志写入失败时静默返回，避免错误处理再次递归报错。
 */
final class Logger
{
    private const MAX_BYTES = 2097152;
    private const MAX_ROTATED_FILES = 2;
    private const MAX_ENTRY_BYTES = 65536;

    public static function error(string $message): void
    {
        $message = self::sanitize($message);
        if ($message === '') {
            $message = 'unknown error';
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        if (strlen($line) > self::MAX_ENTRY_BYTES) {
            $line = self::truncate($line, self::MAX_ENTRY_BYTES - strlen(PHP_EOL)) . PHP_EOL;
        }

        $runtime = defined('PAFISH_ROOT') ? PAFISH_ROOT . '/runtime' : dirname(__DIR__, 2) . '/runtime';
        if (!is_dir($runtime) && !@mkdir($runtime, 0750, true) && !is_dir($runtime)) {
            return;
        }

        $path = $runtime . '/error.log';
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return;
        }

        try {
            clearstatcache(true, $path);
            $size = is_file($path) ? (int) @filesize($path) : 0;
            if ($size > 0 && $size + strlen($line) > self::MAX_BYTES) {
                self::rotate($path);
            }
            @file_put_contents($path, $line, FILE_APPEND);
            @chmod($path, 0640);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function exception(string $message, \Throwable $exception): void
    {
        self::error($message . ': ' . $exception->getMessage() . PHP_EOL . $exception->getTraceAsString());
    }

    public static function redactQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $redacted = preg_replace_callback(
            '/(^|&)([^=&]*)(=)([^&]*)/',
            static function (array $match): string {
                $key = rawurldecode((string) $match[2]);
                if (preg_match('/(?:pass(word)?|token|secret|api[_-]?key|authorization|cookie|csrf|code|signature)/i', $key) === 1) {
                    return $match[1] . $match[2] . $match[3] . '[REDACTED]';
                }
                return $match[0];
            },
            $query
        );

        return is_string($redacted) ? $redacted : '[INVALID_QUERY]';
    }

    private static function sanitize(string $message): string
    {
        $message = preg_replace(
            '/((?:password|passwd|token|secret|api[_-]?key|authorization|cookie|csrf|signature)\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&]+)/i',
            '$1[REDACTED]',
            $message
        ) ?? $message;
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = preg_replace('/[^\P{C}\n\t]/u', '?', $message) ?? $message;
        return self::truncate($message, self::MAX_ENTRY_BYTES);
    }

    private static function truncate(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, $bytes, 'UTF-8') . '...[truncated]';
        }
        return substr($value, 0, $bytes) . '...[truncated]';
    }

    private static function rotate(string $path): void
    {
        $oldest = $path . '.' . self::MAX_ROTATED_FILES;
        if (is_file($oldest)) {
            @unlink($oldest);
        }
        for ($index = self::MAX_ROTATED_FILES - 1; $index >= 1; $index--) {
            $from = $path . '.' . $index;
            $to = $path . '.' . ($index + 1);
            if (is_file($from)) {
                @rename($from, $to);
            }
        }
        if (is_file($path)) {
            @rename($path, $path . '.1');
        }
    }
}
