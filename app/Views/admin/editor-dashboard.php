<?php /** Editor dashboard; uses the same neutral cards and shortcuts as the administrator. */ ?>
<div class="admin-stack admin-dashboard">
  <div class="admin-page-head">
    <div><h1 class="admin-h1">工作台</h1><p class="admin-page-sub">你好，<?= e($username) ?>，欢迎回来</p></div>
  </div>
  <?php require __DIR__ . '/partials/dashboard-stats.php'; ?>
  <section class="card admin-dashboard-panel">
    <div class="admin-dashboard-panel-head"><h2>快捷入口</h2></div>
    <div class="admin-dashboard-shortcuts">
      <?php foreach ([
        ['/admin/posts/new', 'pen', '写文章'], ['/admin/micro/new', 'message', '发微语'],
        ['/admin/uploads', 'upload', '媒体库'], ['/admin/comments', 'message', '文章评论'],
        ['/admin/profile', 'user', '个人资料'], ['/admin/posts', 'file-text', '我的文章'],
      ] as [$href, $icon, $label]): ?>
        <a href="<?= e(url_to($href)) ?>"><span class="admin-dashboard-shortcut-icon"><?= admin_icon($icon, 20) ?></span><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </div>
  </section>
</div>
