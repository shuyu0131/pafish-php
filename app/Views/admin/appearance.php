<?php
$themeTotal = count($themes);
$themeActive = count(array_filter($themes, static fn (array $theme): bool => !empty($theme['active'])));
?>
<div class="admin-stack admin-theme-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">主题与外观</h1>
    </div>
    <div class="admin-head-actions">
      <a class="btn btn-outline" href="<?= e(url_to('/admin/store')) ?>">应用商店</a>
      <button type="button" class="btn btn-primary" data-open-install="#theme-install">安装主题</button>
    </div>
  </div>

  <div class="admin-resource-toolbar">
    <div class="admin-tabs admin-resource-tabs" role="tablist" aria-label="主题筛选">
      <button type="button" class="admin-tab is-active" data-theme-filter="all" role="tab" aria-selected="true">全部 <span class="admin-resource-count"><?= $themeTotal ?></span></button>
      <button type="button" class="admin-tab" data-theme-filter="active" role="tab" aria-selected="false">当前主题 <span class="admin-resource-count"><?= $themeActive ?></span></button>
      <button type="button" class="admin-tab" data-theme-filter="inactive" role="tab" aria-selected="false">未启用 <span class="admin-resource-count"><?= $themeTotal - $themeActive ?></span></button>
    </div>
    <input type="search" id="themeSearch" class="input admin-resource-search" placeholder="搜索主题名称或描述" autocomplete="off">
  </div>

  <div class="admin-installed-theme-grid">
    <?php if ($themes === []): ?>
      <div class="admin-empty-list">
        <?= admin_icon('layout', 32) ?>
        <p>还没有主题</p>
      </div>
    <?php endif; ?>
    <?php foreach ($themes as $t): ?>
      <article class="card admin-installed-theme-card" data-theme-state="<?= $t['active'] ? 'active' : 'inactive' ?>">
        <div class="admin-installed-theme-preview">
          <?php if (($t['preview'] ?? '') !== ''): ?>
            <img src="<?= e($t['preview']) ?>" alt="<?= e($t['title']) ?>" loading="lazy">
          <?php else: ?>
            <span class="admin-installed-theme-placeholder"><?= admin_icon('layout', 36) ?></span>
          <?php endif; ?>
        </div>
        <div class="admin-installed-theme-body">
          <div class="admin-installed-theme-title">
            <?php if ($t['active'] && $t['settingsCount'] > 0): ?>
              <a href="<?= e(url_to('/admin/appearance/' . rawurlencode($t['name']))) ?>"><?= e($t['title']) ?></a>
            <?php else: ?>
              <strong><?= e($t['title']) ?></strong>
            <?php endif; ?>
            <?php if ($t['active']): ?><span class="badge badge-primary">当前</span><?php endif; ?>
          </div>
          <p class="admin-installed-theme-desc">
            <?= $t['error'] !== null ? e($t['error']) : e($t['description']) ?>
            <?php if (($t['homepage'] ?? '') !== ''): ?><a class="admin-resource-link" href="<?= e($t['homepage']) ?>" target="_blank" rel="noopener noreferrer">主题主页</a><?php endif; ?>
          </p>
          <p class="admin-installed-theme-meta">
            <span>
              <?php if (($t['authorUrl'] ?? '') !== ''): ?><a href="<?= e($t['authorUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= e($t['author'] !== '' ? $t['author'] : '未知作者') ?></a><?php else: ?><?= e($t['author'] !== '' ? $t['author'] : '未知作者') ?><?php endif; ?>
            </span>
            <?php if ($t['version'] !== ''): ?><span>v<?= e($t['version']) ?></span><?php endif; ?>
          </p>
        </div>
        <div class="admin-installed-theme-actions">
          <?php if ($t['active']): ?>
            <?php if ($t['settingsCount'] > 0): ?>
              <a class="btn btn-primary btn-sm" href="<?= e(url_to('/admin/appearance/' . rawurlencode($t['name']))) ?>">设置</a>
            <?php endif; ?>
            <?php if ($t['name'] !== 'default'): ?><button type="button" class="btn btn-ghost btn-sm admin-theme-deactivate" data-name="<?= e($t['name']) ?>" data-title="<?= e($t['title']) ?>">停用</button><?php endif; ?>
          <?php else: ?>
            <button type="button" class="btn btn-primary btn-sm admin-theme-activate" data-name="<?= e($t['name']) ?>" data-title="<?= e($t['title']) ?>">启用</button>
            <button type="button" class="btn btn-ghost btn-sm admin-theme-uninstall" data-name="<?= e($t['name']) ?>" data-title="<?= e($t['title']) ?>">卸载</button>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="admin-modal-backdrop admin-install-modal" id="theme-install" hidden>
    <div class="admin-modal admin-install-dialog" role="dialog" aria-modal="true" aria-labelledby="theme-install-title">
      <div class="admin-modal-head admin-install-head">
        <h2 class="admin-modal-title" id="theme-install-title">安装主题</h2>
        <button type="button" class="admin-icon-btn" data-modal-close aria-label="关闭安装面板" title="关闭安装面板">×</button>
      </div>
      <div class="admin-modal-body admin-install-body">
        <input type="file" id="themeZipInput" accept=".zip" hidden>
        <label class="admin-package-picker" for="themeZipInput" id="themeZipPicker">
          <span class="admin-package-picker-icon" aria-hidden="true"><?= admin_icon('upload', 20) ?></span>
          <span class="admin-package-picker-copy">
            <strong>选择主题压缩包</strong>
            <span id="themeZipName" aria-live="polite">仅支持 .zip 格式</span>
          </span>
        </label>
      </div>
      <div class="admin-modal-actions admin-install-actions">
        <button type="button" class="btn btn-ghost" data-modal-close>取消</button>
        <button type="button" id="themeUploadBtn" class="btn btn-primary" disabled>安装主题</button>
      </div>
    </div>
  </div>
</div>

<?php $themeCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($themeCsrf) ?>;
  try { sessionStorage.removeItem("themeMsg"); } catch (e) {}
  function showMsg(text, isError) {
    if (typeof window.pafishToast === "function") {
      window.pafishToast(text, isError ? "error" : "success");
    }
  }
  var themeRows = Array.prototype.slice.call(document.querySelectorAll("[data-theme-state]"));
  var themeSearch = document.getElementById("themeSearch");
  var themeFilter = "all";
  function filterThemes() {
    var query = themeSearch ? themeSearch.value.trim().toLowerCase() : "";
    themeRows.forEach(function (row) {
      var stateMatch = themeFilter === "all" || row.dataset.themeState === themeFilter;
      var textMatch = !query || row.textContent.toLowerCase().indexOf(query) !== -1;
      row.hidden = !(stateMatch && textMatch);
    });
  }
  document.querySelectorAll("[data-theme-filter]").forEach(function (tab) {
    tab.addEventListener("click", function () {
      themeFilter = tab.dataset.themeFilter;
      document.querySelectorAll("[data-theme-filter]").forEach(function (item) {
        var selected = item === tab;
        item.classList.toggle("is-active", selected);
        item.setAttribute("aria-selected", selected ? "true" : "false");
      });
      filterThemes();
    });
  });
  if (themeSearch) themeSearch.addEventListener("input", filterThemes);

  function post(url, fd, onOk) {
    return fetch(url, {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) onOk(j);
        else throw new Error((j && j.error) || "操作失败");
      });
  }
  function run(url, fd, okText) {
    post(url, fd, function () {
      if (typeof window.pafishToastReload === "function") window.pafishToastReload(okText, "success");
      else location.reload();
    }).catch(function (err) {
      showMsg(err.message, true);
    });
  }

  // 启用 / 卸载
  document.querySelectorAll(".admin-theme-activate").forEach(function (btn) {
    btn.addEventListener("click", function () {
      btn.disabled = true;
      btn.textContent = "处理中…";
      var fd = new FormData();
      fd.append("name", btn.dataset.name);
      fd.append("_csrf", CSRF);
      run("/admin/appearance/activate", fd, "已启用主题 " + btn.dataset.title);
    });
  });
  document.querySelectorAll(".admin-theme-deactivate").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var confirmTask = window.pafishConfirm ? window.pafishConfirm("停用主题“" + btn.dataset.title + "”并切回默认主题？", { title: "停用主题", accept: "停用" }) : Promise.resolve(window.confirm("停用后将切回默认主题，是否继续？"));
      confirmTask.then(function (ok) {
        if (!ok) return;
        btn.disabled = true;
        btn.textContent = "处理中…";
        var fd = new FormData();
        fd.append("name", btn.dataset.name);
        fd.append("_csrf", CSRF);
        run("/admin/appearance/deactivate", fd, "已停用主题 " + btn.dataset.title);
      });
    });
  });
  document.querySelectorAll(".admin-theme-uninstall").forEach(function (btn) {
    btn.addEventListener("click", function () {
      (window.pafishConfirm ? window.pafishConfirm("确定要彻底删除主题“" + btn.dataset.title + "”吗？", { title: "卸载主题", accept: "卸载并删除" }) : Promise.resolve(window.confirm("确定要彻底删除主题？操作不可恢复！"))).then(function (ok) {
        if (!ok) return;
        var deleteData = window.confirm("同时删除该主题的设置、数据和自有表吗？\n选择“取消”将保留数据，之后仍可恢复使用。");
        btn.disabled = true;
        btn.textContent = "处理中…";
        var fd = new FormData();
        fd.append("name", btn.dataset.name);
        fd.append("delete_data", deleteData ? "1" : "0");
        fd.append("_csrf", CSRF);
        run("/admin/appearance/uninstall", fd, "已卸载主题 " + btn.dataset.title);
      });
    });
  });

  // 上传 zip
  var zipInput = document.getElementById("themeZipInput");
  var uploadBtn = document.getElementById("themeUploadBtn");
  var zipName = document.getElementById("themeZipName");
  var zipPicker = document.getElementById("themeZipPicker");
  zipInput.addEventListener("change", function () {
    if (zipInput.files && zipInput.files[0]) {
      zipName.textContent = zipInput.files[0].name;
      zipPicker.classList.add("is-ready");
      uploadBtn.disabled = false;
    } else {
      zipName.textContent = "仅支持 .zip 格式";
      zipPicker.classList.remove("is-ready");
      uploadBtn.disabled = true;
    }
  });
  uploadBtn.addEventListener("click", function () {
    if (!zipInput.files || !zipInput.files[0]) {
      showMsg("请先选择 zip 文件", true);
      return;
    }
    uploadBtn.disabled = true;
    uploadBtn.textContent = "安装中…";
    var fd = new FormData();
    fd.append("zip", zipInput.files[0]);
    fd.append("_csrf", CSRF);
    post("/admin/appearance/install", fd, function (j) {
      var message = (j.updated ? "已更新主题 " : "已安装主题 ") + j.title + " v" + j.version;
      if (typeof window.pafishToastReload === "function") window.pafishToastReload(message, "success");
      else location.reload();
    }).catch(function (err) {
      showMsg(err.message, true);
      uploadBtn.disabled = false;
      uploadBtn.textContent = "安装主题";
    });
  });

})();
</script>
