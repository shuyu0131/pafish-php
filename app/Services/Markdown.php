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

        // 1) 占位符保护（先块后行内）：公式内容不受 Parsedown 干扰（_ * \ 等符号原样保留）
        $marks = self::protectMath($md);
        $md = $marks['text'];

        // 2) 默认安全模式；md_allow_raw_html=1 时放行原始 HTML
        self::$parser->setSafeMode((string) settings('md_allow_raw_html', '0') !== '1');
        $html = self::$parser->text($md);

        // 3) 还原公式标记（公式内容实体化后进 HTML，前台 KaTeX 读 textContent 自动解码）
        if ($marks['inline'] !== [] || $marks['block'] !== []) {
            $html = self::restoreMath($html, $marks);
        }

        // 4) 全部链接新窗口
        $html = preg_replace_callback(
            '/<a href="([^"]+)"/',
            static fn (array $m): string => '<a href="' . $m[1] . '" target="_blank" rel="noopener noreferrer"',
            $html
        );

        // 5) 宽表格包一层滚动容器
        $html = str_replace('<table>', '<div class="md-table-wrap"><table>', $html);
        $html = str_replace('</table>', '</table></div>', $html);

        // 6) GFM 任务列表：- [ ] / - [x] → 复选框
        $html = preg_replace_callback(
            '/<li>\[([ xX])\]\s*/',
            static fn (array $m): string => '<li><input type="checkbox" disabled' . ($m[1] !== ' ' ? ' checked' : '') . '> ',
            $html
        );

        // 7) mermaid / flowchart 围栏（```mermaid）→ .md-mermaid 容器，前台脚本按需执行
        $html = preg_replace_callback(
            '/<pre><code class="language-(mermaid|mindmap|flowchart)">([\s\S]*?)<\/code><\/pre>/',
            static fn (array $m): string => '<div class="md-mermaid" data-md-mermaid="' . $m[1] . '">' . $m[2] . '</div>',
            $html
        );

        $filtered = Hooks::applyFilters('markdown_html', $html, [
            'markdown' => $md,
            'safeMode' => (string) settings('md_allow_raw_html', '0') !== '1',
        ]);
        return is_string($filtered) ? $filtered : $html;
    }

    /** 提取公式并用占位符替换；返回占位符文本与内容表 */
    private static function protectMath(string $md): array
    {
        $inline = [];
        $block = [];
        $tpl = '۞PAFISHMD-%s-%d۞'; // 罕见标记，防止与正文冲突（含冲突时下方升级）

        // 块公式 $$...$$（独立成行，可跨行；先处理，行内不会碰已占位内容）
        $blockIx = -1;
        $md = preg_replace_callback(
            '/^[ \t]*\$\$([^$]+?)\$\$[ \t]*$/m',
            function (array $m) use (&$block, &$blockIx, $tpl): string {
                $blockIx++;
                $block[$blockIx] = $m[1];
                return sprintf($tpl, 'b', $blockIx);
            },
            $md
        );
        // 行内公式 $...$（单行、不含 $；前字符为 \ 时视为转义字面 $；右 $ 后紧跟数字不算公式，避免“$5 和 $10”误配对）
        $md = preg_replace_callback(
            '/(^|[^\\\\$])\$([^$\n]+?)\$(?!(?:[0-9]|\$))/',
            function (array $m) use (&$inline, $tpl): string {
                $inline[] = $m[2];
                return $m[1] . sprintf($tpl, 'i', count($inline) - 1);
            },
            $md
        );
        return ['text' => $md, 'inline' => $inline, 'block' => $block];
    }

    /** 将渲染 HTML 中的占位符还原为带标记的公式节点（内容实体化，KaTeX 读 textContent 解码） */
    private static function restoreMath(string $html, array $marks): string
    {
        $html = preg_replace_callback(
            '/۞PAFISHMD-i-(\d+)۞/',
            static fn (array $m): string => '<span class="md-math">$' . htmlspecialchars((string) ($marks['inline'][(int) $m[1]] ?? ''), ENT_NOQUOTES, 'UTF-8') . '$</span>',
            $html
        );
        // 块公式：整段占位（<p> 包裹或裸占位）→ 独立公式节点；其余兜底按行内公式处理
        $html = preg_replace_callback(
            '/<p>۞PAFISHMD-b-(\d+)۞<\/p>|۞PAFISHMD-b-(\d+)۞/',
            static function (array $m) use ($marks): string {
                $ix = (int) (($m[1] ?? '') !== '' ? $m[1] : $m[2]);
                $inner = '$$' . htmlspecialchars((string) ($marks['block'][$ix] ?? ''), ENT_NOQUOTES, 'UTF-8') . '$$';
                return (($m[1] ?? '') !== '' ? '<div class="md-math-block">' . $inner . '</div>' : '<span class="md-math">' . $inner . '</span>');
            },
            $html
        );
        return $html;
    }
}
