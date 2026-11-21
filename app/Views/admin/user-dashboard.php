<?php /** Account overview and paginated personal activity; independent of the front-end theme. */ ?>
<div class="admin-stack admin-dashboard admin-account">
  <header class="admin-page-head">
    <div><h1 class="admin-h1"><?= e(t('admin.account')) ?></h1><p class="admin-page-sub"><?= e((string) ($user['nickname'] ?: $user['username'])) ?></p></div>
    <?php if ($section === 'posts' && $canSubmit): ?><a class="btn btn-primary" href="<?= e(url_to('/admin/submissions/new')) ?>"><?= admin_icon('plus', 15) ?><?= e(t('admin.write_a_submission')) ?></a><?php endif; ?>
  </header>
  <?php if ($section === 'overview'): ?>
    <?php $statsUrlsResolved = true; require __DIR__ . '/partials/dashboard-stats.php'; ?>
  <?php else: ?>
    <section class="card admin-dashboard-panel">
      <div class="admin-dashboard-panel-head"><h2><?= e($sections[$section]) ?></h2><span class="admin-muted"><?= e(t('admin.total')) ?> <?= (int) $total ?> <?= e(t('admin.items')) ?></span></div>
      <?php if ($section === 'posts'): ?>
        <nav class="admin-tabs admin-status-tabs admin-submission-filters" aria-label="<?= e(t('admin.submission_status')) ?>">
          <?php foreach (['' => t('common.all'), 'DRAFT' => t('admin.draft'), 'PENDING' => t('admin.pending_review'), 'CHANGES_REQUESTED' => t('admin.request_changes'), 'PUBLISHED' => t('admin.published')] as $key => $label): ?>
            <a class="admin-tab<?= $submissionStatus === $key ? ' is-active' : '' ?>" <?= $submissionStatus === $key ? 'aria-current="page"' : '' ?> href="<?= e($sectionUrl('posts', 1, $per, $key)) ?>"><?= e($label) ?><?php if ($key !== ''): ?> <span class="admin-tab-count">(<?= (int) ($submissionCounts[$key] ?? 0) ?>)</span><?php endif; ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <?php if ($items === []): ?><p class="admin-empty"><?= $section === 'posts' && !$canSubmit ? t('admin.user_submissions_are_closed_b56952') : t('admin.no_records') ?></p><?php else: ?>
        <ul class="admin-account-list">
          <?php foreach ($items as $item): ?>
            <li>
              <div class="admin-account-list-main">
                <?php if ($section === 'points'): ?>
                  <span class="admin-account-amount"><?= (int) $item['amount'] > 0 ? '+' : '' ?><?= (int) $item['amount'] ?></span><span><?= e($item['reason']) ?></span>
                <?php elseif ($section === 'posts'): ?>
                  <div class="admin-account-post-title"><a href="<?= e(url_to('/admin/submissions/' . (int) $item['id'] . '/edit')) ?>"><?= e($item['title']) ?></a><span class="badge<?= $item['status'] === 'PUBLISHED' ? ' badge-success' : ($item['status'] === 'CHANGES_REQUESTED' ? ' badge-warning' : '') ?>"><?= e(['DRAFT' => t('admin.draft'), 'PENDING' => t('admin.pending_review'), 'CHANGES_REQUESTED' => t('admin.request_changes'), 'PUBLISHED' => t('admin.published'), 'SCHEDULED' => t('admin.scheduled')][$item['status']] ?? $item['status']) ?></span></div>
                  <?php if ($item['status'] === 'CHANGES_REQUESTED' && trim((string) ($item['review_note'] ?? '')) !== ''): ?><p class="admin-account-review-note"><strong><?= e(t('admin.review_feedback_67df42')) ?></strong><?= e($item['review_note']) ?></p><?php endif; ?>
                  <?php if ($item['reviewed_at'] && !empty($item['reviewer_username'])): ?><p class="admin-account-review-meta"><?= e(t('admin.last_reviewed')) ?><?= e((string) (($item['reviewer_nickname'] ?? '') ?: $item['reviewer_username'])) ?> · <?= e(format_date($item['reviewed_at'], 'yyyy-MM-dd HH:mm')) ?></p><?php endif; ?>
                  <div class="admin-account-post-actions"><a href="<?= e(url_to('/admin/submissions/' . (int) $item['id'] . '/edit')) ?>"><?= e(t('common.edit')) ?></a><?php if ($item['status'] === 'PUBLISHED'): ?><a href="<?= e(content_url('post', $item)) ?>" target="_blank" rel="noopener"><?= e(t('admin.view_post')) ?></a><?php endif; ?><form method="post" action="<?= e(url_to('/admin/submissions/' . (int) $item['id'] . '/delete')) ?>" data-confirm="<?= e(t('admin.confirm_withdraw_post', ['title' => $item['title']])) ?>"><?= csrf_field() ?><button class="admin-link-button" type="submit"><?= e(t('admin.withdraw')) ?></button></form></div>
                <?php else: ?>
                  <a href="<?= e(content_url('post', ['id' => $item['post_id'], 'slug' => $item['slug'], 'published_at' => $item['published_at'] ?? null, 'created_at' => $item['post_created_at'] ?? $item['created_at'] ?? null]) . ($section === 'comments' ? '#comment-' . (int) $item['id'] : '')) ?>"><?= e($item['title']) ?></a>
                  <?php if ($section === 'comments'): ?><p><?= e($item['content']) ?></p><span class="badge<?= $item['status'] === 'APPROVED' ? ' badge-success' : '' ?>"><?= e(['APPROVED' => t('admin.visible'), 'PENDING' => t('admin.pending_review'), 'REJECTED' => t('admin.failed'), 'TRASH' => t('admin.deleted')][$item['status']] ?? t('admin.hidden_d62b59')) ?></span><?php endif; ?>
                <?php endif; ?>
              </div>
              <time><?= e(format_date($item[$section === 'posts' ? 'updated_at' : 'created_at'], 'yyyy-MM-dd HH:mm')) ?></time>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?= admin_pagination($page, $pages, $total, fn ($p, $n) => $sectionUrl($section, $p, $n, $section === 'posts' ? $submissionStatus : ''), $per, $perOptions) ?>
  <?php endif; ?>
</div>
