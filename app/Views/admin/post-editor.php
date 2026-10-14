<?php
/**
 * 文章编辑器：
 * 宽屏正文与设置栏按 3:1 双栏展示；窄屏按正文、设置、发布的顺序阅读。
 * 变量：$post $isEdit $postId $catTree $tags $tagIds $customFields $hasPassword $isScheduled $statusLabel
 */
$editorData = [
    'initial' => [
        'title' => (string) $post['title'],
        'slug' => (string) $post['slug'],
        'excerpt' => (string) $post['excerpt'],
        'content' => (string) $post['content'],
        'coverUrl' => (string) ($post['cover_url'] ?? ''),
        'categoryId' => $post['category_id'] ? (string) $post['category_id'] : '',
        'tagIds' => $tagIds,
        'isPinned' => (bool) $post['is_pinned'],
        'categoryPinned' => (bool) $post['category_pinned'],
        'externalUrl' => (string) ($post['external_url'] ?? ''),
        'customFields' => $customFields !== [] ? $customFields : [['key' => '', 'value' => '']],
    ],
    'isEdit' => $isEdit,
    'postId' => $postId,
    'isScheduled' => $isScheduled,
    'hasPassword' => $hasPassword,
    'saveUrl' => url_to($isEdit ? '/admin/posts/' . $postId . '/save' : '/admin/posts/save'),
    'editUrl' => url_to('/admin/posts/{id}/edit'),
    'listUrl' => url_to('/admin/posts'),
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
<div class="admin-editor">
  <form id="postForm" method="post" action="<?= e(url_to($isEdit ? '/admin/posts/' . $postId . '/save' : '/admin/posts/save')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="_idempotency" value="<?= e(\Pafish\Core\Session::requestToken()) ?>">
    <input type="hidden" name="action" id="fAction" value="draft">

    <div class="admin-editor-grid">
      <!-- 左列 -->
      <div class="admin-editor-main">
        <div class="admin-editor-topline">
          <a class="btn btn-ghost" href="<?= e(url_to('/admin/posts')) ?>"><?= admin_icon('arrow-left', 15) ?>返回</a>
          <span class="admin-editor-topline-title"><?= $isEdit ? '编辑文章' : '新建文章' ?></span>
        </div>
        <input class="admin-editor-title" type="text" id="fTitle" name="title" aria-label="标题"
               value="<?= e($post['title']) ?>" placeholder="文章标题" maxlength="255">

        <!-- 轻量 Markdown 源码编辑器 -->
        <div class="admin-md-editor">
          <div id="markdownMount"></div>
          <textarea id="fContent" name="content" hidden
                    maxlength="16000000"><?= e($post['content']) ?></textarea>
        </div>
        <div class="card admin-form-card admin-editor-extra">
          <div class="admin-field admin-editor-excerpt-field">
            <label class="label" for="fExcerpt">摘要</label>
            <textarea class="input admin-excerpt" id="fExcerpt" name="excerpt"
                      placeholder="展示在列表页（留空自动截取）" maxlength="500"><?= e($post['excerpt']) ?></textarea>
          </div>

          <section class="admin-field admin-custom-fields-field" aria-labelledby="customFieldsTitle">
            <div class="admin-editor-extra-head">
              <span class="label" id="customFieldsTitle">自定义字段</span>
              <button type="button" class="btn btn-ghost admin-add-field" id="btnAddField">+ 添加字段</button>
            </div>
            <p class="admin-field-hint">供前台模板详情页按字段名调用，具体展示由模板决定。</p>
            <div class="admin-custom-fields" id="customFields"></div>
            <datalist id="postCustomFieldKeys"></datalist>
          </section>
        </div>
      </div>

      <!-- 右列 -->
      <div class="admin-editor-side">
        <!-- 发布按钮 -->
        <div class="card admin-form-card admin-publish-card">
          <div class="admin-publish-btns">
            <button type="submit" class="btn btn-primary admin-publish-primary" data-save="publish">立即发布</button>
            <button type="submit" class="btn btn-outline" data-save="draft">存为草稿</button>
          </div>
          <div class="admin-editor-error" hidden></div>
        </div>

        <!-- 自动保存状态（编辑模式） -->
        <?php if ($isEdit): ?>
          <p class="admin-autosave" data-autosave>内容将每 60 秒自动保存，防止意外丢失</p>
        <?php endif; ?>

        <!-- 设置 -->
        <div class="card admin-form-card admin-settings-card">
          <div class="admin-editor-card-heading">
            <strong>文章设置</strong>
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
            <input class="input" id="fTagInput" placeholder="输入标签名，回车/逗号添加" maxlength="100">
            <div class="admin-tags-all" id="tagsAll"></div>
          </div>

          <div class="admin-field admin-editor-cover-field">
            <label class="label" for="fCoverUrl">封面图</label>
            <div class="admin-cover-row">
              <input class="input" id="fCoverUrl" name="cover_url" value="<?= e($post['cover_url'] ?? '') ?>" placeholder="图片 URL" maxlength="500">
              <button type="button" class="btn btn-outline" id="btnCoverUpload"><?= admin_icon('upload', 14) ?>上传</button>
              <button type="button" class="btn btn-outline" id="btnCoverPicker"><?= admin_icon('image', 14) ?>媒体库</button>
              <input type="file" id="coverFile" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml,.svg" hidden>
            </div>
            <div class="admin-cover-preview" data-cover-preview hidden>
              <img alt="封面预览">
            </div>
          </div>

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
            </div>
          </details>
        </div>

      </div>
    </div>
  </form>
</div>

<?php require __DIR__ . '/partials/editor-media-modal.php'; ?>

<script>
window.PAFISH_EDITOR_DATA = <?= json_encode($editorData, JSON_UNESCAPED_UNICODE) ?>;
window.PAFISH_EDITOR_CSRF = <?= json_encode(\Pafish\Core\Session::csrfToken()) ?>;
</script>
<script src="<?= e(asset_url('/js/admin-markdown-editor.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-editor.js')) ?>"></script>
