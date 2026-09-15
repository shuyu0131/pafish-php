<?php
/**
 * 移动端抽屉导航（系统默认模板；主题可覆盖 themes/{active}/mobile-nav.php）
 * 可用数据：$items（导航项）、$loggedIn（是否登录）
 * 纯 CSS 抽屉：checkbox hack，无需 JS
 */
$items = $items ?? [];
$loggedIn = (bool) ($loggedIn ?? false);
?>
<div class="mobile-nav">
  <input type="checkbox" id="mobile-nav-toggle" class="mobile-nav-check" aria-hidden="true">
  <label for="mobile-nav-toggle" class="mobile-nav-burger" aria-label="打开菜单" role="button">☰</label>
  <div class="mobile-nav-drawer" role="dialog" aria-label="菜单">
    <label for="mobile-nav-toggle" class="mobile-nav-close" aria-label="关闭菜单">✕</label>
    <nav class="mobile-nav-list" aria-label="移动端导航">
      <?php foreach ($items as $item): ?>
        <a href="<?= e(!empty($item['is_external']) ? $item['url'] : url_to($item['url'] ?? '#')) ?>"
           <?= !empty($item['is_external']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
          <?= e($item['label'] ?? '') ?>
        </a>
      <?php endforeach; ?>
      <a href="<?= e(url_to('/archives')) ?>">归档</a>
      <hr>
      <?php if ($loggedIn): ?>
        <a href="<?= e(url_to('/admin')) ?>">后台</a>
        <form class="mobile-nav-logout" method="post" action="<?= e(url_to('/api/auth/logout')) ?>">
          <?= csrf_field() ?>
          <button type="submit">退出登录</button>
        </form>
      <?php else: ?>
        <a href="<?= e(url_to('/login')) ?>">登录</a>
      <?php endif; ?>
    </nav>
  </div>
  <label for="mobile-nav-toggle" class="mobile-nav-mask" aria-hidden="true"></label>
</div>
<?php if ($loggedIn): ?>
<script>
  document.querySelectorAll('.mobile-nav-logout').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      fetch(f.action, { method: 'POST', body: new FormData(f) })
        .then(function () { window.location.href = '/'; })
        .catch(function () { window.location.href = '/'; });
    });
  });
</script>
<?php endif; ?>
