<?php
/**
 * 页面管理列表
 * 变量：$items $total $page $totalPages $per $perOptions $filters $homePageId $templateOptions $role
 * 操作：设为首页/取消（fetch POST /admin/pages/set-home）、编辑、删除（硬删除，无回收站）
 */
?>
<div class="admin-stack admin-list-page admin-pages-list">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.pages')) ?></h1>
      <p class="admin-page-sub"><?= e(t('admin.total')) ?> <?= (int) $total ?> <?= e(t('admin.pages_375d9f')) ?></p>
    </div>
    <div class="admin-head-actions"><a class="btn btn-primary" href="<?= e(url_to('/admin/pages/new')) ?>"><?= admin_icon('file-plus', 15) ?><span><?= e(t('admin.new_page')) ?></span></a></div>
  </div>

  <form class="admin-list-toolbar admin-list-filter-form" action="<?= e(url_to('/admin/pages')) ?>" method="get" role="search">
    <input type="search" class="input" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="<?= e(t('admin.search_title_or_url')) ?>" autocomplete="off">
    <select class="input" name="status" aria-label="<?= e(t('admin.filter_status')) ?>" onchange="this.form.submit()">
      <option value=""><?= e(t('admin.all_statuses')) ?></option>
      <option value="PUBLISHED"<?= ($filters['status'] ?? '') === 'PUBLISHED' ? ' selected' : '' ?>><?= e(t('admin.published')) ?></option>
      <option value="DRAFT"<?= ($filters['status'] ?? '') === 'DRAFT' ? ' selected' : '' ?>><?= e(t('admin.draft')) ?></option>
    </select>
    <button class="btn btn-primary" type="submit"><?= e(t('admin.filter')) ?></button>
    <?php if (($filters['q'] ?? '') !== '' || ($filters['status'] ?? '') !== ''): ?><a class="btn btn-ghost" href="<?= e(url_to('/admin/pages')) ?>"><?= e(t('common.clear')) ?></a><?php endif; ?>
  </form>

  <div class="admin-table-wrap">
    <table class="admin-table admin-pages-table">
      <thead>
        <tr>
          <th><?= e(t('common.title')) ?></th>
          <th class="admin-page-template admin-table-secondary"><?= e(t('admin.template')) ?></th>
          <th class="admin-list-date admin-table-secondary"><?= e(t('admin.updated')) ?></th>
          <th class="admin-col-ops"><?= e(t('common.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($items === []): ?>
          <tr><td colspan="4">
            <div class="admin-empty-list">
              <?= admin_icon('file-plus', 32) ?>
              <p><?= e(t('admin.no_pages_yet')) ?></p>
              <a class="btn btn-outline" href="<?= e(url_to('/admin/pages/new')) ?>"><?= e(t('admin.create_your_first_page')) ?></a>
            </div>
          </td></tr>
        <?php else: ?>
          <?php foreach ($items as $p): ?>
            <?php $isHome = (string) $p['id'] === $homePageId; ?>
            <tr data-page-row="<?= (int) $p['id'] ?>">
              <td class="admin-post-title-cell">
                <div class="admin-post-title-line">
                  <?php if ($isHome): ?><span class="badge badge-accent"><?= e(t('admin.home')) ?></span><?php endif; ?>
                  <?php if ($p['status'] !== 'PUBLISHED'): ?><span class="admin-list-status"><?= e(t('admin.draft')) ?></span><?php endif; ?>
                  <a class="admin-post-title" href="<?= e(url_to('/admin/pages/' . $p['id'] . '/edit')) ?>"><span class="admin-post-title-text"><?= e($p['title']) ?></span></a>
                </div>
                <div class="admin-post-meta">
                  <span>/<?= e($p['slug']) ?></span>
                </div>
                <div class="admin-table-mobile-meta">
                  <span><?= e(($templateOptions[$p['template']] ?? $p['template'])) ?></span>
                  <time title="<?= e(t('admin.updated')) ?>" datetime="<?= e((string) $p['updated_at']) ?>"><?= e(format_date($p['updated_at'], 'yyyy-MM-dd')) ?></time>
                </div>
              </td>
              <td class="admin-page-template admin-table-secondary" data-label="<?= e(t('admin.template')) ?>"><?= e(($templateOptions[$p['template']] ?? $p['template'])) ?></td>
              <td class="admin-list-date admin-table-secondary" data-label="<?= e(t('admin.updated')) ?>"><?= e(format_date($p['updated_at'], 'yyyy-MM-dd')) ?></td>
              <td class="admin-col-ops" data-label="<?= e(t('common.actions')) ?>">
                <div class="admin-row-ops">
                  <?php if ($p['status'] === 'PUBLISHED'): ?>
                    <a class="admin-icon-btn" href="<?= e(content_url('page', $p)) ?>" target="_blank" rel="noopener" title="<?= e(t('admin.view')) ?>"><?= admin_icon('eye', 15) ?></a>
                  <?php endif; ?>
                  <?php if ($isHome): ?>
                    <button type="button" class="admin-icon-btn admin-icon-danger" data-set-home="<?= (int) $p['id'] ?>" data-set="0" title="<?= e(t('admin.unset_homepage')) ?>"><?= admin_icon('home', 15) ?></button>
                  <?php else: ?>
                    <button type="button" class="admin-icon-btn" data-set-home="<?= (int) $p['id'] ?>" data-set="1" title="<?= e(t('admin.set_as_homepage')) ?>"><?= admin_icon('home', 15) ?></button>
                  <?php endif; ?>
                  <a class="admin-icon-btn" href="<?= e(url_to('/admin/pages/' . $p['id'] . '/edit')) ?>" title="<?= e(t('common.edit')) ?>"><?= admin_icon('edit', 15) ?></a>
                  <button type="button" class="admin-icon-btn admin-icon-danger" data-delete-page="<?= (int) $p['id'] ?>"
                          data-name="<?= e($p['title']) ?>" title="<?= e(t('common.delete')) ?>"><?= admin_icon('trash', 15) ?></button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= admin_pagination($page, $totalPages, $total, static function (int $p, int $size = 20) use ($filters): string {
    $params = ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'per' => $size, 'page' => $p];
    $params = array_filter($params, static fn ($value, $key): bool => $value !== '' && !($key === 'page' && $value === 1), ARRAY_FILTER_USE_BOTH);
    return url_to('/admin/pages') . ($params ? '?' . http_build_query($params) : '');
  }, $per, $perOptions) ?>
</div>

<?php $pageCsrf = csrf_token(); ?>
<script>
(function () {
  var token = <?= json_encode($pageCsrf) ?>;
  function post(url, body) {
    var fd = new FormData();
    Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
    fd.append("_csrf", token);
    return fetch(url, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
  }

  // 设为首页 / 取消
  document.querySelectorAll("[data-set-home]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      post(<?= json_encode(url_to('/admin/pages/set-home')) ?>, {
        id: btn.getAttribute("data-set-home"),
        set: btn.getAttribute("data-set")
      }).then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload(window.pafishT("js.page_status_updated"), "success");
          else pafishNotify((j && j.error) || window.pafishT("common.operation_failed"), true);
        })
        .catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
    });
  });

  // 删除（硬删除，无回收站 → 二次确认）
  document.querySelectorAll("[data-delete-page]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var id = btn.getAttribute("data-delete-page");
      var name = btn.getAttribute("data-name") || "";
      (window.pafishConfirm ? window.pafishConfirm(window.pafishT("js.confirm_delete_page", {name: name}), { title: window.pafishT("js.delete_page_661cc5") }) : Promise.resolve(window.confirm(window.pafishT("js.confirm_delete_page", {name: name})))).then(function (ok) {
        if (!ok) return;
        return post(<?= json_encode(url_to('/admin/pages')) ?> + "/" + id + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) {
            var row = btn.closest("[data-page-row]");
            if (row) row.remove();
            if (document.querySelector("[data-page-row]")) pafishToast(window.pafishT("js.page_deleted"), "success");
            else pafishToastReload(window.pafishT("js.page_deleted"), "success");
          } else pafishNotify((j && j.error) || window.pafishT("js.delete_failed"), true);
        })
        .catch(function () { pafishNotify(window.pafishT("js.network_error"), true); });
      });
    });
  });
})();
</script>
