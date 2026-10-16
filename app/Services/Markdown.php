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

        // 默认安全模式；md_allow_raw_html=1 时放行原始 HTML
        self::$parser->setSafeMode((string) settings('md_allow_raw_html', '0') !== '1');
        $html = self::$parser->text($md);

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

}
