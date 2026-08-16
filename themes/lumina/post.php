<?php

declare(strict_types=1);

$post = $post ?? [];
$locked = !empty($locked);
$postUrl = url_to('/post/' . rawurlencode((string) ($post['slug'] ?? '')));
$contentHtml = $contentHtml ?? '';
get_header();
?>
<?php if ($locked): ?>
  <section class="lumina-password">
    <h1><?= admin_icon('lock', 19) ?> <?= e((string) ($post['title'] ?? '')) ?></h1>
    <p>这篇文章需要密码才能查看</p>
    <?php if (!empty($passwordError)): ?><p class="comment-error"><?= e((string) $passwordError) ?></p><?php endif; ?>
    <form method="post" action="<?= e($postUrl) ?>"><?= csrf_field() ?><input name="password" type="password" placeholder="请输入访问密码" required autofocus><button type="submit">解锁</button></form>
  </section>
<?php else: ?>
  <article class="lumina-article">
    <header class="lumina-article-head">
      <h1><?= e((string) ($post['title'] ?? '')) ?></h1>
      <div class="lumina-article-meta">
        <span><?= admin_icon('user', 13) ?> <?= e((string) ($post['author_name'] ?? '')) ?></span>
        <span><?= admin_icon('calendar', 13) ?> <?= e(format_date($post['published_at'] ?? null, 'Y-m-d')) ?></span>
        <span><?= admin_icon('eye', 13) ?> <?= (int) ($post['view_count'] ?? 0) ?></span>
        <?php if (!empty($post['category_slug'])): ?><a href="<?= e(url_to('/category/' . rawurlencode((string) $post['category_slug']))) ?>">#<?= e((string) $post['category_name']) ?></a><?php endif; ?>
        <?php foreach (($post['tags'] ?? []) as $tag): ?><a href="<?= e(url_to('/tag/' . rawurlencode((string) $tag['slug']))) ?>">#<?= e((string) $tag['name']) ?></a><?php endforeach; ?>
      </div>
    </header>
    <?php if (!empty($post['cover_url'])): ?><img class="lumina-article-cover" src="<?= e((string) $post['cover_url']) ?>" alt="<?= e((string) $post['title']) ?>"><?php endif; ?>
    <div class="md-content"><?= $contentHtml ?></div>

    <?php if (!empty($customFields)): ?><dl class="lumina-custom-fields"><?php foreach ($customFields as $field): ?><div><dt><?= e((string) ($field['key'] ?? '')) ?></dt><dd><?= e((string) ($field['value'] ?? '')) ?></dd></div><?php endforeach; ?></dl><?php endif; ?>
    <div class="lumina-post-actions">
      <button type="button" class="lumina-post-action" data-lumina-post-action="like" data-id="<?= (int) ($post['id'] ?? 0) ?>" data-active="<?= !empty($liked) ? '1' : '0' ?>"><?= admin_icon('heart', 15, !empty($liked)) ?><span>赞</span><strong><?= (int) ($post['like_count'] ?? 0) ?></strong></button>
      <button type="button" class="lumina-post-action" data-lumina-post-action="favorite" data-id="<?= (int) ($post['id'] ?? 0) ?>" data-active="<?= !empty($favorited) ? '1' : '0' ?>"><?= admin_icon('star', 15, !empty($favorited)) ?><span>收藏</span><strong><?= (int) ($post['favorite_count'] ?? 0) ?></strong></button>
    </div>
  </article>

  <?php if (!empty($prevPost) || !empty($nextPost)): ?>
    <nav class="lumina-post-pager" aria-label="文章导航">
      <?php if (!empty($prevPost)): ?><a href="<?= e(url_to('/post/' . rawurlencode((string) $prevPost['slug']))) ?>">上一篇：<?= e((string) $prevPost['title']) ?></a><?php else: ?><span></span><?php endif; ?>
      <?php if (!empty($nextPost)): ?><a href="<?= e(url_to('/post/' . rawurlencode((string) $nextPost['slug']))) ?>">下一篇：<?= e((string) $nextPost['title']) ?></a><?php endif; ?>
    </nav>
  <?php endif; ?>

  <?php if (!empty($related)): ?>
    <section class="lumina-panel lumina-related"><header class="lumina-listing-head"><h2>相关推荐</h2></header><?php foreach ($related as $item): ?><?= render_partial('post-card', ['post' => $item]) ?><?php endforeach; ?></section>
  <?php endif; ?>

  <?php if (!empty($commentsEnabled)): ?>
    <?= render_partial('comment-section', [
        'postId' => (int) ($post['id'] ?? 0),
        'commentPage' => $commentPage ?? 1,
        'commentRoots' => $commentRoots ?? [],
        'commentTotal' => $commentTotal ?? 0,
        'commentTotalPages' => $commentTotalPages ?? 1,
        'needReview' => $needReview ?? true,
        'captchaEnabled' => (string) settings('comments_captcha_enabled', 'true') !== 'false',
        'user' => current_user(),
        'post' => $post,
    ]) ?>
  <?php endif; ?>

  <script>
  (function () {
    var icons = {
      like: { on: '<?= admin_icon('heart', 15, true) ?>', off: '<?= admin_icon('heart', 15) ?>' },
      favorite: { on: '<?= admin_icon('star', 15, true) ?>', off: '<?= admin_icon('star', 15) ?>' }
    };
    document.querySelectorAll('[data-lumina-post-action]').forEach(function (button) {
      button.addEventListener('click', function () {
        if (button.disabled) return;
        button.disabled = true;
        var kind = button.dataset.luminaPostAction;
        fetch(pafishApi('/post/' + button.dataset.id + '/' + kind), { method: 'POST' })
          .then(function (response) { return response.json().then(function (body) { return { status: response.status, body: body }; }); })
          .then(function (result) {
            if (result.status === 401) {
              window.location.href = (result.body.login_url || '<?= e(url_to('/login')) ?>') + '?from=' + encodeURIComponent(location.pathname + location.search);
              return;
            }
            if (!result.body || !result.body.ok) return;
            var active = !!result.body.active;
            button.dataset.active = active ? '1' : '0';
            button.querySelector('svg').outerHTML = icons[kind][active ? 'on' : 'off'];
            button.querySelector('strong').textContent = result.body.count;
          })
          .catch(function () {})
          .finally(function () { button.disabled = false; });
      });
    });
  })();
  </script>
<?php endif; ?>
<?php get_footer();
