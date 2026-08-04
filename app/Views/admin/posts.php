<?php
/**
 * 文章管理列表（对齐 Node posts-manager.tsx + admin/posts/page.tsx）
 * 变量：$posts $total $totalPages $page $per $perOptions $params(status/category/q/sort)
 *       $counts $isTrash $catTree $statusLabel $role
 */

/** 列表页 URL 构造（保留现有筛选，空值/默认排序不写入；patch 含 page 时重置页） */
function admin_posts_url(array $params, array $patch, int $per, bool $includePage = false): string
{
    $merged = array_merge($params, $patch);
    $sp = [];
    if (($merged['status'] ?? '') !== '') $sp['status'] = $merged['status'];
    if (($merged['category'] ?? '') !== '') $sp['category'] = $merged['category'];
    if (($merged['q'] ?? '') !== '') $sp['q'] = $merged['q'];
    if (($merged['sort'] ?? '') !== 'latest') $sp['sort'] = $merged['sort'];
    if ($per !== 20) $sp['per'] = $per;
    if ($includePage && isset($merged['page'])) $sp['page'] = $merged['page'];
    $qs = http_build_query($sp);
    return $qs !== '' ? '/admin/posts?' . $qs : '/admin/posts';
}

$tabItems = [
    ['key' => '', 'label' => '全部'],
    ['key' => 'PUBLISHED', 'label' => '已发布 (' . ($counts['PUBLISHED'] ?? 0) . ')'],
    ['key' => 'DRAFT', 'label' => '草稿 (' . ($counts['DRAFT'] ?? 0) . ')'],
    ['key' => 'SCHEDULED', 'label' => '定时 (' . ($counts['SCHEDULED'] ?? 0) . ')'],
    ['key' => 'trash', 'label' => '回收站 (' . ($counts['TRASH'] ?? 0) . ')'],
];
$sortItems = [
    ['key' => 'latest', 'label' => '最新发布'],
    ['key' => 'updated', 'label' => '最近更新'],
    ['key' => 'pinned', 'label' => '置顶优先'],
    ['key' => 'views', 'label' => '浏览最多'],
    ['key' => 'comments', 'label' => '评论最多'],
];
$canEdit = in_array($role ?? '', ['ADMIN', 'EDITOR'], true);
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">文章管理</h1>
      <p class="admin-page-sub">
        共 <?= $total ?> 篇<?= $params['q'] !== '' ? ' · 搜索“' . e($params['q']) . '”' : '' ?>
      </p>
    </div>
    <?php if ($canEdit): ?>
      <div class="admin-head-actions">
        <a class="btn btn-outline" href="<?= e(url_to('/admin/posts/import')) ?>">
          <?= admin_icon('upload', 15) ?>导入 Markdown
        </a>
        <a class="btn btn-primary" href="<?= e(url_to('/admin/posts/new')) ?>">
          <?= admin_icon('pen', 15) ?>写文章
        </a>
      </div>
    <?php endif; ?>
  </div>

  <!-- 状态 tabs -->
  <div class="admin-tabs">
    <?php foreach ($tabItems as $t): ?>
      <?php $active = ($params['status'] ?? '') === $t['key']; ?>
      <a class="btn <?= $active ? 'btn-primary' : 'btn-outline' ?> admin-tab"
         href="<?= e(url_to(admin_posts_url($params, ['status' => $t['key']], $per))) ?>">
        <?= e($t['label']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- 筛选行 -->
  <div class="admin-filter-bar">
    <div class="admin-filter-group">
      <!-- 分类下拉（原生 select，链接跳转） -->
      <select class="input admin-filter-select" data-filter-url="category"
              data-base="<?= e(url_to('/admin/posts')) ?>">
        <option value="">全部分类</option>
        <option value="none" <?= $params['category'] === 'none' ? 'selected' : '' ?>>未分类</option>
        <?php foreach ($catTree as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $params['category'] === (string) $c['id'] ? 'selected' : '' ?>>
            <?= str_repeat('　', (int) $c['depth']) . e($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <!-- 排序 -->
      <select class="input admin-filter-select" data-filter-url="sort">
        <?php foreach ($sortItems as $s): ?>
          <option value="<?= e($s['key']) ?>" <?= $params['sort'] === $s['key'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
        <?php endforeach; ?>
      </select>

      <!-- 搜索 -->
      <form class="admin-search" method="get" action="<?= e(url_to('/admin/posts')) ?>">
        <?php foreach (['status' => $params['status'], 'category' => $params['category'], 'sort' => $params['sort']] as $k => $v): ?>
          <?php if ($v !== '' && $v !== 'latest'): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <input class="input" type="search" name="q" value="<?= e($params['q']) ?>" placeholder="搜索标题或内容…">
        <button type="submit" class="btn btn-outline"><?= admin_icon('search', 15) ?></button>
      </form>
    </div>

    <!-- 每页条数（cookie 记忆） -->
    <select class="input admin-filter-select" id="adminPerPage">
      <?php foreach ($perOptions as $opt): ?>
        <option value="<?= $opt ?>" <?= $per === $opt ? 'selected' : '' ?>><?= $opt ?> 条/页</option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- 批量操作栏 -->
  <form method="post" action="<?= e(url_to('/admin/posts/batch')) ?>" class="admin-batch-bar" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="ids" value="">
    <span class="admin-batch-count">已选 <b>0</b> 篇</span>
    <?php if ($isTrash): ?>
      <button type="submit" name="op" value="restore" class="btn btn-outline">恢复</button>
      <button type="submit" name="op" value="purge" class="btn btn-danger" data-batch-confirm="确定彻底删除选中的 {n} 篇文章？此操作不可恢复！">彻底删除</button>
    <?php else: ?>
      <button type="submit" name="op" value="publish" class="btn btn-outline">立即发布</button>
      <button type="submit" name="op" value="draft" class="btn btn-outline">转草稿</button>
      <button type="submit" name="op" value="pin" class="btn btn-outline">置顶</button>
      <button type="submit" name="op" value="unpin" class="btn btn-outline">取消置顶</button>
      <select class="input admin-batch-move" name="category_id">
        <option value="">移动到分类…</option>
        <option value="">未分类</option>
        <?php foreach ($catTree as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= str_repeat('　', (int) $c['depth']) . e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" name="op" value="move" class="btn btn-outline" data-batch-move>移动</button>
      <button type="submit" name="op" value="delete" class="btn btn-danger" data-batch-confirm="确定将选中的 {n} 篇文章移入回收站？可在回收站恢复。">移入回收站</button>
    <?php endif; ?>
    <button type="button" class="btn btn-ghost" data-batch-clear>取消选择</button>
  </form>

  <!-- 表格 -->
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th class="admin-col-check"><input type="checkbox" id="adminCheckAll" aria-label="全选"></th>
          <th>标题</th>
          <th class="admin-col-md">分类</th>
          <th class="admin-col-lg">作者</th>
          <th class="admin-col-lg">评论</th>
          <th class="admin-col-sm">浏览</th>
          <th>更新时间</th>
          <th class="admin-col-ops">操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($posts === []): ?>
          <tr><td colspan="8">
            <div class="admin-empty-list">
              <?= admin_icon('file-text', 32) ?>
              <p>暂无文章</p>
              <?php if ($canEdit): ?>
                <a class="btn btn-outline" href="<?= e(url_to('/admin/posts/new')) ?>">去写第一篇</a>
              <?php endif; ?>
            </div>
          </td></tr>
        <?php endif; ?>
        <?php foreach ($posts as $p): ?>
          <tr data-row-id="<?= (int) $p['id'] ?>">
            <td><input type="checkbox" class="admin-row-check" value="<?= (int) $p['id'] ?>"></td>
            <td>
              <a class="admin-post-title" href="<?= e(url_to('/admin/posts/' . $p['id'] . '/edit')) ?>">
                <?php if ($p['is_pinned']): ?><span class="badge badge-accent">置顶</span><?php endif; ?>
                <?php if ($p['category_pinned']): ?><span class="badge admin-badge-cat-pin">分类置顶</span><?php endif; ?>
                <?php if ($p['password']): ?><span class="admin-mini-badge" title="需要密码访问"><?= admin_icon('lock', 12) ?></span><?php endif; ?>
                <?php if ($p['external_url']): ?><span class="admin-mini-badge" title="外链文章，点击标题跳转外链"><?= admin_icon('external-link', 12) ?></span><?php endif; ?>
                <span class="admin-post-title-text"><?= e($p['title']) ?></span>
              </a>
              <div class="admin-post-meta">
                <span class="badge <?= $p['status'] === 'PUBLISHED' ? 'badge-success' : ($p['status'] === 'SCHEDULED' ? 'badge-warning' : '') ?>">
                  <?= e($statusLabel[$p['status']] ?? $p['status']) ?>
                </span>
                <?php if ($isTrash && $p['deleted_at']): ?>
                  <span>删除于 <?= e(format_date($p['deleted_at'], 'yyyy-MM-dd HH:mm')) ?></span>
                <?php elseif ($p['published_at']): ?>
                  <span>发布 <?= e(format_date($p['published_at'], 'yyyy-MM-dd HH:mm')) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td class="admin-col-md"><?= $p['category_name'] ? e($p['category_name']) : '<span class="admin-muted">未分类</span>' ?></td>
            <td class="admin-col-lg"><?= e($p['author_username']) ?></td>
            <td class="admin-col-lg"><?= (int) $p['comment_count'] ?></td>
            <td class="admin-col-sm"><?= number_format((int) $p['view_count']) ?></td>
            <td><?= e(format_date($p['updated_at'], 'yyyy-MM-dd HH:mm')) ?></td>
            <td class="admin-col-ops">
              <div class="admin-row-ops">
                <a class="admin-icon-btn" href="<?= e(url_to('/admin/posts/' . $p['id'] . '/edit')) ?>" title="编辑"><?= admin_icon('edit', 15) ?></a>
                <?php if (!$isTrash && $p['status'] === 'PUBLISHED'): ?>
                  <a class="admin-icon-btn" href="<?= e(url_to('/post/' . $p['slug'])) ?>" target="_blank" rel="noopener" title="查看"><?= admin_icon('eye', 15) ?></a>
                <?php endif; ?>
                <?php if ($isTrash): ?>
                  <form class="admin-inline-form" method="post" action="<?= e(url_to('/admin/posts/' . $p['id'] . '/restore')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="admin-icon-btn" title="恢复"><?= admin_icon('arrow-left', 15) ?></button>
                  </form>
                  <form class="admin-inline-form" method="post" action="<?= e(url_to('/admin/posts/' . $p['id'] . '/purge')) ?>"
                        data-confirm="确定彻底删除《<?= e($p['title']) ?>》？此操作不可恢复！">
                    <?= csrf_field() ?>
                    <button type="submit" class="admin-icon-btn admin-danger-btn" title="彻底删除"><?= admin_icon('trash', 15) ?></button>
                  </form>
                <?php else: ?>
                  <form class="admin-inline-form" method="post" action="<?= e(url_to('/admin/posts/' . $p['id'] . '/delete')) ?>"
                        data-confirm="确定将《<?= e($p['title']) ?>》移入回收站？可在回收站恢复。">
                    <?= csrf_field() ?>
                    <button type="submit" class="admin-icon-btn admin-danger-btn" title="移入回收站"><?= admin_icon('trash', 15) ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- 分页（全部页码，对齐 posts-manager） -->
  <?php if ($totalPages > 1): ?>
    <div class="admin-pager">
      <?php for ($n = 1; $n <= $totalPages; $n++): ?>
        <a class="btn btn-outline <?= $n === $page ? 'current' : '' ?>"
           href="<?= e(url_to(admin_posts_url($params, ['page' => $n], $per, true))) ?>"
           <?= $n === $page ? 'aria-current="page"' : '' ?>>
          <?= $n ?>
        </a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<script src="<?= e(asset_url('/js/admin-posts.js')) ?>"></script>
