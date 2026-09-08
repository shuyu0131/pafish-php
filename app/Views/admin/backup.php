<?php
/**
 * 数据备份：
 * 头部操作卡（立即备份 + 上传 SQL + 计数）→ 结果消息条 → 备份列表
 * （文件名/时间·大小/下载/恢复展开面板/删除，upload-* 不可删）→ 使用说明 5 条
 * 变量：$backups
 */
$backups = $backups ?? [];
$fmtSize = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / 1024 / 1024, 2) . ' MB';
};
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">数据备份</h1>
      <p class="admin-page-sub">备份内容为完整数据库（文章、页面、分类、评论、设置等），文件保存在服务器 backups 目录</p>
    </div>
  </div>

  <div class="card admin-backup-toolbar">
    <button type="button" id="createBackupBtn" class="btn btn-primary">立即备份</button>
    <input type="file" id="uploadSqlInput" accept=".sql" hidden>
    <button type="button" id="uploadSqlBtn" class="btn btn-outline">上传 SQL 文件</button>
    <p class="admin-backup-count">共 <?= count($backups) ?> 份备份，按时间倒序</p>
  </div>
  <p class="admin-backup-msg" id="backupMsg" hidden></p>

  <?php if ($backups === []): ?>
    <div class="card admin-empty admin-backup-empty">还没有备份</div>
  <?php else: ?>
    <div class="card admin-backup-list">
      <?php foreach ($backups as $b): ?>
        <?php
        $isUpload = str_starts_with($b['file'], 'upload-');
        ?>
        <div class="admin-backup-row" data-file="<?= e($b['file']) ?>">
          <div class="admin-backup-main">
            <p class="admin-backup-file"><?= e($b['file']) ?></p>
            <p class="admin-muted"><?= date('Y-m-d H:i:s', (int) $b['mtime']) ?> · <?= $fmtSize((int) $b['size']) ?></p>
          </div>
          <div class="admin-backup-ops">
            <a class="btn btn-ghost btn-sm" href="<?= e(url_to('/admin/backup/download?file=' . rawurlencode($b['file']))) ?>" title="下载">下载</a>
            <button type="button" class="btn btn-outline btn-sm admin-backup-restore">恢复</button>
            <button type="button" class="btn btn-ghost btn-sm admin-backup-delete" title="删除备份"
                    <?= $isUpload ? 'disabled' : '' ?>>删除</button>
          </div>
          <div class="admin-backup-restore-box" hidden>
            <p class="admin-backup-restore-tip">恢复将覆盖当前全部数据。系统会先自动创建一份安全备份。请输入备份文件名以确认：</p>
            <div class="admin-backup-restore-form">
              <input type="text" class="input admin-backup-confirm" placeholder="<?= e($b['file']) ?>" autocomplete="off">
              <button type="button" class="btn btn-danger btn-sm admin-backup-restore-ok" disabled>确认恢复</button>
              <button type="button" class="btn btn-ghost btn-sm admin-backup-restore-cancel">取消</button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card admin-form-card">
    <h2 class="admin-card-title">使用说明</h2>
    <ul class="admin-backup-help">
      <li>点击「立即备份」生成当前数据库快照，建议定期手动备份（部署后可用计划任务自动化）</li>
      <li>恢复前系统会自动先创建一份安全备份，防止误操作无法回退</li>
      <li>恢复会覆盖当前全部数据，需输入备份文件名二次确认</li>
      <li>上传的 .sql 文件会保存为 upload-* 并在列表展示，恢复完成后请手动清理</li>
      <li>备份文件包含数据库凭据之外的全部数据，注意保管，勿公开目录</li>
    </ul>
  </div>
</div>

<?php $backupCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($backupCsrf) ?>;
  var msg = document.getElementById("backupMsg");

  function showMsg(text, isError) {
    if (typeof window.pafishToast === "function") {
      window.pafishToast(text, isError ? "error" : "success");
      return;
    }
    msg.textContent = text;
    msg.className = "admin-backup-msg " + (isError ? "admin-backup-msg-error" : "admin-backup-msg-ok");
    msg.hidden = false;
  }

  // 重载前暂存成功消息，操作后列表刷新且提示仍可见
  function persistMsg(text, isError) {
    try { sessionStorage.setItem("backupMsg", JSON.stringify({ t: text, e: isError ? 1 : 0 })); } catch (e) {}
  }
  try {
    var saved = sessionStorage.getItem("backupMsg");
    if (saved) {
      sessionStorage.removeItem("backupMsg");
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

  // 立即备份
  var createBtn = document.getElementById("createBackupBtn");
  createBtn.addEventListener("click", function () {
    createBtn.disabled = true;
    createBtn.textContent = "备份中…";
    var fd = new FormData();
    fd.append("_csrf", CSRF);
    post("/admin/backup/create", fd, function (j) {
      persistMsg("备份完成：" + j.file, false);
      location.reload();
    }).catch(function (err) {
      showMsg(err.message, true);
      createBtn.disabled = false;
      createBtn.textContent = "立即备份";
    });
  });

  // 上传 SQL
  var fileInput = document.getElementById("uploadSqlInput");
  var uploadBtn = document.getElementById("uploadSqlBtn");
  uploadBtn.addEventListener("click", function () { fileInput.click(); });
  fileInput.addEventListener("change", function () {
    if (!fileInput.files || !fileInput.files[0]) { return; }
    uploadBtn.disabled = true;
    uploadBtn.textContent = "上传中…";
    var fd = new FormData();
    fd.append("file", fileInput.files[0]);
    fd.append("_csrf", CSRF);
    post("/admin/backup/upload", fd, function (j) {
      persistMsg("已上传，可在下方列表中选择恢复：" + j.file, false);
      location.reload();
    }).catch(function (err) {
      showMsg(err.message || "上传失败", true);
      uploadBtn.disabled = false;
      uploadBtn.textContent = "上传 SQL 文件";
    });
  });

  // 恢复：展开面板 + 文件名二次确认
  document.querySelectorAll(".admin-backup-restore").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-backup-row");
      var box = row.querySelector(".admin-backup-restore-box");
      document.querySelectorAll(".admin-backup-restore-box").forEach(function (b) {
        if (b !== box) { b.hidden = true; }
      });
      box.hidden = !box.hidden;
      if (!box.hidden) {
        var input = box.querySelector(".admin-backup-confirm");
        input.value = "";
        box.querySelector(".admin-backup-restore-ok").disabled = true;
        input.focus();
      }
    });
  });
  document.querySelectorAll(".admin-backup-restore-cancel").forEach(function (btn) {
    btn.addEventListener("click", function () { btn.closest(".admin-backup-restore-box").hidden = true; });
  });
  document.querySelectorAll(".admin-backup-confirm").forEach(function (input) {
    input.addEventListener("input", function () {
      var box = input.closest(".admin-backup-restore-box");
      var row = box.closest(".admin-backup-row");
      box.querySelector(".admin-backup-restore-ok").disabled = input.value.trim() !== row.dataset.file;
    });
  });
  document.querySelectorAll(".admin-backup-restore-ok").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var box = btn.closest(".admin-backup-restore-box");
      var row = box.closest(".admin-backup-row");
      btn.disabled = true;
      btn.textContent = "恢复中…";
      var fd = new FormData();
      fd.append("file", row.dataset.file);
      fd.append("confirm", box.querySelector(".admin-backup-confirm").value);
      fd.append("_csrf", CSRF);
      post("/admin/backup/restore", fd, function (j) {
        persistMsg("已从 " + j.restored + " 恢复（安全备份：" + j.safety + "）", false);
        location.reload();
      }).catch(function (err) {
        showMsg(err.message, true);
        btn.disabled = false;
        btn.textContent = "确认恢复";
      });
    });
  });

  // 删除
  document.querySelectorAll(".admin-backup-delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-backup-row");
      var fd = new FormData();
      fd.append("file", row.dataset.file);
      fd.append("_csrf", CSRF);
      run("/admin/backup/delete", fd, "已删除 " + row.dataset.file);
    });
  });
})();
</script>
