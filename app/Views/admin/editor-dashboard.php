<?php /** Editor dashboard; uses the same neutral cards and shortcuts as the administrator. */ ?>
<div class="admin-stack admin-dashboard">
  <div class="admin-page-head">
    <div><h1 class="admin-h1"><?= e(t('admin.dashboard')) ?></h1><p class="admin-page-sub"><?= e(t('admin.hello')) ?><?= e($username) ?><?= e(t('admin.welcome_back')) ?></p></div>
  </div>
  <?php require __DIR__ . '/partials/dashboard-stats.php'; ?>
  <section class="card admin-dashboard-panel">
    <div class="admin-dashboard-panel-head"><h2><?= e(t('admin.quick_actions')) ?></h2></div>
    <div class="admin-dashboard-shortcuts">
      <?php foreach ([
        ['/admin/posts/new', 'pen', t('admin.write_a_post')], ['/admin/micro/new', 'message', t('admin.write_a_note')],
        ['/admin/uploads', 'upload', t('admin.media_library')], ['/admin/comments', 'message', t('admin.post_comments')],
        ['/admin/profile', 'user', t('admin.profile')], ['/admin/posts', 'file-text', t('admin.my_posts')],
      ] as [$href, $icon, $label]): ?>
        <a href="<?= e(url_to($href)) ?>"><span class="admin-dashboard-shortcut-icon"><?= admin_icon($icon, 20) ?></span><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </div>
  </section>
</div>
