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
      <a class="btn btn-primary" href="#theme-install">安装主题</a>
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
          <?php else: ?>
            <button type="button" class="btn btn-primary btn-sm admin-theme-activate" data-name="<?= e($t['name']) ?>" data-title="<?= e($t['title']) ?>">启用</button>
            <button type="button" class="btn btn-ghost btn-sm admin-theme-uninstall" data-name="<?= e($t['name']) ?>" data-title="<?= e($t['title']) ?>">卸载</button>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="admin-theme-install admin-install-panel" id="theme-install">
    <div class="admin-theme-install-head">
      <h2 class="admin-card-title">安装主题</h2>
    </div>
    <div class="admin-theme-install-row">
      <div class="admin-theme-install-block">
        <p class="admin-muted">上传 zip 包</p>
        <input type="file" id="themeZipInput" accept=".zip" hidden>
        <div class="admin-theme-install-line">
          <button type="button" id="themeZipBtn" class="btn btn-outline">选择 zip 文件</button>
          <button type="button" id="themeUploadBtn" class="btn btn-primary" disabled>上传安装</button>
        </div>
        <p class="admin-field-hint" id="themeZipName"></p>
      </div>
      <div class="admin-theme-install-block">
        <p class="admin-muted">从 URL 下载安装</p>
        <div class="admin-theme-install-line">
          <input type="url" id="themeUrlInput" class="input" placeholder="https://example.com/themes/demo.zip" autocomplete="off">
          <button type="button" id="themeUrlBtn" class="btn btn-primary">下载安装</button>
        </div>
      </div>
    </div>
    <p class="admin-field-hint">zip 顶层目录需为主题名，安装前校验目录名与路径安全。</p>
  </div>
</div>

<?php $themeCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($themeCsrf) ?>;
  function showMsg(text, isError) {
    if (typeof window.pafishToast === "function") {
      window.pafishToast(text, isError ? "error" : "success");
    }
  }
  function persistMsg(text, isError) {
    try { sessionStorage.setItem("themeMsg", JSON.stringify({ t: text, e: isError ? 1 : 0 })); } catch (e) {}
  }
  try {
    var saved = sessionStorage.getItem("themeMsg");
    if (saved) {
      sessionStorage.removeItem("themeMsg");
      var m = JSON.parse(saved);
      showMsg(m.t, !!m.e);
    }
  } catch (e) {}

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
      persistMsg(okText, false);
      location.reload();
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
  document.querySelectorAll(".admin-theme-uninstall").forEach(function (btn) {
    btn.addEventListener("click", function () {
      (window.pafishConfirm ? window.pafishConfirm("确定要彻底删除主题“" + btn.dataset.title + "”吗？", { title: "卸载主题", accept: "卸载并删除" }) : Promise.resolve(window.confirm("确定要彻底删除主题？操作不可恢复！"))).then(function (ok) {
        if (!ok) return;
        btn.disabled = true;
        btn.textContent = "处理中…";
        var fd = new FormData();
        fd.append("name", btn.dataset.name);
        fd.append("_csrf", CSRF);
        run("/admin/appearance/uninstall", fd, "已卸载主题 " + btn.dataset.title);
      });
    });
  });

  // 上传 zip
  var zipInput = document.getElementById("themeZipInput");
  var zipBtn = document.getElementById("themeZipBtn");
  var uploadBtn = document.getElementById("themeUploadBtn");
  var zipName = document.getElementById("themeZipName");
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
      persistMsg("✓ " + (j.updated ? "已更新主题 " : "已安装主题 ") + j.title + " v" + j.version, false);
      location.reload();
    }).catch(function (err) {
      showMsg(err.message, true);
      uploadBtn.disabled = false;
      uploadBtn.textContent = "上传安装";
    });
  });

  // URL 下载安装
  var urlInput = document.getElementById("themeUrlInput");
  var urlBtn = document.getElementById("themeUrlBtn");
  urlBtn.addEventListener("click", function () {
    var url = urlInput.value.trim();
    if (!url) {
      showMsg("请填写主题包下载地址", true);
      return;
    }
    urlBtn.disabled = true;
    urlBtn.textContent = "下载中…";
    var fd = new FormData();
    fd.append("url", url);
    fd.append("_csrf", CSRF);
    post("/admin/appearance/install", fd, function (j) {
      persistMsg("✓ " + (j.updated ? "已更新主题 " : "已安装主题 ") + j.title + " v" + j.version, false);
      location.reload();
    }).catch(function (err) {
      showMsg(err.message, true);
      urlBtn.disabled = false;
      urlBtn.textContent = "下载安装";
    });
  });
})();
</script>
