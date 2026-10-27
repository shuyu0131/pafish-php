<?php
/**
 * 文章编辑器：
 * 正文为主，发布设置分组；保存操作在桌面头部、手机正文之后。
 * 变量：$post $isEdit $postId $catTree $tags $tagIds $customFields $hasPassword $isScheduled $statusLabel
 *       $coverAuto（这篇是否开启自动取图兜底）$autoCoverUrl（自动取到的图，只作预览）
 */
$submissionMode = (bool) ($submissionMode ?? false);
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
    'uploadUrl' => url_to('/api/upload'),
    'uploadsUrl' => url_to('/api/uploads'),
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
    <?php if ($post['status'] === 'PENDING'): ?><span>内容已送审，审核完成前不会公开。</span>
    <?php elseif ($post['status'] === 'PUBLISHED' && !empty($submissionReviewRequired)): ?><span>重新提交后文章会暂时从公开页面隐藏，审核通过后重新显示。</span><?php endif; ?>
  </div>
  <?php if ($post['status'] === 'CHANGES_REQUESTED' && trim((string) ($post['review_note'] ?? '')) !== ''): ?>
    <section class="admin-submission-feedback" aria-labelledby="submission-feedback-title"><h2 id="submission-feedback-title">审核意见</h2><p><?= e($post['review_note']) ?></p><span class="admin-muted">根据意见修改后再次提交。</span></section>
  <?php endif; ?>
  <?php endif; ?>

  <form id="postForm" method="post" action="<?= e(url_to($submissionMode ? '/admin/submissions/save' : ($isEdit ? '/admin/posts/' . $postId . '/save' : '/admin/posts/save'))) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="_idempotency" value="<?= e(\Pafish\Core\Session::requestToken()) ?>">
    <?php if ($submissionMode): ?><input type="hidden" name="post_id" value="<?= $postId ?>"><?php endif; ?>
    <input type="hidden" name="action" id="fAction" value="draft">

    <header class="admin-editor-header">
      <div class="admin-editor-heading">
        <h1 class="admin-h1"><?= $submissionMode ? ($isEdit ? '编辑投稿' : '撰写投稿') : ($isEdit ? '编辑文章' : '新建文章') ?></h1>
        <?php if ($isEdit && !$submissionMode): ?><p class="admin-autosave" data-autosave role="status">每 60 秒自动保存</p><?php endif; ?>
      </div>
      <div class="admin-editor-header-actions">
        <button type="submit" class="btn btn-outline" data-save="draft">存为草稿</button>
        <?php if (!$submissionMode || !empty($submissionCanSubmit)): ?>
          <button type="submit" class="btn btn-primary admin-publish-primary" data-save="publish"><?= $submissionMode ? (!empty($submissionReviewRequired) ? '提交审核' : ($isEdit && $post['status'] === 'PUBLISHED' ? '保存并发布' : '发布文章')) : ($isEdit && $post['status'] === 'PUBLISHED' ? '保存修改' : '立即发布') ?></button>
        <?php else: ?>
          <span class="admin-muted">投稿已关闭，可先保存为草稿</span>
        <?php endif; ?>
      </div>
    </header>
    <div class="admin-editor-error" role="alert" hidden></div>
    <div class="admin-editor-grid">
      <div class="admin-editor-main">
        <div class="admin-editor-writing">
        <div class="admin-editor-title-wrap">
          <input class="admin-editor-title" type="text" id="fTitle" name="title" aria-label="文章标题"
                 value="<?= e($post['title']) ?>" placeholder="文章标题" maxlength="255">
        </div>

        <?php $editorType = 'post'; require __DIR__ . '/partials/editor-controls.php'; ?>
        <!-- 正文编辑器 -->
        <div class="admin-md-editor">
          <div id="markdownMount"></div>
          <input type="hidden" id="fContentFormat" name="content_format" value="<?= e($post['content_format'] ?? 'markdown') ?>">
          <textarea id="fContent" name="content" hidden
                    maxlength="16000000"><?= e($post['content']) ?></textarea>
        </div>
        <div class="admin-editor-extra">
          <div class="admin-field admin-editor-excerpt-field">
            <label class="label" for="fExcerpt">摘要</label>
            <textarea class="input admin-excerpt" id="fExcerpt" name="excerpt"
                      placeholder="展示在列表页（留空自动截取）" maxlength="500"><?= e($post['excerpt']) ?></textarea>
          </div>

        </div>
        </div>

        <details class="card admin-advanced admin-editor-fields" id="customFieldsDetails"<?= $submissionMode || !$legacyCustomFieldsVisible ? ' hidden' : '' ?>>
          <summary><?= admin_icon('chevron-right', 14) ?><span id="customFieldsTitle"><?= $hasExtensionMeta ? '旧版自定义字段' : '自定义字段' ?></span></summary>
          <section class="admin-advanced-body admin-custom-fields-field" aria-labelledby="customFieldsTitle">
            <div class="admin-editor-extra-head">
              <button type="button" class="btn btn-ghost admin-add-field" id="btnAddField"><?= admin_icon('plus', 14) ?>添加字段</button>
            </div>
            <div class="admin-custom-fields" id="customFields"></div>
            <datalist id="postCustomFieldKeys"></datalist>
          </section>
        </details>
        <?php if ($extensionMetaFields !== []): ?>
        <details class="card admin-advanced admin-editor-fields" id="extensionMetaDetails">
          <summary><?= admin_icon('chevron-right', 14) ?><span>主题自定义字段</span></summary>
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
                      <option value="0" <?= $value === '0' ? 'selected' : '' ?>>关闭</option>
                      <option value="1" <?= $value === '1' ? 'selected' : '' ?>>开启</option>
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
            <strong>发布设置</strong>
          </div>
          <div class="admin-field">
            <label class="label" for="catTrigger">分类</label>
            <div class="admin-cat-select" id="catSelect"></div>
            <div class="admin-field-hint" data-new-cat-wrap hidden>
              <input class="input" id="fNewCategory" name="new_category" placeholder="输入新分类名称（保存文章时创建）" maxlength="100">
            </div>
          </div>

          <div class="admin-field">
            <label class="label" for="fTagInput">标签</label>
            <div class="admin-tags-selected" id="tagsSelected"></div>
            <input class="input" id="fTagInput" placeholder="<?= $submissionMode ? '输入已有标签名，回车添加' : '输入标签名，回车/逗号添加' ?>" maxlength="100">
            <div class="admin-tags-all" id="tagsAll"></div>
          </div>

          <?php if (count($templateOptions) > 1 || $templateUnavailable): ?>
          <div class="admin-field">
            <label class="label" for="fTemplate">文章模板</label>
            <select class="input" id="fTemplate" name="template">
              <?php if ($templateUnavailable): ?>
              <option value="<?= e($template) ?>" selected><?= e($template) ?>（暂不可用）</option>
              <?php endif; ?>
              <?php foreach ($templateOptions as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $template === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($templateUnavailable): ?>
            <span class="admin-field-hint">原模板暂不可用，前台按分类或默认布局显示；恢复主题后可继续使用。</span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <input type="hidden" id="fTemplate" name="template" value="<?= e($template) ?>">
          <?php endif; ?>

          <?php if ($submissionMode): ?>
          <label class="admin-check">
            <input type="checkbox" id="fAllowComments" name="allow_comments" value="1" <?= ($post['allow_comments'] ?? 1) ? 'checked' : '' ?>>
            <span>允许评论</span>
          </label>
          <?php endif; ?>

        </div>

        <div class="card admin-form-card admin-editor-cover-card">
          <h2 class="admin-card-title">封面图</h2>
          <button type="button" class="admin-cover-picker" id="btnCoverPicker" data-auto-cover="<?= e($autoCoverUrl ?? '') ?>" aria-label="选择或更换封面">
            <span class="admin-cover-empty" data-cover-empty><?= admin_icon('image', 24) ?><span>选择封面</span></span>
            <span class="admin-cover-preview" data-cover-preview hidden><img alt="封面预览"></span>
          </button>
          <div class="admin-cover-actions">
            <details class="admin-cover-address">
              <summary>使用图片地址</summary>
              <label for="fCoverUrl">封面图片地址</label>
              <input class="input" id="fCoverUrl" name="cover_url" value="<?= e($post['cover_url'] ?? '') ?>" placeholder="/uploads/… 或 https://…" maxlength="500" autocomplete="off" spellcheck="false">
            </details>
            <button type="button" class="btn btn-ghost btn-sm" id="btnCoverClear" hidden>移除</button>
          </div>
          <label class="admin-check">
            <input type="checkbox" id="fAutoCover" name="auto_cover" value="1"<?= !empty($coverAuto) ? ' checked' : '' ?>>
            <span>自动使用正文首图</span>
          </label>
        </div>

        <div class="card admin-form-card admin-editor-advanced-card"<?= $submissionMode ? ' hidden' : '' ?>>
          <!-- 高级选项 -->
          <details class="admin-advanced">
            <summary>
              <?= admin_icon('chevron', 13) ?>
              高级选项
            </summary>
            <div class="admin-advanced-body">
              <div class="admin-field">
                <label class="label" for="fSlug">别名（Slug）</label>
                <input class="input" id="fSlug" name="slug" value="<?= e($post['slug']) ?>" placeholder="自动根据标题生成" maxlength="255">
              </div>
              <div class="admin-field">
                <label class="label" for="fScheduledAt">定时发布时间</label>
                <input class="input" type="datetime-local" id="fScheduledAt" name="scheduled_at" value="<?= e($editorData['scheduledAt']) ?>">
                <?php if ($isScheduled): ?>
                  <div class="admin-field-hint">当前为定时发布状态，保存时将更新时间</div>
                <?php endif; ?>
              </div>
              <label class="admin-check">
                <input type="checkbox" id="fPinned" name="is_pinned" value="1" <?= $post['is_pinned'] ? 'checked' : '' ?>>
                <span>置顶文章（列表页优先展示）</span>
              </label>
              <div class="admin-field">
                <label class="label" for="fPassword">访问密码（可选）</label>
                <input class="input" type="password" id="fPassword" name="password" autocomplete="new-password"
                       placeholder="<?= $hasPassword ? '已设置密码，留空保持不变' : '设置后访客需输入密码才能阅读' ?>" maxlength="100">
                <?php if ($hasPassword): ?>
                  <label class="admin-check">
                    <input type="checkbox" id="fRemovePassword" name="remove_password" value="1">
                    <span>移除现有密码</span>
                  </label>
                <?php endif; ?>
              </div>
              <div class="admin-field">
                <label class="label" for="fExternalUrl">外链跳转地址（可选）</label>
                <input class="input" id="fExternalUrl" name="external_url" value="<?= e($post['external_url'] ?? '') ?>" placeholder="https://… 填写后列表标题/封面点击直接跳转外链" maxlength="500">
              </div>
              <label class="admin-check">
                <input type="checkbox" id="fCatPinned" name="category_pinned" value="1" <?= $post['category_pinned'] ? 'checked' : '' ?>>
                <span>分类内置顶（在所属分类页置顶展示）</span>
              </label>
              <?php if (!$submissionMode): ?>
                <input type="hidden" name="allow_comments" value="0">
                <label class="admin-check">
                  <input type="checkbox" id="fAllowComments" name="allow_comments" value="1" <?= ($post['allow_comments'] ?? 1) ? 'checked' : '' ?>>
                  <span>允许评论</span>
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
