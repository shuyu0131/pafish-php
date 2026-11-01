<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Hooks;

/** Markdown 渲染与安全过滤。 */
final class Markdown
{
    private static ?\ParsedownExtra $parser = null;

    public static function render(string $md): string
    {
        $md = (string) $md;
        if ($md === '') {
            return '';
        }
        $sourceMarkdown = $md;
        if (self::$parser === null) {
            self::$parser = new \ParsedownExtra();
        }

        // Keep explicitly-authored media elements usable in safe Markdown while
        // sanitizing their attributes and sources before restoring them.
        $media = [];
        $md = self::extractMediaElements($md, $media);

        // 默认安全模式；后台复选框保存的是 "true"，旧安装也可能保存 1/yes。
        $allowRawHtml = self::rawHtmlAllowed();
        self::$parser->setSafeMode(!$allowRawHtml);
        $html = self::$parser->text($md);
        if ($media !== []) {
            $html = strtr($html, $media);
        }

        // 正文链接新窗口；页内锚点（含脚注往返）保留当前页面。
        $html = preg_replace_callback(
            '/<a href="([^"]+)"/',
            static fn (array $m): string => str_starts_with($m[1], '#') ? $m[0]
                : '<a href="' . $m[1] . '" target="_blank" rel="noopener noreferrer"',
            $html
        );

        // 宽表格包一层滚动容器
        $html = str_replace('<table>', '<div class="md-table-wrap"><table>', $html);
        $html = str_replace('</table>', '</table></div>', $html);

        // GFM 任务列表：- [ ] / - [x] → 复选框
        $html = preg_replace_callback(
            '/<li>(\s*<p>)?\[([ xX])\]\s*/',
            static fn (array $m): string => '<li>' . ($m[1] ?? '') . '<input type="checkbox" disabled' . ($m[2] !== ' ' ? ' checked' : '') . '> ',
            $html
        );

        $filtered = Hooks::applyFilters('markdown_html', $html, [
            // Filters receive the author's source, not the internal media
            // placeholders used while Parsedown is running.
            'markdown' => $sourceMarkdown,
            'safeMode' => !$allowRawHtml,
        ]);
        return is_string($filtered) ? $filtered : $html;
    }

    private static function rawHtmlAllowed(): bool
    {
        $value = settings('md_allow_raw_html', '0');
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value === 1;
        if (is_float($value)) return $value === 1.0;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @param array<string,string> $media */
    private static function extractMediaElements(string $markdown, array &$media): string
    {
        if (stripos($markdown, '<audio') === false && stripos($markdown, '<video') === false) {
            return $markdown;
        }
        $sequence = 0;
        $sourceHash = substr(hash('sha256', $markdown), 0, 12);
        $protected = self::codeRanges($markdown);
        $elements = [];
        $stack = [];
        $length = strlen($markdown);
        for ($offset = 0; $offset < $length;) {
            $start = strpos($markdown, '<', $offset);
            if ($start === false) break;
            $offset = $start + 1;
            if (self::rangeIndexAt($start, $protected) !== null) continue;
            if (preg_match('~\G<\/?(audio|video)(?=[\s/>])~i', $markdown, $tagMatch, 0, $start) !== 1) continue;
            $tag = strtolower($tagMatch[1]);
            $closing = ($markdown[$start + 1] ?? '') === '/';
            $tagEnd = null;
            $quote = '';
            $lastOutside = '';
            for ($cursor = $start + strlen($tagMatch[0]); $cursor < $length; ++$cursor) {
                $character = $markdown[$cursor];
                if ($quote !== '') {
                    if ($character === $quote) $quote = '';
                    continue;
                }
                if ($character === '"' || $character === "'") {
                    $quote = $character;
                } elseif ($character === '>') {
                    $tagEnd = $cursor;
                    break;
                } elseif (!ctype_space($character)) {
                    $lastOutside = $character;
                }
            }
            // An unterminated tag makes the rest of this source an HTML tag;
            // stop here rather than repeatedly scanning its suffix.
            if ($tagEnd === null) break;
            $offset = $tagEnd + 1;
            if (!$closing) {
                if ($lastOutside !== '/') $stack[] = ['tag' => $tag, 'start' => $start];
                continue;
            }
            $top = count($stack) - 1;
            if ($top < 0 || $stack[$top]['tag'] !== $tag) {
                $stack = [];
                continue;
            }
            $element = array_pop($stack);
            if ($stack === []) $elements[] = [$element['start'], $tagEnd + 1];
        }

        $replacements = [];
        foreach ($elements as [$start, $end]) {
            if (self::overlapsCodeRange($start, $end, $protected)) {
                continue;
            }
            $candidate = substr($markdown, $start, $end - $start);
            $safeHtml = Content::sanitize($candidate);
            if (preg_match('/^<(?:audio|video)\b/i', $safeHtml) !== 1) continue;
            $marker = 'PAFISHMEDIA' . $sourceHash . strtoupper(base_convert((string) $sequence++, 10, 36)) . 'TOKEN';
            $media[$marker] = $safeHtml;
            $replacements[] = [$start, $end - $start, $marker];
        }
        for ($i = count($replacements) - 1; $i >= 0; --$i) {
            [$start, $length, $marker] = $replacements[$i];
            $markdown = substr_replace($markdown, $marker, $start, $length);
        }
        return $markdown;
    }

    /** @return list<array{0:int,1:int}> */
    private static function codeRanges(string $markdown): array
    {
        $ranges = [];
        $parts = preg_split('/(\r\n|\n|\r)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return $ranges;
        $offset = 0;
        $fenceChar = '';
        $fenceLength = 0;
        $fenceStart = 0;
        for ($i = 0, $count = count($parts); $i < $count; $i += 2) {
            $line = (string) ($parts[$i] ?? '');
            $newline = (string) ($parts[$i + 1] ?? '');
            $lineEnd = $offset + strlen($line . $newline);
            $fence = preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m) === 1 ? $m[1] : '';
            if ($fenceChar !== '') {
                if ($fence !== '' && $fence[0] === $fenceChar && strlen($fence) >= $fenceLength
                    && preg_match('/^ {0,3}' . preg_quote($fence, '/') . '[ \t]*$/', $line) === 1) {
                    self::appendRange($ranges, $fenceStart, $lineEnd);
                    $fenceChar = '';
                    $fenceLength = 0;
                }
            } elseif ($fence !== '') {
                $fenceChar = $fence[0];
                $fenceLength = strlen($fence);
                $fenceStart = $offset;
            } elseif (preg_match('/^(?: {4}|\t)/', $line) === 1) {
                self::appendRange($ranges, $offset, $lineEnd);
            }
            $offset = $lineEnd;
        }
        if ($fenceChar !== '') self::appendRange($ranges, $fenceStart, strlen($markdown));

        // Protect inline code spans, including spans that cross a line break.
        $length = strlen($markdown);
        $inlineRanges = [];
        $blockIndex = 0;
        for ($i = 0; $i < $length;) {
            while (isset($ranges[$blockIndex]) && $ranges[$blockIndex][1] <= $i) ++$blockIndex;
            if ($markdown[$i] !== '`' || (isset($ranges[$blockIndex]) && $ranges[$blockIndex][0] <= $i)) { ++$i; continue; }
            $runLength = strspn($markdown, '`', $i);
            $run = str_repeat('`', $runLength);
            $closing = strpos($markdown, $run, $i + $runLength);
            while ($closing !== false && ($rangeIndex = self::rangeIndexAt($closing, $ranges)) !== null) {
                $closing = strpos($markdown, $run, $ranges[$rangeIndex][1]);
            }
            if ($closing === false) { $i += $runLength; continue; }
            $end = $closing + $runLength;
            self::appendRange($inlineRanges, $i, $end);
            $i = $end;
        }
        return self::mergeRanges($ranges, $inlineRanges);
    }

    /** @param list<array{0:int,1:int}> $ranges */
    private static function appendRange(array &$ranges, int $start, int $end): void
    {
        $last = count($ranges) - 1;
        if ($last >= 0 && $ranges[$last][1] >= $start) {
            $ranges[$last][1] = max($ranges[$last][1], $end);
            return;
        }
        $ranges[] = [$start, $end];
    }

    /** @param list<array{0:int,1:int}> $ranges */
    private static function rangeIndexAt(int $offset, array $ranges): ?int
    {
        $low = 0;
        $high = count($ranges) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($offset < $ranges[$middle][0]) $high = $middle - 1;
            elseif ($offset >= $ranges[$middle][1]) $low = $middle + 1;
            else return $middle;
        }
        return null;
    }

    /** @param list<array{0:int,1:int}> $left @param list<array{0:int,1:int}> $right @return list<array{0:int,1:int}> */
    private static function mergeRanges(array $left, array $right): array
    {
        $merged = [];
        $i = 0;
        $j = 0;
        while (isset($left[$i]) || isset($right[$j])) {
            if (!isset($right[$j]) || (isset($left[$i]) && $left[$i][0] <= $right[$j][0])) $range = $left[$i++];
            else $range = $right[$j++];
            self::appendRange($merged, $range[0], $range[1]);
        }
        return $merged;
    }

    /** @param list<array{0:int,1:int}> $ranges */
    private static function overlapsCodeRange(int $start, int $end, array $ranges): bool
    {
        $index = self::rangeIndexAt($start, $ranges);
        if ($index !== null) return true;
        $low = 0;
        $high = count($ranges) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($ranges[$middle][0] < $start) $low = $middle + 1;
            else $high = $middle - 1;
        }
        return isset($ranges[$low]) && $ranges[$low][0] < $end;
    }

}
