<?php
/** Desktop site menu, rendered recursively from the same tree as the mobile menu. */
$items = $items ?? [];
$tree = is_array($tree ?? null) ? $tree : nav_tree($items);
$current = nav_current_path();
$isCurrent = static function (string $url) use ($current): bool {
    $path = parse_url($url, PHP_URL_PATH);
    return (is_string($path) && $path !== '' ? $path : '/') === $current;
};
$tree = nav_tree_active($tree, $current);
$renderItems = null;
$renderItems = static function (array $nodes, int $depth = 0) use (&$renderItems, $isCurrent): void {
?>
  <ul class="<?= $depth === 0 ? 'app-nav-list' : 'app-nav-submenu' ?>">
    <?php foreach ($nodes as $item): ?>
      <?php
      $url = (string) ($item['url'] ?? '#');
      $external = !empty($item['is_external']);
      $children = is_array($item['children'] ?? null) ? $item['children'] : [];
      $active = !empty($item['_menu_active']);
      $group = ($item['type'] ?? '') === 'group';
      $submenuId = 'app-nav-submenu-' . (int) ($item['id'] ?? 0);
      ?>
      <li class="app-nav-item<?= $children !== [] ? ' has-children' : '' ?>">
        <?php if ($group): ?>
          <?php if ($children !== []): ?><button class="app-nav-link app-nav-group-button" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="<?= e($submenuId) ?>"><?= e((string) ($item['label'] ?? '')) ?><span class="app-nav-caret" aria-hidden="true"><?= $depth === 0 ? '⌄' : '›' ?></span></button><?php else: ?><span class="app-nav-link app-nav-label"><?= e((string) ($item['label'] ?? '')) ?></span><?php endif; ?>
        <?php else: ?>
          <a class="app-nav-link<?= $active ? ' active' : '' ?>" href="<?= e(nav_href($url, $external)) ?>" <?= $external ? 'target="_blank" rel="noopener noreferrer"' : '' ?> <?= !$external && $isCurrent($url) ? 'aria-current="page"' : '' ?><?= $children !== [] ? ' aria-haspopup="true"' : '' ?>><?= e((string) ($item['label'] ?? '')) ?><?php if ($children !== []): ?><span class="app-nav-caret" aria-hidden="true"><?= $depth === 0 ? '⌄' : '›' ?></span><?php endif; ?></a>
        <?php endif; ?>
        <?php if ($children !== []): ?><div id="<?= e($submenuId) ?>" class="app-nav-submenu-wrap"<?= $group ? ' hidden' : '' ?>><?php $renderItems($children, $depth + 1); ?></div><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php
};
?>
<nav class="app-nav" aria-label="主导航">
  <?php $renderItems($tree); ?>
</nav>
<script>
document.querySelectorAll('.app-nav-group-button').forEach(function (button) {
  button.addEventListener('click', function () {
    var expanded = button.getAttribute('aria-expanded') === 'true';
    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    var menu = document.getElementById(button.getAttribute('aria-controls'));
    if (menu) menu.hidden = expanded;
  });
  button.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { button.setAttribute('aria-expanded', 'false'); button.blur(); }
  });
});
</script>
