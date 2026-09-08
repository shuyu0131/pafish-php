<?php
/**
 * 个人资料：
 * 资料表单（头像上传/粘贴地址、昵称、用户名、邮箱）+ 修改密码表单（当前/新/确认）
 * 变量：$me
 */
$me = $me ?? [];
$avatar = !empty($me['avatar_url']) ? $me['avatar_url'] : admin_gravatar((string) ($me['email'] ?? ''));
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">个人资料</h1>
      <p class="admin-page-sub">头像、昵称与密码设置</p>
    </div>
  </div>

  <!-- 资料表单 -->
  <div class="card admin-form-card">
    <h2 class="admin-card-title">资料</h2>
    <form id="profileForm" class="admin-form-grid" method="post" action="<?= e(url_to('/admin/profile/save')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="admin-field">
        <span class="label">头像</span>
        <div class="admin-avatar-edit">
          <img class="admin-avatar-preview" id="avatarPreview" src="<?= e($avatar) ?>" alt="" width="64" height="64">
          <div class="admin-avatar-edit-actions">
            <input type="file" id="avatarFile" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" hidden>
            <button type="button" id="avatarUploadBtn" class="btn btn-outline btn-sm">上传头像</button>
          </div>
        </div>
        <input class="input" type="text" id="avatarUrl" name="avatar_url" value="<?= e((string) ($me['avatar_url'] ?? '')) ?>"
               placeholder="或粘贴图片地址（/uploads/… 或 https://…）" maxlength="500">
        <p class="admin-field-hint">支持上传图片或填写图片地址（留空则按邮箱显示默认头像）</p>
      </div>
      <div class="admin-field">
        <span class="label">显示昵称</span>
        <input class="input" type="text" name="nickname" value="<?= e((string) ($me['nickname'] ?? '')) ?>" maxlength="50" placeholder="留空则显示用户名">
        <p class="admin-field-hint">显示在后台和评论中，留空用用户名</p>
      </div>
      <div class="admin-field">
        <span class="label">用户名（登录名）</span>
        <input class="input" type="text" name="username" value="<?= e((string) ($me['username'] ?? '')) ?>" maxlength="50" required>
        <p class="admin-field-hint">2-50 位，仅限中文、字母、数字、下划线和连字符</p>
      </div>
      <div class="admin-field">
        <span class="label">邮箱</span>
        <input class="input" type="email" name="email" value="<?= e((string) ($me['email'] ?? '')) ?>" maxlength="255" required>
        <p class="admin-field-hint">用于找回密码与接收评论通知</p>
      </div>
      <div class="admin-form-actions">
        <p class="admin-editor-error" id="profileError" hidden></p>
        <p class="admin-settings-msg" id="profileSaved" hidden>已保存</p>
        <button type="submit" id="profileSaveBtn" class="btn btn-primary">保存资料</button>
      </div>
    </form>
  </div>

  <!-- 修改密码 -->
  <div class="card admin-form-card">
    <h2 class="admin-card-title">修改密码</h2>
    <form id="passwordForm" class="admin-form-grid" method="post" action="<?= e(url_to('/admin/profile/password')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="admin-field">
        <span class="label">当前密码</span>
        <input class="input" type="password" name="current_password" placeholder="••••••••" autocomplete="current-password" minlength="6" required>
      </div>
      <div class="admin-field">
        <span class="label">新密码</span>
        <input class="input" type="password" name="new_password" placeholder="••••••••" autocomplete="new-password" minlength="6" required>
      </div>
      <div class="admin-field">
        <span class="label">确认新密码</span>
        <input class="input" type="password" name="confirm_password" placeholder="••••••••" autocomplete="new-password" minlength="6" required>
      </div>
      <div class="admin-form-actions">
        <p class="admin-editor-error" id="passwordError" hidden></p>
        <p class="admin-settings-msg" id="passwordOk" hidden>密码已修改，下次登录请使用新密码</p>
        <button type="submit" id="passwordSaveBtn" class="btn btn-primary">修改密码</button>
      </div>
    </form>
  </div>
</div>

<?php $profileCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($profileCsrf) ?>;
  var base = <?= json_encode(url_to('/admin/profile')) ?>;

  function post(url, fd, onOk) {
    return fetch(url, {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) onOk(j);
        else { throw new Error((j && j.error) || "操作失败"); }
      });
  }
  function bind(formId, errId, onOk) {
    var form = document.getElementById(formId);
    var errBox = document.getElementById(errId);
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      errBox.hidden = true;
      post(form.action, new FormData(form), onOk)
        .catch(function (err) {
          errBox.textContent = err.message;
          errBox.hidden = false;
        });
    });
  }

  // 资料保存：成功显示「已保存」
  var savedBox = document.getElementById("profileSaved");
  bind("profileForm", "profileError", function () {
    savedBox.hidden = false;
    clearTimeout(savedBox._t);
    savedBox._t = setTimeout(function () { savedBox.hidden = true; }, 2500);
  });

  // 修改密码：前端先比对两次输入；成功后清空三框
  var pwForm = document.getElementById("passwordForm");
  var pwOk = document.getElementById("passwordOk");
  pwForm.addEventListener("submit", function (e) {
    e.preventDefault();
    var errBox = document.getElementById("passwordError");
    errBox.hidden = true;
    var newPw = pwForm.elements.new_password.value;
    var confirmPw = pwForm.elements.confirm_password.value;
    if (newPw !== confirmPw) {
      errBox.textContent = "两次输入的新密码不一致";
      errBox.hidden = false;
      return;
    }
    var fd = new FormData(pwForm);
    fd.delete("confirm_password");
    post(pwForm.action, fd, function () {
      pwOk.hidden = false;
      clearTimeout(pwOk._t);
      pwOk._t = setTimeout(function () { pwOk.hidden = true; }, 3000);
      ["current_password", "new_password", "confirm_password"].forEach(function (k) {
        pwForm.elements[k].value = "";
      });
    }).catch(function (err) {
      errBox.textContent = err.message;
      errBox.hidden = false;
    });
  });

  // 头像上传：复用 /api/upload，成功后回填地址并预览
  var fileInput = document.getElementById("avatarFile");
  var urlInput = document.getElementById("avatarUrl");
  var preview = document.getElementById("avatarPreview");
  document.getElementById("avatarUploadBtn").addEventListener("click", function () {
    fileInput.click();
  });
  fileInput.addEventListener("change", function () {
    if (!fileInput.files || !fileInput.files[0]) { return; }
    var fd = new FormData();
    fd.append("file", fileInput.files[0]);
    fd.append("_csrf", CSRF);
    post(pafishApi("/upload"), fd, function (j) {
      urlInput.value = j.url;
      preview.src = j.url;
      fileInput.value = "";
    }).catch(function (err) {
      pafishNotify(err.message || "上传失败");
      fileInput.value = "";
    });
  });
  urlInput.addEventListener("input", function () {
    preview.src = urlInput.value || <?= json_encode($avatar) ?>;
  });
})();
</script>
