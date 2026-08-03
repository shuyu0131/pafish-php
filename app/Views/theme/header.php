<?php
/**
 * 前台页头（系统 fallback 模板；主题可覆盖 themes/{active}/header.php）
 * 可用数据：$title / $description（控制器传入，可选）
 * 结构复刻 Node 版：左侧 40% 侧边栏（桌面）+ 右侧 60% 内容栏 + 移动端顶栏
 */
$pageTitle = $title ?? '';
$desc = $description ?? (string) settings('site_description', '');
$siteName = site_name();
$subtitle = (string) settings('site_subtitle', '');
$activeTheme = Theme::active();
$themeCss = Theme::css($activeTheme);
$showSidebar = theme_value('sidebar_enabled', '1') !== '0';
$navItems = nav_items();
$loggedIn = is_logged_in();
$fullTitle = $pageTitle !== '' && $pageTitle !== '首页' && $pageTitle !== site_name()
    ? $pageTitle . ' · ' . $siteName
    : $siteName;
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fullTitle) ?></title>
<?php if ($desc !== ''): ?><meta name="description" content="<?= e($desc) ?>"><?php endif; ?>
<?php /* OG / canonical（文章/页面等控制器传入 $og 数组） */ if (is_array($og ?? null)): ?>
<meta property="og:type" content="<?= e($og['type'] ?? 'website') ?>">
<meta property="og:title" content="<?= e($og['title'] ?? $fullTitle) ?>">
<?php if (!empty($og['description'])): ?><meta property="og:description" content="<?= e($og['description']) ?>"><?php endif; ?>
<?php if (!empty($og['url'])): ?><meta property="og:url" content="<?= e($og['url']) ?>"><link rel="canonical" href="<?= e($og['url']) ?>"><?php endif; ?>
<?php if (!empty($og['image'])): ?><meta property="og:image" content="<?= e($og['image']) ?>"><?php endif; ?>
<?php endif; ?>
<script>
/* 首帧防闪烁：渲染前按 localStorage / 系统偏好决定 .dark */
(function () {
  try {
    var s = localStorage.getItem('pafish-theme');
    var dark = s === 'dark' || (s !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches);
    if (dark) document.documentElement.classList.add('dark');
  } catch (e) {}
})();
</script>
<link rel="stylesheet" href="<?= e(url_to('/css/style.css')) ?>">
<?php if ($themeCss): ?><style data-theme="<?= e($activeTheme) ?>"><?= $themeCss ?></style><?php endif; ?>
<?php /* 插件 head 注入（M5） */ do_action('head_inject'); ?>
</head>
<body>
<div class="app-shell">

<?php if ($showSidebar): ?>
<aside class="app-sidebar">
  <a href="<?= e(url_to('/')) ?>" class="app-brand">
    <span class="app-brand-name"><?= e($siteName) ?></span>
    <?php if ($subtitle !== ''): ?><span class="app-brand-sub"><?= e($subtitle) ?></span><?php endif; ?>
  </a>
  <?= render_partial('sidebar-widgets') ?>
  <?php /* 插件侧边栏注入（M5） */ do_action('sidebar_inject'); ?>
</aside>
<?php endif; ?>

<div class="app-content<?= $showSidebar ? '' : ' app-content-full' ?>">

  <?php /* 桌面顶栏 */ ?>
  <header class="app-header">
    <nav class="app-nav"><?= render_partial('public-nav', ['items' => $navItems]) ?></nav>
    <div class="app-header-tools">
      <?= render_partial('site-search') ?>
      <?php if ($loggedIn): ?>
        <a href="<?= e(url_to('/admin')) ?>" class="app-header-link">后台</a>
      <?php else: ?>
        <a href="<?= e(url_to('/login')) ?>" class="btn btn-primary btn-sm">登录</a>
      <?php endif; ?>
      <?= render_partial('theme-toggle') ?>
    </div>
  </header>

  <?php /* 移动端顶栏 */ ?>
  <header class="app-header-mobile">
    <a href="<?= e(url_to('/')) ?>" class="app-brand-mobile"><?= e($siteName) ?></a>
    <div class="app-header-tools">
      <?= render_partial('theme-toggle') ?>
      <?= render_partial('mobile-nav', ['items' => $navItems, 'loggedIn' => $loggedIn]) ?>
    </div>
  </header>

  <main class="app-main">
