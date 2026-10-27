<?php
/** 独立站点框架。保留主题和插件需要的公共 head/sidebar 注入点。 */
$siteName = site_name();
$pageTitle = (string) ($seoTitle ?? $title ?? $siteName);
$description = (string) ($description ?? settings('site_description', ''));
$frontMeta = apply_filters('frontend_meta', [
    'title' => $pageTitle !== '' && $pageTitle !== $siteName ? $pageTitle . ' · ' . $siteName : $siteName,
    'description' => $description,
    'canonical' => is_array($og ?? null) ? (string) ($og['url'] ?? '') : '',
    'robots' => is_string($robots ?? null) ? $robots : '',
    'og' => is_array($og ?? null) ? $og : [],
    'twitter' => [],
    'jsonLd' => [],
], ['path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', 'template' => $GLOBALS['pafish_tpl_ctx'] ?? []]);
$activeTheme = \Pafish\Services\Theme::active();
$navItems = nav_items();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = rtrim((string) \Pafish\Core\Url::base(), '/');
if ($basePath !== '' && str_starts_with($currentPath, $basePath)) $currentPath = substr($currentPath, strlen($basePath)) ?: '/';
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($frontMeta['title'] ?? $siteName) ?></title>
  <?php if (!empty($frontMeta['description'])): ?><meta name="description" content="<?= e($frontMeta['description']) ?>"><?php endif; ?>
  <?php if (!empty($frontMeta['canonical'])): ?><link rel="canonical" href="<?= e($frontMeta['canonical']) ?>"><?php endif; ?>
  <?php if (!empty($frontMeta['robots'])): ?><meta name="robots" content="<?= e($frontMeta['robots']) ?>"><?php endif; ?>
  <?php $ogMeta = is_array($frontMeta['og'] ?? null) ? $frontMeta['og'] : []; ?>
  <?php foreach (['type' => 'og:type', 'title' => 'og:title', 'description' => 'og:description', 'url' => 'og:url', 'image' => 'og:image'] as $key => $property): ?>
    <?php if (!empty($ogMeta[$key])): ?><meta property="<?= e($property) ?>" content="<?= e($ogMeta[$key]) ?>"><?php endif; ?>
  <?php endforeach; ?>
  <link rel="stylesheet" href="<?= e(asset_url('/css/content.css')) ?>">
  <link rel="stylesheet" href="<?= e(theme_asset_url($activeTheme, 'assets/css/style.css')) ?>">
  <link rel="stylesheet" href="<?= e(theme_asset_url($activeTheme, 'theme.css')) ?>">
  <script>(function(){try{var s=localStorage.getItem('pafish-theme');if(s==='dark'||(s!=='light'&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark')}catch(e){}})();</script>
  <?php $jsonLdItems = is_array($frontMeta['jsonLd'] ?? null) ? $frontMeta['jsonLd'] : []; $jsonLdItems = array_is_list($jsonLdItems) ? $jsonLdItems : [$jsonLdItems]; ?>
  <?php foreach ($jsonLdItems as $jsonLd): if (is_array($jsonLd)): ?>
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php endif; endforeach; ?>
  <?php $headInjectContext = ['meta' => $frontMeta, 'template' => $GLOBALS['pafish_tpl_ctx'] ?? []]; ?>
  <?= \Pafish\Services\Theme::renderInjection('head', $headInjectContext) ?>
  <?php do_action('head_inject', $headInjectContext); ?>
  <script>
  window.pafishApi = function (path) {
    var q = path.indexOf('?'), base = q < 0 ? path : path.slice(0, q), query = q < 0 ? '' : path.slice(q + 1);
    return <?= json_encode(url_to('/api')) ?> + base + (query ? <?= json_encode(\Pafish\Core\Config::get('pretty_urls', true) ? '?' : '&') ?> + query : '');
  };
  </script>
</head>
<body>
<div class="starter-frame">
  <header class="starter-header">
    <a class="starter-brand" href="<?= e(url_to('/')) ?>"><?= e($siteName) ?></a>
    <nav class="starter-nav" aria-label="主导航">
      <?php foreach ($navItems as $item): $href = !empty($item['is_external']) ? (string) ($item['url'] ?? '#') : url_to((string) ($item['url'] ?? '#')); ?>
        <a href="<?= e($href) ?>"<?= !empty($item['is_external']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?><?= empty($item['is_external']) && nav_is_active((string) ($item['url'] ?? '#'), $currentPath) ? ' aria-current="page"' : '' ?>><?= e($item['label'] ?? '') ?></a>
      <?php endforeach; ?>
      <?php if (\Pafish\Services\Theme::hasArchivePage()): ?>
        <a href="<?= e(url_to('/archives')) ?>">归档</a>
      <?php endif; ?>
      <a href="<?= e(url_to('/micro')) ?>">微语</a>
    </nav>
    <div class="starter-tools">
      <form action="<?= e(url_to('/search')) ?>" method="get" role="search">
        <input class="starter-search" type="search" name="q" maxlength="100" placeholder="搜索文章" aria-label="搜索文章">
        <button class="starter-search-submit" type="submit">搜索</button>
      </form>
      <?php if (is_logged_in()): ?><a href="<?= e(url_to('/admin')) ?>">后台</a><?php else: ?><a href="<?= e(url_to('/login')) ?>">登录</a><?php endif; ?>
      <button type="button" class="theme-toggle" aria-label="切换深色模式" title="切换深色模式" hidden><span class="theme-toggle-icon">◐</span></button>
    </div>
  </header>
  <aside class="starter-extension-sidebar" aria-label="扩展侧栏">
    <?php $sidebarInjectContext = ['template' => $GLOBALS['pafish_tpl_ctx'] ?? []]; ?>
    <?= \Pafish\Services\Theme::renderInjection('sidebar', $sidebarInjectContext) ?>
    <?php do_action('sidebar_inject', $sidebarInjectContext); ?>
  </aside>
  <main class="starter-main">
