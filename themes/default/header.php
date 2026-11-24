<?php
/**
 * 默认主题页头（覆盖系统 app/Views/theme/header.php，由默认主题及其子主题使用）
 * 与系统模板的区别：
 * - 站点标识：顶栏和移动端顶栏改用 site-brand.php 输出，支持文字 / 图片 / 图片 + 文字 Logo（主题设置「品牌」分组）
 * - 登录入口：移到工具区最右侧，可选文字链接 / 按钮 / 不显示（主题设置「导航栏登录入口」）；已登录时显示「后台」
 *系统 header.php 以后有改动时，记得同步到这里。
 * 可用数据：$title / $description（控制器传入，可选）
 */
$pageTitle = $seoTitle ?? $title ?? '';
$desc = $description ?? (string) settings('site_description', '');
$siteName = site_name();
$subtitle = (string) settings('site_subtitle', '');
$activeTheme = Theme::active();
$themeCss = Theme::css($activeTheme);
$layoutCss = Theme::layoutCss($activeTheme);
$themeHasIconFields = \Pafish\Services\ExtensionKernel::hasSettingType(Theme::settingsFields($activeTheme), 'icon');
// 侧栏属于主题布局选择；系统模板仅在主题明确声明时开启。
$showSidebar = theme_value('sidebar_enabled', '0') !== '0';
$navItems = nav_items();
$navTree = nav_tree($navItems);
$loggedIn = is_logged_in();
$titleOrder = (string) settings('seo_title_order', 'page_site');
$titleSeparator = (string) settings('seo_title_separator', ' · ');
if (!in_array($titleSeparator, [' · ', ' - ', ' | '], true)) {
    $titleSeparator = ' · ';
}
$hasPageTitle = $pageTitle !== '' && $pageTitle !== '首页' && $pageTitle !== $siteName;
$fullTitle = !$hasPageTitle
    ? $siteName
    : ($titleOrder === 'site_page' ? $siteName . $titleSeparator . $pageTitle : $pageTitle . $titleSeparator . $siteName);
$frontMeta = apply_filters('frontend_meta', [
    'title' => $fullTitle,
    'description' => $desc,
    'canonical' => is_array($og ?? null) ? (string) ($og['url'] ?? '') : '',
    'robots' => is_string($robots ?? null) ? $robots : '',
    'og' => is_array($og ?? null) ? $og : [],
    'twitter' => [],
    'jsonLd' => [],
], [
    'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
    'template' => $GLOBALS['pafish_tpl_ctx'] ?? [],
]);
$fullTitle = is_string($frontMeta['title'] ?? null) ? $frontMeta['title'] : $fullTitle;
$desc = is_string($frontMeta['description'] ?? null) ? $frontMeta['description'] : $desc;
$canonical = is_string($frontMeta['canonical'] ?? null) ? $frontMeta['canonical'] : '';
$robots = is_string($frontMeta['robots'] ?? null) ? $frontMeta['robots'] : '';
$ogMeta = is_array($frontMeta['og'] ?? null) ? $frontMeta['og'] : [];
$twitterMeta = is_array($frontMeta['twitter'] ?? null) ? $frontMeta['twitter'] : [];
$jsonLd = $frontMeta['jsonLd'] ?? [];
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fullTitle) ?></title>
<link rel="icon" href="<?= e(asset_url('/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset_url('/css/content.css')) ?>">
<?php if ($themeHasIconFields): ?>
<link rel="stylesheet" href="<?= e(asset_url('/css/remixicon.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/css/font-awesome.min.css')) ?>">
<?php endif; ?>
<?php if ($desc !== ''): ?><meta name="description" content="<?= e($desc) ?>"><?php endif; ?>
<?php if ($robots !== ''): ?><meta name="robots" content="<?= e($robots) ?>"><?php endif; ?>
<?php if ($canonical !== ''): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
<?php /* OG / canonical（控制器默认值可由 frontend_meta 过滤器覆盖） */ if ($ogMeta !== []): ?>
<meta property="og:type" content="<?= e($ogMeta['type'] ?? 'website') ?>">
<meta property="og:title" content="<?= e($ogMeta['title'] ?? $fullTitle) ?>">
<?php if (!empty($ogMeta['description'])): ?><meta property="og:description" content="<?= e($ogMeta['description']) ?>"><?php endif; ?>
<?php if (!empty($ogMeta['url'])): ?><meta property="og:url" content="<?= e($ogMeta['url']) ?>"><?php endif; ?>
<?php if (!empty($ogMeta['image'])): ?><meta property="og:image" content="<?= e($ogMeta['image']) ?>"><?php endif; ?>
<?php endif; ?>
<?php foreach ($twitterMeta as $key => $value): if (is_scalar($value) && (string) $value !== ''): ?>
<meta name="twitter:<?= e($key) ?>" content="<?= e($value) ?>">
<?php endif; endforeach; ?>
<?php
$jsonLdItems = [];
if (is_array($jsonLd)) {
    $jsonLdItems = array_is_list($jsonLd) ? $jsonLd : [$jsonLd];
}
foreach ($jsonLdItems as $item):
    if (!is_array($item)) continue;
?>
<script type="application/ld+json"><?= json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endforeach; ?>
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
<?php if ($layoutCss !== null || $themeCss !== null): ?>
<style data-theme="<?= e($activeTheme) ?>"><?= $layoutCss ?? '' ?><?= $themeCss ?? '' ?></style>
<?php endif; ?>
<?php $headInjectContext = ['meta' => $frontMeta, 'template' => $GLOBALS['pafish_tpl_ctx'] ?? []]; ?>
<?= \Pafish\Services\Theme::renderInjection('head', $headInjectContext) ?>
<?php /* 插件 head 注入（API v2 传入页面上下文） */ do_action('head_inject', $headInjectContext); ?>
<script>
/* API 路径适配：pretty_urls=false 时请求走 index.php?p=api/...，query 用 & 拼接 */
window.pafishApi = function (p) {
  var q = p.indexOf('?');
  var path = q >= 0 ? p.slice(0, q) : p;
  var query = q >= 0 ? p.slice(q + 1) : '';
  return <?= json_encode(url_to('/api')) ?> + path + (query ? <?= json_encode(Config::get('pretty_urls', true) ? '?' : '&') ?> + query : '');
};
</script>
</head>
<body>
<div class="app-shell<?= $showSidebar ? ' app-shell-with-sidebar' : '' ?>">

<?php if ($showSidebar): ?>
<aside class="app-sidebar">
  <a href="<?= e(url_to('/')) ?>" class="app-brand">
    <span class="app-brand-name"><?= e($siteName) ?></span>
    <?php if ($subtitle !== ''): ?><span class="app-brand-sub"><?= e($subtitle) ?></span><?php endif; ?>
  </a>
  <?php $sidebarWidgets = widget_items('sidebar'); ?>
  <?= render_partial('sidebar-widgets', ['area' => 'sidebar']) ?>
  <?php if (Theme::includes('default')): ?>
    <?= render_partial('default-sidebar', ['hasCustomWidgets' => $sidebarWidgets !== []]) ?>
  <?php endif; ?>
  <?php $sidebarInjectContext = ['template' => $GLOBALS['pafish_tpl_ctx'] ?? []]; ?>
  <?= \Pafish\Services\Theme::renderInjection('sidebar', $sidebarInjectContext) ?>
  <?php /* 插件侧边栏注入 */ do_action('sidebar_inject', $sidebarInjectContext); ?>
</aside>
<?php endif; ?>

<div class="app-content<?= $showSidebar ? '' : ' app-content-full' ?>">

  <?php /* 桌面顶栏 */ ?>
  <header class="app-header">
    <?php if (Theme::includes('default')): ?>
    <div class="app-header-nav">
      <?= render_partial('site-brand', ['brandVariant' => 'top']) ?>
      <?php /* public-nav.php 自身即输出 <nav class="app-nav" />，此处只包布局容器。 */ ?>
      <?= render_partial('public-nav', ['items' => $navItems, 'tree' => $navTree]) ?>
    </div>
    <?php else: ?>
      <?= render_partial('public-nav', ['items' => $navItems, 'tree' => $navTree]) ?>
    <?php endif; ?>
    <?php
    // 登录入口（主题设置「导航栏登录入口」）：link 文字链接 / button 按钮 / hidden 不显示；固定放在最右侧
    $loginEntry = theme_value('header_login', 'link');
    if (!in_array($loginEntry, ['link', 'button', 'hidden'], true)) {
        $loginEntry = 'link';
    }
    ?>
    <div class="app-header-tools">
      <?= render_partial('site-search') ?>
      <?= render_partial('theme-toggle') ?>
      <?php if ($loggedIn): ?>
        <a href="<?= e(url_to('/admin')) ?>" class="app-header-link app-header-account">后台</a>
      <?php elseif ($loginEntry !== 'hidden'): ?>
        <a href="<?= e(url_to('/login')) ?>" class="<?= $loginEntry === 'button' ? 'btn btn-primary btn-sm' : 'app-header-link' ?> app-header-account">登录</a>
      <?php endif; ?>
    </div>
  </header>

  <?php /* 移动端顶栏 */ ?>
  <header class="app-header-mobile">
    <?= render_partial('site-brand', ['brandVariant' => 'mobile']) ?>
    <div class="app-header-tools">
      <?= render_partial('theme-toggle') ?>
      <?= render_partial('mobile-nav', ['items' => $navItems, 'tree' => $navTree, 'loggedIn' => $loggedIn]) ?>
    </div>
  </header>

  <main class="app-main">
