<?php
/**
 * 默认主题文章详情页（覆盖系统 app/Views/theme/post.php）
 *
 * 与系统模板的区别只有点赞 / 收藏：
 * - 从标题下的信息行移到正文结尾，居中显示（.post-reactions）
 * - 按钮改为「圆形图标 + 文字 + 计数」，图标 20px，带 aria-pressed；点赞红色、收藏黄色
 * - 新增「分享」：复制当前文章链接（优先 canonical），复制成功后短暂显示「已复制」
 * - 标签从标题下的信息行移到互动按钮下方，居中（.post-tags）
 * 接口、提交逻辑（明确提交目标状态、401 跳登录）与系统模板一致。
 * 系统 post.php 以后有改动时，记得同步到这里。
 *
 * 可用数据：$post（含 author_name/category_name/tags[]/content/content_format）、$contentHtml（正文渲染结果）、
 *           $locked、$passwordError、$liked、$favorited、$customFields、$prevPost、$nextPost、
 *           $showCustomFields、$settings、$commentPage
 */
$post = $post ?? [];
$settings = $settings ?? [];
$locked = (bool) ($locked ?? false);
$contentHtml = $contentHtml ?? '';
$siteName = $settings['site_name'] ?? site_name();
$postUrl = content_url('post', $post);
$publishedIso = $post['published_at'] ? date('c', strtotime((string) $post['published_at'])) : '';
$liked = !empty($liked);
$favorited = !empty($favorited);
get_header();
?>
<div class="container post-page">

  <?php /* 面包屑 */ ?>
  <nav class="breadcrumb" aria-label="面包屑">
    <a href="<?= e(url_to('/')) ?>">首页</a>
    <?php if (!empty($post['category_slug'])): ?>
      <span class="bc-sep">/</span>
      <a href="<?= e(content_url('category', ['id' => $post['category_id'] ?? 0, 'slug' => $post['category_slug']])) ?>"><?= e($post['category_name']) ?></a>
    <?php endif; ?>
    <span class="bc-sep">/</span>
    <span class="bc-current"><?= e($post['title']) ?></span>
  </nav>

  <?php if ($locked): ?>
    <?php /* 密码门：解锁 cookie 24h */ ?>
    <section class="password-gate" aria-labelledby="password-gate-title">
      <p class="password-gate-kicker">加密文章</p>
      <h1 id="password-gate-title" class="password-gate-title"><?= e($post['title']) ?></h1>
      <p class="password-gate-tip">请输入访问密码后继续阅读。</p>
      <?php if (!empty($passwordError)): ?>
        <p class="password-gate-error"><?= e($passwordError) ?></p>
      <?php endif; ?>
      <form method="post" action="<?= e($postUrl) ?>" class="password-gate-form">
        <?= csrf_field() ?>
        <input type="password" name="password" class="input" placeholder="请输入访问密码" autocomplete="off" required autofocus>
        <button type="submit" class="btn btn-primary">解锁</button>
      </form>
    </section>
  <?php else: ?>

    <?php /* 标题区 */ ?>
    <header class="post-head">
      <h1 class="editorial post-title"><?= e($post['title']) ?></h1>
      <div class="post-meta">
        <span class="post-meta-item"><?= admin_icon('user', 14) ?> <?= e($post['author_name'] ?? '') ?></span>
        <span class="post-meta-item"><?= admin_icon('calendar', 14) ?> <?= e(format_date($post['published_at'] ?? null)) ?></span>
        <span class="post-meta-item post-views"><?= admin_icon('eye', 14) ?> <?= (int) ($post['view_count'] ?? 0) ?> 次浏览</span>
      </div>
      <?php if (($externalUrl = \Pafish\Services\Content::safeLinkUrl($post['external_url'] ?? null, false)) !== null): ?>
        <a href="<?= e($externalUrl) ?>" target="_blank" rel="noopener noreferrer" class="post-original">
          <?= admin_icon('external-link', 14) ?> 查看原文
        </a>
      <?php endif; ?>
    </header>

    <?php if (theme_value('show_post_cover', '0') === '1' && !empty($post['cover_url'])): ?>
      <img src="<?= e($post['cover_url']) ?>" alt="<?= e($post['title']) ?>" class="post-cover">
    <?php endif; ?>

    <?php /* 正文（Markdown 已在控制器渲染） */ ?>
    <div class="md-content pafish-content"><?= $contentHtml ?></div>

    <?php /* 点赞 / 收藏：正文结尾居中 */ ?>
    <div class="post-reactions" role="group" aria-label="文章互动">
      <button type="button" class="post-action post-action-like" data-action="like" data-id="<?= (int) $post['id'] ?>" data-active="<?= $liked ? '1' : '0' ?>" aria-pressed="<?= $liked ? 'true' : 'false' ?>">
        <span class="post-action-icon"><?= admin_icon('heart', 20, $liked) ?></span>
        <span class="post-action-text"><span class="post-action-label">点赞</span><span class="post-action-count"><?= (int) ($post['like_count'] ?? 0) ?></span></span>
      </button>
      <button type="button" class="post-action post-action-favorite" data-action="favorite" data-id="<?= (int) $post['id'] ?>" data-active="<?= $favorited ? '1' : '0' ?>" aria-pressed="<?= $favorited ? 'true' : 'false' ?>">
        <span class="post-action-icon"><?= admin_icon('star', 20, $favorited) ?></span>
        <span class="post-action-text"><span class="post-action-label">收藏</span><span class="post-action-count"><?= (int) ($post['favorite_count'] ?? 0) ?></span></span>
      </button>
      <button type="button" class="post-action post-action-share" data-share-copy aria-label="复制文章链接">
        <span class="post-action-icon"><?= admin_icon('link', 20) ?></span>
        <span class="post-action-text"><span class="post-action-label">分享</span></span>
      </button>
    </div>
    <p class="post-reactions-status" role="status" aria-live="polite"></p>

    <?php /* 标签：放在互动按钮下方，居中 */ ?>
    <?php if (!empty($post['tags'])): ?>
      <nav class="post-tags" aria-label="文章标签">
        <?php foreach ($post['tags'] as $tag): ?>
          <a class="post-tag" href="<?= e(content_url('tag', $tag)) ?>" rel="tag">#<?= e($tag['name']) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <?php /* 上下篇 */ ?>
    <?php if (!empty($prevPost) || !empty($nextPost)): ?>
      <div class="post-pager">
        <?php if (!empty($prevPost)): ?>
          <a href="<?= e(content_url('post', $prevPost)) ?>" class="post-pager-link">
            <span class="post-pager-icon">‹</span>
            <span class="post-pager-text">上一篇：<?= e($prevPost['title']) ?></span>
          </a>
        <?php else: ?>
          <span></span>
        <?php endif; ?>
        <?php if (!empty($nextPost)): ?>
          <a href="<?= e(content_url('post', $nextPost)) ?>" class="post-pager-link">
            <span class="post-pager-text">下一篇：<?= e($nextPost['title']) ?></span>
            <span class="post-pager-icon">›</span>
          </a>
        <?php endif; ?>
      </div>
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
          'commentsOpen' => $commentsOpen ?? true,
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
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    </script>

    <?php /* 点赞/收藏：明确提交目标状态，避免双击和网络重试反向切换。 */ ?>
    <script>
    (function () {
      var icons = {
        like: { on: '<?= admin_icon('heart', 20, true) ?>', off: '<?= admin_icon('heart', 20) ?>' },
        favorite: { on: '<?= admin_icon('star', 20, true) ?>', off: '<?= admin_icon('star', 20) ?>' }
      };
      var btns = document.querySelectorAll('.post-reactions .post-action[data-action]');
      btns.forEach(function (btn) {
        btn.addEventListener('animationend', function () { btn.classList.remove('is-pop'); });
        btn.addEventListener('click', function () {
          if (btn.disabled) return;
          var action = btn.dataset.action, id = btn.dataset.id, active = btn.dataset.active === '1';
          var body = new URLSearchParams();
          body.set('_csrf', <?= json_encode(\Pafish\Core\Session::csrfToken()) ?>);
          body.set('active', active ? '0' : '1');
          btn.disabled = true;
          fetch(pafishApi('/post/' + id + '/' + action), { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
            .then(function (res) {
              if (res.status === 401) {
                // 收藏需登录：跳转登录页，登录后回到本页
                var back = encodeURIComponent(window.location.pathname + window.location.search);
                var loginUrl = (res.body && res.body.login_url) || pafishApi('/login');
                window.location.href = loginUrl + '?from=' + back;
                return;
              }
              if (!res.body.ok) {
                alert(res.body.error || '操作失败，请稍后重试');
                return;
              }
              var nowActive = !!res.body.active;
              btn.dataset.active = nowActive ? '1' : '0';
              btn.setAttribute('aria-pressed', nowActive ? 'true' : 'false');
              btn.querySelector('.post-action-icon').innerHTML = icons[action][nowActive ? 'on' : 'off'];
              btn.querySelector('.post-action-count').textContent = res.body.count;
              if (nowActive) {
                btn.classList.remove('is-pop');
                void btn.offsetWidth; // 重新触发动画
                btn.classList.add('is-pop');
              }
            })
            .catch(function () { alert('网络异常，请稍后重试'); })
            .finally(function () { btn.disabled = false; });
        });
      });

      // 分享：复制文章链接
      var share = document.querySelector('.post-reactions [data-share-copy]');
      var status = document.querySelector('.post-reactions-status');
      if (share) {
        var shareIcon = share.querySelector('.post-action-icon');
        var shareLabel = share.querySelector('.post-action-label');
        var linkIcon = shareIcon.innerHTML;
        var checkIcon = '<?= admin_icon('check', 20) ?>';
        var resetTimer = null;
        function postLink() {
          var canonical = document.querySelector('link[rel="canonical"]');
          return canonical && canonical.href ? canonical.href : window.location.href.split('#')[0];
        }
        function fallbackCopy(text) {
          var area = document.createElement('textarea');
          area.value = text;
          area.setAttribute('readonly', '');
          area.style.position = 'fixed';
          area.style.opacity = '0';
          document.body.appendChild(area);
          area.select();
          var ok = false;
          try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
          document.body.removeChild(area);
          return ok ? Promise.resolve() : Promise.reject();
        }
        function copy(text) {
          if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).catch(function () { return fallbackCopy(text); });
          }
          return fallbackCopy(text);
        }
        share.addEventListener('click', function () {
          var url = postLink();
          copy(url).then(function () {
            share.classList.add('is-done');
            shareIcon.innerHTML = checkIcon;
            shareLabel.textContent = '已复制';
            if (status) status.textContent = '文章链接已复制';
            clearTimeout(resetTimer);
            resetTimer = setTimeout(function () {
              share.classList.remove('is-done');
              shareIcon.innerHTML = linkIcon;
              shareLabel.textContent = '分享';
              if (status) status.textContent = '';
            }, 2000);
          }).catch(function () {
            window.prompt('复制下面的链接：', url);
          });
        });
      }
    })();
    </script>
  <?php endif; ?>

</div>
<?php
get_footer();
