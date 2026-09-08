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

  <?php if (!empty($upgradeState) && ($upgradeState['phase'] ?? '') === 'failed'): ?>
    <div class="admin-backup-msg admin-backup-msg-error">
      上次升级未完成：<?= e((string)($upgradeState['message'] ?? '未知错误')) ?>
      <?php if (!empty($upgradeState['database_backup'])): ?>
        <br>数据库安全备份：<code>backups/<?= e((string)$upgradeState['database_backup']) ?></code>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card admin-upgrade-box">
    <div class="admin-upgrade-row">
      <div class="admin-upgrade-col">
        <p class="admin-muted">当前版本</p>
        <p class="admin-upgrade-version">v<?= e($current) ?></p>
      </div>
      <div class="admin-upgrade-arrow" aria-hidden="true">→</div>
      <div class="admin-upgrade-col">
        <p class="admin-muted">最新版本</p>
        <p class="admin-upgrade-version" id="upgLatest">
          <?php if (($info['latest'] ?? '') !== ''): ?>
            v<?= e($info['latest']) ?>
            <span class="badge <?= !empty($info['hasUpdate']) ? 'badge-accent' : 'badge-primary' ?>" id="upgBadge">
              <?= !empty($info['hasUpdate']) ? '有新版本' : '已是最新' ?>
            </span>
          <?php else: ?>
            <span class="admin-upgrade-version admin-muted">—</span>
          <?php endif; ?>
        </p>
      </div>
    </div>

    <?php if (empty($minOk)): ?>
      <p class="admin-backup-msg admin-backup-msg-error" id="upgMinMsg">当前版本 v<?= e($current) ?> 过低，无法直接升级到 v<?= e($info['latest']) ?>，请先升级到 v<?= e($info['minVersion']) ?>。</p>
    <?php else: ?>
      <p class="admin-backup-msg admin-backup-msg-error" id="upgMinMsg" hidden></p>
    <?php endif; ?>

    <?php if (!empty($info['error'])): ?>
      <p class="admin-backup-msg admin-backup-msg-error" id="upgErrMsg">检查更新时出错：<?= e($info['error']) ?>（稍后可重试，不影响使用）</p>
    <?php else: ?>
      <p class="admin-backup-msg admin-backup-msg-error" id="upgErrMsg" hidden></p>
    <?php endif; ?>

    <?php if (!empty($info['notes'])): ?>
      <div class="admin-upgrade-notes" id="upgNotes">
        <p class="admin-muted">v<?= e($info['latest']) ?> 变更说明</p>
        <div class="admin-upgrade-notes-body admin-prose" id="upgNotesBody"><?= md($info['notes']) ?></div>
      </div>
    <?php else: ?>
      <div class="admin-upgrade-notes" id="upgNotes" hidden></div>
    <?php endif; ?>

    <p class="admin-muted admin-upgrade-source" id="upgSource">
      更新来源：<?= ($info['source'] ?? 'official') === 'github' ? 'GitHub Release（官网源不可用时自动回退）' : 'pafish.cn 官方更新源' ?>
    </p>

    <div class="admin-upgrade-ops">
      <button type="button" class="btn btn-sm" id="upgradeCheckBtn">检查更新</button>
      <button type="button" class="btn btn-primary btn-sm" id="upgradeRunBtn" <?= (!empty($info['hasUpdate']) && $minOk) ? '' : 'hidden' ?>>立即更新到 v<?= e($info['latest'] ?? '') ?></button>
    </div>
  </div>
</div>

<?php $upgradeCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($upgradeCsrf) ?>;
  var CURRENT = <?= json_encode($current) ?>;
  var INITIAL_NOTES = <?= json_encode($info['notes'] ?? '') ?>;
  var msg = document.getElementById("upgradeMsg");
  var checkBtn = document.getElementById("upgradeCheckBtn");
  var runBtn = document.getElementById("upgradeRunBtn");
  var latestBox = document.getElementById("upgLatest");
  var notesBox = document.getElementById("upgNotes");
  var notesBody = document.getElementById("upgNotesBody");
  var sourceBox = document.getElementById("upgSource");
  // 当前 showMsg 由 doCheck 调用；保持提示区含义不变
  var lastRunText = "立即更新到 v";

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

  // 用检查结果刷新页面上的版本、徽标与「立即更新」按钮（此前仅提示，不更新页面）
  function renderInfo(j) {
    var latest = (j && j.latest) || "";
    var hasUpdate = !!(j && j.hasUpdate);
    if (sourceBox) {
      sourceBox.textContent = (j && j.source) === "github" ?
        "更新来源：GitHub Release（官网源不可用时自动回退）" :
        "更新来源：pafish.cn 官方更新源";
    }
    if (latestBox) {
      if (latest) {
        latestBox.innerHTML = "v" + escapeHtml(latest) + " <span class=\"badge " + (hasUpdate ? "badge-accent" : "badge-primary") + "\">" + (hasUpdate ? "有新版本" : "已是最新") + "</span>";
      } else {
        latestBox.innerHTML = "<span class=\"admin-upgrade-version admin-muted\">—</span>";
      }
    }
    var minBlocked = hasUpdate && !!(j.minVersion) && compareVersions(CURRENT, j.minVersion) < 0;
    var minMsg = document.getElementById("upgMinMsg");
    if (minMsg) {
      if (minBlocked) {
        minMsg.textContent = "当前版本 v" + CURRENT + " 过低，无法直接升级到 v" + latest + "，请先升级到 v" + j.minVersion + "。";
        minMsg.hidden = false;
      } else {
        minMsg.hidden = true;
      }
    }
    if (runBtn) {
      // 双保险：后端 hasUpdate 已含「latest > 当前」语义，这里再显式校验一次，
      // 即使后端未来误报（如缓存脏数据），已是最新版本时按钮也绝不显示
      if (hasUpdate && latest && !minBlocked && compareVersions(latest, CURRENT) > 0) {
        lastRunText = "立即更新到 v" + latest;
        runBtn.dataset.label = lastRunText;
        runBtn.hidden = false;
      } else {
        runBtn.hidden = true;
      }
    }
    var errMsg = document.getElementById("upgErrMsg");
    if (errMsg) {
      if (j && j.error) {
        errMsg.textContent = "检查更新时出错：" + j.error + "（稍后可重试，不影响使用）";
        errMsg.hidden = false;
      } else {
        errMsg.hidden = true;
      }
    }
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  // 数字分段版本比较（与后端 Version::compare 一致："0.1.9" < "0.1.10"）
  function compareVersions(a, b) {
    var pa = String(a).split(/[^\d]+/).filter(Boolean).map(Number);
    var pb = String(b).split(/[^\d]+/).filter(Boolean).map(Number);
    var n = Math.max(pa.length, pb.length);
    for (var i = 0; i < n; i++) {
      var x = pa[i] || 0;
      var y = pb[i] || 0;
      if (x < y) { return -1; }
      if (x > y) { return 1; }
    }
    return 0;
  }
  // 变更说明随检查结果刷新（示例：缓存过期后官网 notes 更新）；与初始一致时不重复渲染
  function renderNotes(j) {
    if (!notesBox || !notesBody) { return; }
    var notes = (j && j.notes) || "";
    if (!notes) { notesBox.hidden = true; return; }
    var title = notesBox.querySelector("p");
    if (title) { title.textContent = "v" + ((j && j.latest) || "") + " 变更说明"; }
    notesBox.hidden = false;
    if (notes === INITIAL_NOTES && notesBody.innerHTML.trim() !== "") { return; }
    var fd = new FormData();
    fd.append("content", notes);
    fd.append("_csrf", CSRF);
    fetch("/api/md-preview", {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.html) { notesBody.innerHTML = d.html; } })
      .catch(function () { /* notes 渲染失败保留服务端初始内容 */ });
  }

  function doCheck(force) {
    if (checkBtn) { busy(checkBtn, true); }
    var fd = new FormData();
    fd.append("force", force ? "1" : "");
    fd.append("_csrf", CSRF);
    return post("/admin/upgrade/check", fd)
      .then(function (j) {
        renderInfo(j);
        renderNotes(j);
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

  // 进入页面自动实时检查（emlog 同款：打开即查，无需先点「检查更新」）
  doCheck(true);

  if (checkBtn) {
    checkBtn.addEventListener("click", function () { doCheck(true); });
  }
  if (runBtn) {
    runBtn.addEventListener("click", function () {
      var latest = lastRunText.replace(/^立即更新到 v/, "");
      (window.pafishConfirm ? window.pafishConfirm("将系统从 v" + CURRENT + " 升级到 v" + latest + "？\n升级前会自动备份整站，失败自动回滚到当前版本。", { title: "确认系统升级", accept: "开始升级" }) : Promise.resolve(window.confirm("确认升级？"))).then(function (ok) {
        if (!ok) return;
        busy(runBtn, true);
        var fd = new FormData();
        fd.append("_csrf", CSRF);
        post("/admin/upgrade/run", fd)
          .then(function (j) {
            showMsg("✓ 升级完成，当前版本 v" + j.current, false);
            setTimeout(function () { location.href = "/admin/upgrade"; }, 1200);
          })
          .catch(function (err) {
            showMsg("更新失败：" + err.message + "（已自动回滚到 v" + CURRENT + "）", true);
            busy(runBtn, false);
          });
      });
    });
  }
})();
</script>
