<?php
/** 页面数据：$page、$contentHtml、$pluginHtml 及可选的评论字段。 */
$page = is_array($page ?? null) ? $page : [];
$templateName = (string) ($page['template'] ?? 'default');
get_header();
?>
<article class="starter-page<?= $templateName !== 'default' ? ' page-template-' . e($templateName) : '' ?>"<?= $templateName !== 'default' ? ' data-page-template="' . e($templateName) . '"' : '' ?>>
  <header class="starter-page-head"><p class="starter-kicker">独立页面</p><h1 class="starter-title"><?= e($page['title'] ?? '') ?></h1></header>
  <?php if (!empty($pluginHtml)): ?><div class="plugin-page starter-content"><?= $pluginHtml ?></div><?php else: ?><div class="md-content pafish-content starter-content"><?= $contentHtml ?? '' ?></div><?php endif; ?>
  <?php if (!empty($commentsEnabled)): ?><?= render_partial('comment-section', [
    'targetType' => 'page', 'targetId' => (int) ($page['id'] ?? 0),
    'commentBaseUrl' => $commentBaseUrl ?? url_to('/pages/' . rawurlencode((string) ($page['slug'] ?? ''))),
    'commentPage' => $commentPage ?? 1, 'commentRoots' => $commentRoots ?? [],
    'commentTotal' => $commentTotal ?? 0, 'commentTotalPages' => $commentTotalPages ?? 1,
    'needReview' => $needReview ?? true, 'commentsOpen' => $commentsOpen ?? false,
    'captchaEnabled' => (string) settings('comments_captcha_enabled', 'true') !== 'false',
    'user' => current_user(), 'post' => ['slug' => $page['slug'] ?? ''],
  ]) ?><?php endif; ?>
</article>
<?php get_footer(); ?>
