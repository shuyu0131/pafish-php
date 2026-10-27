<?php

declare(strict_types=1);

$page = is_array($page ?? null) ? $page : [];
$layout = (string) ($layout ?? 'standard');
get_header();
?>
<article class="fn-page<?= $layout === 'reading' ? ' fn-page-reading' : '' ?>"<?= $layout === 'reading' ? ' data-page-template="reading"' : '' ?>>
  <header class="fn-page-header">
    <p class="fn-eyebrow"><?= $layout === 'reading' ? 'LONGFORM / 阅读页' : 'PAGE / 独立页面' ?></p>
    <h1><?= e($page['title'] ?? '') ?></h1>
  </header>
  <?php if (!empty($pluginHtml)): ?>
    <div class="plugin-page fn-prose"><?= $pluginHtml ?></div>
  <?php else: ?>
    <div class="md-content pafish-content fn-prose"><?= $contentHtml ?? '' ?></div>
  <?php endif; ?>
  <?php if (!empty($commentsEnabled)): ?>
    <?= render_partial('comment-section', [
        'targetType' => 'page', 'targetId' => (int) ($page['id'] ?? 0),
        'commentBaseUrl' => $commentBaseUrl ?? url_to('/pages/' . rawurlencode((string) ($page['slug'] ?? ''))),
        'commentPage' => $commentPage ?? 1, 'commentRoots' => $commentRoots ?? [],
        'commentTotal' => $commentTotal ?? 0, 'commentTotalPages' => $commentTotalPages ?? 1,
        'needReview' => $needReview ?? true, 'commentsOpen' => $commentsOpen ?? false,
        'captchaEnabled' => (string) settings('comments_captcha_enabled', 'true') !== 'false',
        'user' => current_user(), 'post' => ['slug' => $page['slug'] ?? ''],
    ]) ?>
  <?php endif; ?>
</article>
<?php get_footer(); ?>
