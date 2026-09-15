<?php
/** @var array $stats $latest $pendingComments */
?>
<div class="admin-stack editor-dashboard">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">写作台</h1>
      <p class="admin-page-sub">你好，<?= e($username) ?> · 管理你的内容与互动</p>
    </div>
    <div class="admin-head-actions"><a class="btn btn-primary" href="<?= e(url_to('/admin/posts/new')) ?>"><?= admin_icon('file-plus', 15) ?> 写文章</a></div>
  </div>

  <div class="admin-stats">
    <?php foreach ($stats as $stat): ?>
      <div class="card admin-stat-card">
        <div class="admin-stat-head"><span class="admin-stat-icon" style="color: <?= e($stat['color']) ?>; background-color: <?= e($stat['color']) ?>1f"><?= admin_icon($stat['icon'], 16) ?></span><span class="admin-stat-label"><?= e($stat['label']) ?></span></div>
        <p class="admin-stat-value"><?= number_format((int) $stat['value']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="editor-dashboard-grid">
    <section>
      <div class="editor-dashboard-section-head"><h2 class="admin-chart-title">待审评论</h2><a href="<?= e(url_to('/admin/comments?status=PENDING')) ?>">全部查看</a></div>
      <div class="card admin-latest">
        <?php if ($pendingComments === []): ?><p class="admin-empty">暂无待审核评论。</p><?php endif; ?>
        <?php foreach ($pendingComments as $comment): ?>
          <a class="admin-latest-item" href="<?= e(url_to('/admin/comments?status=PENDING')) ?>">
            <div class="admin-latest-main"><p class="admin-latest-title"><?= e($comment['author_name']) ?> · <?= e($comment['post_title']) ?></p><p class="admin-latest-sub"><?= e(mb_strimwidth((string) $comment['content'], 0, 54, '…')) ?></p></div>
            <span class="admin-muted"><?= e(format_date($comment['created_at'], 'MM-dd')) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <section>
    <div class="editor-dashboard-section-head"><h2 class="admin-chart-title">最近更新</h2><a href="<?= e(url_to('/admin/posts')) ?>">文章管理</a></div>
    <div class="card admin-latest">
      <?php if ($latest === []): ?><p class="admin-empty">还没有文章，开始记录第一篇吧。</p><?php endif; ?>
      <?php foreach ($latest as $post): ?>
        <?php $labels = ['DRAFT' => '草稿', 'PUBLISHED' => '已发布', 'SCHEDULED' => '定时']; $statusClass = $post['status'] === 'PUBLISHED' ? 'badge-success' : ($post['status'] === 'SCHEDULED' ? 'badge-warning' : ''); ?>
        <a class="admin-latest-item" href="<?= e(url_to('/admin/posts/' . $post['id'] . '/edit')) ?>"><div class="admin-latest-main"><p class="admin-latest-title"><?= e($post['title']) ?></p><p class="admin-latest-sub">更新于 <?= e(format_date($post['updated_at'], 'yyyy-MM-dd HH:mm')) ?></p></div><span class="badge <?= $statusClass ?>"><?= e($labels[$post['status']] ?? $post['status']) ?></span></a>
      <?php endforeach; ?>
    </div>
  </section>
</div>
