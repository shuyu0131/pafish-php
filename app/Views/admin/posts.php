<?php
/**
 * 文章管理列表
 * 变量：$posts $total $totalPages $page $per $perOptions $params(status/category/author/q/sort)
 *       $counts $isTrash $catTree $statusLabel $role
 */

/** 列表页 URL 构造（保留现有筛选，空值/默认排序不写入；patch 含 page 时重置页） */
function admin_posts_url(array $params, array $patch, int $per, bool $includePage = false): string
{
    $merged = array_merge($params, $patch);
    $sp = [];
    if (($merged['status'] ?? '') !== '') $sp['status'] = $merged['status'];
    if (($merged['category'] ?? '') !== '') $sp['category'] = $merged['category'];
    if (($merged['author'] ?? '') !== '') $sp['author'] = $merged['author'];
    if (($merged['q'] ?? '') !== '') $sp['q'] = $merged['q'];
    if (($merged['sort'] ?? '') !== 'latest') $sp['sort'] = $merged['sort'];
    if ($per !== 20) $sp['per'] = $per;
    if ($includePage && isset($merged['page'])) $sp['page'] = $merged['page'];
    $qs = http_build_query($sp);
    return $qs !== '' ? '/admin/posts?' . $qs : '/admin/posts';
}

$tabItems = [
    ['key' => '', 'label' => t('common.all'), 'count' => null],
    ['key' => 'PUBLISHED', 'label' => t('admin.published'), 'count' => $counts['PUBLISHED'] ?? 0],
    ['key' => 'PENDING', 'label' => t('admin.pending_review'), 'count' => $counts['PENDING'] ?? 0],
    ['key' => 'CHANGES_REQUESTED', 'label' => t('admin.request_changes'), 'count' => $counts['CHANGES_REQUESTED'] ?? 0],
    ['key' => 'DRAFT', 'label' => t('admin.draft'), 'count' => $counts['DRAFT'] ?? 0],
    ['key' => 'SCHEDULED', 'label' => t('admin.scheduled'), 'count' => $counts['SCHEDULED'] ?? 0],
    ['key' => 'trash', 'label' => t('admin.trash'), 'count' => $counts['TRASH'] ?? 0],
];
$sortItems = [
    ['key' => 'latest', 'label' => t('admin.recently_published')],
    ['key' => 'updated', 'label' => t('admin.recently_updated')],
    ['key' => 'pinned', 'label' => t('admin.pinned_first')],
    ['key' => 'views', 'label' => t('admin.most_viewed')],
    ['key' => 'comments', 'label' => t('admin.most_commented')],
];
$canEdit = in_array($role ?? '', ['ADMIN', 'EDITOR'], true);
$canReview = ($role ?? '') === 'ADMIN';
?>
<div class="admin-stack admin-list-page admin-posts-list">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.posts')) ?></h1>
      <p class="admin-page-sub">
        <?= e(t('admin.total')) ?> <?= $total ?> <?= e(t('admin.posts_69007d')) ?><?= $params['q'] !== '' ? t('admin.search') . e($params['q']) . '”' : '' ?>
      </p>
    </div>
  <?php if ($canEdit): ?>
  <div class="admin-head-actions">
    <?php if ($canReview && ($counts['PENDING'] ?? 0) > 0): ?><a class="btn btn-outline" href="<?= e(url_to('/admin/posts?status=PENDING')) ?>"><?= e(t('admin.pending_review')) ?> <?= (int) $counts['PENDING'] ?></a><?php endif; ?>
    <a class="btn btn-primary" href="<?= e(url_to('/admin/posts/new')) ?>"><?= admin_icon('pen', 15) ?><?= e(t('admin.write_a_post')) ?></a>
    <a class="btn btn-outline" href="<?= e(url_to('/admin/posts/import')) ?>"><?= admin_icon('upload', 15) ?><?= e(t('admin.import_markdown')) ?></a>
  </div>
  <?php endif; ?>
  </div>
  <div class="admin-list-toolbar">
  <!-- 状态 tabs -->
  <nav class="admin-tabs admin-status-tabs" aria-label="<?= e(t('admin.post_status')) ?>">
    <?php foreach ($tabItems as $t): ?>
      <?php $active = ($params['status'] ?? '') === $t['key']; ?>
      <a class="admin-tab <?= $active ? 'is-active' : '' ?>"
         <?= $active ? 'aria-current="page"' : '' ?>
         href="<?= e(url_to(admin_posts_url($params, ['status' => $t['key']], $per))) ?>">
        <?= e($t['label']) ?>
        <?php if ($t['count'] !== null): ?><span class="admin-tab-count">(<?= (int) $t['count'] ?>)</span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <!-- 列表筛选工具区 -->
  <div class="admin-filter-bar admin-posts-filter-bar">
    <div class="admin-filter-group">
      <select class="input admin-filter-select" data-filter-url="category"
              data-base="<?= e(url_to('/admin/posts')) ?>">
        <option value=""><?= e(t('admin.all_categories')) ?></option>
        <option value="none" <?= $params['category'] === 'none' ? 'selected' : '' ?>><?= e(t('admin.uncategorized')) ?></option>
        <?php foreach ($catTree as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $params['category'] === (string) $c['id'] ? 'selected' : '' ?>>
            <?= str_repeat('　', (int) $c['depth']) . e($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select class="input admin-filter-select" data-filter-url="sort">
        <?php foreach ($sortItems as $s): ?>
          <option value="<?= e($s['key']) ?>" <?= $params['sort'] === $s['key'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <form class="admin-search" method="get" action="<?= e(url_to('/admin/posts')) ?>">
        <?php foreach (['status' => $params['status'], 'category' => $params['category'], 'author' => $params['author'], 'sort' => $params['sort']] as $k => $v): ?>
          <?php if ($v !== '' && $v !== 'latest'): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <input class="input" type="search" name="q" value="<?= e($params['q']) ?>" placeholder="<?= e(t('admin.search_title_or_content')) ?>">
        <button type="submit" class="btn btn-outline"><?= admin_icon('search', 15) ?></button>
      </form>
    </div>
  </div>
  </div>

  <!-- 表格 -->
  <div class="admin-table-wrap">
    <table class="admin-table admin-posts-table">
      <thead>
        <tr>
          <th class="admin-col-check"><input type="checkbox" id="adminCheckAll" aria-label="<?= e(t('admin.select_all')) ?>"></th>
          <th><?= e(t('common.title')) ?></th>
          <th class="admin-post-category admin-table-secondary"><?= e(t('common.category')) ?></th>
          <th class="admin-post-author admin-table-secondary"><?= e(t('admin.author')) ?></th>
          <th class="admin-post-count"><?= e(t('common.comments')) ?></th>
          <th class="admin-post-count" data-stat="views"><?= e(t('admin.views')) ?></th>
          <th class="admin-post-engagement"><?= e(t('admin.likes_bookmarks')) ?></th>
          <th class="admin-list-date admin-table-secondary"><?= $isTrash ? t('admin.deleted_8d55c8') : ($params['sort'] === 'updated' ? t('admin.updated') : t('admin.published_b410c2')) ?></th>
          <th class="admin-col-ops"><?= e(t('common.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($posts === []): ?>
          <tr><td colspan="9">
            <div class="admin-empty-list">
              <?= admin_icon('file-text', 32) ?>
              <p><?= e(t('admin.no_posts_yet')) ?></p>
              <?php if ($canEdit): ?>
                <a class="btn btn-outline" href="<?= e(url_to('/admin/posts/new')) ?>"><?= e(t('admin.write_your_first_post')) ?></a>
              <?php endif; ?>
            </div>
          </td></tr>
        <?php endif; ?>
        <?php foreach ($posts as $p): ?>
          <?php $listTime = $isTrash ? ($p['deleted_at'] ?: $p['updated_at']) : ($params['sort'] === 'updated' ? $p['updated_at'] : ($p['published_at'] ?: $p['updated_at'])); ?>
          <tr data-row-id="<?= (int) $p['id'] ?>">
            <td class="admin-col-check"><input type="checkbox" class="admin-row-check" value="<?= (int) $p['id'] ?>"></td>
            <td class="admin-post-title-cell" data-label="<?= e(t('common.title')) ?>">
              <div class="admin-post-title-line">
                <?php if ($p['is_pinned']): ?><span class="badge badge-accent"><?= e(t('admin.pin')) ?></span><?php endif; ?>
                <?php if ($p['category_pinned']): ?><span class="badge admin-badge-cat-pin"><?= e(t('admin.pinned_in_category')) ?></span><?php endif; ?>
                <?php if ($p['password']): ?><span class="admin-mini-badge" title="<?= e(t('admin.password_required')) ?>"><?= admin_icon('lock', 12) ?></span><?php endif; ?>
                <?php if ($p['external_url']): ?><span class="admin-mini-badge" title="<?= e(t('admin.external_post_the_title_links_to_an_external')) ?>"><?= admin_icon('external-link', 12) ?></span><?php endif; ?>
                <?php if ($p['status'] !== 'PUBLISHED' && !$isTrash): ?><span class="admin-list-status"><?= e($statusLabel[$p['status']] ?? $p['status']) ?></span><?php endif; ?>
                <a class="admin-post-title" title="<?= e($p['title']) ?>" href="<?= e(url_to('/admin/posts/' . $p['id'] . '/edit')) ?>"><span class="admin-post-title-text"><?= e($p['title']) ?></span></a>
              </div>
              <div class="admin-table-mobile-meta">
                <?php if ($p['category_name']): ?><a href="<?= e(url_to(admin_posts_url($params, ['category' => (string) $p['category_id']], $per))) ?>"><?= e($p['category_name']) ?></a><?php else: ?><span><?= e(t('admin.uncategorized')) ?></span><?php endif; ?>
                <a href="<?= e(url_to(admin_posts_url($params, ['author' => (string) $p['author_id']], $per))) ?>"><?= e($p['author_username']) ?></a>
                <time title="<?= e(($isTrash ? t('admin.deleted_d9f0c4') : ($params['sort'] === 'updated' || !$p['published_at'] ? t('admin.updated_4668a1') : t('admin.published_55f0f1'))) . format_date($listTime, 'yyyy-MM-dd HH:mm')) ?>" datetime="<?= e((string) $listTime) ?>"><?= e(format_date($listTime, 'yyyy-MM-dd')) ?></time>
              </div>
            </td>
            <td class="admin-post-category admin-table-secondary" data-label="<?= e(t('common.category')) ?>">
              <?php if ($p['category_name']): ?>
                <a class="admin-table-filter-link" href="<?= e(url_to(admin_posts_url($params, ['category' => (string) $p['category_id']], $per))) ?>"><?= e($p['category_name']) ?></a>
              <?php else: ?><span class="admin-muted"><?= e(t('admin.uncategorized')) ?></span><?php endif; ?>
            </td>
            <td class="admin-post-author admin-table-secondary" data-label="<?= e(t('admin.author')) ?>"><a class="admin-table-filter-link" href="<?= e(url_to(admin_posts_url($params, ['author' => (string) $p['author_id']], $per))) ?>"><?= e($p['author_username']) ?></a></td>
            <td class="admin-post-count" data-label="<?= e(t('common.comments')) ?>"><a class="admin-table-filter-link" href="<?= e(url_to('/admin/comments?status=ALL&post_id=' . (int) $p['id'])) ?>"><?= (int) $p['comment_count'] ?></a></td>
            <td class="admin-post-count" data-label="<?= e(t('admin.views')) ?>"><?= number_format((int) $p['view_count']) ?></td>
            <td class="admin-post-engagement" data-label="<?= e(t('admin.likes_bookmarks')) ?>"><span title="<?= e(t('admin.likes_and_bookmarks', ['likes' => (int) $p['like_count'], 'bookmarks' => (int) $p['favorite_count']])) ?>"><?= number_format((int) $p['like_count']) ?> / <?= number_format((int) $p['favorite_count']) ?></span></td>
            <td class="admin-list-date admin-table-secondary" data-label="<?= e(t('admin.time')) ?>"><time title="<?= e(($isTrash ? t('admin.deleted_d9f0c4') : ($params['sort'] === 'updated' || !$p['published_at'] ? t('admin.updated_4668a1') : t('admin.published_55f0f1'))) . format_date($listTime, 'yyyy-MM-dd HH:mm')) ?>" datetime="<?= e((string) $listTime) ?>"><?= e(format_date($listTime, 'yyyy-MM-dd')) ?></time></td>
            <td class="admin-col-ops" data-label="<?= e(t('common.actions')) ?>">
              <div class="admin-row-ops">
                <?php if ($canReview && !$isTrash && $p['status'] === 'PENDING'): ?>
                  <form class="admin-inline-form" method="post" action="<?= e(url_to('/admin/posts/' . (int) $p['id'] . '/review')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="decision" value="publish">
                    <button type="submit" class="btn btn-primary btn-sm" title="<?= e(t('admin.approve_and_publish')) ?>"><?= e(t('admin.approve')) ?></button>
                  </form>
                  <details class="admin-review-return">
                    <summary class="admin-icon-btn" title="<?= e(t('admin.request_changes')) ?>" aria-label="<?= e(t('admin.request_changes')) ?>"><?= admin_icon('corner-down-right', 15) ?></summary>
                    <form method="post" action="<?= e(url_to('/admin/posts/' . (int) $p['id'] . '/review')) ?>">
                      <?= csrf_field() ?><input type="hidden" name="decision" value="changes_requested">
                      <label class="admin-sr-only" for="review-note-list-<?= (int) $p['id'] ?>"><?= e(t('admin.change_request')) ?></label>
                      <textarea class="input" id="review-note-list-<?= (int) $p['id'] ?>" name="review_note" required maxlength="2000" rows="3" placeholder="<?= e(t('admin.describe_the_changes_required')) ?>"></textarea>
                      <button type="submit" class="btn btn-outline btn-sm"><?= e(t('admin.return')) ?></button>
                    </form>
                  </details>
                <?php endif; ?>
                <a class="admin-icon-btn" href="<?= e(url_to('/admin/posts/' . $p['id'] . '/edit')) ?>" title="<?= e(t('common.edit')) ?>"><?= admin_icon('edit', 15) ?></a>
                <?php if (!$isTrash && $p['status'] === 'PUBLISHED'): ?>
                  <a class="admin-icon-btn" href="<?= e(content_url('post', $p)) ?>" target="_blank" rel="noopener" title="<?= e(t('admin.view')) ?>"><?= admin_icon('eye', 15) ?></a>
                <?php endif; ?>
                <?php if ($isTrash): ?>
                  <form class="admin-inline-form" method="post" action="<?= e(url_to('/admin/posts/' . $p['id'] . '/restore')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="admin-icon-btn" title="<?= e(t('admin.restore')) ?>"><?= admin_icon('arrow-left', 15) ?></button>
                  </form>
                  <form class="admin-inline-form" method="post" action="<?= e(url_to('/admin/posts/' . $p['id'] . '/purge')) ?>"
                        data-confirm="<?= e(t('admin.confirm_purge_post', ['title' => $p['title']])) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="admin-icon-btn admin-danger-btn" title="<?= e(t('admin.delete_permanently')) ?>"><?= admin_icon('trash', 15) ?></button>
                  </form>
                <?php else: ?>
                  <form class="admin-inline-form" method="post" action="<?= e(url_to('/admin/posts/' . $p['id'] . '/delete')) ?>"
                        data-confirm="<?= e(t('admin.confirm_trash_post', ['title' => $p['title']])) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="admin-icon-btn admin-danger-btn" title="<?= e(t('admin.move_to_trash')) ?>"><?= admin_icon('trash', 15) ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- 批量操作栏 -->
  <form method="post" action="<?= e(url_to('/admin/posts/batch')) ?>" class="admin-batch-bar">
    <?= csrf_field() ?>
    <input type="hidden" name="ids" value="">
    <span class="admin-batch-count"><?= e(t('admin.selected')) ?> <span class="admin-batch-count-number">0</span> <?= e(t('admin.posts_69007d')) ?></span>
    <?php if ($isTrash): ?>
      <select class="input admin-batch-op" name="op" aria-label="<?= e(t('admin.batch_actions')) ?>">
        <option value="restore"><?= e(t('admin.restore')) ?></option>
        <option value="purge" data-batch-confirm="<?= e(t('admin.confirm_purge_posts')) ?>"><?= e(t('admin.delete_permanently')) ?></option>
      </select>
    <?php else: ?>
      <select class="input admin-batch-op" name="op" aria-label="<?= e(t('admin.batch_actions')) ?>">
        <option value="publish"><?= e(t('admin.publish_now')) ?></option>
        <option value="draft"><?= e(t('admin.move_to_draft')) ?></option>
        <option value="pin"><?= e(t('admin.pin')) ?></option>
        <option value="unpin"><?= e(t('admin.unpin')) ?></option>
        <option value="move"><?= e(t('admin.move_to_category')) ?></option>
        <option value="delete" data-batch-confirm="<?= e(t('admin.confirm_trash_posts')) ?>"><?= e(t('admin.move_to_trash')) ?></option>
      </select>
      <select class="input admin-batch-move" name="category_id">
        <option value=""><?= e(t('admin.choose_category')) ?></option>
        <option value="none"><?= e(t('admin.uncategorized')) ?></option>
        <?php foreach ($catTree as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= str_repeat('　', (int) $c['depth']) . e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary admin-batch-apply"><?= e(t('admin.app')) ?></button>
    <button type="button" class="btn btn-ghost" data-batch-clear><?= e(t('admin.deselect')) ?></button>
  </form>

  <?= admin_pagination($page, $totalPages, $total, static function (int $p, int $size = 20) use ($params): string {
    return url_to(admin_posts_url($params, ['page' => $p], $size, true));
  }, $per, $perOptions) ?>
</div>

<script src="<?= e(asset_url('/js/admin-posts.js')) ?>"></script>
