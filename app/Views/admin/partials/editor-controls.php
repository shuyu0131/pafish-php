<?php
/** $editorType: post / page / micro。启用的编辑器插件自动接管。 */
$editorConfigured = \Pafish\Services\Editor::configured($editorType);
?>
<div class="admin-editor-controls">
  <?php if ($editorMediaEnabled ?? true): ?>
  <button type="button" class="btn btn-ghost" data-editor-media title="插入媒体" aria-label="插入媒体"><?= admin_icon('image', 15) ?><span>插入媒体</span></button>
  <?php endif; ?>
</div>
<p class="admin-editor-adapter-status" data-editor-status role="status" hidden></p>
<script type="application/json" id="editorProviders"><?= json_encode(['selected' => $editorConfigured['id'], 'providers' => $editorConfigured['id'] === 'default' ? [] : [$editorConfigured]], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
