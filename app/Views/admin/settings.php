<?php
/** 系统设置：按用途分组，切换保留输入，统一保存。 */
$all = $all ?? [];
$roleCapabilities = $roleCapabilities ?? [];

/** 布尔键：未存储时用默认值；返回是否勾选 */
$boolVal = static function (string $key, string $default) use ($all): bool {
    return ((string) ($all[$key] ?? $default)) === 'true';
};

/** 密码/文本键回显值 */
$textVal = static function (string $key, string $default = '') use ($all): string {
    return (string) ($all[$key] ?? $default);
};

// IP 黑名单：存储 JSON 数组，逗号分隔文本展示
$ipsText = '';
try {
    $ips = json_decode((string) ($all['blocked_ips'] ?? '[]'), true);
    if (is_array($ips)) {
        $ipsText = implode(', ', $ips);
    }
} catch (\Throwable) {
}
?>
<div class="admin-stack admin-system-settings-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">站点设置</h1>
    </div>
  </div>

  <form id="settingsForm" class="admin-settings-form admin-system-settings-form" method="post" action="<?= e(url_to('/admin/settings/save')) ?>" novalidate>
    <?= csrf_field() ?>

    <nav class="admin-settings-tabs" aria-label="设置分组">
      <a href="#settings-basic" data-settings-group="basic">基本设置</a>
      <a href="#settings-seo" data-settings-group="seo">SEO</a>
      <a href="#settings-content" data-settings-group="content">内容与评论</a>
      <a href="#settings-accounts" data-settings-group="accounts">账号与权限</a>
      <a href="#settings-media" data-settings-group="media">上传与媒体</a>
      <a href="#settings-mail" data-settings-group="mail">邮件服务</a>
      <a href="#settings-extensions" data-settings-group="extensions">扩展服务</a>
    </nav>
    <div class="admin-settings-panels">
    <section id="settings-basic" data-settings-panel="basic" aria-label="基本设置">
    <div class="card admin-form-card">
      <h2 class="admin-card-title">站点信息</h2>
      <div class="admin-form-grid">
        <div class="admin-field">
          <label class="label" for="setting-site_name">站点名称</label>
          <input class="input" type="text" id="setting-site_name" name="site_name" value="<?= e($textVal('site_name')) ?>" placeholder="纸鱼博客" maxlength="100">
        </div>
        <div class="admin-field">
          <label class="label" for="setting-site_subtitle">副标题</label>
          <input class="input" type="text" id="setting-site_subtitle" name="site_subtitle" value="<?= e($textVal('site_subtitle')) ?>" placeholder="记录技术、设计与生活" maxlength="200">
        </div>
        <div class="admin-field">
          <label class="label" for="setting-site_icp">ICP 备案号</label>
          <input class="input" type="text" id="setting-site_icp" name="site_icp" value="<?= e($textVal('site_icp')) ?>" placeholder="如：京ICP备xxxxxxxx号" maxlength="100">
        </div>

      </div>
    </div>
    <div class="card admin-form-card">
      <h2 class="admin-card-title">固定链接</h2>
      <div class="admin-field"><label class="label" for="setting-permalink_structure">文章地址结构</label>
        <select class="input" id="setting-permalink_structure" name="permalink_structure">
          <?php foreach (['/post/%postname%' => '/post/文章别名（兼容默认）', '/%postname%' => '/文章别名', '/%year%/%month%/%postname%' => '/年份/月/文章别名'] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $textVal('permalink_structure', '/post/%postname%') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="admin-field-hint">原有 /post/文章别名地址始终保留，切换后新链接会按所选结构生成。</p>
    </div>
    </section>
    <section id="settings-seo" data-settings-panel="seo" aria-label="SEO">
    <div class="card admin-form-card">
      <h2 class="admin-card-title">搜索引擎优化</h2>
      <div class="admin-form-grid">
        <div class="admin-field">
          <label class="label" for="setting-site_description">站点描述</label>
          <textarea class="input" id="setting-site_description" name="site_description" rows="3" maxlength="500" placeholder="用于首页与未单独设置摘要的页面"><?= e($textVal('site_description')) ?></textarea>
        </div>
        <div class="admin-field admin-field-narrow">
          <label class="label" for="setting-seo_title_order">浏览器标题顺序</label>
          <select class="input" id="setting-seo_title_order" name="seo_title_order">
            <option value="page_site" <?= $textVal('seo_title_order', 'page_site') === 'page_site' ? 'selected' : '' ?>>页面标题在前</option>
            <option value="site_page" <?= $textVal('seo_title_order', 'page_site') === 'site_page' ? 'selected' : '' ?>>站点名称在前</option>
          </select>
        </div>
        <div class="admin-field admin-field-narrow">
          <label class="label" for="setting-seo_title_separator">标题分隔符</label>
          <select class="input" id="setting-seo_title_separator" name="seo_title_separator">
            <?php foreach ([' · ' => '·', ' - ' => '短横线', ' | ' => '竖线'] as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= $textVal('seo_title_separator', ' · ') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    </section>
    <section id="settings-content" data-settings-panel="content" aria-label="内容与评论">
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
          <label class="label" for="setting-posts_per_page">每页文章数</label>
          <input class="input admin-input-w32" type="number" id="setting-posts_per_page" name="posts_per_page" value="<?= e($textVal('posts_per_page', '10')) ?>" min="1" max="50">
        </div>
        <div class="admin-field">
          <label class="label" for="setting-blocked_ips">IP 黑名单</label>
          <textarea class="input admin-input-block" id="setting-blocked_ips" name="blocked_ips" rows="3" placeholder="如：1.2.3.4, 5.6.7.8（逗号或换行分隔）"><?= e($ipsText) ?></textarea>
          <p class="admin-field-hint">拉黑后该 IP 无法再提交评论；也可在评论审核页按 IP 一键拉黑。</p>
        </div>
      </div>
    </div>
    <div class="card admin-form-card">
      <h2 class="admin-card-title">文章与内容</h2>
      <label class="admin-check-row">
        <input type="checkbox" name="md_allow_raw_html" value="true" <?= $boolVal('md_allow_raw_html', 'false') ? 'checked' : '' ?>>
        <span>允许文章中的原始 HTML</span>
      </label>
      <p class="admin-field-hint">开启后文章可直接运行 HTML 与脚本，请仅允许可信作者使用。</p>
    </div>
    </section>
    <section id="settings-accounts" data-settings-panel="accounts" aria-label="账号与权限">
    <div class="card admin-form-card">
      <h2 class="admin-card-title">账号与注册</h2>
      <label class="admin-check-row">
        <input type="checkbox" name="allow_registration" value="true" <?= $boolVal('allow_registration', 'true') ? 'checked' : '' ?>>
        <span>开放注册（关闭后注册页不可用）</span>
      </label>
      <label class="admin-check-row">
        <input type="checkbox" name="require_email_verify" value="true" <?= $boolVal('require_email_verify', 'true') ? 'checked' : '' ?>>
        <span>注册需邮箱验证码（需配置邮件服务）</span>
      </label>
    </div>
    <div class="card admin-form-card">
      <h2 class="admin-card-title">角色权限</h2>
      <div class="admin-field"><label class="label" for="setting-role_capabilities">角色能力 JSON</label>
        <textarea class="input admin-input-block" id="setting-role_capabilities" name="role_capabilities" rows="8" spellcheck="false"><?= e(json_encode($roleCapabilities, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></textarea>
        <p class="admin-field-hint">只填写系统提供的能力标识；ADMIN 永远拥有全部能力。错误 JSON 会被忽略并使用默认权限。</p>
      </div>
    </div>
    </section>
    <section id="settings-media" data-settings-panel="media" aria-label="上传与媒体">
    <div class="card admin-form-card">
      <h2 class="admin-card-title">上传与媒体库</h2>
      <div class="admin-form-grid">
        <div class="admin-field admin-field-narrow">
          <label class="label" for="setting-upload_max_mb">上传大小限制（MB）</label>
          <input class="input admin-input-w32" type="number" id="setting-upload_max_mb" name="upload_max_mb" value="<?= e($textVal('upload_max_mb', '20')) ?>" min="1" max="200">
        </div>
      </div>
      <p class="admin-field-hint">上传还受服务器请求大小限制。</p>
    </div>
    </section>
    <section id="settings-mail" data-settings-panel="mail" aria-label="邮件服务">
    <div class="card admin-form-card">
      <h2 class="admin-card-title">邮件服务（SMTP）</h2>
      <div class="admin-form-grid">
        <div class="admin-field">
          <label class="label" for="setting-smtp_host">SMTP 主机</label>
          <input class="input" type="text" id="setting-smtp_host" name="smtp_host" value="<?= e($textVal('smtp_host')) ?>" placeholder="smtp.example.com" maxlength="255">
        </div>
        <div class="admin-field admin-field-narrow">
          <label class="label" for="setting-smtp_port">端口</label>
          <input class="input admin-input-w40" type="number" id="setting-smtp_port" name="smtp_port" value="<?= e($textVal('smtp_port', '465')) ?>" placeholder="465（SSL）或 587（STARTTLS）" min="1" max="65535">
        </div>
        <div class="admin-field">
          <label class="label" for="setting-smtp_user">账号</label>
          <input class="input" type="text" id="setting-smtp_user" name="smtp_user" value="<?= e($textVal('smtp_user')) ?>" placeholder="noreply@example.com" maxlength="255">
        </div>
        <div class="admin-field">
          <label class="label" for="setting-smtp_pass">密码 / 授权码</label>
          <input class="input" type="password" id="setting-smtp_pass" name="smtp_pass" value="" placeholder="<?= $textVal('smtp_pass') !== '' ? '已设置，留空保留原密码' : '输入邮箱密码或授权码' ?>" autocomplete="new-password" maxlength="500">
        </div>
        <div class="admin-field">
          <label class="label" for="setting-smtp_from">发件人地址（可选，默认用账号）</label>
          <input class="input" type="email" id="setting-smtp_from" name="smtp_from" value="<?= e($textVal('smtp_from')) ?>" placeholder="noreply@example.com" maxlength="255">
        </div>
      </div>
      <p class="admin-field-hint">更换主机或账号时需重新输入密码。</p>
      <button type="button" id="testSmtpBtn" class="btn btn-outline">发送测试邮件</button>
      <p id="testSmtpMsg" role="status" class="admin-settings-msg" hidden></p>
    </div>
    <div class="card admin-form-card">
      <h2 class="admin-card-title">邮件通知（新评论提醒）</h2>
      <label class="admin-check-row">
        <input type="checkbox" name="notify_email_enabled" value="true" <?= $boolVal('notify_email_enabled', 'false') ? 'checked' : '' ?>>
        <span>启用邮件通知（需配置 SMTP）</span>
      </label>
      <div class="admin-form-grid">
        <div class="admin-field">
          <label class="label" for="setting-notify_email">接收通知的邮箱</label>
          <input class="input" type="email" id="setting-notify_email" name="notify_email" value="<?= e($textVal('notify_email')) ?>" placeholder="admin@example.com" maxlength="255">
        </div>
      </div>
    </div>
    </section>
    <section id="settings-extensions" data-settings-panel="extensions" aria-label="扩展服务">
    <div class="card admin-form-card">
      <h2 class="admin-card-title">应用商店</h2>
      <div class="admin-field">
        <label class="label" for="setting-store_account_token">官方商城账号令牌</label>
        <input class="input" type="password" id="setting-store_account_token" name="store_account_token" value="" placeholder="<?= $textVal('store_account_token') !== '' ? '已绑定，粘贴新令牌可替换' : '在官网账号设置中生成后粘贴' ?>" autocomplete="new-password" maxlength="500">
      </div>
    </div>
    <div class="card admin-form-card">
      <h2 class="admin-card-title">开放 API</h2>
      <label class="admin-check-row">
        <input type="checkbox" name="api_enabled" value="true" <?= $boolVal('api_enabled', 'false') ? 'checked' : '' ?>>
        <span>启用开放 API（JSON 接口，供第三方程序调用）</span>
      </label>
      <div class="admin-field">
        <label class="label" for="setting-api_key">API Key</label>
        <div class="admin-key-row">
          <input class="input admin-key-input" type="text" id="setting-api_key" name="api_key" value="<?= e($textVal('api_key')) ?>" placeholder="启用后自动生成" readonly>
          <button type="button" id="regenerateKeyBtn" class="btn btn-outline admin-key-btn">重新生成</button>
        </div>
        <p class="admin-field-hint">调用时在请求头携带 X-API-Key；Key 泄露后请立即重新生成（旧 Key 作废）。</p>
        <div class="admin-form-grid">
          <div class="admin-field admin-field-narrow"><label class="label" for="setting-api_rate_limit">每分钟请求上限</label><input class="input admin-input-w32" type="number" id="setting-api_rate_limit" name="api_rate_limit" value="<?= e($textVal('api_rate_limit', '120')) ?>" min="0" max="10000"></div>
          <div class="admin-field admin-field-narrow"><label class="label" for="setting-api_max_limit">单页最大条数</label><input class="input admin-input-w32" type="number" id="setting-api_max_limit" name="api_max_limit" value="<?= e($textVal('api_max_limit', '50')) ?>" min="1" max="200"></div>
          <div class="admin-field"><label class="label" for="setting-api_cors">CORS 来源</label><input class="input" type="text" id="setting-api_cors" name="api_cors" value="<?= e($textVal('api_cors', '')) ?>" placeholder="留空关闭；多个来源用逗号分隔"></div>
        </div>
        <pre class="admin-code-block">curl -H "X-API-Key: &lt;你的Key&gt;" https://你的域名/api/v1/posts
curl -H "X-API-Key: &lt;你的Key&gt;" https://你的域名/api/v1/posts/文章别名
curl -H "X-API-Key: &lt;你的Key&gt;" "https://你的域名/api/v1/comments?postId=1"
# 更多：/api/v1/categories、/api/v1/tags</pre>
      </div>
    </div>
    </section>
    </div>
    <div class="admin-settings-save">
      <p class="admin-muted" id="settingsSaveStatus" role="status"></p>
      <button type="submit" id="saveSettingsBtn" class="btn btn-primary">保存设置</button>
      <p class="admin-editor-error" id="settingsError" role="alert" hidden></p>
    </div>
  </form>
</div>

<script src="<?= e(url_to('/js/admin-settings.js')) ?>" defer></script>
