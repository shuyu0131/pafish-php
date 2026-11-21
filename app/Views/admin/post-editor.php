<?php
/**
 * 文章编辑器：
 * 正文为主，发布设置分组；保存操作在桌面头部、手机正文之后。
 * 变量：$post $isEdit $postId $catTree $tags $tagIds $customFields $hasPassword $isScheduled $statusLabel
 *       $coverAuto（这篇是否开启自动取图兜底）$autoCoverUrl（自动取到的图，只作预览）
 */
$submissionMode = (bool) ($submissionMode ?? false);
$editorMediaEnabled = !$submissionMode || !empty($submissionCanSubmit);
$extensionMetaFields = $submissionMode ? [] : \Pafish\Services\EntityMeta::editorFields('post', (int) ($post['id'] ?? 0));
$hasExtensionMeta = $extensionMetaFields !== [];
$legacyCustomFieldsVisible = $customFields !== [] || !$hasExtensionMeta;
$template = (string) ($post['template'] ?? 'default');
$templateUnavailable = !array_key_exists($template, $templateOptions);
$editorData = [
    'initial' => [
        'title' => (string) $post['title'],
        'slug' => (string) $post['slug'],
        'excerpt' => (string) $post['excerpt'],
        'content' => (string) $post['content'],
        'contentFormat' => (string) ($post['content_format'] ?? 'markdown'),
        'template' => $template,
        'coverUrl' => (string) ($post['cover_url'] ?? ''),
        'coverAuto' => (bool) ($coverAuto ?? false),
        'autoCoverUrl' => (string) ($autoCoverUrl ?? ''),
        'categoryId' => $post['category_id'] ? (string) $post['category_id'] : '',
        'tagIds' => $tagIds,
        'isPinned' => (bool) $post['is_pinned'],
        'categoryPinned' => (bool) $post['category_pinned'],
        'externalUrl' => (string) ($post['external_url'] ?? ''),
        'allowComments' => (bool) ($post['allow_comments'] ?? true),
        'seoTitle' => (string) ($post['seo_title'] ?? ''),
        'metaDescription' => (string) ($post['meta_description'] ?? ''),
        'customFields' => $customFields !== [] ? $customFields : [['key' => '', 'value' => '']],
        'extensionMeta' => array_reduce($extensionMetaFields, static function (array $values, array $field): array {
            $values[$field['scope']][$field['key']] = (string) ($field['value'] ?? '');
            return $values;
        }, []),
    ],
    'isEdit' => $isEdit,
    'postId' => $postId,
    'isScheduled' => $isScheduled,
    'hasPassword' => $hasPassword,
    'submissionMode' => $submissionMode,
    'emptyContentAllowed' => $submissionMode && !empty($submissionHasExtensionContent),
    'autosaveEnabled' => !$submissionMode,
    'allowNewCategories' => !$submissionMode && \Pafish\Core\Auth::isAdmin(),
    'allowNewTags' => !$submissionMode && \Pafish\Core\Auth::isAdmin(),
    'saveUrl' => url_to($submissionMode ? '/admin/submissions/save' : ($isEdit ? '/admin/posts/' . $postId . '/save' : '/admin/posts/save')),
    'editUrl' => url_to($submissionMode ? '/admin/submissions/{id}/edit' : '/admin/posts/{id}/edit'),
    'listUrl' => url_to($submissionMode ? '/admin?section=posts' : '/admin/posts'),
    'previewUrl' => url_to('/api/md-preview'),
    'uploadUrl' => $editorMediaEnabled ? url_to('/api/upload') : '',
    'uploadsUrl' => $editorMediaEnabled ? url_to('/api/uploads') : '',
    'assetBase' => rtrim(\Pafish\Core\Url::base(), '/') . '/public',
    'scheduledAt' => ($post['scheduled_at'] ?? '') ? date('Y-m-d\TH:i', strtotime((string) $post['scheduled_at'])) : '',
    'categories' => array_map(
        fn ($c) => ['id' => (string) $c['id'], 'name' => (string) $c['name'], 'depth' => (int) $c['depth']],
        $catTree
    ),
    'tags' => array_map(fn ($t) => ['id' => (string) $t['id'], 'name' => (string) $t['name']], $tags),
    'hiddenCustomFieldKeys' => $hiddenCustomFieldKeys ?? [],
];
?>
<div class="admin-editor admin-editor-workspace">
  <?php if ($submissionMode && $isEdit): ?>
  <div class="admin-submission-status-note"><span class="badge<?= $post['status'] === 'PUBLISHED' ? ' badge-success' : ($post['status'] === 'CHANGES_REQUESTED' ? ' badge-warning' : '') ?>"><?= e($submissionStatusLabel ?? $post['status']) ?></span>
    <?php if ($post['status'] === 'PENDING'): ?><span><?= e(t('admin.submitted_for_review_this_content_stays_private_until')) ?></span>
    <?php elseif ($post['status'] === 'PUBLISHED' && !empty($submissionReviewRequired)): ?><span><?= e(t('admin.resubmitting_temporarily_hides_the_post_from_public_pages')) ?></span><?php endif; ?>
  </div>
  <?php if ($post['status'] === 'CHANGES_REQUESTED' && trim((string) ($post['review_note'] ?? '')) !== ''): ?>
    <section class="admin-submission-feedback" aria-labelledby="submission-feedback-title"><h2 id="submission-feedback-title"><?= e(t('admin.review_feedback')) ?></h2><p><?= e($post['review_note']) ?></p><span class="admin-muted"><?= e(t('admin.address_the_feedback_and_resubmit')) ?></span></section>
  <?php endif; ?>
  <?php endif; ?>

  <form id="postForm" method="post" action="<?= e(url_to($submissionMode ? '/admin/submissions/save' : ($isEdit ? '/admin/posts/' . $postId . '/save' : '/admin/posts/save'))) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="_idempotency" value="<?= e(\Pafish\Core\Session::requestToken()) ?>">
    <?php if ($submissionMode): ?><input type="hidden" name="post_id" value="<?= $postId ?>"><?php endif; ?>
    <input type="hidden" name="action" id="fAction" value="draft">
    <input type="hidden" name="slug_auto" id="fSlugAuto" value="<?= !$isEdit && !$submissionMode ? '1' : '0' ?>">

    <header class="admin-editor-header">
      <div class="admin-editor-heading">
        <h1 class="admin-h1"><?= $submissionMode ? ($isEdit ? t('admin.edit_submission') : t('admin.write_a_submission')) : ($isEdit ? t('admin.edit_post') : t('admin.new_post')) ?></h1>
        <?php if ($isEdit && !$submissionMode): ?><p class="admin-autosave" data-autosave role="status"><?= e(t('admin.autosave_every_60_seconds')) ?></p><?php endif; ?>
      </div>
      <div class="admin-editor-header-actions">
        <button type="submit" class="btn btn-outline" data-save="draft"><?= e(t('admin.save_draft')) ?></button>
        <?php if (!$submissionMode || !empty($submissionCanSubmit)): ?>
          <button type="submit" class="btn btn-primary admin-publish-primary" data-save="publish"><?= $submissionMode ? (!empty($submissionReviewRequired) ? t('admin.submit_for_review') : ($isEdit && $post['status'] === 'PUBLISHED' ? t('admin.save_and_publish') : t('admin.publish_post'))) : ($isEdit && $post['status'] === 'PUBLISHED' ? t('admin.save_changes') : t('admin.publish_now')) ?></button>
        <?php else: ?>
          <span class="admin-muted"><?= e(t('admin.submissions_are_closed_you_can_save_a_draft')) ?></span>
        <?php endif; ?>
      </div>
    </header>
    <div class="admin-editor-error" role="alert" hidden></div>
    <div class="admin-editor-grid">
      <div class="admin-editor-main">
        <div class="admin-editor-writing">
        <div class="admin-editor-title-wrap">
          <input class="admin-editor-title" type="text" id="fTitle" name="title" aria-label="<?= e(t('admin.post_title')) ?>"
                 value="<?= e($post['title']) ?>" placeholder="<?= e(t('admin.post_title')) ?>" maxlength="255">
        </div>

        <?php $editorType = 'post'; require __DIR__ . '/partials/editor-controls.php'; ?>
        <!-- 正文编辑器 -->
        <div class="admin-md-editor">
          <div id="markdownMount"></div>
          <input type="hidden" id="fContentFormat" name="content_format" data-editor-new="<?= $isEdit ? '0' : '1' ?>" value="<?= e($post['content_format'] ?? 'markdown') ?>">
          <textarea id="fContent" name="content" hidden
                    maxlength="16000000"><?= e($post['content']) ?></textarea>
        </div>
        <div class="admin-editor-extra">
          <div class="admin-field admin-editor-excerpt-field">
            <label class="label" for="fExcerpt"><?= e(t('admin.excerpt')) ?></label>
            <textarea class="input admin-excerpt" id="fExcerpt" name="excerpt"
                      placeholder="<?= e(t('admin.shown_on_listing_pages_leave_blank_to_generate')) ?>" maxlength="500"><?= e($post['excerpt']) ?></textarea>
          </div>

        </div>
        </div>

        <details class="card admin-advanced admin-editor-fields" id="customFieldsDetails"<?= $submissionMode || !$legacyCustomFieldsVisible ? ' hidden' : '' ?>>
          <summary><?= admin_icon('chevron-right', 14) ?><span id="customFieldsTitle"><?= $hasExtensionMeta ? t('admin.legacy_custom_fields') : t('admin.custom_fields') ?></span></summary>
          <section class="admin-advanced-body admin-custom-fields-field" aria-labelledby="customFieldsTitle">
            <div class="admin-editor-extra-head">
              <button type="button" class="btn btn-ghost admin-add-field" id="btnAddField"><?= admin_icon('plus', 14) ?><?= e(t('admin.add_field')) ?></button>
            </div>
            <div class="admin-custom-fields" id="customFields"></div>
            <datalist id="postCustomFieldKeys"></datalist>
          </section>
        </details>
        <?php if ($extensionMetaFields !== []): ?>
        <details class="card admin-advanced admin-editor-fields" id="extensionMetaDetails">
          <summary><?= admin_icon('chevron-right', 14) ?><span><?= e(t('admin.theme_custom_fields')) ?></span></summary>
          <section class="admin-advanced-body admin-extension-meta-fields">
            <?php foreach ($extensionMetaFields as $index => $field): ?>
              <?php $fieldId = 'extension-meta-' . $index; $value = (string) ($field['value'] ?? ''); ?>
              <div class="admin-field">
                <label class="label" for="<?= e($fieldId) ?>"><?= e($field['label']) ?> <span class="admin-field-hint">· <?= e($field['owner']) ?></span></label>
                <?php if ($field['type'] === 'textarea'): ?>
                  <textarea class="input" id="<?= e($fieldId) ?>" data-extension-meta data-meta-scope="<?= e($field['scope']) ?>" data-meta-key="<?= e($field['key']) ?>" maxlength="<?= (int) ($field['maxLength'] ?? 10000) ?>" <?= !empty($field['required']) ? 'required' : '' ?>><?= e($value) ?></textarea>
                <?php elseif (in_array($field['type'], ['select', 'checkbox'], true)): ?>
                  <select class="input" id="<?= e($fieldId) ?>" data-extension-meta data-meta-scope="<?= e($field['scope']) ?>" data-meta-key="<?= e($field['key']) ?>">
                    <?php if ($field['type'] === 'checkbox'): ?>
                      <option value="0" <?= $value === '0' ? 'selected' : '' ?>><?= e(t('common.close')) ?></option>
                      <option value="1" <?= $value === '1' ? 'selected' : '' ?>><?= e(t('admin.enable')) ?></option>
                    <?php else: ?>
                      <?php foreach ((array) $field['options'] as $option => $label): ?><option value="<?= e((string) $option) ?>" <?= $value === (string) $option ? 'selected' : '' ?>><?= e((string) $label) ?></option><?php endforeach; ?>
                    <?php endif; ?>
                  </select>
                <?php else: ?>
                  <input class="input" type="<?= $field['type'] === 'number' ? 'number' : 'text' ?>" id="<?= e($fieldId) ?>" data-extension-meta data-meta-scope="<?= e($field['scope']) ?>" data-meta-key="<?= e($field['key']) ?>" value="<?= e($value) ?>" maxlength="<?= (int) ($field['maxLength'] ?? 10000) ?>" <?= !empty($field['required']) ? 'required' : '' ?>>
                <?php endif; ?>
                <?php if (!empty($field['desc'])): ?><p class="admin-field-hint"><?= e((string) $field['desc']) ?></p><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </section>
        </details>
        <?php endif; ?>
      </div>

      <!-- 右列 -->
      <div class="admin-editor-side">
        <!-- 设置 -->
        <div class="card admin-form-card admin-settings-card">
          <div class="admin-editor-card-heading">
            <strong><?= e(t('admin.publishing_settings')) ?></strong>
          </div>
          <div class="admin-field">
            <label class="label" for="catTrigger"><?= e(t('common.category')) ?></label>
            <div class="admin-cat-select" id="catSelect"></div>
            <div class="admin-field-hint" data-new-cat-wrap hidden>
              <input class="input" id="fNewCategory" name="new_category" placeholder="<?= e(t('admin.enter_a_new_category_name_created_when_saving')) ?>" maxlength="100">
            </div>
          </div>

          <div class="admin-field">
            <label class="label" for="fTagInput"><?= e(t('common.tags')) ?></label>
            <div class="admin-tags-selected" id="tagsSelected"></div>
            <input class="input" id="fTagInput" placeholder="<?= $submissionMode ? t('admin.enter_an_existing_tag_name_and_press_enter') : t('admin.enter_a_tag_name_press_enter_or_comma') ?>" maxlength="100">
            <div class="admin-tags-all" id="tagsAll"></div>
          </div>

          <?php if (count($templateOptions) > 1 || $templateUnavailable): ?>
          <div class="admin-field">
            <label class="label" for="fTemplate"><?= e(t('admin.post_template')) ?></label>
            <select class="input" id="fTemplate" name="template">
              <?php if ($templateUnavailable): ?>
              <option value="<?= e($template) ?>" selected><?= e($template) ?><?= e(t('admin.unavailable')) ?></option>
              <?php endif; ?>
              <?php foreach ($templateOptions as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $template === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($templateUnavailable): ?>
            <span class="admin-field-hint"><?= e(t('admin.the_original_template_is_unavailable_the_category_or')) ?></span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <input type="hidden" id="fTemplate" name="template" value="<?= e($template) ?>">
          <?php endif; ?>

          <?php if ($submissionMode): ?>
          <label class="admin-check">
            <input type="checkbox" id="fAllowComments" name="allow_comments" value="1" <?= ($post['allow_comments'] ?? 1) ? 'checked' : '' ?>>
            <span><?= e(t('admin.allow_comments')) ?></span>
          </label>
          <?php endif; ?>

        </div>

        <div class="card admin-form-card admin-editor-cover-card">
          <h2 class="admin-card-title"><?= e(t('admin.cover_image')) ?></h2>
          <button type="button" class="admin-cover-picker" id="btnCoverPicker"<?= $editorMediaEnabled ? '' : ' disabled' ?> data-auto-cover="<?= e($autoCoverUrl ?? '') ?>" aria-label="<?= e(t('admin.choose_or_replace_cover')) ?>">
            <span class="admin-cover-empty" data-cover-empty><?= admin_icon('image', 24) ?><span><?= e(t('admin.choose_cover')) ?></span></span>
            <span class="admin-cover-preview" data-cover-preview hidden><img alt="封面预览"></span>
          </button>
          <div class="admin-cover-actions">
            <details class="admin-cover-address">
              <summary><?= e(t('admin.use_image_url')) ?></summary>
              <label for="fCoverUrl"><?= e(t('admin.cover_image_url')) ?></label>
              <input class="input" id="fCoverUrl" name="cover_url" value="<?= e($post['cover_url'] ?? '') ?>" placeholder="<?= e(t('admin.uploads_or_https')) ?>" maxlength="500" autocomplete="off" spellcheck="false">
            </details>
            <button type="button" class="btn btn-ghost btn-sm" id="btnCoverClear" hidden><?= e(t('admin.remove')) ?></button>
          </div>
          <label class="admin-check">
            <input type="checkbox" id="fAutoCover" name="auto_cover" value="1"<?= !empty($coverAuto) ? ' checked' : '' ?>>
            <span><?= e(t('admin.use_the_first_content_image_automatically')) ?></span>
          </label>
        </div>

        <div class="card admin-form-card admin-editor-advanced-card"<?= $submissionMode ? ' hidden' : '' ?>>
          <!-- 高级选项 -->
          <details class="admin-advanced">
            <summary>
              <?= admin_icon('chevron', 13) ?>
              <?= e(t('admin.advanced_options')) ?>
            </summary>
            <div class="admin-advanced-body">
              <div class="admin-field">
                <label class="label" for="fSlug"><?= e(t('admin.slug')) ?></label>
                <input class="input" id="fSlug" name="slug" value="<?= e($post['slug']) ?>" placeholder="<?= e(t('admin.leave_blank_to_generate_an_english_or_pinyin')) ?>" maxlength="255">
                <span class="admin-field-hint"><?= e(t('admin.new_slugs_default_to_lowercase_english_or_pinyin')) ?></span>
              </div>
              <div class="admin-field">
                <label class="label" for="fScheduledAt"><?= e(t('admin.scheduled_publication')) ?></label>
                <input class="input" type="datetime-local" id="fScheduledAt" name="scheduled_at" value="<?= e($editorData['scheduledAt']) ?>">
                <?php if ($isScheduled): ?>
                  <div class="admin-field-hint"><?= e(t('admin.this_post_is_scheduled_saving_will_update_the')) ?></div>
                <?php endif; ?>
              </div>
              <label class="admin-check">
                <input type="checkbox" id="fPinned" name="is_pinned" value="1" <?= $post['is_pinned'] ? 'checked' : '' ?>>
                <span><?= e(t('admin.pin_post_show_first_on_listing_pages')) ?></span>
              </label>
              <div class="admin-field">
                <label class="label" for="fPassword"><?= e(t('admin.access_password_optional')) ?></label>
                <input class="input" type="password" id="fPassword" name="password" autocomplete="new-password"
                       placeholder="<?= $hasPassword ? t('admin.password_configured_leave_blank_to_keep_it') : t('admin.visitors_must_enter_a_password_to_read_this') ?>" maxlength="100">
                <?php if ($hasPassword): ?>
                  <label class="admin-check">
                    <input type="checkbox" id="fRemovePassword" name="remove_password" value="1">
                    <span><?= e(t('admin.remove_existing_password')) ?></span>
                  </label>
                <?php endif; ?>
              </div>
              <div class="admin-field">
                <label class="label" for="fExternalUrl"><?= e(t('admin.external_url_optional')) ?></label>
                <input class="input" id="fExternalUrl" name="external_url" value="<?= e($post['external_url'] ?? '') ?>" placeholder="<?= e(t('admin.https_link_the_title_and_cover_directly_to')) ?>" maxlength="500">
              </div>
              <label class="admin-check">
                <input type="checkbox" id="fCatPinned" name="category_pinned" value="1" <?= $post['category_pinned'] ? 'checked' : '' ?>>
                <span><?= e(t('admin.pin_within_category')) ?></span>
              </label>
              <?php if (!$submissionMode): ?>
                <input type="hidden" name="allow_comments" value="0">
                <label class="admin-check">
                  <input type="checkbox" id="fAllowComments" name="allow_comments" value="1" <?= ($post['allow_comments'] ?? 1) ? 'checked' : '' ?>>
                  <span><?= e(t('admin.allow_comments')) ?></span>
                </label>
              <?php endif; ?>
              <?php if (!$submissionMode): ?>
              <?= \Pafish\Services\Plugin::renderInjection('post_editor', [
                  'post' => $post,
                  'isEdit' => $isEdit,
                  'postId' => $postId,
              ]) ?>
              <?= \Pafish\Services\Theme::renderInjection('post_editor', [
                  'post' => $post,
                  'isEdit' => $isEdit,
                  'postId' => $postId,
              ]) ?>
              <?php endif; ?>
            </div>
          </details>
          <?php $seoValues = $post; $seoPrefix = 'post'; require __DIR__ . '/partials/seo-fields.php'; ?>
        </div>

      </div>
    </div>
  </form>
</div>

<?php require __DIR__ . '/partials/editor-media-modal.php'; ?>

<script>
window.PAFISH_EDITOR_DATA = <?= json_encode($editorData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.PAFISH_EDITOR_CSRF = <?= json_encode(\Pafish\Core\Session::csrfToken()) ?>;
</script>
<script src="<?= e(asset_url('/js/admin-markdown-editor.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-editor-host.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-editor.js')) ?>"></script>
