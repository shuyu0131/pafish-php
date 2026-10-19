<?php
/**
 * 移动端抽屉导航（覆盖系统模板）。
 *
 * 导航项完全取自后台「导航菜单」（nav_items，visible = 1，按 sort_order 排序），
 * 与桌面顶栏同源同序；不再额外补硬编码的“归档”，避免和后台已有项重复。
 * 分隔线以下只放账号动作（后台 / 登录 / 退出），它们不属于站点导航。
 *
 * 可用数据：$items（导航项）、$loggedIn（是否登录）
 * 纯 CSS 抽屉：checkbox hack，无需 JS
 */
$items = $items ?? [];
$loggedIn = (bool) ($loggedIn ?? false);
$current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base = rtrim((string) Url::base(), '/');
if ($base !== '' && str_starts_with($current, $base)) {
    $current = substr($current, strlen($base)) ?: '/';
}
?>
<div class="mobile-nav">
  <input type="checkbox" id="mobile-nav-toggle" class="mobile-nav-check" aria-hidden="true">
  <label for="mobile-nav-toggle" class="mobile-nav-burger" aria-label="打开菜单" role="button">☰</label>
  <div class="mobile-nav-drawer" role="dialog" aria-label="菜单">
    <label for="mobile-nav-toggle" class="mobile-nav-close" aria-label="关闭菜单">✕</label>
    <nav class="mobile-nav-list" aria-label="移动端导航">
      <?php foreach ($items as $item): ?>
        <?php
        $label = (string) ($item['label'] ?? '');
        $url = (string) ($item['url'] ?? '#');
        $external = !empty($item['is_external']);
        $active = $external ? false : nav_is_active($url, $current);
        ?>
        <a href="<?= e($external ? $url : url_to($url)) ?>"
           class="<?= $active ? 'is-active' : '' ?>"
           <?= $external ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
          <?= e($label) ?>
        </a>
      <?php endforeach; ?>
      <?php if ($items !== []): ?><hr><?php endif; ?>
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
  // 退出成功后回到首页。跳转目标用服务端生成的站内地址，避免硬编码 '/' 在子目录部署下丢前缀。
  // 不要用 fetch 的 res.url：脚本调用拿到的是 JSON（200），res.url 就是接口地址本身。
  document.querySelectorAll('.mobile-nav-logout').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var home = <?= json_encode(url_to('/')) ?>;
      fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function () { window.location.href = home; })
        .catch(function () { window.location.href = home; });
    });
  });
</script>
<?php endif; ?>
