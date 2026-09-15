<?php

declare(strict_types=1);

$user = $user ?? current_user() ?? [];
$posts = $posts ?? [];
$stats = $stats ?? ['posts' => 0, 'views' => 0, 'comments' => 0];
$reactions = $reactions ?? [];
$comments = $comments ?? [];
$pointsEnabled = !empty($pointsEnabled);
$pointsBalance = (int) ($pointsBalance ?? 0);
$pointTransactions = $pointTransactions ?? [];
$avatar = !empty($user['avatar_url']) ? (string) $user['avatar_url'] : admin_gravatar((string) ($user['email'] ?? ''));
get_header();
?>
<section class="profile-page">
  <header class="profile-page-head"><h1>个人中心</h1><p>管理资料、密码和你的公开动态。</p></header>
  <div class="profile-page-grid">
    <section class="profile-card">
      <div class="profile-card-avatar"><img src="<?= e($avatar) ?>" alt=""></div>
      <h2><?= e((string) ($user['nickname'] ?: $user['username'])) ?></h2>
      <p class="profile-card-handle">@<?= e((string) $user['username']) ?></p>
      <div class="profile-stats"><span><strong><?= (int) $stats['posts'] ?></strong>动态</span><span><strong><?= (int) $stats['views'] ?></strong>浏览</span><span><strong><?= (int) $stats['comments'] ?></strong>评论</span></div>
      <?php if ($pointsEnabled): ?><div class="profile-points"><span>当前积分</span><strong><?= $pointsBalance ?></strong></div><?php endif; ?>
    </section>
    <section class="profile-form-card">
      <h2>资料设置</h2>
      <form class="profile-form" method="post" action="<?= e(url_to('/profile/save')) ?>" data-profile-form>
        <?= csrf_field() ?>
        <label>头像地址<input class="input" name="avatar_url" value="<?= e((string) ($user['avatar_url'] ?? '')) ?>" placeholder="/uploads/avatar.webp 或 https://…" maxlength="500"></label>
        <label>显示昵称<input class="input" name="nickname" value="<?= e((string) ($user['nickname'] ?? '')) ?>" maxlength="50"></label>
        <label>用户名<input class="input" name="username" value="<?= e((string) ($user['username'] ?? '')) ?>" maxlength="50" required></label>
        <label>邮箱<input class="input" type="email" name="email" value="<?= e((string) ($user['email'] ?? '')) ?>" maxlength="255" required></label>
        <p class="profile-form-message" data-profile-message hidden></p>
        <button class="profile-submit" type="submit">保存资料</button>
      </form>
      <h2 class="profile-password-heading">修改密码</h2>
      <form class="profile-form" method="post" action="<?= e(url_to('/profile/password')) ?>" data-profile-form>
        <?= csrf_field() ?>
        <label>当前密码<input class="input" type="password" name="current_password" autocomplete="current-password" minlength="6" required></label>
        <label>新密码<input class="input" type="password" name="new_password" autocomplete="new-password" minlength="6" required></label>
        <label>确认新密码<input class="input" type="password" name="confirm_password" autocomplete="new-password" minlength="6" required></label>
        <p class="profile-form-message" data-profile-message hidden></p>
        <button class="profile-submit" type="submit">修改密码</button>
      </form>
    </section>
  </div>
  <?php if ($pointsEnabled): ?>
    <section class="profile-points-ledger">
      <header><h2>积分记录</h2><span>每一笔变动都可追溯</span></header>
      <?php if ($pointTransactions === []): ?><p class="profile-posts-empty">暂无积分记录。</p><?php else: ?><div class="profile-ledger-list"><?php foreach ($pointTransactions as $item): ?><div><span><strong><?= (int) $item['amount'] > 0 ? '+' : '' ?><?= (int) $item['amount'] ?></strong><small><?= e((string) $item['reason']) ?></small></span><time><?= e(format_date($item['created_at'] ?? null, 'Y-m-d H:i')) ?></time></div><?php endforeach; ?></div><?php endif; ?>
    </section>
  <?php endif; ?>
  <section class="profile-posts">
    <header><h2>我的点赞与收藏</h2><span>最近 <?= count($reactions) ?> 条</span></header>
    <?php if ($reactions === []): ?><p class="profile-posts-empty">还没有点赞或收藏的文章。</p><?php else: ?>
      <div class="profile-post-list">
        <?php foreach ($reactions as $reaction): ?>
          <a href="<?= e(url_to('/post/' . rawurlencode((string) $reaction['slug']))) ?>"><span><strong><?= $reaction['kind'] === 'favorite' ? '收藏 · ' : '点赞 · ' ?><?= e((string) $reaction['title']) ?></strong><small><?= e(format_date($reaction['published_at'] ?? null, 'Y-m-d')) ?> · <?= (int) $reaction['like_count'] ?> 赞 · <?= (int) $reaction['favorite_count'] ?> 收藏</small></span><?= admin_icon('arrow-right', 15) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <section class="profile-posts">
    <header><h2>我的评论</h2><span>最近 <?= count($comments) ?> 条</span></header>
    <?php if ($comments === []): ?><p class="profile-posts-empty">还没有发表过评论。</p><?php else: ?>
      <div class="profile-post-list">
        <?php foreach ($comments as $comment): ?>
          <a href="<?= e(url_to('/post/' . rawurlencode((string) $comment['post_slug']))) ?>">
            <span><strong><?= e(mb_strimwidth((string) $comment['content'], 0, 42, '…')) ?></strong><small><?= e((string) $comment['post_title']) ?> · <?= (int) $comment['like_count'] ?> 赞 · <?= e(format_date($comment['created_at'], 'Y-m-d')) ?></small></span><?= admin_icon('arrow-right', 15) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <section class="profile-posts">
    <header><h2>我的动态</h2><span>最近 30 条公开动态</span></header>
    <?php if ($posts === []): ?><p class="profile-posts-empty">还没有公开动态。</p><?php else: ?>
      <div class="profile-post-list">
        <?php foreach ($posts as $post): ?><a href="<?= e(url_to('/post/' . rawurlencode((string) $post['slug']))) ?>"><span><strong><?= e((string) $post['title']) ?></strong><small><?= e(format_date($post['published_at'] ?? null, 'Y-m-d')) ?> · <?= (int) $post['view_count'] ?> 次浏览</small></span><?= admin_icon('arrow-right', 15) ?></a><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</section>
<script>
(function () {
  document.querySelectorAll('[data-profile-form]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var message = form.querySelector('[data-profile-message]');
      var button = form.querySelector('button[type="submit"]');
      var original = button.textContent;
      button.disabled = true;
      message.hidden = true;
      if (form.elements.confirm_password && form.elements.new_password.value !== form.elements.confirm_password.value) {
        message.textContent = '两次输入的新密码不一致'; message.className = 'profile-form-message error'; message.hidden = false; button.disabled = false; return;
      }
      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (response) { return response.json().then(function (body) { return { ok: response.ok, body: body }; }); })
        .then(function (result) { if (!result.ok || !result.body.ok) throw new Error(result.body.error || '保存失败'); message.textContent = '已保存'; message.className = 'profile-form-message'; message.hidden = false; if (form.elements.confirm_password) form.reset(); })
        .catch(function (error) { message.textContent = error.message || '保存失败'; message.className = 'profile-form-message error'; message.hidden = false; })
        .finally(function () { button.disabled = false; button.textContent = original; });
    });
  });
})();
</script>
<?php get_footer();
