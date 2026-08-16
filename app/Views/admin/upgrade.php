<?php
/**
 * 系统更新（对齐 emlog 官方源 + prain update.php 迁移脚本机制）：
 * 当前版本 / 最新版本 / 变更说明 → 检查更新 / 立即更新（二次确认，失败自动回滚）
 * 变量：$info（check() 结果）、$current
 */
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">系统更新</h1>
    </div>
  </div>
  <p class="admin-backup-msg" id="upgradeMsg" hidden></p>

  <div class="card admin-upgrade-box">
    <div class="admin-upgrade-row">
      <div class="admin-upgrade-col">
        <p class="admin-muted">当前版本</p>
        <p class="admin-upgrade-version">v<?= e($current) ?></p>
      </div>
      <div class="admin-upgrade-arrow" aria-hidden="true">→</div>
      <div class="admin-upgrade-col">
        <p class="admin-muted">最新版本</p>
        <?php if (($info['latest'] ?? '') !== ''): ?>
          <p class="admin-upgrade-version">
            v<?= e($info['latest']) ?>
            <?php if (!empty($info['hasUpdate'])): ?>
              <span class="badge badge-accent">有新版本</span>
            <?php else: ?>
              <span class="badge badge-primary">已是最新</span>
            <?php endif; ?>
          </p>
        <?php else: ?>
          <p class="admin-upgrade-version admin-muted">—</p>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($minOk)): ?>
      <p class="admin-backup-msg admin-backup-msg-error">当前版本 v<?= e($current) ?> 过低，无法直接升级到 v<?= e($info['latest']) ?>，请先升级到 v<?= e($info['minVersion']) ?>。</p>
    <?php endif; ?>

    <?php if (!empty($info['error'])): ?>
      <p class="admin-backup-msg admin-backup-msg-error">检查更新时出错：<?= e($info['error']) ?>（稍后可重试，不影响使用）</p>
    <?php endif; ?>

    <?php if (!empty($info['notes'])): ?>
      <div class="admin-upgrade-notes">
        <p class="admin-muted">v<?= e($info['latest']) ?> 变更说明</p>
        <div class="admin-upgrade-notes-body admin-prose"><?= md($info['notes']) ?></div>
      </div>
    <?php endif; ?>

    <div class="admin-upgrade-ops">
      <button type="button" class="btn btn-sm" id="upgradeCheckBtn">检查更新</button>
      <?php if (!empty($info['hasUpdate'])): ?>
        <button type="button" class="btn btn-primary btn-sm" id="upgradeRunBtn">立即更新到 v<?= e($info['latest']) ?></button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php $upgradeCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($upgradeCsrf) ?>;
  var msg = document.getElementById("upgradeMsg");
  var checkBtn = document.getElementById("upgradeCheckBtn");
  var runBtn = document.getElementById("upgradeRunBtn");

  function showMsg(text, isError) {
    if (typeof window.pafishToast === "function") {
      window.pafishToast(text, isError ? "error" : "success");
      return;
    }
    msg.textContent = text;
    msg.className = "admin-backup-msg " + (isError ? "admin-backup-msg-error" : "admin-backup-msg-ok");
    msg.hidden = false;
  }
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
  function busy(btn, on) {
    if (!btn) { return; }
    btn.disabled = on;
    btn.textContent = on ? "处理中…" : btn.dataset.label;
  }
  if (checkBtn) { checkBtn.dataset.label = checkBtn.textContent; }
  if (runBtn) { runBtn.dataset.label = runBtn.textContent; }

  function doCheck(force) {
    if (checkBtn) { busy(checkBtn, true); }
    var fd = new FormData();
    fd.append("force", force ? "1" : "");
    fd.append("_csrf", CSRF);
    return post("/admin/upgrade/check", fd)
      .then(function (j) {
        if (j.hasUpdate) {
          showMsg("发现新版本 v" + j.latest + "，可点击「立即更新」", false);
        } else {
          showMsg("当前已是最新版本（v" + j.current + "）", false);
        }
        return j;
      })
      .catch(function (err) { showMsg(err.message, true); throw err; })
      .finally(function () { if (checkBtn) { busy(checkBtn, false); } });
  }

  if (checkBtn) {
    checkBtn.addEventListener("click", function () { doCheck(true); });
  }
  if (runBtn) {
    runBtn.addEventListener("click", function () {
      if (!window.confirm("将系统从 v<?= e($current) ?> 升级到 v<?= e($info['latest']) ?>？\n升级前会自动备份整站，失败自动回滚到当前版本。")) { return; }
      busy(runBtn, true);
      var fd = new FormData();
      fd.append("_csrf", CSRF);
      post("/admin/upgrade/run", fd)
        .then(function (j) {
          showMsg("✓ 升级完成，当前版本 v" + j.current, false);
          setTimeout(function () { location.href = "/admin/upgrade"; }, 1200);
        })
        .catch(function (err) {
          showMsg("更新失败：" + err.message + "（已自动回滚到 v<?= e($current) ?>）", true);
          busy(runBtn, false);
        });
    });
  }
})();
</script>
