<?php
/**
 * 前台页头（系统默认模板；主题可覆盖 themes/{active}/header.php）
 * 可用数据：$title / $description（控制器传入，可选）
 * 前台布局：左侧 40% 侧边栏（桌面）+ 右侧 60% 内容栏 + 移动端顶栏
 */
$pageTitle = $title ?? '';
$desc = $description ?? (string) settings('site_description', '');
$siteName = site_name();
$subtitle = (string) settings('site_subtitle', '');
$activeTheme = Theme::active();
$themeCss = Theme::css($activeTheme);
$layoutCss = Theme::layoutCss($activeTheme);
$showSidebar = theme_value('sidebar_enabled', '1') !== '0';
$navItems = nav_items();
$loggedIn = is_logged_in();
$fullTitle = $pageTitle !== '' && $pageTitle !== '首页' && $pageTitle !== site_name()
    ? $pageTitle . ' · ' . $siteName
    : $siteName;
$frontMeta = apply_filters('frontend_meta', [
    'title' => $fullTitle,
    'description' => $desc,
    'canonical' => is_array($og ?? null) ? (string) ($og['url'] ?? '') : '',
    'robots' => '',
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
<?php /* 插件 head 注入（API v2 传入页面上下文） */ do_action('head_inject', ['meta' => $frontMeta, 'template' => $GLOBALS['pafish_tpl_ctx'] ?? []]); ?>
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
<div class="app-shell">

<?php if ($showSidebar): ?>
<aside class="app-sidebar">
  <a href="<?= e(url_to('/')) ?>" class="app-brand">
    <span class="app-brand-name"><?= e($siteName) ?></span>
    <?php if ($subtitle !== ''): ?><span class="app-brand-sub"><?= e($subtitle) ?></span><?php endif; ?>
  </a>
  <?= render_partial('sidebar-widgets') ?>
  <?php /* 插件侧边栏注入 */ do_action('sidebar_inject', ['template' => $GLOBALS['pafish_tpl_ctx'] ?? []]); ?>
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
