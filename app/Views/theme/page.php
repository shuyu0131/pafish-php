<?php
/**
 * 独立页面详情（系统默认模板；主题可覆盖 themes/{active}/page.php）
 * 可用数据：$page（pages 表行，含 title/content/template）、$contentHtml、$title、$description
 * 独立页面布局：
 * - 非 default 模板时容器带 data-page-template + page-template-{name} 类（主题 CSS 据此布局）
 * - 插件模板优先：激活插件 renderPageTemplate 输出非空时用 .plugin-page 渲染，否则 Markdown
 */
$page = $page ?? [];
$tpl = !empty($page['template']) && $page['template'] !== 'default' ? (string) $page['template'] : '';
$pluginHtml = $pluginHtml ?? '';
$contentHtml = $contentHtml ?? content_html((string) ($page['content'] ?? ''), (string) ($page['content_format'] ?? 'markdown'));
get_header();
?>
<div class="container container-narrow<?= $tpl !== '' ? ' page-template-' . e($tpl) : '' ?>"<?= $tpl !== '' ? ' data-page-template="' . e($tpl) . '"' : '' ?>>
  <header class="page-header">
    <h1 class="page-title"><?= e((string) ($page['title'] ?? '')) ?></h1>
  </header>
  <?php if ($pluginHtml !== ''): ?>
    <div class="plugin-page"><?= $pluginHtml ?></div>
  <?php else: ?>
    <div class="md-content pafish-content"><?= $contentHtml ?></div>
  <?php endif; ?>
  <?php if (!empty($commentsEnabled)): ?>
    <?= render_partial('comment-section', [
        'targetType' => 'page',
        'targetId' => (int) $page['id'],
        'commentBaseUrl' => $commentBaseUrl ?? content_url('page', $page),
        'commentPage' => $commentPage ?? 1,
        'commentRoots' => $commentRoots ?? [],
        'commentTotal' => $commentTotal ?? 0,
        'commentTotalPages' => $commentTotalPages ?? 1,
        'needReview' => $needReview ?? true,
        'commentsOpen' => $commentsOpen ?? false,
        'captchaEnabled' => (string) settings('comments_captcha_enabled', 'true') !== 'false',
        'user' => current_user(),
        'post' => ['slug' => $page['slug']],
    ]) ?>
  <?php endif; ?>
</div>
<?php
get_footer();
