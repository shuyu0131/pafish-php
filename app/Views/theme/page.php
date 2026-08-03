<?php
/**
 * 独立页面详情（系统 fallback 模板；主题可覆盖 themes/{active}/page.php）
 * 可用数据：$page（pages 表行，含 title/content/template/updated_at）、$contentHtml、$title、$description
 * 结构对齐 Node 版 /pages/[slug]/page.tsx：
 * - 非 default 模板时容器带 data-page-template + page-template-{name} 类（主题 CSS 据此布局）
 * - 正文走 Markdown 渲染（$contentHtml 由控制器预渲染；模板兜底用 md()）
 */
$page = $page ?? [];
$tpl = !empty($page['template']) && $page['template'] !== 'default' ? (string) $page['template'] : '';
$contentHtml = $contentHtml ?? md((string) ($page['content'] ?? ''));
$updatedAt = !empty($page['updated_at']) ? format_date($page['updated_at'], 'yyyy-MM-dd HH:mm') : '';
get_header();
?>
<div class="container container-narrow<?= $tpl !== '' ? ' page-template-' . e($tpl) : '' ?>"<?= $tpl !== '' ? ' data-page-template="' . e($tpl) . '"' : '' ?>>
  <header class="page-header">
    <h1 class="page-title"><?= e((string) ($page['title'] ?? '')) ?></h1>
    <p class="page-updated"><?= e(site_name()) ?> · 更新于 <?= e($updatedAt) ?></p>
  </header>
  <div class="md-content"><?= $contentHtml ?></div>
</div>
<?php
get_footer();
