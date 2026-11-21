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
      <h1 class="admin-h1"><?= e(t('admin.backups')) ?></h1>
      <p class="admin-page-sub"><?= e(t('admin.back_up_core_tables_and_registered_or_declared')) ?></p>
    </div>
    <div class="admin-head-actions">
      <a class="btn btn-outline" href="<?= e(url_to('/admin/extensions/data')) ?>"><?= e(t('admin.extension_tables')) ?></a>
    </div>
  </div>

  <div class="card admin-backup-toolbar">
    <button type="button" id="createBackupBtn" class="btn btn-primary"><?= e(t('admin.back_up_now')) ?></button>
    <input type="file" id="uploadSqlInput" accept=".sql" hidden>
    <button type="button" id="uploadSqlBtn" class="btn btn-outline"><?= e(t('admin.upload_sql_file')) ?></button>
    <p class="admin-backup-count"><?= e(t('admin.total')) ?> <?= count($backups) ?> <?= e(t('admin.backups_newest_first')) ?></p>
  </div>
  <p class="admin-backup-msg" id="backupMsg" hidden></p>

  <?php if ($backups === []): ?>
    <div class="card admin-empty admin-backup-empty"><?= e(t('admin.no_backups_yet')) ?></div>
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
            <a class="btn btn-ghost btn-sm" href="<?= e(url_to('/admin/backup/download?file=' . rawurlencode($b['file']))) ?>" title="<?= e(t('admin.download')) ?>"><?= e(t('admin.download')) ?></a>
            <button type="button" class="btn btn-outline btn-sm admin-backup-restore"><?= e(t('admin.restore')) ?></button>
            <button type="button" class="btn btn-ghost btn-sm admin-backup-delete" title="<?= e(t('admin.delete_backup')) ?>"
                    <?= $isUpload ? 'disabled' : '' ?>><?= e(t('common.delete')) ?></button>
          </div>
          <div class="admin-backup-restore-box" hidden>
            <p class="admin-backup-restore-tip"><?= e(t('admin.restoring_executes_the_sql_file_and_overwrites_its')) ?></p>
            <div class="admin-backup-restore-form">
              <input type="text" class="input admin-backup-confirm" placeholder="<?= e($b['file']) ?>" autocomplete="off">
              <button type="button" class="btn btn-danger btn-sm admin-backup-restore-ok" disabled><?= e(t('admin.confirm_restore')) ?></button>
              <button type="button" class="btn btn-ghost btn-sm admin-backup-restore-cancel"><?= e(t('common.cancel')) ?></button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card admin-form-card">
    <h2 class="admin-card-title"><?= e(t('admin.instructions')) ?></h2>
    <ul class="admin-backup-help">
      <li><?= e(t('admin.click_back_up_now_to_take_a_database')) ?></li>
      <li><?= e(t('admin.a_safety_backup_is_created_automatically_before_restoring')) ?></li>
      <li><?= e(t('admin.restoring_overwrites_tables_in_the_sql_file_schema')) ?></li>
      <li><?= e(t('admin.uploaded_sql_files_are_saved_as_upload_and')) ?></li>
      <li><?= e(t('admin.undeclared_and_unregistered_private_plugin_tables_uploaded_files')) ?></li>
      <li><?= e(t('admin.backups_may_contain_accounts_and_extension_secrets_store')) ?></li>
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
        else throw new Error((j && j.error) || window.pafishT("common.operation_failed"));
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
    createBtn.textContent = window.pafishT("js.backing_up");
    var fd = new FormData();
    fd.append("_csrf", CSRF);
    post("/admin/backup/create", fd, function (j) {
      persistMsg(window.pafishT("js.backup_complete") + j.file, false);
      location.reload();
    }).catch(function (err) {
      showMsg(err.message, true);
      createBtn.disabled = false;
      createBtn.textContent = window.pafishT("js.back_up_now");
    });
  });

  // 上传 SQL
  var fileInput = document.getElementById("uploadSqlInput");
  var uploadBtn = document.getElementById("uploadSqlBtn");
  uploadBtn.addEventListener("click", function () { fileInput.click(); });
  fileInput.addEventListener("change", function () {
    if (!fileInput.files || !fileInput.files[0]) { return; }
    uploadBtn.disabled = true;
    uploadBtn.textContent = window.pafishT("js.uploading");
    var fd = new FormData();
    fd.append("file", fileInput.files[0]);
    fd.append("_csrf", CSRF);
    post("/admin/backup/upload", fd, function (j) {
      persistMsg(window.pafishT("js.uploaded_select_it_from_the_list_below_to") + j.file, false);
      location.reload();
    }).catch(function (err) {
      showMsg(err.message || window.pafishT("js.upload_failed"), true);
      uploadBtn.disabled = false;
      uploadBtn.textContent = window.pafishT("js.upload_sql_file");
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
      btn.textContent = window.pafishT("js.restoring");
      var fd = new FormData();
      fd.append("file", row.dataset.file);
      fd.append("confirm", box.querySelector(".admin-backup-confirm").value);
      fd.append("_csrf", CSRF);
      post("/admin/backup/restore", fd, function (j) {
        persistMsg(window.pafishT("js.from") + j.restored + window.pafishT("js.restored_safety_backup") + j.safety + "）", false);
        location.reload();
      }).catch(function (err) {
        showMsg(err.message, true);
        btn.disabled = false;
        btn.textContent = window.pafishT("js.confirm_restore");
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
      run("/admin/backup/delete", fd, window.pafishT("js.deleted") + row.dataset.file);
    });
  });
})();
</script>
