<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Hooks;

/**
 * Markdown 渲染（ParsedownExtra：GFM 表格/删除线/围栏代码等）
 * 与 Node 版 markdown-render.tsx 对齐：
 * - 全部链接新窗口打开（target="_blank" rel="noopener noreferrer"）
 * - 宽表格包 .md-table-wrap 滚动容器（窄屏不撑破版面）
 * - GFM 任务列表 → 复选框
 * - 代码块交给前台 highlight.js 上色（.hljs 样式已在 style.css）
 *
 * 安全：默认开启 Parsedown safe mode（原始 HTML 转义为文本、危险链接协议清洗），
 * 对齐 Node 版 react-markdown（默认不渲染原始 HTML），防止文章内容存储型 XSS。
 * 后台设置 md_allow_raw_html=1（仅管理员可改）时放行原始 HTML。
 * 游客评论等用户输入不走本服务。
 */
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
        // 默认安全模式（对齐 Node react-markdown）；md_allow_raw_html=1 时放行原始 HTML
        self::$parser->setSafeMode((string) settings('md_allow_raw_html', '0') !== '1');
        $html = self::$parser->text($md);

        // 全部链接新窗口（与 Node 版 a 组件一致）
        $html = preg_replace_callback(
            '/<a href="([^"]+)"/',
            static fn (array $m): string => '<a href="' . $m[1] . '" target="_blank" rel="noopener noreferrer"',
            $html
        );

        // 宽表格包一层滚动容器
        $html = str_replace('<table>', '<div class="md-table-wrap"><table>', $html);
        $html = str_replace('</table>', '</table></div>', $html);

        // GFM 任务列表：- [ ] / - [x] → 复选框
        $html = preg_replace_callback(
            '/<li>\[([ xX])\]\s*/',
            static fn (array $m): string => '<li><input type="checkbox" disabled' . ($m[1] !== ' ' ? ' checked' : '') . '> ',
            $html
        );

        $filtered = Hooks::applyFilters('markdown_html', $html, [
            'markdown' => $md,
            'safeMode' => (string) settings('md_allow_raw_html', '0') !== '1',
        ]);
        return is_string($filtered) ? $filtered : $html;
    }
}
