<?php
/**
 * 后台布局：
 * 桌面：固定左侧导航 + 顶栏账户操作 + 内容区
 * 移动端：顶栏 + 抽屉（遮罩点击/Esc 关闭）；导航分组始终展开
 * 变量：$title $siteName $user $nav $currentPath $content
 */

function admin_nav_active(array $item, string $currentPath): bool
{
    if (!empty($item['exact'])) {
        return $currentPath === $item['href'];
    }
    return $currentPath === $item['href']
        || str_starts_with($currentPath, rtrim($item['href'], '/') . '/');
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - <?= e($siteName) ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="<?= e(asset_url('/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset_url('/css/admin.css')) ?>">
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
</script>
</head>
<body class="admin-body">
  <!-- 顶栏：保留账户操作与移动端抽屉入口。 -->
  <header class="admin-topbar">
    <div class="admin-topbar-start">
      <button type="button" class="admin-drawer-toggle admin-icon-btn" aria-label="打开菜单"><?= admin_icon('menu', 20) ?></button>
    </div>
    <div class="admin-topbar-actions">
      <a class="admin-topbar-front" href="<?= e(url_to('/')) ?>" target="_blank" rel="noopener"><?= admin_icon('external-link', 15) ?><span>查看网站</span></a>
      <a class="admin-topbar-account" href="<?= e(url_to('/admin/profile')) ?>" aria-label="个人资料" title="个人资料">
        <img class="admin-avatar-sm" src="<?= e($user['avatar_url'] ?: admin_gravatar((string) $user['email'])) ?>" alt="" width="28" height="28">
        <span><?= e($user['nickname'] ?: $user['username']) ?></span>
      </a>
      <form class="admin-topbar-logout" method="post" action="<?= e(url_to('/api/auth/logout')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="admin-topbar-exit" aria-label="退出登录" title="退出登录"><?= admin_icon('logout', 15) ?><span>退出登录</span></button>
      </form>
    </div>
  </header>
  <div class="admin-drawer-backdrop" hidden></div>

  <!-- 侧边栏 -->
  <aside class="admin-sidebar" id="adminSidebar">
    <nav class="admin-nav">
      <?php foreach ($nav['top'] as $item): ?>
        <a class="admin-nav-item<?= admin_nav_active($item, $currentPath) ? ' active' : '' ?>"
           href="<?= e(url_to($item['href'])) ?>">
          <?= admin_icon($item['icon']) ?>
          <span><?= e($item['label']) ?></span>
          <?php if (!empty($item['badge'])): ?><span class="admin-nav-badge"><?= (int) $item['badge'] > 99 ? '99+' : (int) $item['badge'] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>

      <?php foreach ($nav['groups'] as $group): ?>
        <div class="admin-nav-group" data-group-id="<?= e($group['id']) ?>">
          <p class="admin-nav-group-toggle">
            <span><?= e($group['label']) ?></span>
          </p>
            <div class="admin-nav-group-items">
            <?php foreach ($group['items'] as $item): ?>
              <a class="admin-nav-item<?= admin_nav_active($item, $currentPath) ? ' active' : '' ?>"
                 href="<?= e(url_to($item['href'])) ?>">
                <?= admin_icon($item['icon']) ?>
                <span><?= e($item['label']) ?></span>
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
          'success' => '成功',
          'error' => '错误',
          'warning' => '警告',
          'info' => '提示',
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
            <button type="button" class="admin-toast-close" aria-label="关闭提示" title="关闭">&times;</button>
          </div>
        </div>
      <?php endif; ?>
      <?= $content ?>
    </div>
  </main>

<script>
(function () {
  // 移动端抽屉
  var body = document.body;
  var backdrop = document.querySelector('.admin-drawer-backdrop');
  function open() { body.classList.add('admin-drawer-open'); if (backdrop) backdrop.hidden = false; }
  function close() { body.classList.remove('admin-drawer-open'); if (backdrop) backdrop.hidden = true; }
  document.querySelectorAll('.admin-drawer-toggle').forEach(function (b) { b.addEventListener('click', open); });
  if (backdrop) backdrop.addEventListener('click', close);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

  // 退出登录（POST + CSRF，成功后回首页）
  // 选择器必须匹配顶栏表单的实际 class：一旦不匹配，表单会走浏览器原生提交，
  // 直接把页面导航到 /api/auth/logout 的 JSON 响应上（用户看到的"退出后跳到接口地址"）。
  // 注意不要用 res.url 做跳转目标——fetch 收到的是 JSON（200），res.url 就是接口地址本身。
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
</body>
</html>
