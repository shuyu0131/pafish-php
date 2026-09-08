<?php
/**
 * 文章详情页（系统 fallback 模板；主题可覆盖 themes/{active}/post.php）
 * 可用数据：$post（含 author_name/category_name/tags[]/content）、$contentHtml（Markdown 渲染结果）、
 *           $locked、$passwordError、$liked、$favorited、$customFields、$prevPost、$nextPost、
 *           $related、$showCustomFields、$showRelated、$settings、$commentPage
 * 文章详情布局
 */
$post = $post ?? [];
$settings = $settings ?? [];
$locked = (bool) ($locked ?? false);
$contentHtml = $contentHtml ?? '';
$siteName = $settings['site_name'] ?? site_name();
$postUrl = \url_to('/post/' . rawurlencode((string) $post['slug']));
$publishedIso = $post['published_at'] ? date('c', strtotime((string) $post['published_at'])) : '';
get_header();
?>
<div class="container post-page">

  <?php /* 面包屑 */ ?>
  <nav class="breadcrumb" aria-label="面包屑">
    <a href="<?= e(url_to('/')) ?>">首页</a>
    <?php if (!empty($post['category_slug'])): ?>
      <span class="bc-sep">/</span>
      <a href="<?= e(url_to('/category/' . rawurlencode((string) $post['category_slug']))) ?>"><?= e($post['category_name']) ?></a>
    <?php endif; ?>
    <span class="bc-sep">/</span>
    <span class="bc-current"><?= e($post['title']) ?></span>
  </nav>

  <?php if ($locked): ?>
    <?php /* 密码门：解锁 cookie 24h */ ?>
    <div class="password-gate card">
      <h1 class="password-gate-title"><?= admin_icon('lock', 18) ?> <?= e($post['title']) ?></h1>
      <p class="password-gate-tip">这篇文章需要密码才能查看</p>
      <?php if (!empty($passwordError)): ?>
        <p class="password-gate-error"><?= e($passwordError) ?></p>
      <?php endif; ?>
      <form method="post" action="<?= e($postUrl) ?>" class="password-gate-form">
        <?= csrf_field() ?>
        <input type="password" name="password" class="input" placeholder="请输入访问密码" autocomplete="off" required autofocus>
        <button type="submit" class="btn btn-primary">解锁</button>
      </form>
    </div>
  <?php else: ?>

    <?php /* 标题区 */ ?>
    <header class="post-head">
      <h1 class="editorial post-title"><?= e($post['title']) ?></h1>
      <div class="post-meta">
        <span class="post-meta-item"><?= admin_icon('user', 14) ?> <?= e($post['author_name'] ?? '') ?></span>
        <span class="post-meta-item"><?= admin_icon('calendar', 14) ?> <?= e(format_date($post['published_at'] ?? null)) ?></span>
        <button type="button" class="post-action" data-action="like" data-id="<?= (int) $post['id'] ?>" data-active="<?= !empty($liked) ? '1' : '0' ?>">
          <span class="post-action-icon"><?= admin_icon('heart', 15, !empty($liked)) ?></span>
          <span class="post-action-count"><?= (int) ($post['like_count'] ?? 0) ?></span>
        </button>
        <button type="button" class="post-action" data-action="favorite" data-id="<?= (int) $post['id'] ?>" data-active="<?= !empty($favorited) ? '1' : '0' ?>">
          <span class="post-action-icon"><?= admin_icon('star', 15, !empty($favorited)) ?></span>
          <span class="post-action-count"><?= (int) ($post['favorite_count'] ?? 0) ?></span>
        </button>
        <span class="post-meta-item post-views"><?= admin_icon('eye', 14) ?> <?= (int) ($post['view_count'] ?? 0) ?> 次浏览</span>
        <?php if (!empty($post['tags'])): ?>
          <span class="post-meta-tags">
            <?php foreach ($post['tags'] as $tag): ?>
              <a href="<?= e(url_to('/tag/' . rawurlencode((string) $tag['slug']))) ?>">#<?= e($tag['name']) ?></a>
            <?php endforeach; ?>
          </span>
        <?php endif; ?>
      </div>
      <?php if (!empty($post['external_url'])): ?>
        <a href="<?= e($post['external_url']) ?>" target="_blank" rel="noopener noreferrer" class="post-original">
          <?= admin_icon('external-link', 14) ?> 查看原文
        </a>
      <?php endif; ?>
    </header>

    <?php if (!empty($post['cover_url'])): ?>
      <img src="<?= e($post['cover_url']) ?>" alt="<?= e($post['title']) ?>" class="post-cover">
    <?php endif; ?>

    <?php /* 正文（Markdown 已在控制器渲染） */ ?>
    <div class="md-content"><?= $contentHtml ?></div>

    <?php /* 自定义字段（外观设置可关闭） */ ?>
    <?php if (!empty($showCustomFields) && !empty($customFields)): ?>
      <dl class="custom-fields">
        <?php foreach ($customFields as $field): ?>
          <div class="custom-field">
            <dt><?= e($field['key'] ?? '') ?></dt>
            <dd><?= e($field['value'] ?? '') ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>

    <?php /* 上下篇 */ ?>
    <?php if (!empty($prevPost) || !empty($nextPost)): ?>
      <div class="post-pager">
        <?php if (!empty($prevPost)): ?>
          <a href="<?= e(url_to('/post/' . rawurlencode((string) $prevPost['slug']))) ?>" class="post-pager-link">
            <span class="post-pager-icon">‹</span>
            <span class="post-pager-text">上一篇：<?= e($prevPost['title']) ?></span>
          </a>
        <?php else: ?>
          <span></span>
        <?php endif; ?>
        <?php if (!empty($nextPost)): ?>
          <a href="<?= e(url_to('/post/' . rawurlencode((string) $nextPost['slug']))) ?>" class="post-pager-link">
            <span class="post-pager-text">下一篇：<?= e($nextPost['title']) ?></span>
            <span class="post-pager-icon">›</span>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php /* 相关推荐（外观设置可关闭） */ ?>
    <?php if (!empty($showRelated)): ?>
      <?= render_partial('related-posts', ['posts' => $related ?? []]) ?>
    <?php endif; ?>

    <?php /* 评论区（评论功能关闭时整块隐藏） */ ?>
    <?php if (!empty($commentsEnabled)): ?>
      <?= render_partial('comment-section', [
          'postId' => (int) $post['id'],
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

    <?php /* 结构化数据：Google/必应 富结果（BlogPosting） */ ?>
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $post['title'],
        'description' => $post['excerpt'] ?? '',
        'image' => !empty($post['cover_url'])
            ? (str_starts_with((string) $post['cover_url'], 'http') ? $post['cover_url'] : \absolute_url((string) $post['cover_url']))
            : null,
        'datePublished' => $publishedIso,
        'author' => ['@type' => 'Person', 'name' => $post['author_name'] ?? ''],
        'publisher' => ['@type' => 'Organization', 'name' => $siteName],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $postUrl],
        'articleSection' => $post['category_name'] ?? null,
        'keywords' => implode(', ', array_column($post['tags'] ?? [], 'name')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    </script>

    <?php /* 点赞/收藏：cookie 幂等切换 + DB 计数（图标随激活态在描边/实心间切换） */ ?>
    <script>
    (function () {
      var icons = {
        like: { on: '<?= admin_icon('heart', 15, true) ?>', off: '<?= admin_icon('heart', 15) ?>' },
        favorite: { on: '<?= admin_icon('star', 15, true) ?>', off: '<?= admin_icon('star', 15) ?>' }
      };
      var btns = document.querySelectorAll('.post-action');
      btns.forEach(function (btn) {
        btn.addEventListener('click', function () {
          var action = btn.dataset.action, id = btn.dataset.id, active = btn.dataset.active === '1';
          fetch(pafishApi('/post/' + id + '/' + action), { method: 'POST' })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
            .then(function (res) {
              if (res.status === 401) {
                // 收藏需登录：跳转登录页，登录后回到本页
                var back = encodeURIComponent(window.location.pathname + window.location.search);
                var loginUrl = (res.body && res.body.login_url) || pafishApi('/login');
                window.location.href = loginUrl + '?from=' + back;
                return;
              }
              if (!res.body.ok) return;
              var active = res.body.active;
              btn.dataset.active = active ? '1' : '0';
              btn.querySelector('.post-action-icon').innerHTML = icons[action][active ? 'on' : 'off'];
              btn.querySelector('.post-action-count').textContent = res.body.count;
            })
            .catch(function () {});
        });
      });
    })();
    </script>
  <?php endif; ?>

</div>
<?php
get_footer();
