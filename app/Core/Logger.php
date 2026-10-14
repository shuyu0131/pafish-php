<?php

declare(strict_types=1);

namespace Pafish\Core;

/**
 * 应用日志写入器：统一位置、脱敏、去重、锁写和有限轮转。
 * runtime/ 由安装器创建；日志写入失败时静默返回，避免错误处理再次递归报错。
 *
 * 关于"日志短时间内涨到几 MB"：
 * 单个持续故障（如数据库连不上）每个请求都会完整落一次堆栈，实测约 350 B/次。
 * 日访问 1 万次的站点遇到这种故障，一天就能写出约 3.3 MB，并很快击穿 2 MB
 * 轮转阈值——而轮转恰恰会冲掉最有价值的那段历史。因此这里对**相同故障**做
 * 窗口内合并：首次完整记录，后续只累加次数，请求结束时补一行汇总。
 */
final class Logger
{
    private const MAX_BYTES = 2097152;
    private const MAX_ROTATED_FILES = 2;
    private const MAX_ENTRY_BYTES = 65536;

    /** 相同故障的合并窗口（秒）。 */
    public const DEDUP_WINDOW = 300;

    /** 汇总行前缀，便于统计时识别与排除。 */
    public const SUMMARY_PREFIX = '[pafish-logger] 相同故障已合并';

    /** 指纹 → [重复次数]；请求级，同一请求内相同故障只落一次盘。 */
    private static array $dedup = [];

    public static function error(string $message): void
    {
        $message = self::sanitize($message);
        if ($message === '') {
            $message = 'unknown error';
        }

        if (self::shouldSuppress($message)) {
            return;
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

    /**
     * 相同故障的窗口内去重。
     *
     * 指纹取「去掉数字后的前 200 字符」——时间、ID、行号不同但本质相同的故障
     * 归为同一类。同一请求内重复出现只累加计数。
     *
     * @return bool true 表示本次应丢弃（已在窗口内记录过）
     */
    private static function shouldSuppress(string $message): bool
    {
        // 汇总行本身不参与去重
        if (str_starts_with($message, self::SUMMARY_PREFIX)) {
            return false;
        }

        $fingerprint = md5((string) preg_replace('/\d+/', 'N', mb_substr($message, 0, 200)));

        if (isset(self::$dedup[$fingerprint])) {
            self::$dedup[$fingerprint]++;
            return true;
        }

        self::$dedup[$fingerprint] = 1;
        return false;
    }

    /**
     * 请求结束时把被合并掉的次数汇总成一行。
     *
     * 由 bootstrap 的 shutdown 钩子调用；没有抑制过任何条目时不写。
     */
    public static function flushSuppressed(): void
    {
        foreach (self::$dedup as $count) {
            if ($count <= 1) {
                continue;
            }
            self::writeRaw(
                self::SUMMARY_PREFIX . '：' . self::DEDUP_WINDOW . ' 秒窗口内重复出现 '
                . ($count - 1) . ' 次（首次已完整记录，内容见上方同指纹条目）'
            );
        }
        self::$dedup = [];
    }

    /** 跳过脱敏与去重直接落盘（仅供内部汇总行使用）。 */
    private static function writeRaw(string $message): void
    {
        $runtime = defined('PAFISH_ROOT') ? PAFISH_ROOT . '/runtime' : dirname(__DIR__, 2) . '/runtime';
        if (!is_dir($runtime)) {
            return;
        }
        @file_put_contents(
            $runtime . '/error.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    public static function exception(string $message, \Throwable $exception): void
    {
        self::error($message . ': ' . $exception->getMessage() . PHP_EOL . self::trace($exception));
    }

    /**
     * 压缩堆栈：去掉站点绝对路径并限制帧数。
     *
     * 实测堆栈占日志体积的 66%，且每帧都重复 ~255 字节的绝对路径
     * （`C:\Users\xxx\ZCodeProject\pafish-php\vendor\...`）。这里把根路径替换成
     * `<root>`，并只保留前 12 帧，既能定位又不淹没日志。
     */
    private static function trace(\Throwable $exception): string
    {
        $frames = $exception->getTrace();
        $out = [];
        $limit = 12;
        foreach ($frames as $i => $frame) {
            if ($i >= $limit) {
                $out[] = '#' . $i . ' ...（其余 ' . (count($frames) - $limit) . ' 帧已省略）';
                break;
            }
            $file = isset($frame['file']) ? self::relPath((string) $frame['file']) : '[internal]';
            $func = (string) ($frame['class'] ?? '') . (string) ($frame['type'] ?? '') . (string) ($frame['function'] ?? '');
            $out[] = '#' . $i . ' ' . $file . '(' . (int) ($frame['line'] ?? 0) . '): ' . $func . '()';
        }
        if ($out === []) {
            return '#0 ' . self::relPath($exception->getFile()) . '(' . $exception->getLine() . '): {main}';
        }
        return implode(PHP_EOL, $out);
    }

    /** 把站点根路径替换为 <root>，缩短堆栈长度。 */
    private static function relPath(string $path): string
    {
        $root = defined('PAFISH_ROOT') ? PAFISH_ROOT : dirname(__DIR__, 2);
        if ($root !== '' && str_starts_with($path, $root)) {
            return '<root>' . str_replace('\\', '/', substr($path, strlen($root)));
        }
        return str_replace('\\', '/', $path);
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
                if (self::isSensitiveKey($key)) {
                    return $match[1] . $match[2] . $match[3] . '[REDACTED]';
                }
                return $match[0];
            },
            $query
        );

        return is_string($redacted) ? $redacted : '[INVALID_QUERY]';
    }

    /**
     * 判断一个键名是否属于敏感信息。
     *
     * 用“子串包含”而不是“整词匹配”：`smtp_pass`、`db_password`、`user_pass`
     * 里的 `pass` 前面是下划线（属于 \w），旧写法 `\bpass(word)?\b` 匹配不到，
     * 导致这些最关键的字段名反而不脱敏。
     */
    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach ([
            'pass', 'pwd', 'secret', 'token', 'code', 'api_key', 'apikey', 'api-key',
            'authorization', 'auth', 'cookie', 'session', 'csrf', 'signature',
            'credential', 'private', 'salt', 'bearer',
        ] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function sanitize(string $message): string
    {
        // 1) 先处理 DSN 形式（mysql://user:pass@host），整体替换凭据段。
        //    这类串没有 key=value 结构，靠下面的规则永远匹配不到。
        $message = preg_replace(
            '#([a-z][a-z0-9+.\-]*://[^:/\s@]+):([^@\s/]+)@#i',
            '$1:[REDACTED]@',
            $message
        ) ?? $message;

        // 2) key=value / key: value / key=>value / "key":"value" / [key] => value
        //    统一按“敏感键名 + 分隔符 + 值”脱敏；键名允许任意前后缀（smtp_pass、db_password）。
        //    键名后允许一个闭合引号，覆盖 JSON 的 "smtp_pass":"..." 形态。
        $message = preg_replace_callback(
            '/([A-Za-z0-9_.\-\[\]]*(?:pass|pwd|secret|token|api[_-]?key|authorization|auth|cookie|session|csrf|signature|credential|private|salt|bearer)[A-Za-z0-9_.\-\[\]]*)'
            . '("?)'
            . '(\s*(?:=>|=|:)\s*)'
            . '("[^"]*"|\'[^\']*\'|Bearer\s+\S+|[^\s,;&\]\)\}]+)/i',
            static fn (array $m): string => $m[1] . $m[2] . $m[3] . '[REDACTED]',
            $message
        ) ?? $message;

        // 3) 兜底：单独出现的 Bearer <token>（无键名）
        $message = preg_replace('/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [REDACTED]', $message) ?? $message;

        // 4) 兜底：裸会话 ID（PHPSESSID=...、session_id=... 等）
        $message = preg_replace(
            '/\b(PHPSESSID|JSESSIONID|session[_-]?id)\b(\s*=?\s*)[A-Za-z0-9]{6,}/i',
            '$1$2[REDACTED]',
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
