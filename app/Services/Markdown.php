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
        if (self::$parser === null) {
            self::$parser = new \ParsedownExtra();
        }

        // Keep explicitly-authored media elements usable in safe Markdown while
        // sanitizing their attributes and sources before restoring them.
        $media = [];
        $md = self::extractMediaElements($md, $media);

        // 默认安全模式；md_allow_raw_html=1 时放行原始 HTML
        self::$parser->setSafeMode((string) settings('md_allow_raw_html', '0') !== '1');
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
            'markdown' => $md,
            'safeMode' => (string) settings('md_allow_raw_html', '0') !== '1',
        ]);
        return is_string($filtered) ? $filtered : $html;
    }

    /** @param array<string,string> $media */
    private static function extractMediaElements(string $markdown, array &$media): string
    {
        if (!str_contains($markdown, '<audio') && !str_contains($markdown, '<video')) {
            return $markdown;
        }
        $sequence = 0;
        $sourceHash = substr(hash('sha256', $markdown), 0, 12);
        $parts = preg_split('/(\r\n|\n|\r)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return $markdown;
        $output = '';
        $fenceChar = '';
        $fenceLength = 0;

        for ($lineIndex = 0, $partCount = count($parts); $lineIndex < $partCount; $lineIndex += 2) {
            $line = $parts[$lineIndex];
            $newline = $parts[$lineIndex + 1] ?? '';
            $fence = preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $fenceMatch) === 1 ? $fenceMatch[1] : '';
            if ($fenceChar !== '') {
                $output .= $line . $newline;
                if ($fence !== '' && $fence[0] === $fenceChar && strlen($fence) >= $fenceLength
                    && preg_match('/^ {0,3}' . preg_quote($fence, '/') . '[ \t]*$/', $line) === 1) {
                    $fenceChar = '';
                    $fenceLength = 0;
                }
                continue;
            }
            if ($fence !== '') {
                $fenceChar = $fence[0];
                $fenceLength = strlen($fence);
                $output .= $line . $newline;
                continue;
            }
            if (preg_match('/^(?: {4}|\t)/', $line) === 1) {
                $output .= $line . $newline;
                continue;
            }
            if (!str_contains($line, '<')) {
                $output .= $line . $newline;
                continue;
            }

            $length = strlen($line);
            for ($offset = 0; $offset < $length;) {
                if ($line[$offset] === '`') {
                    $runLength = strspn($line, '`', $offset);
                    $run = str_repeat('`', $runLength);
                    $closing = strpos($line, $run, $offset + $runLength);
                    while ($closing !== false
                        && (($closing > 0 && $line[$closing - 1] === '`')
                            || ($closing + $runLength < $length && $line[$closing + $runLength] === '`'))) {
                        $closing = strpos($line, $run, $closing + $runLength);
                    }
                    if ($closing !== false) {
                        $end = $closing + $runLength;
                        $output .= substr($line, $offset, $end - $offset);
                        $offset = $end;
                        continue;
                    }
                    $output .= $run;
                    $offset += $runLength;
                    continue;
                }

                $tagStart = strpos($line, '<', $offset);
                if ($tagStart === false) {
                    $output .= substr($line, $offset);
                    break;
                }
                $codeStart = strpos($line, '`', $offset);
                if ($codeStart !== false && $codeStart < $tagStart) {
                    $output .= substr($line, $offset, $codeStart - $offset);
                    $offset = $codeStart;
                    continue;
                }
                if ($tagStart > $offset) {
                    $output .= substr($line, $offset, $tagStart - $offset);
                    $offset = $tagStart;
                    continue;
                }

                if (preg_match('/^<(audio|video)\b[^>]*>.*?<\/\1\s*>/is', substr($line, $offset), $match) === 1) {
                    $safeHtml = Content::sanitize($match[0]);
                    if (preg_match('/^<(?:audio|video)\b/i', $safeHtml)) {
                        $marker = 'PAFISHMEDIA' . $sourceHash . strtoupper(base_convert((string) $sequence++, 10, 36)) . 'TOKEN';
                        $media[$marker] = $safeHtml;
                        $output .= $marker;
                        $offset += strlen($match[0]);
                        continue;
                    }
                }
                $output .= $line[$offset];
                ++$offset;
            }
            $output .= $newline;
        }
        return $output;
    }

}
