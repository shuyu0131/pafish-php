<?php

declare(strict_types=1);

$post = is_array($post ?? null) ? $post : [];
$locked = (bool) ($locked ?? false);
$postUrl = url_to('/post/' . rawurlencode((string) ($post['slug'] ?? '')));
get_header();
?>
<?php if ($locked): ?>
  <article class="fn-article fn-article-locked">
    <p class="fn-eyebrow">PRIVATE NOTE</p>
    <h1><?= e($post['title'] ?? '') ?></h1>
    <p>这篇文章需要密码才能继续阅读。</p>
    <?php if (!empty($passwordError)): ?><p class="fn-form-error"><?= e($passwordError) ?></p><?php endif; ?>
    <form class="fn-password-form" method="post" action="<?= e($postUrl) ?>">
      <?= csrf_field() ?>
      <label for="post-password">访问密码</label>
      <div><input id="post-password" type="password" name="password" autocomplete="current-password" required><button type="submit">解锁文章</button></div>
    </form>
  </article>
<?php else: ?>
  <article class="fn-article">
    <header class="fn-article-header">
      <p class="fn-eyebrow">
        <?php if (!empty($post['category_slug'])): ?><a href="<?= e(url_to('/category/' . rawurlencode((string) $post['category_slug']))) ?>"><?= e($post['category_name'] ?? '文章') ?></a><?php else: ?>阅读记录<?php endif; ?>
      </p>
      <h1><?= e($post['title'] ?? '') ?></h1>
      <div class="fn-entry-meta"><span><?= e($post['author_name'] ?? '') ?></span><time><?= e(format_date($post['published_at'] ?? null)) ?></time><span><?= (int) ($post['view_count'] ?? 0) ?> 次阅读</span></div>
    </header>
    <?php if (theme_value('show_post_cover', '1') === '1' && !empty($post['cover_url'])): ?>
      <?php $coverUrl = \Pafish\Services\Content::safeImageUrl($post['cover_url']); ?>
      <?php if ($coverUrl !== null): ?><img class="fn-article-cover" src="<?= e($coverUrl) ?>" alt="<?= e($post['title'] ?? '') ?>" loading="lazy" decoding="async"><?php endif; ?>
    <?php endif; ?>
    <div class="md-content pafish-content fn-prose"><?= $contentHtml ?? '' ?></div>
    <?php if (!empty($customFields)): ?>
      <dl class="fn-custom-fields"><?php foreach ($customFields as $field): ?><div><dt><?= e($field['key'] ?? '') ?></dt><dd><?= e($field['value'] ?? '') ?></dd></div><?php endforeach; ?></dl>
    <?php endif; ?>
    <?php if (!empty($prevPost) || !empty($nextPost)): ?>
      <nav class="fn-post-nav" aria-label="前后文章">
        <div><?php if (!empty($prevPost)): ?><small>上一篇</small><a href="<?= e(url_to('/post/' . rawurlencode((string) $prevPost['slug']))) ?>"><?= e($prevPost['title']) ?></a><?php endif; ?></div>
        <div><?php if (!empty($nextPost)): ?><small>下一篇</small><a href="<?= e(url_to('/post/' . rawurlencode((string) $nextPost['slug']))) ?>"><?= e($nextPost['title']) ?></a><?php endif; ?></div>
      </nav>
    <?php endif; ?>
    <?php if (theme_value('show_related_posts', '1') !== '0' && !empty($related)): ?>
      <aside class="fn-related"><h2>继续阅读</h2><ul><?php foreach ($related as $item): ?><li><a href="<?= e(url_to('/post/' . rawurlencode((string) $item['slug']))) ?>"><?= e($item['title']) ?></a></li><?php endforeach; ?></ul></aside>
    <?php endif; ?>
    <?php if (!empty($commentsEnabled)): ?>
      <?= render_partial('comment-section', [
          'postId' => (int) ($post['id'] ?? 0), 'commentPage' => $commentPage ?? 1,
          'commentRoots' => $commentRoots ?? [], 'commentTotal' => $commentTotal ?? 0,
          'commentTotalPages' => $commentTotalPages ?? 1, 'needReview' => $needReview ?? true,
          'commentsOpen' => $commentsOpen ?? true,
          'captchaEnabled' => (string) settings('comments_captcha_enabled', 'true') !== 'false',
          'user' => current_user(), 'post' => $post,
      ]) ?>
    <?php endif; ?>
  </article>
<?php endif; ?>
<?php get_footer(); ?>
