<?php
/**
 * 站点设置（对齐 Node app/admin/settings/）：
 * 7 张卡片：站点信息 / 评论与列表 / 上传与媒体库 / 账号与注册 / 邮件服务（SMTP）/
 * 邮件通知（新评论提醒）/ 开放 API；整页一个表单整体保存，底部 SMTP 测试与 Key 重新生成。
 * 变量：$all（settings 全量键值）
 */
$all = $all ?? [];

/** 布尔键：未存储时用默认值；返回是否勾选 */
$boolVal = static function (string $key, string $default) use ($all): bool {
    return ((string) ($all[$key] ?? $default)) === 'true';
};

/** 密码/文本键回显值 */
$textVal = static function (string $key, string $default = '') use ($all): string {
    return (string) ($all[$key] ?? $default);
};

// IP 黑名单：存储 JSON 数组 → 逗号分隔文本展示（对齐 Node ipListToText）
$ipsText = '';
try {
    $ips = json_decode((string) ($all['blocked_ips'] ?? '[]'), true);
    if (is_array($ips)) {
        $ipsText = implode(', ', $ips);
    }
} catch (\Throwable) {
}
?>
<div class="admin-stack">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">站点设置</h1>
      <p class="admin-page-sub">配置博客前台显示与评论规则</p>
    </div>
  </div>

  <form id="settingsForm" class="admin-settings-form" method="post" action="<?= e(url_to('/admin/settings/save')) ?>" novalidate>
    <?= csrf_field() ?>

    <!-- 站点信息 -->
    <div class="card admin-form-card">
      <div class="admin-form-grid">
        <div class="admin-field">
          <span class="label">站点名称</span>
          <input class="input" type="text" name="site_name" value="<?= e($textVal('site_name')) ?>" placeholder="纸鱼博客" maxlength="100">
        </div>
        <div class="admin-field">
          <span class="label">副标题</span>
          <input class="input" type="text" name="site_subtitle" value="<?= e($textVal('site_subtitle')) ?>" placeholder="记录技术、设计与生活" maxlength="200">
        </div>
        <div class="admin-field">
          <span class="label">站点描述（SEO）</span>
          <input class="input" type="text" name="site_description" value="<?= e($textVal('site_description')) ?>" placeholder="用于搜索引擎描述" maxlength="500">
        </div>
        <div class="admin-field">
          <span class="label">ICP 备案号</span>
          <input class="input" type="text" name="site_icp" value="<?= e($textVal('site_icp')) ?>" placeholder="如：京ICP备xxxxxxxx号" maxlength="100">
        </div>
        <div class="admin-field">
          <span class="label">应用商店地址</span>
          <input class="input" type="text" name="store_url" value="<?= e($textVal('store_url')) ?>" placeholder="留空使用内置官方商店" maxlength="500">
          <p class="admin-field-hint">远程商店基础地址，需提供 themes.json 与 plugins.json；留空用内置官方商店，远程不可达自动回退本地内置。</p>
        </div>
        <div class="admin-field">
          <span class="label">商店访问令牌</span>
          <input class="input" type="password" name="store_token" value="<?= e($textVal('store_token')) ?>" placeholder="公开源留空" autocomplete="new-password" maxlength="500">
          <p class="admin-field-hint">私有源鉴权，请求目录与 zip 下载携带 Authorization: Bearer 头；留空=公开源；令牌只存服务器。</p>
        </div>
      </div>
    </div>

    <!-- 评论与列表 -->
    <div class="card admin-form-card">
      <h2 class="admin-card-title">评论与列表</h2>
      <label class="admin-check-row">
        <input type="checkbox" name="comments_enabled" value="true" <?= $boolVal('comments_enabled', 'true') ? 'checked' : '' ?>>
        <span>启用评论功能</span>
      </label>
      <label class="admin-check-row">
        <input type="checkbox" name="comments_need_review" value="true" <?= $boolVal('comments_need_review', 'true') ? 'checked' : '' ?>>
        <span>新评论需审核后显示</span>
      </label>
      <label class="admin-check-row">
        <input type="checkbox" name="comments_captcha_enabled" value="true" <?= $boolVal('comments_captcha_enabled', 'true') ? 'checked' : '' ?>>
        <span>游客评论需输入图形验证码（已登录用户免验证码）</span>
      </label>
      <div class="admin-form-grid">
        <div class="admin-field admin-field-narrow">
          <span class="label">每页文章数</span>
          <input class="input admin-input-w32" type="number" name="posts_per_page" value="<?= e($textVal('posts_per_page', '10')) ?>" min="1" max="50">
        </div>
        <div class="admin-field">
          <span class="label">IP 黑名单</span>
          <textarea class="input admin-input-block" name="blocked_ips" rows="3" placeholder="如：1.2.3.4, 5.6.7.8（逗号或换行分隔）"><?= e($ipsText) ?></textarea>
          <p class="admin-field-hint">拉黑后该 IP 无法再提交评论；也可在评论审核页按 IP 一键拉黑。</p>
        </div>
      </div>
    </div>

    <!-- 上传与媒体库 -->
    <div class="card admin-form-card">
      <h2 class="admin-card-title">上传与媒体库</h2>
      <div class="admin-form-grid">
        <div class="admin-field admin-field-narrow">
          <span class="label">上传大小限制（MB）</span>
          <input class="input admin-input-w32" type="number" name="upload_max_mb" value="<?= e($textVal('upload_max_mb', '20')) ?>" min="1" max="200">
        </div>
      </div>
      <p class="admin-field-hint">媒体库支持图片自动压缩、文档、压缩包与音视频；Nginx 默认 1MB 请求体限制，若上传大文件需同步调整 client_max_body_size。</p>
    </div>

    <!-- 账号与注册 -->
    <div class="card admin-form-card">
      <h2 class="admin-card-title">账号与注册</h2>
      <label class="admin-check-row">
        <input type="checkbox" name="allow_registration" value="true" <?= $boolVal('allow_registration', 'true') ? 'checked' : '' ?>>
        <span>开放注册（关闭后注册页不可用）</span>
      </label>
      <label class="admin-check-row">
        <input type="checkbox" name="require_email_verify" value="true" <?= $boolVal('require_email_verify', 'true') ? 'checked' : '' ?>>
        <span>注册需邮箱验证码（依赖下方 SMTP 配置）</span>
      </label>
      <p class="admin-field-hint">开启后访客可在 /register 自助注册，注册即登录；管理员可在「用户管理」中禁用/解禁账号。</p>
    </div>

    <!-- 邮件服务（SMTP） -->
    <div class="card admin-form-card">
      <h2 class="admin-card-title">邮件服务（SMTP）</h2>
      <div class="admin-form-grid">
        <div class="admin-field">
          <span class="label">SMTP 主机</span>
          <input class="input" type="text" name="smtp_host" value="<?= e($textVal('smtp_host')) ?>" placeholder="smtp.example.com" maxlength="255">
        </div>
        <div class="admin-field admin-field-narrow">
          <span class="label">端口</span>
          <input class="input admin-input-w40" type="number" name="smtp_port" value="<?= e($textVal('smtp_port', '465')) ?>" placeholder="465（SSL）或 587（STARTTLS）" min="1" max="65535">
        </div>
        <div class="admin-field">
          <span class="label">账号</span>
          <input class="input" type="text" name="smtp_user" value="<?= e($textVal('smtp_user')) ?>" placeholder="noreply@example.com" maxlength="255">
        </div>
        <div class="admin-field">
          <span class="label">密码 / 授权码</span>
          <input class="input" type="password" name="smtp_pass" value="<?= e($textVal('smtp_pass')) ?>" placeholder="••••••••" autocomplete="new-password" maxlength="500">
        </div>
        <div class="admin-field">
          <span class="label">发件人地址（可选，默认用账号）</span>
          <input class="input" type="email" name="smtp_from" value="<?= e($textVal('smtp_from')) ?>" placeholder="noreply@example.com" maxlength="255">
        </div>
      </div>
      <p class="admin-field-hint">用于发送注册/找回密码验证码、评论回复提醒与站长通知；配置保存在服务器，不会出现在页面源码；也可在 .env 配置 SMTP_* 作为兜底。</p>
      <button type="button" id="testSmtpBtn" class="btn btn-outline">发送测试邮件</button>
      <p id="testSmtpMsg" class="admin-settings-msg" hidden></p>
    </div>

    <!-- 邮件通知（新评论提醒） -->
    <div class="card admin-form-card">
      <h2 class="admin-card-title">邮件通知（新评论提醒）</h2>
      <label class="admin-check-row">
        <input type="checkbox" name="notify_email_enabled" value="true" <?= $boolVal('notify_email_enabled', 'false') ? 'checked' : '' ?>>
        <span>启用邮件通知（需在上方配置 SMTP）</span>
      </label>
      <div class="admin-form-grid">
        <div class="admin-field">
          <span class="label">接收通知的邮箱</span>
          <input class="input" type="email" name="notify_email" value="<?= e($textVal('notify_email')) ?>" placeholder="admin@example.com" maxlength="255">
        </div>
      </div>
      <p class="admin-field-hint">新评论/新回复产生时发送提醒邮件；站内通知（后台铃铛）始终生效，无需 SMTP。</p>
    </div>

    <!-- 开放 API -->
    <div class="card admin-form-card">
      <h2 class="admin-card-title">开放 API</h2>
      <label class="admin-check-row">
        <input type="checkbox" name="api_enabled" value="true" <?= $boolVal('api_enabled', 'false') ? 'checked' : '' ?>>
        <span>启用开放 API（JSON 接口，供第三方程序调用）</span>
      </label>
      <div class="admin-field">
        <span class="label">API Key</span>
        <div class="admin-key-row">
          <input class="input admin-key-input" type="text" name="api_key" value="<?= e($textVal('api_key')) ?>" placeholder="启用后自动生成" readonly>
          <button type="button" id="regenerateKeyBtn" class="btn btn-outline admin-key-btn">重新生成</button>
        </div>
        <p class="admin-field-hint">调用时在请求头携带 X-API-Key；Key 泄露后请立即重新生成（旧 Key 作废）。</p>
        <pre class="admin-code-block">curl -H "X-API-Key: &lt;你的Key&gt;" https://你的域名/api/v1/posts
curl -H "X-API-Key: &lt;你的Key&gt;" https://你的域名/api/v1/posts/文章别名
curl -H "X-API-Key: &lt;你的Key&gt;" "https://你的域名/api/v1/comments?postId=1"
# 更多：/api/v1/categories、/api/v1/tags</pre>
      </div>
    </div>

    <p class="admin-editor-error" id="settingsError" hidden></p>
    <p class="admin-settings-msg" id="settingsSaved" hidden>✓ 设置已保存</p>
    <button type="submit" id="saveSettingsBtn" class="btn btn-primary">保存设置</button>
  </form>
</div>

<?php $settingsCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($settingsCsrf) ?>;
  var base = <?= json_encode(url_to('/admin/settings')) ?>;
  var form = document.getElementById("settingsForm");
  var errBox = document.getElementById("settingsError");
  var savedBox = document.getElementById("settingsSaved");
  var saveBtn = document.getElementById("saveSettingsBtn");

  function showError(msg) {
    errBox.textContent = msg;
    errBox.hidden = false;
  }
  function clearError() {
    errBox.textContent = "";
    errBox.hidden = true;
  }
  function showSaved() {
    savedBox.hidden = false;
    clearTimeout(savedBox._t);
    savedBox._t = setTimeout(function () { savedBox.hidden = true; }, 2500);
  }

  function post(url, fd, onOk) {
    return fetch(url, {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) onOk(j);
        else showError((j && j.error) || "操作失败");
      })
      .catch(function () { showError("网络错误"); });
  }

  // 保存：checkbox 未勾选提交 "false"；IP 黑名单文本转 JSON 数组（对齐 Node textToIpList）
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    clearError();
    saveBtn.disabled = true;
    var fd = new FormData(form);
    form.querySelectorAll("input[type=checkbox]").forEach(function (cb) {
      fd.set(cb.name, cb.checked ? "true" : "false");
    });
    var ips = (fd.get("blocked_ips") || "").split(/[,，\s]+/)
      .map(function (s) { return s.trim(); })
      .filter(Boolean);
    fd.set("blocked_ips", JSON.stringify([...new Set(ips)]));
    post(form.action, fd, function () {
      location.reload();
    }).catch(function () { saveBtn.disabled = false; });
  });

  // SMTP 测试：用表单当前值（未保存也能测）
  var testBtn = document.getElementById("testSmtpBtn");
  var testMsg = document.getElementById("testSmtpMsg");
  testBtn.addEventListener("click", function () {
    clearError();
    testMsg.hidden = true;
    testBtn.disabled = true;
    testBtn.textContent = "发送中…";
    var fd = new FormData();
    // 测试接口契约（对齐 Node sendTestEmailAction）：host/port/user/pass/from
    fd.append("host", form.elements.smtp_host.value);
    fd.append("port", form.elements.smtp_port.value);
    fd.append("user", form.elements.smtp_user.value);
    fd.append("pass", form.elements.smtp_pass.value);
    fd.append("from", form.elements.smtp_from.value);
    fd.append("_csrf", CSRF);
    post(base + "/test-smtp", fd, function (j) {
      testMsg.textContent = "✓ 测试邮件已发送至 " + j.to + "，请查收（含垃圾箱）";
      testMsg.hidden = false;
    }).finally(function () {
      testBtn.disabled = false;
      testBtn.textContent = "发送测试邮件";
    });
  });

  // API Key 重新生成
  var regenBtn = document.getElementById("regenerateKeyBtn");
  regenBtn.addEventListener("click", function () {
    clearError();
    regenBtn.disabled = true;
    var fd = new FormData();
    fd.append("_csrf", CSRF);
    post(base + "/regenerate-key", fd, function (j) {
      form.elements.api_key.value = j.key;
      showSaved();
    }).finally(function () { regenBtn.disabled = false; });
  });
})();
</script>
