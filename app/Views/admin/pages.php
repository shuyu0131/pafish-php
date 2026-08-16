<?php
/**
 * 页面管理列表（对齐 Node app/admin/pages/ 列表）
 * 变量：$pages $homePageId $templateOptions $role
 * 操作：设为首页/取消（fetch POST /admin/pages/set-home）、编辑、删除（硬删除，无回收站）
 */
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">页面管理</h1>
    </div>
    <div class="admin-head-actions">
      <a class="btn btn-primary" href="<?= e(url_to('/admin/pages/new')) ?>">
        <?= admin_icon('file-plus', 15) ?>新建页面
      </a>
    </div>
  </div>

  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>标题</th>
          <th>状态</th>
          <th>模板</th>
          <th>更新时间</th>
          <th class="admin-col-ops">操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($pages === []): ?>
          <tr><td colspan="5">
            <div class="admin-empty-list">
              <?= admin_icon('file-plus', 32) ?>
              <p>还没有页面</p>
              <a class="btn btn-outline" href="<?= e(url_to('/admin/pages/new')) ?>">新建第一个页面</a>
            </div>
          </td></tr>
        <?php else: ?>
          <?php foreach ($pages as $p): ?>
            <?php $isHome = (string) $p['id'] === $homePageId; ?>
            <tr data-page-row="<?= (int) $p['id'] ?>">
              <td>
                <a class="admin-post-title" href="<?= e(url_to('/admin/pages/' . $p['id'] . '/edit')) ?>">
                  <?php if ($isHome): ?><span class="badge badge-accent">首页</span><?php endif; ?>
                  <span class="admin-post-title-text"><?= e($p['title']) ?></span>
                </a>
                <div class="admin-post-meta">
                  <span class="badge <?= $p['status'] === 'PUBLISHED' ? 'badge-success' : '' ?>">
                    <?= $p['status'] === 'PUBLISHED' ? '已发布' : '草稿' ?>
                  </span>
                  <span>/<?= e($p['slug']) ?></span>
                </div>
              </td>
              <td class="admin-muted"><?= e(($templateOptions[$p['template']] ?? $p['template'])) ?></td>
              <td class="admin-muted"><?= e(format_date($p['updated_at'], 'yyyy-MM-dd HH:mm')) ?></td>
              <td class="admin-col-ops">
                <div class="admin-row-ops">
                  <?php if ($p['status'] === 'PUBLISHED'): ?>
                    <a class="admin-icon-btn" href="<?= e(url_to('/pages/' . rawurlencode($p['slug']))) ?>" target="_blank" rel="noopener" title="查看"><?= admin_icon('eye', 15) ?></a>
                  <?php endif; ?>
                  <?php if ($isHome): ?>
                    <button type="button" class="admin-icon-btn admin-icon-danger" data-set-home="<?= (int) $p['id'] ?>" data-set="0" title="取消首页"><?= admin_icon('home', 15) ?></button>
                  <?php else: ?>
                    <button type="button" class="admin-icon-btn" data-set-home="<?= (int) $p['id'] ?>" data-set="1" title="设为首页"><?= admin_icon('home', 15) ?></button>
                  <?php endif; ?>
                  <a class="admin-icon-btn" href="<?= e(url_to('/admin/pages/' . $p['id'] . '/edit')) ?>" title="编辑"><?= admin_icon('edit', 15) ?></a>
                  <button type="button" class="admin-icon-btn admin-icon-danger" data-delete-page="<?= (int) $p['id'] ?>"
                          data-name="<?= e($p['title']) ?>" title="删除"><?= admin_icon('trash', 15) ?></button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
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
          if (j && j.ok) location.reload();
          else alert((j && j.error) || "操作失败");
        })
        .catch(function () { alert("网络错误"); });
    });
  });

  // 删除（硬删除，无回收站 → 二次确认）
  document.querySelectorAll("[data-delete-page]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var id = btn.getAttribute("data-delete-page");
      var name = btn.getAttribute("data-name") || "";
      if (!confirm("确定删除页面「" + name + "」？此操作不可恢复！")) return;
      post(<?= json_encode(url_to('/admin/pages')) ?> + "/" + id + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) {
            var row = btn.closest("[data-page-row]");
            if (row) row.remove();
            if (!document.querySelector("[data-page-row]")) location.reload();
          } else alert((j && j.error) || "删除失败");
        })
        .catch(function () { alert("网络错误"); });
    });
  });
})();
</script>
