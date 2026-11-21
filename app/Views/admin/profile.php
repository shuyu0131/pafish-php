<?php
/**
 * 个人资料：
 * 资料表单（头像上传/粘贴地址、昵称、用户名、邮箱）+ 修改密码表单（当前/新/确认）
 * 变量：$me
 */
$me = $me ?? [];
$appTokens = $appTokens ?? [];
$avatar = \Pafish\Http\Comments::avatarUrl($me['avatar_url'] ?? null, (string) ($me['email'] ?? ''));
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.profile')) ?></h1>
      <p class="admin-page-sub"><?= e(t('admin.profile_password_and_device_access')) ?></p>
    </div>
  </div>

  <!-- 资料表单 -->
  <div class="card admin-form-card">
    <h2 class="admin-card-title"><?= e(t('admin.profile_adfadb')) ?></h2>
    <form id="profileForm" class="admin-form-grid" method="post" action="<?= e(url_to('/admin/profile/save')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="admin-field">
        <span class="label"><?= e(t('admin.avatar')) ?></span>
        <div class="admin-avatar-edit">
          <img class="admin-avatar-preview" id="avatarPreview" src="<?= e($avatar) ?>" alt="" width="64" height="64">
          <div class="admin-avatar-edit-actions">
            <input type="file" id="avatarFile" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
            <button type="button" id="avatarUploadBtn" class="btn btn-outline btn-sm"><?= e(t('admin.upload_avatar')) ?></button>
          </div>
        </div>
        <input class="input" type="text" id="avatarUrl" name="avatar_url" autocomplete="off" value="<?= e((string) ($me['avatar_url'] ?? '')) ?>"
               placeholder="<?= e(t('admin.or_paste_an_image_url_uploads_or_https')) ?>" maxlength="500">
        <p class="admin-field-hint"><?= e(t('admin.leave_blank_to_use_the_default_avatar')) ?></p>
      </div>
      <div class="admin-field">
        <span class="label"><?= e(t('admin.display_name')) ?></span>
        <input class="input" type="text" name="nickname" value="<?= e((string) ($me['nickname'] ?? '')) ?>" maxlength="50" placeholder="<?= e(t('admin.leave_blank_to_display_the_username')) ?>">
        <p class="admin-field-hint"><?= e(t('admin.shown_in_the_admin_interface_and_comments_leave')) ?></p>
      </div>
      <div class="admin-field">
        <span class="label"><?= e(t('admin.username_sign_in_name')) ?></span>
        <input class="input" type="text" name="username" value="<?= e((string) ($me['username'] ?? '')) ?>" maxlength="50" required>
        <p class="admin-field-hint"><?= e(t('admin.2_50_characters_chinese_characters_letters_numbers_underscores')) ?></p>
      </div>
      <div class="admin-field">
        <span class="label"><?= e(t('admin.email')) ?></span>
        <input class="input" type="email" name="email" value="<?= e((string) ($me['email'] ?? '')) ?>" maxlength="255" required>
        <p class="admin-field-hint"><?= e(t('admin.used_for_password_recovery_and_comment_notifications')) ?></p>
      </div>
      <div class="admin-field">
        <span class="label"><?= e(t('admin.current_password')) ?></span>
        <input class="input" type="password" name="current_password" autocomplete="current-password">
        <p class="admin-field-hint"><?= e(t('admin.required_when_changing_your_username_or_email')) ?></p>
      </div>
      <div class="admin-form-actions">
        <p class="admin-editor-error" id="profileError" hidden></p>
        <button type="submit" id="profileSaveBtn" class="btn btn-primary"><?= e(t('admin.save_profile')) ?></button>
      </div>
    </form>
  </div>

  <!-- 修改密码 -->
  <div class="card admin-form-card">
    <h2 class="admin-card-title"><?= e(t('admin.change_password')) ?></h2>
    <form id="passwordForm" class="admin-form-grid" method="post" action="<?= e(url_to('/admin/profile/password')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="admin-field">
        <span class="label"><?= e(t('admin.current_password')) ?></span>
        <input class="input" type="password" name="current_password" placeholder="••••••••" autocomplete="current-password" minlength="6" required>
      </div>
      <div class="admin-field">
        <span class="label"><?= e(t('admin.new_password')) ?></span>
        <input class="input" type="password" name="new_password" placeholder="••••••••" autocomplete="new-password" minlength="6" required>
      </div>
      <div class="admin-field">
        <span class="label"><?= e(t('admin.confirm_new_password')) ?></span>
        <input class="input" type="password" name="confirm_password" placeholder="••••••••" autocomplete="new-password" minlength="6" required>
      </div>
      <div class="admin-form-actions">
        <p class="admin-editor-error" id="passwordError" hidden></p>
        <button type="submit" id="passwordSaveBtn" class="btn btn-primary"><?= e(t('admin.change_password')) ?></button>
      </div>
    </form>
  </div>

  <div class="card admin-form-card">
    <h2 class="admin-card-title"><?= e(t('admin.mobile_devices')) ?></h2>
    <form id="appTokenForm" class="admin-form-grid admin-app-token-form" method="post" action="<?= e(url_to('/admin/profile/app-token/create')) ?>">
      <?= csrf_field() ?>
      <div class="admin-field">
        <label class="label" for="appTokenDevice"><?= e(t('admin.device_name')) ?></label>
        <input class="input" id="appTokenDevice" name="device_name" type="text" placeholder="<?= e(t('admin.for_example_my_phone')) ?>" maxlength="100" required>
      </div>
      <div class="admin-field">
        <label class="label" for="appTokenPassword"><?= e(t('admin.current_password')) ?></label>
        <input class="input" id="appTokenPassword" name="current_password" type="password" autocomplete="current-password" required>
      </div>
      <div class="admin-form-actions">
        <p class="admin-editor-error" id="appTokenError" role="alert" hidden></p>
        <button type="submit" class="btn btn-primary"><?= e(t('admin.create_token')) ?></button>
      </div>
    </form>
    <div id="appTokenResult" class="admin-app-token-result" hidden>
      <div class="admin-field">
        <label class="label" for="appTokenValue"><?= e(t('admin.access_token')) ?></label>
        <input class="input" id="appTokenValue" type="text" readonly autocomplete="off" spellcheck="false" aria-describedby="appTokenHint appTokenExpiry">
        <p class="admin-field-hint" id="appTokenHint"><?= e(t('admin.the_full_token_is_shown_only_once_copy')) ?></p>
        <p class="admin-field-hint" id="appTokenExpiry"></p>
        <p class="admin-field-hint" id="appTokenCopyStatus" role="status" aria-live="polite"></p>
      </div>
      <div class="admin-form-actions">
        <button type="button" id="appTokenCopy" class="btn btn-primary"><?= e(t('admin.copy_token')) ?></button>
        <button type="button" id="appTokenDone" class="btn btn-outline"><?= e(t('admin.done')) ?></button>
      </div>
    </div>
    <div id="appTokenDevices" class="admin-app-token-devices">
    <?php if ($appTokens === []): ?>
      <p class="admin-muted"><?= e(t('admin.no_mobile_devices_authorized_yet')) ?></p>
    <?php else: ?>
      <div class="admin-table-wrap" role="region" aria-label="<?= e(t('admin.mobile_app_access')) ?>">
        <table class="admin-table">
          <thead><tr><th><?= e(t('admin.device')) ?></th><th><?= e(t('admin.last_used')) ?></th><th><?= e(t('admin.expires')) ?></th><th><?= e(t('common.status')) ?></th><th class="admin-col-ops"><?= e(t('common.actions')) ?></th></tr></thead>
          <tbody>
            <?php foreach ($appTokens as $token): ?>
              <?php $expired = strtotime((string) $token['expires_at']) <= time(); $revoked = !empty($token['revoked_at']); ?>
              <tr>
                <td><?= e((string) $token['device_name']) ?><span class="admin-muted"> · <?= e(date('Y-m-d', strtotime((string) $token['created_at']))) ?></span></td>
                <td><?= $token['last_used_at'] ? e(date('Y-m-d H:i', strtotime((string) $token['last_used_at']))) : t('admin.never_used') ?></td>
                <td><?= e(date('Y-m-d H:i', strtotime((string) $token['expires_at']))) ?></td>
                <td><?= $revoked ? t('admin.revoked') : ($expired ? t('admin.expired') : t('admin.valid')) ?></td>
                <td>
                  <?php if (!$revoked && !$expired): ?>
                    <button type="button" class="btn btn-ghost btn-sm" data-app-token-revoke="<?= (int) $token['id'] ?>" data-device-name="<?= e((string) $token['device_name']) ?>"><?= e(t('admin.revoke')) ?></button>
                  <?php elseif ($revoked): ?>
                    <button type="button" class="btn btn-ghost btn-sm" data-app-token-delete="<?= (int) $token['id'] ?>" data-device-name="<?= e((string) $token['device_name']) ?>"><?= e(t('common.delete')) ?></button>
                  <?php else: ?>
                    <span class="admin-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    </div>
  </div>
</div>

<?php $profileCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($profileCsrf) ?>;

  function post(url, fd, onOk) {
    return fetch(url, {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function (r) {
        return r.json().then(function (j) {
          if (!r.ok || !j || !j.ok) throw new Error((j && j.error) || window.pafishT("common.operation_failed"));
          return j;
        });
      })
      .then(function (j) {
        onOk(j);
      });
  }
  function bind(formId, errId, onOk, pendingLabel) {
    var form = document.getElementById(formId);
    var errBox = document.getElementById(errId);
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      errBox.hidden = true;
      var submit = form.querySelector("button[type=submit]");
      if (submit) { submit.disabled = true; submit.dataset.label = submit.textContent; submit.textContent = pendingLabel || window.pafishT("common.saving"); }
      post(form.action, new FormData(form), onOk)
        .catch(function (err) {
          errBox.textContent = err.message;
          errBox.hidden = false;
        })
        .finally(function () {
          if (submit) { submit.disabled = false; submit.textContent = submit.dataset.label || window.pafishT("common.save"); }
        });
    });
  }

  // 资料保存：统一使用后台 toast
  bind("profileForm", "profileError", function () {
    if (typeof window.pafishToast === "function") window.pafishToast(window.pafishT("js.profile_saved"), "success");
  });

  var tokenForm = document.getElementById("appTokenForm");
  var tokenResult = document.getElementById("appTokenResult");
  var tokenValue = document.getElementById("appTokenValue");
  var tokenCopyStatus = document.getElementById("appTokenCopyStatus");
  bind("appTokenForm", "appTokenError", function (j) {
    tokenForm.reset();
    tokenValue.value = j.accessToken;
    document.getElementById("appTokenExpiry").textContent = window.pafishT("js.expires") + j.expiresAt;
    tokenCopyStatus.textContent = "";
    tokenForm.hidden = true;
    document.getElementById("appTokenDevices").hidden = true;
    tokenResult.hidden = false;
    tokenValue.focus();
    tokenValue.select();
  }, window.pafishT("js.creating"));
  document.getElementById("appTokenCopy").addEventListener("click", function () {
    function selectForCopy() {
      tokenValue.focus();
      tokenValue.select();
      tokenCopyStatus.textContent = window.pafishT("js.long_press_or_use_your_system_s_copy");
    }
    if (!navigator.clipboard || !navigator.clipboard.writeText) { selectForCopy(); return; }
    navigator.clipboard.writeText(tokenValue.value).then(function () {
      tokenCopyStatus.textContent = window.pafishT("js.token_copied");
    }).catch(selectForCopy);
  });
  document.getElementById("appTokenDone").addEventListener("click", function () {
    tokenValue.value = "";
    location.reload();
  });
  window.addEventListener("pagehide", function () {
    tokenValue.value = "";
    tokenForm.reset();
    tokenResult.hidden = true;
    tokenForm.hidden = false;
    document.getElementById("appTokenDevices").hidden = false;
  });
  window.addEventListener("pageshow", function (event) {
    if (event.persisted) location.reload();
  });

  // 修改密码：前端先比对两次输入；成功后清空三框
  var pwForm = document.getElementById("passwordForm");
  pwForm.addEventListener("submit", function (e) {
    e.preventDefault();
    var errBox = document.getElementById("passwordError");
    errBox.hidden = true;
    var newPw = pwForm.elements.new_password.value;
    var confirmPw = pwForm.elements.confirm_password.value;
    if (newPw !== confirmPw) {
      errBox.textContent = window.pafishT("js.the_new_passwords_do_not_match");
      errBox.hidden = false;
      return;
    }
    var fd = new FormData(pwForm);
    fd.delete("confirm_password");
    post(pwForm.action, fd, function () {
      if (typeof window.pafishToast === "function") window.pafishToast(window.pafishT("js.password_changed_use_the_new_password_next_time"), "success");
      ["current_password", "new_password", "confirm_password"].forEach(function (k) {
        pwForm.elements[k].value = "";
      });
    }).catch(function (err) {
      errBox.textContent = err.message;
      errBox.hidden = false;
    });
  });

  // Avatar-only endpoint also serves ordinary members; saving the form commits the address.
  var fileInput = document.getElementById("avatarFile");
  var urlInput = document.getElementById("avatarUrl");
  var preview = document.getElementById("avatarPreview");
  var uploadButton = document.getElementById("avatarUploadBtn");
  uploadButton.addEventListener("click", function () {
    fileInput.click();
  });
  fileInput.addEventListener("change", function () {
    if (!fileInput.files || !fileInput.files[0]) { return; }
    var fd = new FormData();
    fd.append("file", fileInput.files[0]);
    fd.append("_csrf", CSRF);
    uploadButton.disabled = true;
    uploadButton.textContent = window.pafishT("js.uploading");
    post(<?= json_encode(url_to('/admin/profile/avatar')) ?>, fd, function (j) {
      urlInput.value = j.url;
      preview.src = j.url;
      fileInput.value = "";
    }).catch(function (err) {
      pafishNotify(err.message || window.pafishT("js.upload_failed"), true);
      fileInput.value = "";
    }).finally(function () {
      uploadButton.disabled = false;
      uploadButton.textContent = window.pafishT("js.upload_avatar");
    });
  });
  urlInput.addEventListener("input", function () {
    var value = urlInput.value.trim();
    preview.src = /^(?:\/(?!\/)|https?:\/\/)/i.test(value) && !/[\x00-\x20\x7f\\]/.test(value) ? value : <?= json_encode(admin_gravatar((string) ($me['email'] ?? ''))) ?>;
  });

  document.querySelectorAll("[data-app-token-revoke], [data-app-token-delete]").forEach(function (button) {
    button.addEventListener("click", function () {
      var label = button.dataset.deviceName || window.pafishT("js.this_device");
      var deleting = button.hasAttribute("data-app-token-delete");
      var action = deleting ? "delete" : "revoke";
      var tokenId = deleting ? button.dataset.appTokenDelete : button.dataset.appTokenRevoke;
      var prompt = deleting ? window.pafishT("js.delete") + label + window.pafishT("js.revoked_token_record") : window.pafishT("js.revoke") + label + window.pafishT("js.mobile_app_access");
      var confirmAction = window.pafishConfirm
        ? window.pafishConfirm(prompt, { title: deleting ? window.pafishT("js.delete_token") : window.pafishT("js.revoke_device"), accept: deleting ? window.pafishT("common.delete") : window.pafishT("js.revoke_9fcefd") })
        : Promise.resolve(window.confirm(prompt));
      confirmAction.then(function (ok) {
        if (!ok) return;
        button.disabled = true;
        var fd = new FormData();
        fd.append("_csrf", CSRF);
        post(<?= json_encode(url_to('/admin/profile/app-token/')) ?> + encodeURIComponent(tokenId) + "/" + action, fd, function () {
          if (typeof window.pafishToastReload === "function") window.pafishToastReload(deleting ? window.pafishT("js.token_record_deleted") : window.pafishT("js.device_authorization_revoked"), "success");
          else location.reload();
        }).catch(function (err) { pafishNotify(err.message || (deleting ? window.pafishT("js.delete_failed") : window.pafishT("js.unable_to_revoke")), true); })
          .finally(function () { button.disabled = false; });
      });
    });
  });
})();
</script>
