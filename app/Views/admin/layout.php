<?php
/**
 * 后台布局：
 * 桌面：可收起的左侧导航 + 顶栏账户操作 + 内容区；导航分组始终展开
 * 移动端：顶栏 + 可访问的抽屉导航
 * 变量：$title $siteName $user $nav $currentPath $content
 */

$currentQuery = is_array($_GET ?? null) ? $_GET : [];
$requestQuery = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
if (is_string($requestQuery) && $requestQuery !== '') {
    parse_str($requestQuery, $requestQueryValues);
    $currentQuery = array_replace($requestQueryValues, $currentQuery);
}
$navHref = static function (array $item): string {
    $href = url_to((string) $item['href']);
    $query = is_array($item['query'] ?? null) ? $item['query'] : [];
    return $query === [] ? $href : $href . (str_contains($href, '?') ? '&' : '?') . http_build_query($query);
};
$extensionActivePath = '';
foreach (($nav['groups'] ?? []) as $group) {
    foreach (($group['items'] ?? []) as $navItem) {
        $activePath = (string) ($navItem['activePath'] ?? '');
        if ($activePath !== '' && ($currentPath === $activePath || str_starts_with($currentPath, rtrim($activePath, '/') . '/'))) {
            $extensionActivePath = $activePath;
            break 2;
        }
    }
}
$navActive = static function (array $item, string $currentPath) use ($currentQuery, $extensionActivePath): bool {
    $activePath = (string) ($item['activePath'] ?? '');
    if ($activePath !== '') {
        $normalizedPath = $currentPath === '/' ? '/' : rtrim($currentPath, '/');
        $normalizedActivePath = $activePath === '/' ? '/' : rtrim($activePath, '/');
        return $normalizedPath === $normalizedActivePath || str_starts_with($normalizedPath, $normalizedActivePath . '/');
    }
    if ($extensionActivePath !== '' && str_starts_with($extensionActivePath, rtrim((string) ($item['href'] ?? ''), '/') . '/')) {
        return false;
    }
    $itemPath = (string) ($item['href'] ?? '');
    $normalizedPath = $currentPath === '/' ? '/' : rtrim($currentPath, '/');
    $normalizedItemPath = $itemPath === '/' ? '/' : rtrim($itemPath, '/');
    if (!empty($item['exact'])) {
        if ($normalizedPath !== $normalizedItemPath) {
            return false;
        }
    } elseif ($normalizedPath !== $normalizedItemPath && !str_starts_with($normalizedPath, $normalizedItemPath . '/')) {
        return false;
    }
    $query = is_array($item['query'] ?? null) ? $item['query'] : [];
    foreach ($query as $key => $value) {
        $current = $currentQuery[$key] ?? null;
        if ((string) $current !== (string) $value && !($value === 'overview' && ($current === null || $current === ''))) {
            return false;
        }
    }
    return true;
};
?>
<!doctype html>
<html lang="<?= e(\Pafish\Core\I18n::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - <?= e($siteName) ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="<?= e(asset_url('/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset_url('/css/admin.css')) ?>">
<?= i18n_script() ?>
<script src="<?= e(asset_url('/js/admin-toast.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-ui.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-controls.js')) ?>"></script>
<?php if (!empty($headExtra)): ?><?= $headExtra ?><?php endif; ?>
<script>
/* API 路径适配：pretty_urls=false 时请求走 index.php?p=api/...，query 用 & 拼接 */
window.pafishApi = function (p) {
  var q = p.indexOf('?');
  var path = q >= 0 ? p.slice(0, q) : p;
  var query = q >= 0 ? p.slice(q + 1) : '';
  return <?= json_encode(url_to('/api')) ?> + path + (query ? <?= json_encode(Config::get('pretty_urls', true) ? '?' : '&') ?> + query : '');
};
window.pafishSlugPreviewUrl = <?= json_encode(url_to('/admin/slug-preview')) ?>;
window.pafishSessionConfig = <?= json_encode([
    'adminUrl' => url_to('/admin'),
    'statusUrl' => url_to('/api/auth/session'),
    'loginUrl' => url_to('/login'),
    'context' => hash('sha256', $user['id'] . ':' . csrf_token()),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= e(asset_url('/js/admin-session.js')) ?>"></script>
</head>
<body class="admin-body">
  <!-- 顶栏：保留账户操作与移动端抽屉入口。 -->
  <header class="admin-topbar">
    <div class="admin-topbar-start">
      <button type="button" class="admin-layout-toggle admin-drawer-toggle admin-icon-btn"
              aria-label="<?= e(t('admin.collapse_navigation')) ?>" title="<?= e(t('admin.collapse_navigation')) ?>"
              aria-controls="adminSidebar" aria-expanded="true" data-label-open="<?= e(t('admin.open_menu')) ?>"
              data-label-close="<?= e(t('common.close')) ?>" data-label-expand="<?= e(t('admin.expand_navigation')) ?>"
              data-label-collapse="<?= e(t('admin.collapse_navigation')) ?>">
        <span data-nav-icon="collapse"><?= admin_icon('panel-left-close', 18) ?></span>
        <span data-nav-icon="expand" hidden><?= admin_icon('panel-left-open', 18) ?></span>
        <span data-nav-icon="menu" hidden><?= admin_icon('menu', 18) ?></span>
      </button>
    </div>
    <div class="admin-topbar-actions">
      <a class="admin-topbar-front" href="<?= e(url_to('/')) ?>" target="_blank" rel="noopener"><?= admin_icon('external-link', 15) ?><span><?= e(t('admin.view_site')) ?></span></a>
      <a class="admin-topbar-account" href="<?= e(url_to('/admin/profile')) ?>" aria-label="<?= e(t('admin.profile')) ?>" title="<?= e(t('admin.profile')) ?>">
        <img class="admin-avatar-sm" src="<?= e(\Pafish\Http\Comments::avatarUrl($user['avatar_url'] ?? null, (string) $user['email'])) ?>" alt="" width="28" height="28">
        <span><?= e($user['nickname'] ?: $user['username']) ?></span>
      </a>
      <form class="admin-topbar-logout" method="post" action="<?= e(url_to('/api/auth/logout')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="admin-topbar-exit" aria-label="<?= e(t('admin.sign_out')) ?>" title="<?= e(t('admin.sign_out')) ?>"><?= admin_icon('logout', 15) ?><span><?= e(t('admin.sign_out')) ?></span></button>
      </form>
    </div>
  </header>
  <div class="admin-drawer-backdrop" hidden aria-hidden="true"></div>

  <!-- 侧边栏 -->
  <aside class="admin-sidebar" id="adminSidebar" aria-label="<?= e(t('admin.admin')) ?>">
    <nav class="admin-nav" aria-label="<?= e(t('admin.admin')) ?>">
      <?php foreach ($nav['top'] as $item): ?>
        <?php $itemActive = $navActive($item, $currentPath); $itemLabel = t((string) ($item['navLabel'] ?? $item['label'])); ?>
        <a class="admin-nav-item<?= $itemActive ? ' active' : '' ?>"
           href="<?= e($navHref($item)) ?>" aria-label="<?= e($itemLabel) ?>" title="<?= e(t($item['label'])) ?>"<?= $itemActive ? ' aria-current="page"' : '' ?>>
          <?= admin_icon($item['icon']) ?>
          <span><?= e($itemLabel) ?></span>
          <?php if (!empty($item['badge'])): ?><span class="admin-nav-badge"><?= (int) $item['badge'] > 99 ? '99+' : (int) $item['badge'] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>

      <?php foreach ($nav['groups'] as $group): ?>
        <div class="admin-nav-group" data-group-id="<?= e($group['id']) ?>">
          <p class="admin-nav-group-toggle">
            <span><?= e(t($group['label'])) ?></span>
          </p>
          <div class="admin-nav-group-items">
            <?php foreach ($group['items'] as $item): ?>
              <?php $itemActive = $navActive($item, $currentPath); $itemLabel = t((string) ($item['navLabel'] ?? $item['label'])); ?>
              <a class="admin-nav-item<?= $itemActive ? ' active' : '' ?>"
                 href="<?= e($navHref($item)) ?>" aria-label="<?= e($itemLabel) ?>" title="<?= e(t($item['label'])) ?>"<?= $itemActive ? ' aria-current="page"' : '' ?>>
                <?= admin_icon($item['icon']) ?>
                <span><?= e($itemLabel) ?></span>
                 <?php if (!empty($item['badge'])): ?><span class="admin-nav-badge"><?= (int) $item['badge'] > 99 ? '99+' : (int) $item['badge'] ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>

  </aside>

  <!-- 内容区 -->
  <main class="admin-main">
    <div class="admin-content">
      <?php if (is_array($flash ?? null) && ($flash['message'] ?? '') !== ''):
        $toastType = $flash['type'] ?? 'info';
        $toastIcons = [
          'success' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>',
          'error' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>',
          'warning' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
          'info' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>',
        ];
        $toastIcon = $toastIcons[$toastType] ?? $toastIcons['info'];
        $toastTitles = [
          'success' => t('common.success'),
          'error' => t('common.error'),
          'warning' => t('common.warning'),
          'info' => t('common.info'),
        ];
        $toastTitle = $toastTitles[$toastType] ?? $toastTitles['info'];
      ?>
        <div class="admin-toast-stack" data-toast-stack>
          <div class="admin-toast admin-toast-<?= e($toastType) ?>" data-toast role="<?= $toastType === 'error' ? 'alert' : 'status' ?>" aria-live="polite">
            <span class="admin-toast-icon" aria-hidden="true"><?= $toastIcon ?></span>
            <span class="admin-toast-content">
              <strong class="admin-toast-title"><?= e($toastTitle) ?></strong>
              <span class="admin-toast-msg"><?= e($flash['message']) ?></span>
            </span>
            <button type="button" class="admin-toast-close" aria-label="<?= e(t('admin.dismiss_notification')) ?>" title="<?= e(t('common.close')) ?>">&times;</button>
          </div>
        </div>
      <?php endif; ?>
      <?= $content ?>
    </div>
  </main>

<script>
(function () {
  // 退出登录（POST + CSRF，成功后回首页）

  // 直接把页面导航到 /api/auth/logout 的 JSON 响应上（用户看到的"退出后跳到接口地址"）。

  var logoutHome = <?= json_encode(url_to('/')) ?>;
  document.querySelectorAll('.admin-topbar-logout').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function () { location.href = f.dataset.home || logoutHome; })
        .catch(function () { location.href = f.dataset.home || logoutHome; });
    });
  });

})();
</script>
<script src="<?= e(asset_url('/js/admin-navigation.js')) ?>"></script>
<?= \Pafish\Services\Plugin::renderInjection('admin_footer', [
    'user' => $user,
    'role' => (string) ($user['role'] ?? ''),
    'currentPath' => (string) $currentPath,
]) ?>
</body>
</html>
