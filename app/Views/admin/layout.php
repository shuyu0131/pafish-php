<?php
/**
 * 后台布局：
 * 桌面：固定左侧栏（品牌 → 分组导航 → 用户信息/退出/查看前台）+ 内容区
 * 移动端：顶栏 + 抽屉（遮罩点击/Esc 关闭）；分组折叠状态记忆于 localStorage admin_nav_collapsed
 * 变量：$title $siteName $user $nav $unreadNotifications $currentPath $content
 */

function admin_nav_active(array $item, string $currentPath): bool
{
    if (!empty($item['exact'])) {
        return $currentPath === $item['href'];
    }
    return $currentPath === $item['href']
        || str_starts_with($currentPath, rtrim($item['href'], '/') . '/');
}
$roleLabel = match ((string) ($user['role'] ?? '')) {
    'ADMIN' => '管理员',
    'EDITOR' => '编辑',
    default => '用户',
};
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - <?= e($siteName) ?></title>
<meta name="robots" content="noindex,nofollow">
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
  <!-- 移动端顶栏 -->
  <header class="admin-topbar">
    <button type="button" class="admin-drawer-toggle admin-icon-btn" aria-label="打开菜单"><?= admin_icon('menu', 20) ?></button>
    <a class="admin-topbar-brand" href="<?= e(url_to('/admin')) ?>"><?= e($siteName) ?></a>
    <a class="admin-topbar-account" href="<?= e(url_to('/admin/profile')) ?>">
      <img class="admin-avatar-sm" src="<?= e($user['avatar_url'] ?: admin_gravatar((string) $user['email'])) ?>" alt="" width="28" height="28">
      <span><?= e($user['nickname'] ?: $user['username']) ?></span>
    </a>
  </header>
  <div class="admin-drawer-backdrop" hidden></div>

  <!-- 侧边栏 -->
  <aside class="admin-sidebar" id="adminSidebar">
    <a class="admin-brand" href="<?= e(url_to('/admin')) ?>"><?= e($siteName) ?></a>

    <nav class="admin-nav">
      <?php foreach ($nav['top'] as $item): ?>
        <a class="admin-nav-item<?= admin_nav_active($item, $currentPath) ? ' active' : '' ?>"
           href="<?= e(url_to($item['href'])) ?>">
          <?= admin_icon($item['icon']) ?>
          <span><?= e($item['label']) ?></span>
          <?php if (($item['href'] ?? '') === '/admin/notifications' && $unreadNotifications > 0): ?>
            <span class="admin-nav-badge"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>

      <?php foreach ($nav['groups'] as $group): ?>
        <div class="admin-nav-group" data-group-id="<?= e($group['id']) ?>">
          <button type="button" class="admin-nav-group-toggle">
            <span><?= e($group['label']) ?></span>
            <?= admin_icon('chevron', 14) ?>
          </button>
            <div class="admin-nav-group-items">
            <?php foreach ($group['items'] as $item): ?>
              <a class="admin-nav-item<?= admin_nav_active($item, $currentPath) ? ' active' : '' ?>"
                 href="<?= e(url_to($item['href'])) ?>">
                <?= admin_icon($item['icon']) ?>
                <span><?= e($item['label']) ?></span>
                <?php if (($item['href'] ?? '') === '/admin/notifications' && $unreadNotifications > 0): ?>
                  <span class="admin-nav-badge"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>

    <div class="admin-sidebar-foot">
      <a class="admin-user" href="<?= e(url_to('/admin/profile')) ?>" title="个人资料">
        <img class="admin-avatar" src="<?= e($user['avatar_url'] ?: admin_gravatar((string) $user['email'])) ?>"
             alt="" width="32" height="32">
        <div class="admin-user-meta">
          <p class="admin-user-name"><?= e($user['nickname'] ?: $user['username']) ?></p>
          <p class="admin-user-sub"><?= e($roleLabel) ?> · <?= e($user['username']) ?></p>
        </div>
      </a>
      <div class="admin-user-actions">
        <a class="admin-icon-btn" href="<?= e(url_to('/')) ?>" title="查看博客前台"><?= admin_icon('home', 17) ?></a>
        <form class="admin-logout" method="post" action="<?= e(url_to('/api/auth/logout')) ?>">
          <?= csrf_field() ?>
          <button type="submit" class="admin-icon-btn" title="退出登录"><?= admin_icon('logout', 17) ?></button>
        </form>
      </div>
    </div>
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

  // 导航分组折叠（localStorage 记忆）
  var key = 'admin_nav_collapsed';
  var saved = [];
  try { saved = JSON.parse(localStorage.getItem(key) || '[]'); } catch (err) {}
  document.querySelectorAll('.admin-nav-group').forEach(function (g) {
    var id = g.dataset.groupId;
    if (saved.indexOf(id) !== -1) g.classList.add('collapsed');
    g.querySelector('.admin-nav-group-toggle').addEventListener('click', function () {
      g.classList.toggle('collapsed');
      var arr = [];
      try { arr = JSON.parse(localStorage.getItem(key) || '[]'); } catch (err) {}
      var i = arr.indexOf(id);
      if (g.classList.contains('collapsed')) { if (i === -1) arr.push(id); }
      else if (i !== -1) arr.splice(i, 1);
      localStorage.setItem(key, JSON.stringify(arr));
    });
  });

  // 退出登录（POST + CSRF，成功后回首页）
  document.querySelectorAll('.admin-logout').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      fetch(f.action, { method: 'POST', body: new FormData(f) })
        .then(function () { location.href = f.dataset.home || '/'; })
        .catch(function () { location.href = f.dataset.home || '/'; });
    });
  });

})();
</script>
</body>
</html>
