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

$listText = static function (string $key, string $separator = ', ') use ($all): string {
    try {
        $items = json_decode((string) ($all[$key] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        return is_array($items) ? implode($separator, array_filter($items, 'is_string')) : '';
    } catch (\Throwable) {
        return '';
    }
};
$ipsText = $listText('blocked_ips');
$emailsText = $listText('blocked_emails');
$keywordsText = $listText('comment_spam_keywords', "\n");
?>
<div class="admin-stack admin-system-settings-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e(t('admin.site_settings')) ?></h1>
    </div>
  </div>

  <form id="settingsForm" class="admin-settings-form admin-system-settings-form" method="post" action="<?= e(url_to('/admin/settings/save')) ?>" novalidate>
    <?= csrf_field() ?>

    <nav class="admin-settings-tabs" aria-label="<?= e(t('admin.settings_groups')) ?>">
      <a href="#settings-basic" data-settings-group="basic"><?= e(t('admin.general_b68228')) ?></a>
      <a href="#settings-seo" data-settings-group="seo">SEO</a>
      <a href="#settings-content" data-settings-group="content"><?= e(t('admin.content_and_comments')) ?></a>
      <a href="#settings-media" data-settings-group="media"><?= e(t('admin.uploads_and_media')) ?></a>
      <a href="#settings-mail" data-settings-group="mail"><?= e(t('admin.email_519470')) ?></a>
      <a href="#settings-extensions" data-settings-group="extensions"><?= e(t('admin.extension_services')) ?></a>
    </nav>
    <div class="admin-settings-panels">
    <section id="settings-basic" data-settings-panel="basic" aria-label="<?= e(t('admin.general_b68228')) ?>">
    <div class="card admin-form-card">
      <h2 class="admin-card-title"><?= e(t('admin.site_information')) ?></h2>
      <div class="admin-form-grid">
        <div class="admin-field">
          <label class="label" for="setting-site_locale"><?= e(t('admin.system_language')) ?></label>
          <select class="input" id="setting-site_locale" name="site_locale">
            <?php foreach (\Pafish\Core\I18n::locales() as $locale => $name): ?>
              <option value="<?= e($locale) ?>" <?= $textVal('site_locale', 'zh-CN') === $locale ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="admin-field-hint"><?= e(t('admin.saving_refreshes_the_admin_interface_in_the_selected')) ?></p>
        </div>
        <div class="admin-field">
          <label class="label" for="setting-site_name"><?= e(t('admin.site_name_e72ec8')) ?></label>
          <input class="input" type="text" id="setting-site_name" name="site_name" value="<?= e($textVal('site_name')) ?>" placeholder="纸鱼博客" maxlength="100">
        </div>
        <div class="admin-field">
          <label class="label" for="setting-site_subtitle"><?= e(t('admin.subtitle')) ?></label>
          <input class="input" type="text" id="setting-site_subtitle" name="site_subtitle" value="<?= e($textVal('site_subtitle')) ?>" placeholder="<?= e(t('admin.notes_on_technology_design_and_life')) ?>" maxlength="200">
        </div>
        <div class="admin-field">
          <label class="label" for="setting-site_icp"><?= e(t('admin.icp_registration_number')) ?></label>
          <input class="input" type="text" id="setting-site_icp" name="site_icp" value="<?= e($textVal('site_icp')) ?>" placeholder="<?= e(t('admin.for_example_icp_registration_number')) ?>" maxlength="100">
        </div>

      </div>
    </div>
    <div class="card admin-form-card">
      <h2 class="admin-card-title"><?= e(t('admin.permalinks')) ?></h2>
      <?php foreach ([
          'post' => ['key' => 'permalink_structure', 'title' => t('admin.post_url_structure')],
          'page' => ['key' => 'page_permalink_structure', 'title' => t('admin.page_url_structure')],
          'category' => ['key' => 'category_permalink_structure', 'title' => t('admin.category_url_structure')],
          'tag' => ['key' => 'tag_permalink_structure', 'title' => t('admin.tag_url_structure')],
      ] as $type => $setting): ?>
        <?php $options = \Pafish\Services\Permalinks::options($type); $default = array_key_first($options); ?>
        <div class="admin-field">
          <label class="label" for="setting-<?= e($setting['key']) ?>"><?= e($setting['title']) ?></label>
          <select class="input" id="setting-<?= e($setting['key']) ?>" name="<?= e($setting['key']) ?>">
            <?php foreach ($options as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= $textVal($setting['key'], (string) $default) === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>
      <p class="admin-field-hint"><?= e(t('admin.old_url_formats_remain_accessible_and_redirect_to')) ?></p>
    </div>
    <div class="card admin-form-card">
      <h2 class="admin-card-title"><?= e(t('admin.theme_demo')) ?></h2>
      <input type="hidden" name="theme_demo_enabled" value="false">
      <label class="admin-check-row">
        <input type="checkbox" name="theme_demo_enabled" value="true" <?= $boolVal('theme_demo_enabled', 'false') ? 'checked' : '' ?>>
        <span><?= e(t('admin.enable_theme_demo')) ?></span>
      </label>
      <p class="admin-field-hint"><?= e(t('admin.theme_demo_hint')) ?></p>
    </div>
    <div class="card admin-form-card">
      <h2 class="admin-card-title"><?= e(t('admin.accounts_and_registration')) ?></h2>
      <label class="admin-check-row">
        <input type="checkbox" name="allow_registration" value="true" <?= $boolVal('allow_registration', 'true') ? 'checked' : '' ?>>
        <span><?= e(t('admin.allow_registration_the_registration_page_is_unavailable_when')) ?></span>
      </label>
      <label class="admin-check-row">
        <input type="checkbox" name="require_email_verify" value="true" <?= $boolVal('require_email_verify', 'true') ? 'checked' : '' ?>>
        <span><?= e(t('admin.require_an_email_verification_code_for_registration_email')) ?></span>
      </label>
    </div>
    </section>
    <section id="settings-seo" data-settings-panel="seo" aria-label="SEO">
    <div class="card admin-form-card">
      <h2 class="admin-card-title"><?= e(t('admin.search_engine_optimization')) ?></h2>
      <div class="admin-form-grid">
        <div class="admin-field">
          <label class="label" for="setting-site_description"><?= e(t('admin.site_description')) ?></label>
          <textarea class="input" id="setting-site_description" name="site_description" rows="3" maxlength="500" placeholder="<?= e(t('admin.used_for_the_homepage_and_pages_without_their')) ?>"><?= e($textVal('site_description')) ?></textarea>
        </div>
        <div class="admin-field admin-field-narrow">
          <label class="label" for="setting-seo_title_order"><?= e(t('admin.browser_title_order')) ?></label>
          <select class="input" id="setting-seo_title_order" name="seo_title_order">
            <option value="page_site" <?= $textVal('seo_title_order', 'page_site') === 'page_site' ? 'selected' : '' ?>><?= e(t('admin.page_title_first')) ?></option>
            <option value="site_page" <?= $textVal('seo_title_order', 'page_site') === 'site_page' ? 'selected' : '' ?>><?= e(t('admin.site_name_first')) ?></option>
          </select>
        </div>
        <div class="admin-field admin-field-narrow">
          <label class="label" for="setting-seo_title_separator"><?= e(t('admin.title_separator')) ?></label>
          <select class="input" id="setting-seo_title_separator" name="seo_title_separator">
            <?php foreach ([' · ' => '·', ' - ' => t('admin.hyphen'), ' | ' => t('admin.vertical_bar')] as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= $textVal('seo_title_separator', ' · ') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    </section>
    <section id="settings-content" data-settings-panel="content" aria-label="<?= e(t('admin.content_and_comments')) ?>">
    <div class="card admin-form-card">
      <h2 class="admin-card-title"><?= e(t('admin.comments_and_lists')) ?></h2>
      <label class="admin-check-row">
        <input type="checkbox" name="comments_enabled" value="true" <?= $boolVal('comments_enabled', 'true') ? 'checked' : '' ?>>
        <span><?= e(t('admin.enable_comments')) ?></span>
      </label>
      <label class="admin-check-row">
        <input type="checkbox" name="comments_need_review" value="true" <?= $boolVal('comments_need_review', 'true') ? 'checked' : '' ?>>
        <span><?= e(t('admin.require_review_before_displaying_new_comments')) ?></span>
      </label>
      <label class="admin-check-row">
        <input type="checkbox" name="comments_captcha_enabled" value="true" <?= $boolVal('comments_captcha_enabled', 'true') ? 'checked' : '' ?>>
        <span><?= e(t('admin.require_an_image_captcha_for_guest_comments_signed')) ?></span>
      </label>
      <label class="admin-check-row">
        <input type="checkbox" name="comments_honeypot_enabled" value="true" <?= $boolVal('comments_honeypot_enabled', 'true') ? 'checked' : '' ?>>
        <span><?= e(t('admin.enable_the_invisible_spam_honeypot_recommended')) ?></span>
      </label>
      <div class="admin-form-grid">
        <div class="admin-field admin-field-narrow">
          <label class="label" for="setting-posts_per_page"><?= e(t('admin.posts_per_page')) ?></label>
          <input class="input admin-input-w32" type="number" id="setting-posts_per_page" name="posts_per_page" value="<?= e($textVal('posts_per_page', '10')) ?>" min="1" max="50">
        </div>
        <div class="admin-field">
          <label class="label" for="setting-blocked_ips"><?= e(t('admin.blocked_ip_addresses')) ?></label>
          <textarea class="input admin-input-block" id="setting-blocked_ips" name="blocked_ips" rows="3" placeholder="<?= e(t('admin.for_example_1_2_3_4_5_6')) ?>"><?= e($ipsText) ?></textarea>
          <p class="admin-field-hint"><?= e(t('admin.comments_from_blocked_ip_addresses_are_rejected_you')) ?></p>
        </div>
        <div class="admin-field">
          <label class="label" for="setting-blocked_emails"><?= e(t('admin.blocked_email_addresses')) ?></label>
          <textarea class="input admin-input-block" id="setting-blocked_emails" name="blocked_emails" rows="3" placeholder="visitor@example.com, @example.net"><?= e($emailsText) ?></textarea>
          <p class="admin-field-hint"><?= e(t('admin.enter_a_complete_email_address_or_a_domain_a1ece7')) ?></p>
        </div>
        <div class="admin-field">
          <label class="label" for="setting-comment_spam_keywords"><?= e(t('admin.comment_spam_keywords')) ?></label>
          <textarea class="input admin-input-block" id="setting-comment_spam_keywords" name="comment_spam_keywords" rows="4" placeholder="<?= e(t('admin.one_keyword_per_line')) ?>"><?= e($keywordsText) ?></textarea>
          <p class="admin-field-hint"><?= e(t('admin.guest_comments_containing_a_keyword_are_marked_as')) ?></p>
        </div>
      </div>
    </div>
    <div class="card admin-form-card">
      <h2 class="admin-card-title"><?= e(t('admin.posts_and_content')) ?></h2>
      <input type="hidden" name="submissions_enabled" value="false">
      <label class="admin-check-row">
        <input type="checkbox" name="submissions_enabled" value="true" <?= $boolVal('submissions_enabled', 'false') ? 'checked' : '' ?>>
        <span><?= e(t('admin.allow_user_submissions')) ?></span>
      </label>
      <input type="hidden" name="submissions_need_review" value="false">
      <label class="admin-check-row">
        <input type="checkbox" name="submissions_need_review" value="true" <?= $boolVal('submissions_need_review', 'true') ? 'checked' : '' ?>>
        <span><?= e(t('admin.require_review_before_publishing_user_submissions')) ?></span>
      </label>
      <p class="admin-field-hint"><?= e(t('admin.when_review_is_disabled_new_and_updated_user')) ?></p>
    </div>
    </section>
    <section id="settings-media" data-settings-panel="media" aria-label="<?= e(t('admin.uploads_and_media')) ?>">
    <div class="card admin-form-card">
      <h2 class="admin-card-title"><?= e(t('admin.uploads_and_media_library')) ?></h2>
      <div class="admin-form-grid">
        <div class="admin-field admin-field-narrow">
          <label class="label" for="setting-upload_max_mb"><?= e(t('admin.maximum_upload_size_mb')) ?></label>
          <input class="input admin-input-w32" type="number" id="setting-upload_max_mb" name="upload_max_mb" value="<?= e($textVal('upload_max_mb', '20')) ?>" min="1" max="200">
        </div>
      </div>
      <p class="admin-field-hint"><?= e(t('admin.server_request_size_limits_also_apply_to_uploads')) ?></p>
    </div>
    </section>
    <section id="settings-mail" data-settings-panel="mail" aria-label="<?= e(t('admin.email_519470')) ?>">
    <div class="card admin-form-card">
      <h2 class="admin-card-title"><?= e(t('admin.email_smtp')) ?></h2>
      <div class="admin-form-grid">
        <div class="admin-field">
          <label class="label" for="setting-smtp_host"><?= e(t('admin.smtp_host')) ?></label>
          <input class="input" type="text" id="setting-smtp_host" name="smtp_host" value="<?= e($textVal('smtp_host')) ?>" placeholder="smtp.example.com" maxlength="255">
        </div>
        <div class="admin-field admin-field-narrow">
          <label class="label" for="setting-smtp_port"><?= e(t('admin.port')) ?></label>
          <input class="input admin-input-w40" type="number" id="setting-smtp_port" name="smtp_port" value="<?= e($textVal('smtp_port', '465')) ?>" placeholder="<?= e(t('admin.465_ssl_or_587_starttls')) ?>" min="1" max="65535">
        </div>
        <div class="admin-field">
          <label class="label" for="setting-smtp_user"><?= e(t('admin.account_901384')) ?></label>
          <input class="input" type="text" id="setting-smtp_user" name="smtp_user" value="<?= e($textVal('smtp_user')) ?>" placeholder="noreply@example.com" maxlength="255">
        </div>
        <div class="admin-field">
          <label class="label" for="setting-smtp_pass"><?= e(t('admin.password_app_password')) ?></label>
          <input class="input" type="password" id="setting-smtp_pass" name="smtp_pass" value="" placeholder="<?= $textVal('smtp_pass') !== '' ? t('admin.configured_leave_blank_to_keep_the_current_password') : t('admin.enter_your_email_password_or_app_password') ?>" autocomplete="new-password" maxlength="500">
        </div>
        <div class="admin-field">
          <label class="label" for="setting-smtp_from"><?= e(t('admin.sender_address_optional_defaults_to_the_account_address')) ?></label>
          <input class="input" type="email" id="setting-smtp_from" name="smtp_from" value="<?= e($textVal('smtp_from')) ?>" placeholder="noreply@example.com" maxlength="255">
        </div>
      </div>
      <p class="admin-field-hint"><?= e(t('admin.re_enter_the_password_when_changing_the_host')) ?></p>
      <button type="button" id="testSmtpBtn" class="btn btn-outline"><?= e(t('admin.send_test_email')) ?></button>
      <p id="testSmtpMsg" role="status" class="admin-settings-msg" hidden></p>
    </div>
    <div class="card admin-form-card">
      <h2 class="admin-card-title"><?= e(t('admin.email_notifications_new_comments')) ?></h2>
      <label class="admin-check-row">
        <input type="checkbox" name="notify_email_enabled" value="true" <?= $boolVal('notify_email_enabled', 'false') ? 'checked' : '' ?>>
        <span><?= e(t('admin.enable_email_notifications_smtp_required')) ?></span>
      </label>
      <div class="admin-form-grid">
        <div class="admin-field">
          <label class="label" for="setting-notify_email"><?= e(t('admin.notification_email')) ?></label>
          <input class="input" type="email" id="setting-notify_email" name="notify_email" value="<?= e($textVal('notify_email')) ?>" placeholder="admin@example.com" maxlength="255">
        </div>
      </div>
    </div>
    </section>
    <section id="settings-extensions" data-settings-panel="extensions" aria-label="<?= e(t('admin.extension_services')) ?>">
    <div class="card admin-form-card">
      <h2 class="admin-card-title"><?= e(t('admin.app_store')) ?></h2>
      <div class="admin-field">
        <label class="label" for="setting-store_account_token"><?= e(t('admin.official_store_account_token')) ?></label>
        <input class="input" type="password" id="setting-store_account_token" name="store_account_token" value="" placeholder="<?= $textVal('store_account_token') !== '' ? t('admin.linked_paste_a_new_token_to_replace_it') : t('admin.generate_a_token_in_your_official_account_settings') ?>" autocomplete="new-password" maxlength="500">
      </div>
    </div>
    <div class="card admin-form-card">
      <h2 class="admin-card-title"><?= e(t('admin.public_api')) ?></h2>
      <label class="admin-check-row">
        <input type="checkbox" name="api_enabled" value="true" <?= $boolVal('api_enabled', 'false') ? 'checked' : '' ?>>
        <span><?= e(t('admin.enable_the_public_api_json_endpoints_for_third')) ?></span>
      </label>
      <div class="admin-field">
        <label class="label" for="setting-api_key">API Key</label>
        <div class="admin-key-row">
          <input class="input admin-key-input" type="text" id="setting-api_key" name="api_key" value="<?= e($textVal('api_key')) ?>" placeholder="<?= e(t('admin.generated_automatically_when_enabled')) ?>" readonly>
          <button type="button" id="regenerateKeyBtn" class="btn btn-outline admin-key-btn"><?= e(t('admin.regenerate')) ?></button>
        </div>
        <p class="admin-field-hint"><?= e(t('admin.send_x_api_key_in_the_request_headers')) ?></p>
        <div class="admin-form-grid">
          <div class="admin-field admin-field-narrow"><label class="label" for="setting-api_rate_limit"><?= e(t('admin.requests_per_minute')) ?></label><input class="input admin-input-w32" type="number" id="setting-api_rate_limit" name="api_rate_limit" value="<?= e($textVal('api_rate_limit', '120')) ?>" min="0" max="10000"></div>
          <div class="admin-field admin-field-narrow"><label class="label" for="setting-api_max_limit"><?= e(t('admin.maximum_items_per_page')) ?></label><input class="input admin-input-w32" type="number" id="setting-api_max_limit" name="api_max_limit" value="<?= e($textVal('api_max_limit', '50')) ?>" min="1" max="200"></div>
          <div class="admin-field"><label class="label" for="setting-api_cors"><?= e(t('admin.cors_origins')) ?></label><input class="input" type="text" id="setting-api_cors" name="api_cors" value="<?= e($textVal('api_cors', '')) ?>" placeholder="<?= e(t('admin.leave_blank_to_disable_separate_multiple_origins_with')) ?>"></div>
        </div>
        <pre class="admin-code-block">curl -H "X-API-Key: &lt;<?= e(t('admin.your_key')) ?>&gt;<?= e(t('admin.https_your_domain_api_v1_posts_curl_h')) ?> &lt;<?= e(t('admin.your_key')) ?>&gt;<?= e(t('admin.https_your_domain_api_v1_posts_post_slug')) ?> &lt;<?= e(t('admin.your_key')) ?>&gt;<?= e(t('admin.https_your_domain_api_v1_comments_postid_1')) ?></pre>
      </div>
    </div>
    </section>
    </div>
    <div class="admin-settings-save">
      <p class="admin-muted" id="settingsSaveStatus" role="status"></p>
      <button type="submit" id="saveSettingsBtn" class="btn btn-primary"><?= e(t('admin.save_settings')) ?></button>
      <p class="admin-editor-error" id="settingsError" role="alert" hidden></p>
    </div>
  </form>
</div>

<script src="<?= e(asset_url('/js/admin-settings.js')) ?>" defer></script>
