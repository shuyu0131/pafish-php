<?php
/** 文章数据：$post、$contentHtml、锁定状态、上下篇、评论数据及相关文章。 */
$post = is_array($post ?? null) ? $post : [];
$locked = (bool) ($locked ?? false);
$slug = (string) ($post['slug'] ?? '');
$postUrl = url_to('/post/' . rawurlencode($slug));
get_header();
?>
<nav class="starter-breadcrumb" aria-label="面包屑"><a href="<?= e(url_to('/')) ?>">首页</a><?php if (!empty($post['category_slug'])): ?><span>›</span><a href="<?= e(url_to('/category/' . rawurlencode((string) $post['category_slug']))) ?>"><?= e($post['category_name'] ?? '') ?></a><?php endif; ?></nav>
<?php if ($locked): ?>
  <section class="starter-callout">
    <p class="starter-kicker">加密文章</p>
    <h1 class="starter-title"><?= e($post['title'] ?? '') ?></h1>
    <p>请输入访问密码后继续阅读。</p>
    <?php if (!empty($passwordError)): ?><p class="starter-password-error"><?= e($passwordError) ?></p><?php endif; ?>
    <form class="starter-password" method="post" action="<?= e($postUrl) ?>"><?= csrf_field() ?><input type="password" name="password" autocomplete="off" placeholder="访问密码" required><button class="starter-button" type="submit">解锁</button></form>
  </section>
<?php else: ?>
  <article>
    <header class="starter-article-head">
      <p class="starter-kicker"><?= e($post['category_name'] ?? '文章') ?></p>
      <h1 class="starter-title"><?= e($post['title'] ?? '') ?></h1>
      <div class="starter-meta"><span><?= e($post['author_name'] ?? '') ?></span><time><?= e(format_date($post['published_at'] ?? null)) ?></time><span><?= (int) ($post['view_count'] ?? 0) ?> 次浏览</span></div>
    </header>
    <?php if (theme_value('show_post_cover', '1') === '1' && !empty($post['cover_url'])): ?><img class="starter-cover" src="<?= e($post['cover_url']) ?>" alt="<?= e($post['title'] ?? '') ?>" loading="lazy"><?php endif; ?>
    <div class="md-content pafish-content starter-article-content"><?= $contentHtml ?? '' ?></div>
    <?php if (!empty($prevPost) || !empty($nextPost)): ?><nav class="starter-post-navigation" aria-label="前后文章">
      <?php if (!empty($prevPost)): ?><a href="<?= e(url_to('/post/' . rawurlencode((string) $prevPost['slug']))) ?>">← <?= e($prevPost['title']) ?></a><?php else: ?><span></span><?php endif; ?>
      <?php if (!empty($nextPost)): ?><a href="<?= e(url_to('/post/' . rawurlencode((string) $nextPost['slug']))) ?>"><?= e($nextPost['title']) ?> →</a><?php endif; ?>
    </nav><?php endif; ?>
    <?php if (theme_value('show_related_posts', '1') === '1'): ?><?= render_partial('related-posts', ['posts' => $related ?? []]) ?><?php endif; ?>
    <?php if (!empty($commentsEnabled)): ?><?= render_partial('comment-section', [
      'postId' => (int) ($post['id'] ?? 0), 'commentPage' => $commentPage ?? 1,
      'commentRoots' => $commentRoots ?? [], 'commentTotal' => $commentTotal ?? 0,
      'commentTotalPages' => $commentTotalPages ?? 1, 'needReview' => $needReview ?? true,
      'commentsOpen' => $commentsOpen ?? true,
      'captchaEnabled' => (string) settings('comments_captcha_enabled', 'true') !== 'false',
      'user' => current_user(), 'post' => $post,
    ]) ?><?php endif; ?>
  </article>
<?php endif; ?>
<?php get_footer(); ?>
