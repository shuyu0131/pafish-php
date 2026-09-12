<?php
/**
 * 插件管理：
 * 说明 → 消息条 → 插件卡片（启用徽章/云存储后端/注入/设置项/错误标注）→ 安装卡（上传 zip / URL 下载）
 * 变量：$plugins、$activeCount
 */
?>
<div class="admin-stack admin-extension-page admin-plugin-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">插件管理</h1>
    </div>
  </div>
  <p class="admin-backup-msg" id="pluginMsg" hidden></p>

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
                  <p>还没有插件，可以从下方安装，或直接把插件目录放入 plugins/。</p>
                </div>
              </td></tr>
            <?php endif; ?>
            <?php foreach ($plugins as $p): ?>
              <tr>
                <td>
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
                    <?php endif; ?>
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

  <div class="card admin-theme-install admin-install-panel">
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
    if (typeof window.pafishToast === "function") {
      window.pafishToast(text, isError ? "error" : "success");
      return;
    }
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
      (window.pafishConfirm ? window.pafishConfirm("确定要彻底删除插件“" + btn.dataset.title + "”吗？", { title: "卸载插件", accept: "卸载并删除" }) : Promise.resolve(window.confirm("确定要彻底删除插件？操作不可恢复！"))).then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append("name", btn.dataset.name);
        fd.append("_csrf", CSRF);
        post("/admin/plugins/uninstall", fd)
          .then(function () { persistMsg("已卸载 " + btn.dataset.title, false); location.reload(); })
          .catch(function (err) { showMsg(err.message, true); });
      });
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
