<?php
/**
 * 认证页（登录/注册/找回密码/重置密码；系统 fallback 模板，主题可覆盖）
 * 可用数据：$mode（login|register|forgot|reset）、$siteName、$subtitle、$showRegister、
 *           $requireVerify、$from（登录后跳转）、$token（重置令牌）
 * 结构对齐 Node 版 app/login|register|forgot-password|reset-password：独立居中卡片，无博客壳
 */
$mode = (string) ($mode ?? 'login');
$siteName = $siteName ?? site_name();
$subtitle = (string) ($subtitle ?? '');
$showRegister = (bool) ($showRegister ?? false);
$requireVerify = (bool) ($requireVerify ?? true);
$from = (string) ($from ?? '/admin');
$token = (string) ($token ?? '');
$titleMap = ['login' => '登录', 'register' => '注册', 'forgot' => '找回密码', 'reset' => '重置密码'];
$title = $titleMap[$mode] ?? '登录';
$activeTheme = Theme::active();
$themeCss = Theme::css($activeTheme);
$layoutCss = Theme::layoutCss($activeTheme);
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title . ' · ' . $siteName) ?></title>
<?php if ($layoutCss !== null || $themeCss !== null): ?>
<style><?= $layoutCss ?? '' ?><?= $themeCss ?? '' ?></style>
<?php endif; ?>
<script>
/* API 路径适配：pretty_urls=false 时请求走 index.php?p=api/...，query 用 & 拼接 */
window.pafishApi = function (p) {
  var q = p.indexOf('?');
  var path = q >= 0 ? p.slice(0, q) : p;
  var query = q >= 0 ? p.slice(q + 1) : '';
  return <?= json_encode(url_to('/api')) ?> + path + (query ? <?= json_encode(Config::get('pretty_urls', true) ? '?' : '&') ?> + query : '');
};
</script>
</head>
<body class="auth-body">
<div class="auth-page">
  <div class="card auth-card">
    <div class="auth-head">
      <h1 class="auth-title"><?= e($siteName) ?></h1>
      <p class="auth-sub"><?= e($subtitle) ?></p>
    </div>

    <?php if ($mode === 'login'): ?>
    <form id="auth-form" class="auth-form" data-mode="login" novalidate>
      <div>
        <label class="label" for="auth-username">用户名</label>
        <input id="auth-username" class="input" placeholder="请输入用户名" autocomplete="username" required>
      </div>
      <div>
        <label class="label" for="auth-password">密码</label>
        <input id="auth-password" type="password" class="input" placeholder="请输入密码" autocomplete="current-password" required>
      </div>
      <p class="auth-error" hidden></p>
      <button type="submit" class="btn btn-primary auth-submit">登 录</button>
      <p class="auth-links">
        <a href="<?= e(url_to('/forgot-password')) ?>">忘记密码</a>
        <?php if ($showRegister): ?>
          <a href="<?= e(url_to('/register')) ?>" class="auth-link-accent">注册账号</a>
        <?php endif; ?>
      </p>
    </form>

    <?php elseif ($mode === 'register'): ?>
    <?php if (!$showRegister): ?>
      <div class="auth-closed">
        <p>本站暂未开放注册，请联系管理员。</p>
        <a href="<?= e(url_to('/login')) ?>" class="btn btn-outline auth-wide">去登录</a>
      </div>
    <?php else: ?>
    <form id="auth-form" class="auth-form" data-mode="register" novalidate>
      <div>
        <label class="label" for="reg-username">用户名</label>
        <input id="reg-username" class="input" placeholder="2-50 位，中文、字母、数字" autocomplete="username" required>
      </div>
      <div>
        <label class="label" for="reg-email">邮箱</label>
        <div class="auth-row">
          <input id="reg-email" type="email" class="input auth-flex" placeholder="用于找回密码" autocomplete="email" required>
          <?php if ($requireVerify): ?>
            <button type="button" id="send-code-btn" class="btn btn-outline auth-send-btn">发送验证码</button>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($requireVerify): ?>
      <div>
        <label class="label" for="reg-code">邮箱验证码</label>
        <input id="reg-code" class="input auth-code" placeholder="6 位数字" maxlength="6" inputmode="numeric" required>
        <p class="auth-hint">验证码已发送至邮箱，10 分钟内有效；未收到请检查垃圾箱。</p>
      </div>
      <?php endif; ?>
      <div>
        <label class="label" for="reg-password">密码</label>
        <input id="reg-password" type="password" class="input" placeholder="至少 6 位" autocomplete="new-password" required>
      </div>
      <div>
        <label class="label" for="reg-confirm">确认密码</label>
        <input id="reg-confirm" type="password" class="input" placeholder="再次输入密码" autocomplete="new-password" required>
      </div>
      <p class="auth-error" hidden></p>
      <button type="submit" class="btn btn-primary auth-submit">注 册</button>
      <p class="auth-links auth-center">
        已有账号？<a href="<?= e(url_to('/login')) ?>" class="auth-link-accent">去登录</a>
      </p>
    </form>
    <?php endif; ?>

    <?php elseif ($mode === 'forgot'): ?>
    <form id="forgot-send-form" class="auth-form" novalidate>
      <div>
        <label class="label" for="forgot-email">注册邮箱</label>
        <input id="forgot-email" type="email" class="input" placeholder="请输入注册时使用的邮箱" autocomplete="email" required>
        <p class="auth-hint">输入邮箱后点击发送，验证码将通过邮件送达（未注册邮箱同样提示已发送）。</p>
      </div>
      <p class="auth-error" hidden></p>
      <button type="submit" class="btn btn-primary auth-submit">发送验证码</button>
      <p class="auth-links auth-center">
        <a href="<?= e(url_to('/login')) ?>">返回登录</a>
      </p>
    </form>
    <form id="forgot-reset-form" class="auth-form" hidden novalidate>
      <p class="auth-hint">验证码已发送至 <span id="forgot-email-show" class="auth-strong"></span>，10 分钟内有效。</p>
      <div>
        <label class="label" for="forgot-code">邮箱验证码</label>
        <input id="forgot-code" class="input auth-code" placeholder="6 位数字" maxlength="6" inputmode="numeric" required>
      </div>
      <div>
        <label class="label" for="forgot-password">新密码</label>
        <input id="forgot-password" type="password" class="input" placeholder="至少 6 位" autocomplete="new-password" required>
      </div>
      <div>
        <label class="label" for="forgot-confirm">确认新密码</label>
        <input id="forgot-confirm" type="password" class="input" placeholder="再次输入新密码" autocomplete="new-password" required>
      </div>
      <p class="auth-error" hidden></p>
      <button type="submit" class="btn btn-primary auth-submit">重置密码</button>
      <p class="auth-links auth-center">
        未收到验证码？
        <button type="button" id="resend-code-btn" class="auth-link-btn">重新发送</button>
      </p>
    </form>
    <div id="forgot-done" class="auth-closed" hidden>
      <p>密码已重置，请使用新密码登录。</p>
      <a href="<?= e(url_to('/login')) ?>" class="btn btn-primary auth-wide">去登录</a>
    </div>

    <?php elseif ($mode === 'reset'): ?>
    <?php if ($token === ''): ?>
      <div class="auth-closed">
        <p>缺少重置令牌，请从邮件或重置链接进入本页。</p>
        <a href="<?= e(url_to('/forgot-password')) ?>" class="btn btn-outline auth-wide">重新申请</a>
      </div>
    <?php else: ?>
    <form id="auth-form" class="auth-form" data-mode="reset" novalidate>
      <div>
        <label class="label" for="reset-password">新密码</label>
        <input id="reset-password" type="password" class="input" placeholder="至少 6 位" autocomplete="new-password" required>
      </div>
      <div>
        <label class="label" for="reset-confirm">确认新密码</label>
        <input id="reset-confirm" type="password" class="input" placeholder="再次输入新密码" autocomplete="new-password" required>
      </div>
      <p class="auth-error" hidden></p>
      <button type="submit" class="btn btn-primary auth-submit">重置密码</button>
      <p class="auth-links auth-center">
        <a href="<?= e(url_to('/login')) ?>">返回登录</a>
      </p>
    </form>
    <div id="reset-done" class="auth-closed" hidden>
      <p>密码已重置，请使用新密码登录。</p>
      <a href="<?= e(url_to('/login')) ?>" class="btn btn-primary auth-wide">去登录</a>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
  <p class="auth-footer">© <?= date('Y') ?> <?= e($siteName) ?></p>
</div>

<script>
(function () {
  'use strict';
  var FROM = <?= json_encode($from, JSON_UNESCAPED_UNICODE) ?>;
  var MODE = <?= json_encode($mode) ?>;

  function showError(el, msg) { el.textContent = msg; el.hidden = false; }
  function hideError(el) { el.hidden = true; el.textContent = ''; }
  function setLoading(btn, on, loadingText) {
    if (!btn) return;
    if (on) { btn.dataset.orig = btn.textContent; btn.textContent = loadingText; btn.disabled = true; }
    else { btn.textContent = btn.dataset.orig || btn.textContent; btn.disabled = false; }
  }
  function postJSON(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
  }

  // 登录 / 注册 / 令牌重置 通用提交
  var form = document.getElementById('auth-form');
  if (form) {
    var errBox = form.querySelector('.auth-error');
    var submitBtn = form.querySelector('.auth-submit');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      hideError(errBox);
      var body = {};
      if (MODE === 'login') {
        body = { username: form.querySelector('#auth-username').value, password: form.querySelector('#auth-password').value };
      } else if (MODE === 'register') {
        var p1 = form.querySelector('#reg-password').value, p2 = form.querySelector('#reg-confirm').value;
        if (p1 !== p2) { showError(errBox, '两次输入的密码不一致'); return; }
        body = {
          username: form.querySelector('#reg-username').value,
          email: form.querySelector('#reg-email').value,
          password: p1,
          code: form.querySelector('#reg-code') ? form.querySelector('#reg-code').value : '',
        };
      } else if (MODE === 'reset') {
        var r1 = form.querySelector('#reset-password').value, r2 = form.querySelector('#reset-confirm').value;
        if (r1 !== r2) { showError(errBox, '两次输入的密码不一致'); return; }
        body = { token: <?= json_encode($token) ?>, password: r1 };
      }
      setLoading(submitBtn, true, MODE === 'login' ? '登录中…' : '提交中…');
      postJSON(pafishApi('/auth/' + (MODE === 'reset' ? 'reset' : MODE)), body).then(function (res) {
        if (!res.ok) { showError(errBox, res.data.error || '操作失败'); return; }
        if (MODE === 'reset') {
          form.hidden = true;
          var done = document.getElementById('reset-done');
          if (done) done.hidden = false;
        } else {
          window.location.href = FROM;
        }
      }).catch(function () { showError(errBox, '网络错误，请重试'); })
        .finally(function () { setLoading(submitBtn, false); });
    });
  }

  // 发送验证码（注册 / 找回密码共用倒计时）
  var countdownTimer = null;
  function startCountdown(btn) {
    var left = 60;
    if (countdownTimer) clearInterval(countdownTimer);
    btn.disabled = true;
    countdownTimer = setInterval(function () {
      left--;
      if (left <= 0) { clearInterval(countdownTimer); countdownTimer = null; btn.textContent = btn.dataset.orig || '发送验证码'; btn.disabled = false; return; }
      btn.textContent = left + 's';
    }, 1000);
  }
  function sendCode(email, purpose, btn, errBox) {
    hideError(errBox);
    var re = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
    if (!re.test(email)) { showError(errBox, '请先输入正确的邮箱'); return Promise.reject(); }
    btn.dataset.orig = btn.textContent;
    return postJSON(pafishApi('/auth/send-code'), { email: email, purpose: purpose }).then(function (res) {
      if (!res.ok) { showError(errBox, res.data.error || '发送失败'); return false; }
      startCountdown(btn);
      return true;
    }).catch(function () { showError(errBox, '网络错误，请重试'); return false; });
  }

  var sendBtn = document.getElementById('send-code-btn');
  if (sendBtn) {
    sendBtn.addEventListener('click', function () {
      sendCode(document.getElementById('reg-email').value, 'register', sendBtn, document.querySelector('#auth-form .auth-error'));
    });
  }

  // 找回密码两步流程
  var sendForm = document.getElementById('forgot-send-form');
  if (sendForm) {
    var forgotEmail = null;
    sendForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var errBox = sendForm.querySelector('.auth-error');
      var btn = sendForm.querySelector('.auth-submit');
      var email = document.getElementById('forgot-email').value;
      hideError(errBox);
      setLoading(btn, true, '发送中…');
      postJSON(pafishApi('/auth/send-code'), { email: email, purpose: 'reset' }).then(function (res) {
        if (!res.ok) { showError(errBox, res.data.error || '发送失败'); return; }
        forgotEmail = email;
        sendForm.hidden = true;
        document.getElementById('forgot-reset-form').hidden = false;
        document.getElementById('forgot-email-show').textContent = email;
        var resend = document.getElementById('resend-code-btn');
        resend.dataset.orig = resend.textContent;
        startCountdown(resend);
      }).catch(function () { showError(errBox, '网络错误，请重试'); })
        .finally(function () { setLoading(btn, false); });
    });
  }

  var resetForm = document.getElementById('forgot-reset-form');
  if (resetForm) {
    resetForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var errBox = resetForm.querySelector('.auth-error');
      var btn = resetForm.querySelector('.auth-submit');
      var p1 = document.getElementById('forgot-password').value;
      var p2 = document.getElementById('forgot-confirm').value;
      if (p1 !== p2) { showError(errBox, '两次输入的密码不一致'); return; }
      hideError(errBox);
      setLoading(btn, true, '提交中…');
      postJSON(pafishApi('/auth/reset-by-code'), {
        email: forgotEmail || '',
        code: document.getElementById('forgot-code').value,
        newPassword: p1,
      }).then(function (res) {
        if (!res.ok) { showError(errBox, res.data.error || '重置失败'); return; }
        resetForm.hidden = true;
        document.getElementById('forgot-done').hidden = false;
      }).catch(function () { showError(errBox, '网络错误，请重试'); })
        .finally(function () { setLoading(btn, false); });
    });
  }

  var resendBtn = document.getElementById('resend-code-btn');
  if (resendBtn) {
    resendBtn.addEventListener('click', function () {
      if (forgotEmail) {
        sendCode(forgotEmail, 'reset', resendBtn, resetForm.querySelector('.auth-error'));
      }
    });
  }
})();
</script>
</body>
</html>
