<?php
/** 首页独立布局；其他页面继续使用系统模板，保持既有文章详情页。 */
if (empty($GLOBALS['default_theme_home'])) {
    include dirname(__DIR__, 2) . '/app/Views/theme/header.php';
    return;
}

$siteName = site_name();
$subtitle = trim((string) settings('site_subtitle', ''));
$description = trim((string) ($description ?? settings('site_description', '')));
$navItems = nav_items();
$loggedIn = is_logged_in();
$activeTheme = \Pafish\Services\Theme::active();
$themeCss = \Pafish\Services\Theme::css($activeTheme);
$layoutCss = \Pafish\Services\Theme::layoutCss($activeTheme);
$pageTitle = (string) ($title ?? '首页');
$fullTitle = $pageTitle !== '' && $pageTitle !== '首页' ? $pageTitle . ' · ' . $siteName : $siteName;
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim((string) \Pafish\Core\Url::base(), '/');
if ($base !== '' && str_starts_with($currentPath, $base)) {
    $currentPath = substr($currentPath, strlen($base)) ?: '/';
}
$hasHomeNav = false;
foreach ($navItems as $item) {
    if (empty($item['is_external']) && (string) ($item['url'] ?? '') === '/') {
        $hasHomeNav = true;
        break;
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fullTitle) ?></title>
<?php if ($description !== ''): ?><meta name="description" content="<?= e($description) ?>"><?php endif; ?>
<script>
(function () {
  try {
    var saved = localStorage.getItem('pafish-theme');
    if (saved === 'dark' || (saved !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark');
  } catch (e) {}
})();
</script>
<?php if ($layoutCss !== null || $themeCss !== null): ?><style data-theme="<?= e($activeTheme) ?>"><?= $layoutCss ?? '' ?><?= $themeCss ?? '' ?></style><?php endif; ?>
<?php do_action('head_inject', ['template' => $GLOBALS['pafish_tpl_ctx'] ?? []]); ?>
</head>
<body class="default-home">
<div class="default-home-shell">
  <header class="default-home-mobile-header">
    <a class="default-home-mobile-brand" href="<?= e(url_to('/')) ?>"><?= e($siteName) ?></a>
    <div class="default-home-mobile-tools">
      <?= render_partial('theme-toggle') ?>
      <input class="default-home-menu-check" type="checkbox" id="default-home-menu" aria-hidden="true">
      <label class="default-home-menu-button" for="default-home-menu" aria-label="打开菜单" role="button">☰</label>
      <div class="default-home-drawer" role="dialog" aria-label="菜单">
        <label class="default-home-menu-close" for="default-home-menu" aria-label="关闭菜单" role="button">×</label>
        <nav class="default-home-mobile-nav" aria-label="移动端导航">
          <?php if (!$hasHomeNav): ?><a href="<?= e(url_to('/')) ?>"<?= $currentPath === '/' ? ' aria-current="page"' : '' ?>>首页</a><?php endif; ?>
          <?php foreach ($navItems as $item): ?>
            <?php $external = !empty($item['is_external']); $url = (string) ($item['url'] ?? '/'); ?>
            <a href="<?= e($external ? $url : url_to($url)) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?><?= !$external && nav_is_active($url, $currentPath) ? ' aria-current="page"' : '' ?>><?= e((string) ($item['label'] ?? '')) ?></a>
          <?php endforeach; ?>
          <hr>
          <a href="<?= e(url_to($loggedIn ? '/admin' : '/login')) ?>"><?= $loggedIn ? '后台' : '登录' ?></a>
        </nav>
      </div>
      <label class="default-home-menu-mask" for="default-home-menu" aria-hidden="true"></label>
    </div>
  </header>
  <aside class="default-home-sidebar" aria-label="站点信息与导航">
    <div class="default-home-sidebar-inner">
      <div>
        <a class="default-home-brand" href="<?= e(url_to('/')) ?>">
          <?php if ($subtitle !== ''): ?><span><?= e($subtitle) ?></span><?php endif; ?>
          <strong><?= e($siteName) ?></strong>
        </a>
        <?php if ($description !== ''): ?><p class="default-home-description"><?= e($description) ?></p><?php endif; ?>
        <i class="default-home-rule" aria-hidden="true"></i>
        <nav class="default-home-nav" aria-label="主导航">
          <?php if (!$hasHomeNav): ?><a href="<?= e(url_to('/')) ?>" data-index="01"<?= $currentPath === '/' ? ' aria-current="page"' : '' ?>>首页</a><?php endif; ?>
          <?php foreach ($navItems as $index => $item): ?>
            <?php $external = !empty($item['is_external']); $url = (string) ($item['url'] ?? '/'); ?>
            <a href="<?= e($external ? $url : url_to($url)) ?>" data-index="<?= str_pad((string) ($index + ($hasHomeNav ? 1 : 2)), 2, '0', STR_PAD_LEFT) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?><?= !$external && nav_is_active($url, $currentPath) ? ' aria-current="page"' : '' ?>><?= e((string) ($item['label'] ?? '')) ?></a>
          <?php endforeach; ?>
        </nav>
        <div class="default-home-actions">
          <a href="<?= e(url_to($loggedIn ? '/admin' : '/login')) ?>"><?= $loggedIn ? '进入后台' : '登录' ?></a>
          <?= render_partial('theme-toggle') ?>
        </div>
      </div>
      <footer class="default-home-sidebar-footer">
        <p>© <?= date('Y') ?> <?= e($siteName) ?><br>ALL RIGHTS RESERVED</p>
        <?php if ((string) settings('site_icp', '') !== ''): ?><p><?= e((string) settings('site_icp', '')) ?></p><?php endif; ?>
      </footer>
    </div>
  </aside>
  <main class="default-home-main">
