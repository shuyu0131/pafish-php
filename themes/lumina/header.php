<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$pageTitle = (string) ($title ?? '');
$siteName = site_name();
$subtitle = (string) settings('site_subtitle', '');
$profileName = trim(theme_value('profile_name')) ?: $siteName;
$profileBio = trim(theme_value('profile_bio')) ?: $subtitle;
$cover = lumina_theme_image('header_cover', lumina_asset_url('img/homeimg.jpg'));
$avatar = lumina_theme_image('avatar_image', lumina_asset_url('img/tx.png'));
$accent = theme_value('accent_color', '#07c160');
if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) !== 1) {
    $accent = '#07c160';
}
$showSearch = theme_value('show_search', '1') !== '0';
$smoothPagination = theme_value('smooth_pagination', '1') !== '0';
$navItems = nav_items();
$loggedIn = is_logged_in();
$currentUser = current_user();
$canManage = in_array((string) ($currentUser['role'] ?? ''), ['ADMIN', 'EDITOR'], true);
$fullTitle = $pageTitle !== '' && $pageTitle !== '首页' && $pageTitle !== $siteName
    ? $pageTitle . ' - ' . $siteName
    : $siteName;
$descriptionText = (string) ($description ?? settings('site_description', ''));
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fullTitle) ?></title>
<?php if ($descriptionText !== ''): ?><meta name="description" content="<?= e($descriptionText) ?>"><?php endif; ?>
<link rel="icon" href="<?= e(lumina_asset_url('img/favicon.png')) ?>">
<script>
(function () {
  try {
    var saved = localStorage.getItem('pafish-theme');
    if (saved === 'dark' || (saved !== 'light' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches)) {
      document.documentElement.classList.add('dark');
    }
  } catch (e) {}
})();
</script>
<style>:root { --lumina-accent: <?= e($accent) ?>; --lumina-cover: url('<?= e($cover) ?>'); }</style>
<?php if (($layoutCss = \Pafish\Services\Theme::layoutCss('lumina')) !== null): ?><style data-theme="lumina"><?= $layoutCss ?></style><?php endif; ?>
<?php do_action('head_inject', ['template' => $GLOBALS['pafish_tpl_ctx'] ?? []]); ?>
<script>
window.pafishApi = function (p) {
  var q = p.indexOf('?');
  var path = q >= 0 ? p.slice(0, q) : p;
  var query = q >= 0 ? p.slice(q + 1) : '';
  return <?= json_encode(url_to('/api')) ?> + path + (query ? <?= json_encode(\Pafish\Core\Config::get('pretty_urls', true) ? '?' : '&') ?> + query : '');
};
</script>
</head>
<body class="lumina-body">
<div class="lumina-app" data-lumina-smooth-pagination="<?= $smoothPagination ? '1' : '0' ?>">
  <header class="lumina-nav">
    <a class="lumina-nav-brand" href="<?= e(url_to('/')) ?>" aria-label="<?= e($siteName) ?> 首页">
      <span class="lumina-nav-mark"></span><span><?= e($siteName) ?></span>
    </a>
    <nav class="lumina-nav-links" aria-label="主导航">
      <?php foreach ($navItems as $item): ?>
        <?php $external = !empty($item['is_external']); ?>
        <a href="<?= e($external ? $item['url'] : url_to((string) $item['url'])) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= e($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="lumina-nav-actions">
      <?php if ($showSearch): ?><button type="button" class="lumina-icon-button" data-lumina-search-open aria-label="搜索"><?= admin_icon('search', 18) ?></button><?php endif; ?>
      <button type="button" class="lumina-icon-button theme-toggle" aria-label="切换到暗色" title="切换到暗色" hidden><span class="theme-toggle-icon"><?= admin_icon('moon', 17) ?></span></button>
      <?php if ($loggedIn): ?><a class="lumina-admin-link" href="<?= e(url_to('/profile')) ?>">资料</a><?php if ($canManage): ?><a class="lumina-admin-link" href="<?= e(url_to('/admin')) ?>">后台</a><?php endif; ?><?php else: ?><a class="lumina-admin-link" href="<?= e(url_to('/login')) ?>">登录</a><?php endif; ?>
    </div>
  </header>

  <section class="lumina-profile" aria-label="站点资料">
    <div class="lumina-cover"></div>
    <div class="lumina-profile-info">
      <div class="lumina-avatar-wrap"><img class="lumina-avatar" src="<?= e($avatar) ?>" alt="<?= e($profileName) ?>"></div>
      <div><h1><?= e($profileName) ?></h1><?php if ($profileBio !== ''): ?><p><?= e($profileBio) ?></p><?php endif; ?></div>
    </div>
  </section>

  <main class="lumina-main">
