<?php
/**
 * 桌面顶栏导航（系统默认模板；主题可覆盖 themes/{active}/public-nav.php）
 * 可用数据：$items（导航项数组：label/url/is_external）
 */
$items = $items ?? [];
$current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base = rtrim((string) Url::base(), '/');
if ($base !== '' && str_starts_with($current, $base)) {
    $current = substr($current, strlen($base)) ?: '/';
}
?>
<nav class="app-nav" aria-label="主导航">
  <?php foreach ($items as $item): ?>
    <?php
    $label = (string) ($item['label'] ?? '');
    $url = (string) ($item['url'] ?? '#');
    $external = !empty($item['is_external']);
    $active = $external ? false : nav_is_active($url, $current);
    ?>
    <a class="app-nav-link<?= $active ? ' active' : '' ?>"
       href="<?= e($external ? $url : url_to($url)) ?>"
       <?= $external ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
      <?= e($label) ?>
    </a>
  <?php endforeach; ?>
</nav>
