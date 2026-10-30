<?php
/** Mobile drawer menu; the same recursive tree as the desktop menu. */
$items = $items ?? [];
$tree = is_array($tree ?? null) ? $tree : nav_tree($items);
$loggedIn = (bool) ($loggedIn ?? false);
$current = nav_current_path();
$isCurrent = static function (string $url) use ($current): bool {
    $path = parse_url($url, PHP_URL_PATH);
    return (is_string($path) && $path !== '' ? $path : '/') === $current;
};
$tree = nav_tree_active($tree, $current);
$renderItems = null;
$renderItems = static function (array $nodes, int $depth = 0) use (&$renderItems, $isCurrent): void {
?>
  <ul class="<?= $depth === 0 ? '' : 'mobile-nav-submenu' ?>">
    <?php foreach ($nodes as $item): ?>
      <?php
      $url = (string) ($item['url'] ?? '#');
      $external = !empty($item['is_external']);
      $children = is_array($item['children'] ?? null) ? $item['children'] : [];
      $group = ($item['type'] ?? '') === 'group';
      ?>
      <li class="mobile-nav-item<?= $children !== [] ? ' has-children' : '' ?>">
        <?php if ($group): ?><span class="mobile-nav-group"><?= e((string) ($item['label'] ?? '')) ?></span><?php else: ?>
          <a href="<?= e(nav_href($url, $external)) ?>" class="<?= !empty($item['_menu_active']) ? 'is-active' : '' ?>" <?= $external ? 'target="_blank" rel="noopener noreferrer"' : '' ?> <?= !$external && $isCurrent($url) ? 'aria-current="page"' : '' ?>><?= e((string) ($item['label'] ?? '')) ?></a>
        <?php endif; ?>
        <?php if ($children !== []): $renderItems($children, $depth + 1); endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php
};
$archivesAvailable = \Pafish\Services\Theme::hasArchivePage();
$hasArchiveMenuItem = false;
$findArchive = null;
$findArchive = static function (array $items) use (&$findArchive, &$hasArchiveMenuItem): void {
  foreach ($items as $item) {
    if (parse_url((string) ($item['url'] ?? ''), PHP_URL_PATH) === '/archives') $hasArchiveMenuItem = true;
    if (is_array($item['children'] ?? null)) $findArchive($item['children']);
  }
};
$findArchive($tree);
?>
<div class="mobile-nav">
  <input type="checkbox" id="mobile-nav-toggle" class="mobile-nav-check" aria-hidden="true">
  <label for="mobile-nav-toggle" class="mobile-nav-burger" aria-label="打开菜单" role="button">☰</label>
  <div class="mobile-nav-drawer" role="dialog" aria-label="菜单">
    <label for="mobile-nav-toggle" class="mobile-nav-close" aria-label="关闭菜单">✕</label>
    <nav class="mobile-nav-list" aria-label="移动端导航">
      <?php $renderItems($tree); ?>
      <?php if ($archivesAvailable && !$hasArchiveMenuItem): ?><ul><li class="mobile-nav-item"><a href="<?= e(url_to('/archives')) ?>">归档</a></li></ul><?php endif; ?>
      <?php if ($tree !== [] || $archivesAvailable): ?><hr><?php endif; ?>
      <?php if ($loggedIn): ?>
        <a href="<?= e(url_to('/admin')) ?>">后台</a>
        <form class="mobile-nav-logout" method="post" action="<?= e(url_to('/api/auth/logout')) ?>"><?= csrf_field() ?><button type="submit">退出登录</button></form>
      <?php else: ?>
        <a href="<?= e(url_to('/login')) ?>">登录</a>
      <?php endif; ?>
    </nav>
  </div>
  <label for="mobile-nav-toggle" class="mobile-nav-mask" aria-hidden="true"></label>
</div>
<?php if ($loggedIn): ?>
<script>
document.querySelectorAll('.mobile-nav-logout').forEach(function (form) {
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    fetch(form.action, {method: 'POST', body: new FormData(form), headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}})
      .then(function () { window.location.href = <?= json_encode(url_to('/')) ?>; })
      .catch(function () { window.location.href = <?= json_encode(url_to('/')) ?>; });
  });
});
</script>
<?php endif; ?>
