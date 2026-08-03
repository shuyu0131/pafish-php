<?php
/**
 * 主题与外观（对齐 Node admin/appearance/page.tsx + theme-list + theme-install）：
 * 说明 → 消息条 → 已安装主题卡片（启用/设置/卸载）→ 安装卡（上传 zip / URL 下载）
 * 变量：$themes、$activeTheme
 */
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">主题与外观</h1>
      <p class="admin-page-sub">主题存放在 themes/ 目录，每个主题由 theme.json 声明设置项，可选 theme.css 覆盖配色。切换后即时生效，点击当前主题的"设置"进入独立设置页。</p>
    </div>
  </div>
  <p class="admin-backup-msg" id="themeMsg" hidden></p>

  <h2 class="admin-h2">已安装主题</h2>
  <?php if ($themes === []): ?>
    <div class="card admin-empty">还没有主题</div>
  <?php else: ?>
    <div class="admin-theme-grid">
      <?php foreach ($themes as $t): ?>
        <div class="card admin-theme-card">
          <div class="admin-theme-head">
            <p class="admin-theme-name"><?= e($t['title']) ?>
              <?php if ($t['active']): ?><span class="badge badge-primary">当前主题</span><?php endif; ?>
              <?php if ($t['version'] !== ''): ?><span class="admin-theme-version">v<?= e($t['version']) ?></span><?php endif; ?>
            </p>
            <p class="admin-theme-desc"><?= e($t['error'] ?? ($t['description'] !== '' ? $t['description'] : '')) ?></p>
            <?php if ($t['error'] !== null): ?>
              <p class="admin-muted">（缺少有效 theme.json）</p>
            <?php endif; ?>
            <p class="admin-muted admin-theme-meta">作者：<?= e($t['author'] !== '' ? $t['author'] : '未知') ?>　目录：themes/<?= e($t['name']) ?>/　设置项：<?= (int) $t['settingsCount'] ?></p>
          </div>
          <div class="admin-theme-ops">
            <?php if ($t['active']): ?>
              <?php if ($t['settingsCount'] > 0): ?>
                <a class="btn btn-primary btn-sm" href="<?= e(url_to('/admin/appearance/' . rawurlencode($t['name']))) ?>">设置</a>
              <?php endif; ?>
            <?php else: ?>
              <button type="button" class="btn btn-primary btn-sm admin-theme-activate" data-name="<?= e($t['name']) ?>" data-title="<?= e($t['title']) ?>">启用</button>
              <?php if ($t['settingsCount'] > 0): ?>
                <span class="admin-muted admin-theme-hint">启用后可配置</span>
              <?php endif; ?>
              <button type="button" class="btn btn-ghost btn-sm admin-theme-uninstall" data-name="<?= e($t['name']) ?>" data-title="<?= e($t['title']) ?>">卸载</button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card admin-theme-install">
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
    <p class="admin-field-hint">结构约定：zip 顶层目录为主题名（theme.json 声明 manifest 与设置项，可选 theme.css 覆盖配色）。安装前会校验目录名与路径安全，非法包将被拒绝。</p>
  </div>
</div>

<?php $themeCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($themeCsrf) ?>;
  var msg = document.getElementById("themeMsg");

  function showMsg(text, isError) {
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
      if (!window.confirm("确定卸载主题“" + btn.dataset.title + "”吗？\n将删除主题目录与设置，不可恢复。")) { return; }
      btn.disabled = true;
      btn.textContent = "处理中…";
      var fd = new FormData();
      fd.append("name", btn.dataset.name);
      fd.append("_csrf", CSRF);
      run("/admin/appearance/uninstall", fd, "已卸载主题 " + btn.dataset.title);
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
