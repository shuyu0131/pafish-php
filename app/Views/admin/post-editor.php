<?php
/**
 * 文章编辑器：
 * 左侧标题+Markdown 编辑器（工具栏/分栏预览/拖拽粘贴上传/插入媒体），右侧 300px 设置栏
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
    <input type="hidden" name="action" id="fAction" value="draft">

    <div class="admin-editor-grid">
      <!-- 左列 -->
      <div class="admin-editor-main">
        <input class="admin-editor-title" type="text" id="fTitle" name="title"
               value="<?= e($post['title']) ?>" placeholder="文章标题" maxlength="255">

        <!-- Markdown 编辑器（Vditor：所见即所得/分栏/源码三模式，完整博客写作工具栏，拖拽粘贴上传） -->
        <div class="admin-md-editor">
          <div id="vditorMount"></div>
          <textarea id="fContent" name="content" hidden
                    maxlength="16000000"><?= e($post['content']) ?></textarea>
        </div>
      </div>

      <!-- 右列 -->
      <div class="admin-editor-side">
        <!-- 发布按钮 -->
        <div class="card admin-form-card admin-publish-card">
          <div class="admin-publish-btns">
            <button type="submit" class="btn btn-outline" data-save="draft">存为草稿</button>
            <button type="submit" class="btn btn-primary" data-save="publish">立即发布</button>
          </div>
          <button type="submit" class="btn btn-ghost admin-publish-schedule" data-save="schedule">定时发布</button>
          <div class="admin-editor-error" hidden></div>
        </div>

        <!-- 自动保存状态（编辑模式） -->
        <?php if ($isEdit): ?>
          <p class="admin-autosave" data-autosave>内容将每 60 秒自动保存，防止意外丢失</p>
        <?php endif; ?>

        <!-- 设置 -->
        <div class="card admin-form-card admin-settings-card">
          <div class="admin-field">
            <span class="label">分类</span>
            <div class="admin-cat-select" id="catSelect"></div>
            <div class="admin-field-hint" data-new-cat-wrap hidden>
              <input class="input" id="fNewCategory" name="new_category" placeholder="输入新分类名称（保存文章时创建）" maxlength="100">
            </div>
          </div>

          <div class="admin-field">
            <span class="label">标签</span>
            <div class="admin-tags-selected" id="tagsSelected"></div>
            <input class="input" id="fTagInput" placeholder="输入标签名，回车/逗号添加" maxlength="100">
            <div class="admin-tags-all" id="tagsAll"></div>
          </div>

          <div class="admin-field">
            <span class="label">封面图</span>
            <div class="admin-cover-row">
              <input class="input" id="fCoverUrl" name="cover_url" value="<?= e($post['cover_url'] ?? '') ?>" placeholder="图片 URL" maxlength="500">
              <button type="button" class="btn btn-outline" id="btnCoverUpload">上传</button>
              <button type="button" class="btn btn-outline" id="btnCoverPicker">媒体库</button>
              <input type="file" id="coverFile" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" hidden>
            </div>
            <div class="admin-cover-preview" data-cover-preview hidden>
              <img alt="封面预览">
            </div>
          </div>

          <div class="admin-field">
            <span class="label">摘要</span>
            <textarea class="input admin-excerpt" id="fExcerpt" name="excerpt"
                      placeholder="展示在列表页（留空自动截取）" maxlength="500"><?= e($post['excerpt']) ?></textarea>
          </div>

          <!-- 高级选项 -->
          <details class="admin-advanced">
            <summary>
              <?= admin_icon('chevron', 13) ?>
              高级选项
            </summary>
            <div class="admin-advanced-body">
              <div class="admin-field">
                <span class="label">别名（Slug）</span>
                <input class="input" id="fSlug" name="slug" value="<?= e($post['slug']) ?>" placeholder="自动根据标题生成" maxlength="255">
              </div>
              <div class="admin-field">
                <span class="label">定时发布时间</span>
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
                <span class="label">访问密码（可选）</span>
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
                <span class="label">外链跳转地址（可选）</span>
                <input class="input" id="fExternalUrl" name="external_url" value="<?= e($post['external_url'] ?? '') ?>" placeholder="https://… 填写后列表标题/封面点击直接跳转外链" maxlength="500">
              </div>
              <label class="admin-check">
                <input type="checkbox" id="fCatPinned" name="category_pinned" value="1" <?= $post['category_pinned'] ? 'checked' : '' ?>>
                <span>分类内置顶（在所属分类页置顶展示）</span>
              </label>
              <div class="admin-field">
                <span class="label">其他自定义字段</span>
                <div class="admin-custom-fields" id="customFields"></div>
                <button type="button" class="btn btn-outline admin-add-field" id="btnAddField">+ 添加字段</button>
              </div>
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

        <a class="btn btn-ghost admin-back-link" href="<?= e(url_to('/admin/posts')) ?>">
          <?= admin_icon('arrow-left', 15) ?>返回文章列表
        </a>
      </div>
    </div>
  </form>
</div>

<!-- 媒体选择弹窗 -->
<div class="admin-modal-backdrop" id="mediaModal" hidden>
  <div class="admin-modal" role="dialog" aria-modal="true" aria-label="插入媒体">
    <div class="admin-modal-head">
      <div class="admin-modal-tabs">
        <button type="button" class="admin-modal-tab active" data-mtab="upload">本地上传</button>
        <button type="button" class="admin-modal-tab" data-mtab="library">媒体库</button>
      </div>
      <button type="button" class="admin-icon-btn" data-close-modal aria-label="关闭" title="关闭"><?= admin_icon('x', 16) ?></button>
    </div>
    <div class="admin-modal-body">
      <div data-mpanel="upload">
        <input type="file" class="input" id="mediaFile"
               accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.md,.csv,.zip,.rar,.7z,.tar,.gz,.mp3,.wav,.ogg,.m4a,.flac,.mp4,.webm,.mov,.mkv">
        <p class="admin-field-hint admin-modal-hint" data-mupload-hint>图片插入为 Markdown 图片，其他文件插入为下载链接</p>
        <div class="admin-modal-error" data-mupload-error hidden></div>
      </div>
      <div data-mpanel="library" hidden>
        <input class="input admin-lib-search" type="search" placeholder="搜索媒体…" data-lib-q>
        <div class="admin-lib-grid" data-lib-grid></div>
        <div class="admin-pager admin-lib-pager" data-lib-pager hidden></div>
      </div>
    </div>
  </div>
</div>

<script>
window.PAFISH_EDITOR_DATA = <?= json_encode($editorData, JSON_UNESCAPED_UNICODE) ?>;
window.PAFISH_EDITOR_CSRF = <?= json_encode(\Pafish\Core\Session::csrfToken()) ?>;
</script>
<script src="<?= e(asset_url('/vendor/vditor/dist/index.min.js')) ?>"></script>
<script src="<?= e(asset_url('/js/admin-editor.js')) ?>"></script>
