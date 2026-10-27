<?php

declare(strict_types=1);

$siteName = site_name();
$title = (string) ($seoTitle ?? $title ?? $siteName);
$description = (string) ($description ?? settings('site_description', ''));
$og = is_array($og ?? null) ? $og : [];
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$meta = apply_filters('frontend_meta', [
    'title' => $title !== '' && $title !== $siteName ? $title . ' · ' . $siteName : $siteName,
    'description' => $description,
    'canonical' => (string) ($og['url'] ?? ''),
    'robots' => (string) ($robots ?? ''),
    'og' => $og,
    'twitter' => [],
    'jsonLd' => [],
], ['path' => $requestPath, 'template' => $GLOBALS['pafish_tpl_ctx'] ?? []]);
$activeTheme = \Pafish\Services\Theme::active();
$navItems = nav_items();
$basePath = rtrim((string) \Pafish\Core\Url::base(), '/');
$currentPath = $requestPath;
if ($basePath !== '' && str_starts_with($currentPath, $basePath)) {
    $currentPath = substr($currentPath, strlen($basePath)) ?: '/';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title><?= e($meta['title'] ?? $siteName) ?></title>
  <?php if (!empty($meta['description'])): ?><meta name="description" content="<?= e($meta['description']) ?>"><?php endif; ?>
  <?php if (!empty($meta['canonical'])): ?><link rel="canonical" href="<?= e($meta['canonical']) ?>"><?php endif; ?>
  <?php if (!empty($meta['robots'])): ?><meta name="robots" content="<?= e($meta['robots']) ?>"><?php endif; ?>
  <?php $ogMeta = is_array($meta['og'] ?? null) ? $meta['og'] : []; ?>
  <?php foreach (['type' => 'og:type', 'title' => 'og:title', 'description' => 'og:description', 'url' => 'og:url', 'image' => 'og:image'] as $key => $property): ?>
    <?php if (!empty($ogMeta[$key])): ?><meta property="<?= e($property) ?>" content="<?= e($ogMeta[$key]) ?>"><?php endif; ?>
  <?php endforeach; ?>
  <link rel="stylesheet" href="<?= e(asset_url('/css/content.css')) ?>">
  <link rel="stylesheet" href="<?= e(theme_asset_url($activeTheme, 'assets/css/style.css')) ?>">
  <link rel="stylesheet" href="<?= e(theme_asset_url($activeTheme, 'theme.css')) ?>">
  <script>(function(){try{var s=localStorage.getItem('pafish-theme');if(s==='dark'||(s!=='light'&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark')}catch(e){}})();</script>
  <?php $jsonLd = $meta['jsonLd'] ?? []; $jsonLd = is_array($jsonLd) ? (array_is_list($jsonLd) ? $jsonLd : [$jsonLd]) : []; ?>
  <?php foreach ($jsonLd as $item): if (is_array($item)): ?><script type="application/ld+json"><?= json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><?php endif; endforeach; ?>
  <?php $headContext = ['meta' => $meta, 'template' => $GLOBALS['pafish_tpl_ctx'] ?? []]; ?>
  <?= \Pafish\Services\Theme::renderInjection('head', $headContext) ?>
  <?php do_action('head_inject', $headContext); ?>
</head>
<body class="fn-site">
  <div class="fn-shell">
    <header class="fn-header">
      <a class="fn-brand" href="<?= e(url_to('/')) ?>"><span class="fn-brand-mark" aria-hidden="true">✳</span><span><?= e($siteName) ?></span></a>
      <nav class="fn-nav" aria-label="主导航">
        <?php foreach ($navItems as $item): $href = !empty($item['is_external']) ? (string) ($item['url'] ?? '#') : url_to((string) ($item['url'] ?? '#')); ?>
          <a href="<?= e($href) ?>"<?= !empty($item['is_external']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?><?= empty($item['is_external']) && nav_is_active((string) ($item['url'] ?? '#'), $currentPath) ? ' aria-current="page"' : '' ?>><?= e($item['label'] ?? '') ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="fn-header-actions">
        <a href="<?= e(url_to('/search')) ?>" aria-label="搜索文章">搜索</a>
        <?php if (is_logged_in()): ?><a href="<?= e(url_to('/admin')) ?>">后台</a><?php else: ?><a href="<?= e(url_to('/login')) ?>">登录</a><?php endif; ?>
        <?= render_partial('theme-toggle') ?>
      </div>
    </header>
    <main class="fn-main">
