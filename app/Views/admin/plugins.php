<?php
$pluginTotal = count($plugins);
$pluginActive = count(array_filter($plugins, static fn (array $plugin): bool => !empty($plugin['active'])));
?>
<div class="admin-stack admin-extension-page admin-plugin-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">插件管理</h1>
    </div>
    <div class="admin-head-actions">
      <a class="btn btn-outline" href="<?= e(url_to('/admin/store')) ?>">应用商店</a>
      <button type="button" class="btn btn-primary" data-open-install="#plugin-install">安装插件</button>
    </div>
  </div>

  <div class="admin-resource-toolbar">
    <div class="admin-tabs admin-resource-tabs" role="tablist" aria-label="插件筛选">
      <button type="button" class="admin-tab is-active" data-plugin-filter="all" role="tab" aria-selected="true">全部 <span class="admin-resource-count"><?= $pluginTotal ?></span></button>
      <button type="button" class="admin-tab" data-plugin-filter="active" role="tab" aria-selected="false">已启用 <span class="admin-resource-count"><?= $pluginActive ?></span></button>
      <button type="button" class="admin-tab" data-plugin-filter="inactive" role="tab" aria-selected="false">未启用 <span class="admin-resource-count"><?= $pluginTotal - $pluginActive ?></span></button>
    </div>
    <input type="search" id="pluginSearch" class="input admin-resource-search" placeholder="搜索插件名称或描述" autocomplete="off">
  </div>

  <div class="admin-resource-list">
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>插件</th>
              <th class="admin-col-md">状态</th>
              <th class="admin-col-md">作者</th>
              <th class="admin-col-sm">版本</th>
              <th class="admin-col-ops">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($plugins === []): ?>
              <tr><td colspan="5">
                <div class="admin-empty-list">
                  <?= admin_icon('box', 32) ?>
                  <p>还没有插件</p>
                </div>
              </td></tr>
            <?php endif; ?>
            <?php foreach ($plugins as $p): ?>
              <tr data-plugin-state="<?= $p['active'] ? 'active' : 'inactive' ?>">
                <td>
                  <div class="admin-plugin-cell">
                    <span class="admin-plugin-icon" aria-hidden="true"><?= admin_icon('puzzle', 22) ?></span>
                    <div class="admin-plugin-info">
                    <div class="admin-plugin-title">
                    <?php if ($p['active'] && $p['settingsCount'] > 0): ?>
                      <a href="<?= e(url_to('/admin/plugins/' . rawurlencode($p['name']))) ?>" class="admin-plugin-name"><?= e($p['title']) ?></a>
                    <?php else: ?>
                      <span class="admin-plugin-name"><?= e($p['title']) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="admin-plugin-meta">
                    <?php if ($p['error'] !== null): ?>
                      <span class="admin-text-danger"><?= e($p['error']) ?></span>
                      <span class="admin-muted">（缺少有效 plugin.json）</span>
                    <?php else: ?>
                      <span><?= e($p['description'] !== '' ? $p['description'] : '该插件未提供描述') ?></span>
                      <?php if (($p['homepage'] ?? '') !== ''): ?><a class="admin-resource-link" href="<?= e($p['homepage']) ?>" target="_blank" rel="noopener noreferrer">项目主页</a><?php endif; ?>
                    <?php endif; ?>
                  </div>
                    </div>
                  </div>
                </td>
                <td class="admin-col-md" data-label="状态">
                  <?php if ($p['active']): ?>
                    <span class="badge badge-success">已启用</span>
                  <?php else: ?>
                    <span class="badge">未启用</span>
                  <?php endif; ?>
                </td>
                <td class="admin-col-md" data-label="作者">
                  <?php if (($p['authorUrl'] ?? '') !== ''): ?><a href="<?= e($p['authorUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['author'] !== '' ? $p['author'] : '未知') ?></a><?php else: ?><?= e($p['author'] !== '' ? $p['author'] : '未知') ?><?php endif; ?>
                </td>
                <td class="admin-col-sm" data-label="版本">
                  <?php if ($p['version'] !== ''): ?>v<?= e($p['version']) ?><?php endif; ?>
                  <div class="admin-muted" style="font-size: 11px;">API v<?= (int) $p['apiVersion'] ?></div>
                </td>
                <td class="admin-col-ops" data-label="操作">
                  <div class="admin-row-ops">
                    <?php if ($p['active'] && $p['settingsCount'] > 0): ?>
                      <a class="btn btn-sm btn-primary" href="<?= e(url_to('/admin/plugins/' . rawurlencode($p['name']))) ?>">设置</a>
                    <?php endif; ?>
                    <?php if ($p['active']): ?>
                      <button type="button" class="btn btn-ghost btn-sm admin-plugin-deactivate" data-name="<?= e($p['name']) ?>" data-title="<?= e($p['title']) ?>">停用</button>
                    <?php else: ?>
                      <?php if ($p['error'] === null): ?>
                        <button type="button" class="btn btn-primary btn-sm admin-plugin-activate" data-name="<?= e($p['name']) ?>" data-title="<?= e($p['title']) ?>">启用</button>
                      <?php endif; ?>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ghost btn-sm admin-plugin-uninstall" data-name="<?= e($p['name']) ?>" data-title="<?= e($p['title']) ?>">卸载</button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
  </div>

  <div class="admin-modal-backdrop admin-install-modal" id="plugin-install" hidden>
    <div class="admin-modal admin-install-dialog" role="dialog" aria-modal="true" aria-labelledby="plugin-install-title">
    <div class="admin-modal-head admin-theme-install-head">
      <h2 class="admin-modal-title" id="plugin-install-title">安装插件</h2>
      <button type="button" class="admin-icon-btn" data-modal-close aria-label="关闭安装面板" title="关闭安装面板">×</button>
    </div>
    <div class="admin-modal-body"><div class="admin-theme-install-row">
      <div class="admin-theme-install-block">
        <p class="admin-muted">上传 zip 包</p>
        <input type="file" id="pluginZipInput" accept=".zip" hidden>
        <div class="admin-theme-install-line">
          <button type="button" id="pluginZipBtn" class="btn btn-outline">选择 zip 文件</button>
          <button type="button" id="pluginUploadBtn" class="btn btn-primary" disabled>上传安装</button>
        </div>
        <p class="admin-field-hint" id="pluginZipName"></p>
      </div>
    </div>
    </div></div></div>
  </div>
</div>

<?php $pluginCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($pluginCsrf) ?>;
  try { sessionStorage.removeItem("pluginMsg"); } catch (e) {}
  function showMsg(text, isError) {
    if (typeof window.pafishToast === "function") {
      window.pafishToast(text, isError ? "error" : "success");
    }
  }
  var pluginRows = Array.prototype.slice.call(document.querySelectorAll("[data-plugin-state]"));
  var pluginSearch = document.getElementById("pluginSearch");
  var pluginFilter = "all";
  function filterPlugins() {
    var query = pluginSearch ? pluginSearch.value.trim().toLowerCase() : "";
    pluginRows.forEach(function (row) {
      var stateMatch = pluginFilter === "all" || row.dataset.pluginState === pluginFilter;
      var textMatch = !query || row.textContent.toLowerCase().indexOf(query) !== -1;
      row.hidden = !(stateMatch && textMatch);
    });
  }
  document.querySelectorAll("[data-plugin-filter]").forEach(function (tab) {
    tab.addEventListener("click", function () {
      pluginFilter = tab.dataset.pluginFilter;
      document.querySelectorAll("[data-plugin-filter]").forEach(function (item) {
        var selected = item === tab;
        item.classList.toggle("is-active", selected);
        item.setAttribute("aria-selected", selected ? "true" : "false");
      });
      filterPlugins();
    });
  });
  if (pluginSearch) pluginSearch.addEventListener("input", filterPlugins);

  function post(url, fd) {
    return fetch(url, {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) return j;
        throw new Error((j && j.error) || "操作失败");
      });
  }

  // ---- 启用 / 停用 / 卸载 ----
  document.querySelectorAll(".admin-plugin-activate").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var fd = new FormData();
      fd.append("name", btn.dataset.name);
      fd.append("_csrf", CSRF);
      post("/admin/plugins/activate", fd)
        .then(function () { if (typeof window.pafishToastReload === "function") window.pafishToastReload("已启用 " + btn.dataset.title, "success"); else location.reload(); })
        .catch(function (err) { showMsg(err.message, true); });
    });
  });
  document.querySelectorAll(".admin-plugin-deactivate").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var fd = new FormData();
      fd.append("name", btn.dataset.name);
      fd.append("_csrf", CSRF);
      post("/admin/plugins/deactivate", fd)
        .then(function () { if (typeof window.pafishToastReload === "function") window.pafishToastReload("已停用 " + btn.dataset.title, "success"); else location.reload(); })
        .catch(function (err) { showMsg(err.message, true); });
    });
  });
  document.querySelectorAll(".admin-plugin-uninstall").forEach(function (btn) {
    btn.addEventListener("click", function () {
      (window.pafishConfirm ? window.pafishConfirm("确定要彻底删除插件“" + btn.dataset.title + "”吗？", { title: "卸载插件", accept: "卸载并删除" }) : Promise.resolve(window.confirm("确定要彻底删除插件？操作不可恢复！"))).then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append("name", btn.dataset.name);
        fd.append("_csrf", CSRF);
        post("/admin/plugins/uninstall", fd)
          .then(function () { if (typeof window.pafishToastReload === "function") window.pafishToastReload("已卸载 " + btn.dataset.title, "success"); else location.reload(); })
          .catch(function (err) { showMsg(err.message, true); });
      });
    });
  });

  // ---- 安装：zip / URL ----
  var zipInput = document.getElementById("pluginZipInput");
  var zipBtn = document.getElementById("pluginZipBtn");
  var uploadBtn = document.getElementById("pluginUploadBtn");
  var zipName = document.getElementById("pluginZipName");

  zipBtn.addEventListener("click", function () { zipInput.click(); });
  zipInput.addEventListener("change", function () {
    if (zipInput.files && zipInput.files[0]) {
      zipName.textContent = zipInput.files[0].name;
      uploadBtn.disabled = false;
    } else {
      zipName.textContent = "";
      uploadBtn.disabled = true;
    }
  });
  function install(fd, okText, button, label) {
    post("/admin/plugins/install", fd)
      .then(function (j) {
        var message = okText + " 已安装 " + j.title + " v" + j.version + "，可在上方启用。";
        if (typeof window.pafishToastReload === "function") window.pafishToastReload(message, "success"); else location.reload();
      })
      .catch(function (err) {
        if (button) { button.disabled = false; button.textContent = label; }
        showMsg(err.message, true);
      });
  }
  uploadBtn.addEventListener("click", function () {
    if (!zipInput.files || !zipInput.files[0]) { return; }
    uploadBtn.disabled = true;
    uploadBtn.textContent = "安装中…";
    var fd = new FormData();
    fd.append("zip", zipInput.files[0]);
    fd.append("_csrf", CSRF);
    install(fd, "插件包已上传", uploadBtn, "上传安装");
  });
})();
</script>
