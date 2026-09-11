<?php
/**
 * 主题与外观：
 * 说明 → 消息条 → 已安装主题卡片（启用/设置/卸载）→ 安装卡（上传 zip / URL 下载）
 * 变量：$themes、$activeTheme
 */
?>
<div class="admin-stack admin-theme-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">主题与外观</h1>
    </div>
  </div>
  <p class="admin-backup-msg" id="themeMsg" hidden></p>

  <div class="card admin-resource-list">
    <div class="card-body" style="padding: 0;">
      <div class="admin-table-wrap" style="border: none; border-radius: 0;">
        <table class="admin-table">
          <thead>
            <tr>
              <th>主题</th>
              <th class="admin-col-md">状态</th>
              <th class="admin-col-md">作者</th>
              <th class="admin-col-sm">版本</th>
              <th class="admin-col-ops">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($themes === []): ?>
              <tr><td colspan="5">
                <div class="admin-empty-list">
                  <?= admin_icon('layout', 32) ?>
                  <p>还没有主题</p>
                </div>
              </td></tr>
            <?php endif; ?>
            <?php foreach ($themes as $t): ?>
              <tr>
                <td>
                  <div class="admin-plugin-title">
                    <?php if ($t['active'] && $t['settingsCount'] > 0): ?>
                      <a href="<?= e(url_to('/admin/appearance/' . rawurlencode($t['name']))) ?>" class="admin-plugin-name"><?= e($t['title']) ?></a>
                    <?php else: ?>
                      <span class="admin-plugin-name"><?= e($t['title']) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="admin-plugin-meta">
                    <?php if ($t['error'] !== null): ?>
                      <span class="admin-text-danger"><?= e($t['error']) ?></span>
                      <span class="admin-muted">（缺少有效 theme.json）</span>
                    <?php else: ?>
                      <span><?= e($t['description'] !== '' ? $t['description'] : '') ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="admin-plugin-info">
                    <span class="admin-muted">目录：themes/<?= e($t['name']) ?>/</span>
                    <span class="admin-muted">设置项：<?= (int) $t['settingsCount'] ?></span>
                  </div>
                </td>
                <td class="admin-col-md" data-label="状态">
                  <?php if ($t['active']): ?>
                    <span class="badge badge-primary">当前主题</span>
                  <?php else: ?>
                    <span class="badge">未启用</span>
                  <?php endif; ?>
                </td>
                <td class="admin-col-md" data-label="作者"><?= e($t['author'] !== '' ? $t['author'] : '未知') ?></td>
                <td class="admin-col-sm" data-label="版本"><?php if ($t['version'] !== ''): ?>v<?= e($t['version']) ?><?php endif; ?></td>
                <td class="admin-col-ops" data-label="操作">
                  <div class="admin-row-ops">
                    <?php if ($t['active']): ?>
                      <?php if ($t['settingsCount'] > 0): ?>
                        <a class="btn btn-primary btn-sm" href="<?= e(url_to('/admin/appearance/' . rawurlencode($t['name']))) ?>">设置</a>
                      <?php endif; ?>
                    <?php else: ?>
                      <button type="button" class="btn btn-primary btn-sm admin-theme-activate" data-name="<?= e($t['name']) ?>" data-title="<?= e($t['title']) ?>">启用</button>
                      <button type="button" class="btn btn-ghost btn-sm admin-theme-uninstall" data-name="<?= e($t['name']) ?>" data-title="<?= e($t['title']) ?>">卸载</button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card admin-theme-install admin-install-panel">
    <div class="admin-theme-install-head">
      <h2 class="admin-card-title">安装主题</h2>
      <span class="badge">兼容商店分发 zip 格式</span>
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
  var msg = document.getElementById("themeMsg");

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
      persistMsg("✓ 已安装主题 " + j.title + " v" + j.version, false);
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
      persistMsg("✓ 已安装主题 " + j.title + " v" + j.version, false);
      location.reload();
    }).catch(function (err) {
      showMsg(err.message, true);
      urlBtn.disabled = false;
      urlBtn.textContent = "下载安装";
    });
  });
})();
</script>
