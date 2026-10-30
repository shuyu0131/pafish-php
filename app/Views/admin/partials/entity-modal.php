<?php
/** Short entity form. Field markup is rendered by the caller with normal escaping. */
$entityConfig['saveUrl'] = url_to($entityConfig['savePath']);
$entityConfig['baseUrl'] = url_to($entityConfig['basePath']);
unset($entityConfig['savePath'], $entityConfig['basePath']);
?>
<div class="admin-modal-backdrop" id="<?= e($entityModalId) ?>" data-entity-modal hidden>
  <div class="admin-modal admin-entity-modal" role="dialog" aria-modal="true" aria-labelledby="<?= e($entityModalId) ?>Title">
    <div class="admin-modal-head">
      <h2 class="admin-modal-title" id="<?= e($entityModalId) ?>Title"><?= e($entityConfig['createTitle']) ?></h2>
      <button type="button" class="admin-icon-btn" data-modal-close aria-label="关闭窗口"><?= admin_icon('x', 16) ?></button>
    </div>
    <form id="<?= e($entityFormId) ?>" method="post" action="<?= e($entityConfig['saveUrl']) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="">
      <input type="hidden" name="slug_auto" value="1" data-slug-auto>
      <div class="admin-modal-body">
        <?= $entityFields ?>
        <div class="admin-editor-error" role="alert" hidden></div>
      </div>
      <div class="admin-modal-actions">
        <button type="button" class="btn btn-ghost" data-modal-close>取消</button>
        <button type="submit" class="btn btn-primary"><?= e($entityConfig['createTitle']) ?></button>
      </div>
    </form>
  </div>
  <script type="application/json" data-entity-config><?= json_encode($entityConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>
