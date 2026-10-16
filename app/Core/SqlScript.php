<?php

declare(strict_types=1);

namespace Pafish\Core;

/** MySQL script lexer: comments/directives are recognized only outside quoted values. */
final class SqlScript
{
    public static function split(string $sql, bool $noBackslashEscapes = false, bool $ansiQuotes = false): array
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new \RuntimeException('无法创建 SQL 解析缓冲区');
        try {
            for ($offset=0,$length=strlen($sql);$offset<$length;) {
                $written = fwrite($stream, substr($sql, $offset));
                if ($written === false || $written === 0) throw new \RuntimeException('无法写入 SQL 解析缓冲区');
                $offset += $written;
            }
            rewind($stream);
            return iterator_to_array(self::read($stream, false, static fn (): array => [$noBackslashEscapes, $ansiQuotes]), false);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream @return \Generator<int, string> */
    public static function read($stream, bool $backup = false, ?callable $modes = null): \Generator
    {
        $buffer = '';
        $quote = null;
        $block = false;
        $executable = false;
        $escaped = false;
        $delimiter = ';';
        $first = true;
        [$noBackslashEscapes, $ansiQuotes] = $modes ? $modes() : [false, false];
        while (($line = fgets($stream)) !== false) {
            if ($first) { $line = preg_replace('/^\xEF\xBB\xBF/', '', $line); $first = false; }
            if ($quote === null && !$block && trim($buffer) === ''
                && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)) {
                if (!$backup) throw new \RuntimeException('迁移不支持自定义 SQL 分隔符');
                $delimiter = $match[1];
                if (strlen($delimiter) > 16 || strpbrk($delimiter, "'\"\\" . chr(96) . '#') !== false
                    || str_contains($delimiter, '--') || str_contains($delimiter, '/*')) {
                    throw new \RuntimeException('SQL 分隔符无效');
                }
                continue;
            }
            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];
                $next = $line[$i + 1] ?? '';
                if ($block) {
                    if ($executable) $buffer .= $char;
                    if ($char === '*' && $next === '/') {
                        if ($executable) $buffer .= '/';
                        $i++;
                        $block = false;
                    }
                    continue;
                }
                if ($quote !== null) {
                    $buffer .= $char;
                    if ($escaped) { $escaped = false; continue; }
                    if ($char === '\\' && !$noBackslashEscapes && $quote !== chr(96)
                        && !($ansiQuotes && $quote === '"')) {
                        $escaped = true;
                    } elseif ($char === $quote) {
                        if ($next === $quote) { $buffer .= $next; $i++; }
                        else $quote = null;
                    }
                    continue;
                }
                if (substr($line, $i, strlen($delimiter)) === $delimiter) {
                    if (trim($buffer) !== '') {
                        yield trim($buffer);
                        [$noBackslashEscapes, $ansiQuotes] = $modes ? $modes() : [false, false];
                    }
                    $buffer = '';
                    $i += strlen($delimiter) - 1;
                } elseif ($char === "'" || $char === '"' || $char === chr(96)) {
                    $quote = $char;
                    $buffer .= $char;
                } elseif ($char === '#' || ($char === '-' && $next === '-'
                    && ($i + 2 === $length || ord($line[$i + 2]) <= 32))) {
                    $buffer .= "\n";
                    break;
                } elseif ($char === '/' && $next === '*') {
                    $executable = ($line[$i + 2] ?? '') === '!' || strtoupper(substr($line, $i + 2, 2)) === 'M!';
                    if ($executable && !$backup) throw new \RuntimeException('迁移不支持可执行 SQL 注释，请写成普通语句');
                    $buffer .= $executable ? '/*' : ' ';
                    $block = true;
                    $i++;
                } else $buffer .= $char;
            }
        }
        if (!feof($stream)) throw new \RuntimeException('读取 SQL 文件失败');
        if ($quote !== null) throw new \RuntimeException('SQL 引号未闭合');
        if ($block) throw new \RuntimeException('SQL 注释未闭合');
        if (trim($buffer) !== '') yield trim($buffer);
    }
}
