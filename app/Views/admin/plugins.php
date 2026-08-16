<?php
/**
 * 插件管理（对齐 Node admin/plugins/page.tsx + plugin-list + plugin-install）：
 * 说明 → 消息条 → 插件卡片（启用徽章/云存储后端/注入/设置项/错误标注）→ 安装卡（上传 zip / URL 下载）
 * 变量：$plugins、$activeCount
 */
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">插件管理</h1>
    </div>
  </div>
  <p class="admin-backup-msg" id="pluginMsg" hidden></p>

  <h2 class="admin-h2">已安装插件（<?= (int) $activeCount ?> 个已启用）</h2>
  <?php if ($plugins === []): ?>
    <div class="card admin-empty">还没有插件，可以从下方安装，或直接把插件目录放入 plugins/。</div>
  <?php else: ?>
    <div class="admin-theme-grid">
      <?php foreach ($plugins as $p): ?>
        <div class="card admin-theme-card">
          <div class="admin-theme-head">
            <p class="admin-theme-name"><?= e($p['title']) ?>
              <?php if ($p['active']): ?>
                <span class="badge badge-primary">已启用</span>
              <?php else: ?>
                <span class="badge">未启用</span>
              <?php endif; ?>
              <?php if ($p['version'] !== ''): ?><span class="admin-theme-version">v<?= e($p['version']) ?></span><?php endif; ?>
              <span class="badge">API v<?= (int) $p['apiVersion'] ?></span>
              <?php if ($p['storage'] !== null): ?><span class="badge badge-primary">云存储后端</span><?php endif; ?>
            </p>
            <p class="admin-theme-desc<?= $p['error'] !== null ? ' admin-text-danger' : '' ?>"><?= e($p['error'] ?? ($p['description'] !== '' ? $p['description'] : '该插件未提供描述')) ?></p>
            <?php if ($p['error'] !== null): ?>
              <p class="admin-muted">（缺少有效 plugin.json）</p>
            <?php endif; ?>
            <p class="admin-muted admin-theme-meta">
              作者：<?= e($p['author'] !== '' ? $p['author'] : '未知') ?>　目录：plugins/<?= e($p['name']) ?>/
              <?php if ($p['injects'] !== []): ?>　注入：<?= e(implode('/', $p['injects'])) ?><?php endif; ?>
              　设置项：<?= (int) $p['settingsCount'] ?>
            </p>
          </div>
          <div class="admin-theme-ops">
            <?php if ($p['active'] && $p['settingsCount'] > 0): ?>
              <a class="btn btn-primary btn-sm" href="<?= e(url_to('/admin/plugins/' . rawurlencode($p['name']))) ?>">设置</a>
            <?php endif; ?>
            <?php if ($p['active']): ?>
              <button type="button" class="btn btn-ghost btn-sm admin-plugin-deactivate" data-name="<?= e($p['name']) ?>" data-title="<?= e($p['title']) ?>">停用</button>
            <?php else: ?>
              <?php if ($p['error'] === null): ?>
                <button type="button" class="btn btn-primary btn-sm admin-plugin-activate" data-name="<?= e($p['name']) ?>" data-title="<?= e($p['title']) ?>">启用</button>
              <?php endif; ?>
              <?php if ($p['settingsCount'] > 0): ?>
                <span class="admin-muted admin-theme-hint">启用后可配置</span>
              <?php endif; ?>
            <?php endif; ?>
            <button type="button" class="btn btn-ghost btn-sm admin-plugin-uninstall" data-name="<?= e($p['name']) ?>" data-title="<?= e($p['title']) ?>">卸载</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card admin-theme-install">
    <div class="admin-theme-install-head">
      <h2 class="admin-card-title">安装插件</h2>
      <span class="badge">兼容商店分发 zip 格式</span>
    </div>
    <div class="admin-theme-install-row">
      <div class="admin-theme-install-block">
        <p class="admin-muted">上传 zip 包</p>
        <input type="file" id="pluginZipInput" accept=".zip" hidden>
        <div class="admin-theme-install-line">
          <button type="button" id="pluginZipBtn" class="btn btn-outline">选择 zip 文件</button>
          <button type="button" id="pluginUploadBtn" class="btn btn-primary" disabled>上传安装</button>
        </div>
        <p class="admin-field-hint" id="pluginZipName"></p>
      </div>
      <div class="admin-theme-install-block">
        <p class="admin-muted">从 URL 下载安装</p>
        <div class="admin-theme-install-line">
          <input type="url" id="pluginUrlInput" class="input" placeholder="https://example.com/plugins/demo.zip" autocomplete="off">
          <button type="button" id="pluginUrlBtn" class="btn btn-primary">下载安装</button>
        </div>
      </div>
    </div>
    <p class="admin-field-hint">zip 顶层目录需为插件名，安装前校验目录名与路径安全。安装成功后需手动启用。</p>
  </div>
</div>

<?php $pluginCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($pluginCsrf) ?>;
  var msg = document.getElementById("pluginMsg");

  function showMsg(text, isError) {
    msg.textContent = text;
    msg.className = "admin-backup-msg " + (isError ? "admin-backup-msg-error" : "admin-backup-msg-ok");
    msg.hidden = false;
  }
  function persistMsg(text, isError) {
    try { sessionStorage.setItem("pluginMsg", JSON.stringify({ t: text, e: isError ? 1 : 0 })); } catch (e) {}
  }
  try {
    var saved = sessionStorage.getItem("pluginMsg");
    if (saved) {
      sessionStorage.removeItem("pluginMsg");
      var m = JSON.parse(saved);
      showMsg(m.t, !!m.e);
    }
  } catch (e) {}

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
        .then(function () { persistMsg("✓ 已启用 " + btn.dataset.title, false); location.reload(); })
        .catch(function (err) { showMsg(err.message, true); });
    });
  });
  document.querySelectorAll(".admin-plugin-deactivate").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var fd = new FormData();
      fd.append("name", btn.dataset.name);
      fd.append("_csrf", CSRF);
      post("/admin/plugins/deactivate", fd)
        .then(function () { persistMsg("已停用 " + btn.dataset.title, false); location.reload(); })
        .catch(function (err) { showMsg(err.message, true); });
    });
  });
  document.querySelectorAll(".admin-plugin-uninstall").forEach(function (btn) {
    btn.addEventListener("click", function () {
      if (!window.confirm("确定卸载插件“" + btn.dataset.title + "”吗？\n将删除插件目录与数据，不可恢复。")) { return; }
      var fd = new FormData();
      fd.append("name", btn.dataset.name);
      fd.append("_csrf", CSRF);
      post("/admin/plugins/uninstall", fd)
        .then(function () { persistMsg("已卸载 " + btn.dataset.title, false); location.reload(); })
        .catch(function (err) { showMsg(err.message, true); });
    });
  });

  // ---- 安装：zip / URL ----
  var zipInput = document.getElementById("pluginZipInput");
  var zipBtn = document.getElementById("pluginZipBtn");
  var uploadBtn = document.getElementById("pluginUploadBtn");
  var zipName = document.getElementById("pluginZipName");
  var urlInput = document.getElementById("pluginUrlInput");
  var urlBtn = document.getElementById("pluginUrlBtn");

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
  function install(fd, okText) {
    post("/admin/plugins/install", fd)
      .then(function (j) {
        persistMsg(okText + " ✓ 已安装 " + j.title + " v" + j.version + "，可在上方启用。", false);
        location.reload();
      })
      .catch(function (err) { showMsg(err.message, true); });
  }
  uploadBtn.addEventListener("click", function () {
    if (!zipInput.files || !zipInput.files[0]) { return; }
    uploadBtn.disabled = true;
    uploadBtn.textContent = "安装中…";
    var fd = new FormData();
    fd.append("zip", zipInput.files[0]);
    fd.append("_csrf", CSRF);
    install(fd, "插件包已上传");
    uploadBtn.disabled = false;
    uploadBtn.textContent = "上传安装";
  });
  urlBtn.addEventListener("click", function () {
    var url = urlInput.value.trim();
    if (!url) { showMsg("请填写 zip 下载地址", true); return; }
    urlBtn.disabled = true;
    urlBtn.textContent = "下载中…";
    var fd = new FormData();
    fd.append("url", url);
    fd.append("_csrf", CSRF);
    install(fd, "插件包已下载");
    urlBtn.disabled = false;
    urlBtn.textContent = "下载安装";
  });
})();
</script>
