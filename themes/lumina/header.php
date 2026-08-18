<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$pageTitle = (string) ($title ?? '');
$siteName = site_name();
$accent = theme_value('accent_color', '#07c160');
if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) !== 1) {
    $accent = '#07c160';
}
$fullTitle = $pageTitle !== '' && $pageTitle !== '首页' && $pageTitle !== $siteName ? $pageTitle . ' - ' . $siteName : $siteName;
$descriptionText = (string) ($description ?? settings('site_description', ''));
$frontMeta = apply_filters('frontend_meta', [
    'title' => $fullTitle,
    'description' => $descriptionText,
    'canonical' => is_array($og ?? null) ? (string) ($og['url'] ?? '') : '',
    'robots' => '',
], ['path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', 'template' => $GLOBALS['pafish_tpl_ctx'] ?? []]);
$fullTitle = is_string($frontMeta['title'] ?? null) ? $frontMeta['title'] : $fullTitle;
$descriptionText = is_string($frontMeta['description'] ?? null) ? $frontMeta['description'] : $descriptionText;
$canonical = is_string($frontMeta['canonical'] ?? null) ? $frontMeta['canonical'] : '';
$robots = is_string($frontMeta['robots'] ?? null) ? $frontMeta['robots'] : '';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= e($fullTitle) ?></title>
<?php if ($descriptionText !== ''): ?><meta name="description" content="<?= e($descriptionText) ?>"><?php endif; ?>
<?php if ($robots !== ''): ?><meta name="robots" content="<?= e($robots) ?>"><?php endif; ?>
<?php if ($canonical !== ''): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
<link rel="icon" href="<?= e(lumina_asset_url('img/favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(lumina_asset_url('iconfont/iconfont.css')) ?>">
<script>
(function () {
  try {
    var saved = localStorage.getItem('pafish-theme');
    if (saved === 'dark' || (saved !== 'light' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark');
  } catch (e) {}
})();
</script>
<?php if (($layoutCss = \Pafish\Services\Theme::layoutCss('lumina')) !== null): ?><style data-theme="lumina"><?= $layoutCss ?></style><?php endif; ?>
<style>:root { --theme: <?= e($accent) ?>; --themetm: <?= e($accent) ?>1a; }</style>
<?php do_action('head_inject', ['template' => $GLOBALS['pafish_tpl_ctx'] ?? []]); ?>
<script>
window.pafishApi = function (p) {
  var q = p.indexOf('?'); var path = q >= 0 ? p.slice(0, q) : p; var query = q >= 0 ? p.slice(q + 1) : '';
  return <?= json_encode(url_to('/api')) ?> + path + (query ? <?= json_encode(\Pafish\Core\Config::get('pretty_urls', true) ? '?' : '&') ?> + query : '');
};
</script>
</head>
<body class="lumina-body">
