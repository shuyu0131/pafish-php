<?php
/**
 * 侧边栏组件管理：
 * - 新建与编辑复用弹窗，内容框仅在自定义类型显示
 * - 列表行：标题（无标题显示类型名）+ 类型徽标 + 「已隐藏」badge + custom 预览首行
 * - 操作：↑/↓ 上下移动（边界禁用）、显隐、编辑（弹窗）、删除（确认弹窗）
 * 变量：$items $types $typeLabels $defaultTitles $role
 */
$count = count($items);
$hidden = 0;
foreach ($items as $w) {
    if ((int) $w['visible'] === 0) {
        $hidden++;
    }
}
$typeJson = json_encode($defaultTitles, JSON_UNESCAPED_UNICODE);
$existingTypes = [];
foreach ($items as $item) {
    $existingTypes[(string) ($item['type'] ?? '')] = true;
}
$typeHints = [
    'categories' => '按分类浏览文章',
    'tags' => '展示站点标签',
    'recent_posts' => '显示最新发布文章',
    'hot_posts' => '显示热门文章排行',
    'recent_comments' => '显示最新评论',
    'recent_micro_statuses' => '显示最新公开微语',
    'custom' => '添加一段自定义内容',
];
?>
<div class="admin-stack admin-detail-page admin-widgets-page">
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1">组件管理</h1>
      <p class="admin-page-sub">共 <?= $count ?> 个（含隐藏 <?= $hidden ?> 个）· 区域和顺序由当前主题决定</p>
    </div>
    <button type="button" class="btn btn-primary" data-entity-new="widgetsModal"><?= admin_icon('plus', 15) ?>添加组件</button>
  </div>

  <div class="admin-widget-layout">
    <section class="admin-widget-side">
      <div class="admin-widget-available">
        <div class="admin-widget-panel-head">
          <div>
            <h2 class="admin-card-title">可用组件</h2>
          </div>
        </div>
        <div class="admin-widget-available-list">
          <?php foreach ($types as $t): ?>
            <div class="admin-widget-available-row">
              <div>
                <strong><?= e($typeLabels[$t] ?? $t) ?></strong>
                <span><?= e($typeHints[$t] ?? '') ?></span>
              </div>
              <button type="button" class="btn btn-outline btn-sm admin-widget-add" data-widget-add="<?= e($t) ?>"<?= isset($existingTypes[$t]) && $t !== 'custom' ? ' disabled' : '' ?>>
                <?= isset($existingTypes[$t]) && $t !== 'custom' ? '已添加' : '添加' ?>
              </button>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    </section>

    <section class="admin-widget-active">
      <div class="admin-widget-panel-head">
        <div>
          <h2 class="admin-card-title">已添加组件</h2>
          <p class="admin-widget-panel-meta">拖动或使用箭头调整显示顺序</p>
        </div>
      </div>
      <?php if ($count === 0): ?>
        <div class="admin-empty-list card">
      <?= admin_icon('layout', 32) ?>
      <p>还没有组件</p>
        </div>
      <?php else: ?>
        <div class="admin-list admin-widget-list">
      <div class="admin-list-head" aria-hidden="true">
        <span>组件</span>
        <span>操作</span>
      </div>
      <?php foreach ($items as $i => $w): ?>
        <?php
        $rowArea = (string) ($w['area'] ?? 'sidebar');
        $areaRows = array_values(array_filter($items, static fn (array $item): bool => (string) ($item['area'] ?? 'sidebar') === $rowArea));
        $areaPosition = 0;
        foreach ($areaRows as $areaRowIndex => $areaRow) {
            if ((int) $areaRow['id'] === (int) $w['id']) {
                $areaPosition = $areaRowIndex;
                break;
            }
        }
        $wTitle = trim((string) ($w['title'] ?? ''));
        $typeLabel = $typeLabels[$w['type']] ?? $w['type'];
        $showTitle = $w['type'] === 'custom'
            ? ($wTitle !== '' ? $wTitle : '自定义内容')
            : $typeLabel;
        ?>
        <div class="admin-list-row" data-id="<?= (int) $w['id'] ?>"
             data-type="<?= e($w['type']) ?>" data-area="<?= e((string) ($w['area'] ?? 'sidebar')) ?>" data-title="<?= e($wTitle) ?>"
             data-content="<?= e((string) ($w['content'] ?? '')) ?>">
          <div class="admin-list-main">
            <span class="admin-list-name"><?= e($showTitle) ?></span>
            <?php if ($w['type'] === 'custom'): ?>
              <span class="badge badge-accent">自定义</span>
            <?php endif; ?>
            <span class="admin-widget-area"><?= e(($areas ?? ['sidebar' => '侧边栏'])[$w['area'] ?? 'sidebar'] ?? (string) ($w['area'] ?? 'sidebar')) ?></span>
            <?php if ((int) $w['visible'] === 0): ?>
              <span class="badge badge-danger">已隐藏</span>
            <?php endif; ?>
            <?php if ($w['type'] === 'custom' && $wTitle === ''): ?>
              <span class="admin-muted">（自定义文本）</span>
            <?php endif; ?>
            <?php if ($w['type'] === 'custom' && trim((string) $w['content']) !== ''): ?>
              <div class="admin-muted"><?= e(mb_strimwidth(str_replace("\n", ' ', trim((string) $w['content'])), 0, 60, '…')) ?></div>
            <?php endif; ?>
          </div>
          <div class="admin-list-ops">
            <button type="button" class="admin-icon-btn" data-move="up" title="上移" aria-label="上移 <?= e($showTitle) ?>" <?= $areaPosition === 0 ? 'disabled' : '' ?>><?= admin_icon('chevron-up', 15) ?></button>
            <button type="button" class="admin-icon-btn" data-move="down" title="下移" aria-label="下移 <?= e($showTitle) ?>" <?= $areaPosition === count($areaRows) - 1 ? 'disabled' : '' ?>><?= admin_icon('chevron-down', 15) ?></button>
            <button type="button" class="admin-icon-btn" data-toggle title="<?= (int) $w['visible'] === 1 ? '隐藏' : '显示' ?>" aria-label="<?= (int) $w['visible'] === 1 ? '隐藏' : '显示' ?> <?= e($showTitle) ?>">
              <?= admin_icon((int) $w['visible'] === 1 ? 'eye' : 'eye-off', 15) ?>
            </button>
            <button type="button" class="admin-icon-btn" data-entity-edit="widgetsModal" title="编辑" aria-label="编辑 <?= e($showTitle) ?>"><?= admin_icon('edit', 15) ?></button>
            <button type="button" class="admin-icon-btn admin-icon-danger" data-delete title="删除" aria-label="删除 <?= e($showTitle) ?>"><?= admin_icon('trash', 15) ?></button>
          </div>
        </div>
      <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php ob_start(); ?>
<div class="admin-form-grid">
        <div class="admin-field">
          <label class="label" for="newType">类型 *</label>
          <select class="input" name="type" id="newType" required>
            <?php foreach ($types as $t): ?>
              <option value="<?= e($t) ?>" data-default="<?= e($defaultTitles[$t]) ?>"><?= e($typeLabels[$t]) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-field">
          <label class="label" for="newArea">显示区域 *</label>
          <select class="input" name="area" id="newArea" required>
            <?php foreach (($areas ?? ['sidebar' => '侧边栏']) as $areaKey => $areaLabel): ?>
              <option value="<?= e($areaKey) ?>"><?= e($areaLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-field">
          <label class="label" for="newTitle">标题（留空用默认）</label>
          <input class="input" type="text" name="title" id="newTitle" placeholder="分类" maxlength="100">
        </div>
      </div>
      <div class="admin-field" id="newContentField">
        <label class="label" for="widgetsContent">内容 *</label>
          <textarea class="input" name="content" rows="4" maxlength="5000"
                  placeholder="每行一段，支持 [文字](https://链接) 格式" id="widgetsContent" required></textarea>
      </div>
<?php
$entityFields = (string) ob_get_clean();
$entityModalId = 'widgetsModal';
$entityFormId = 'createForm';
$entityConfig = ['createTitle'=>'添加组件', 'editTitle'=>'编辑组件', 'savedMessage'=>'组件已保存',
    'savePath'=>'/admin/widgets/save', 'basePath'=>'/admin/widgets', 'fields'=>['type'=>'type','area'=>'area','title'=>'title','content'=>'content']];
require __DIR__ . '/partials/entity-modal.php';
?>
<script src="<?= e(asset_url('/js/admin-entity-forms.js')) ?>" defer></script>

<?php $widgetsCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($widgetsCsrf) ?>;
  var base = <?= json_encode(url_to('/admin/widgets')) ?>;
  var defaults = <?= $typeJson ?>;

  function post(url, body) {
    var fd = new FormData();
    Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
    fd.append("_csrf", CSRF);
    return fetch(url, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
  }
  // 类型切换：custom 才显示内容框；切换时标题为空自动填默认标题
  function syncType(select, titleInput, contentField, isEdit, fillTitle) {
    var t = select.value;
    var custom = t === "custom";
    if (fillTitle && !isEdit && titleInput && !titleInput.value.trim()) {
      titleInput.value = defaults[t] || "";
      titleInput.placeholder = defaults[t] || "";
    } else if (titleInput) {
      titleInput.placeholder = defaults[t] || "";
    }
    if (contentField) contentField.style.display = custom ? "" : "none";
    if (contentField) contentField.querySelector("textarea").required = custom;
    var label = contentField ? contentField.querySelector(".label") : null;
    if (label) label.textContent = "内容" + (custom ? " *" : "");
  }
  function wireForm(form, isEdit) {
    if (form.dataset.widgetWired === "true") return;
    form.dataset.widgetWired = "true";
    var select = form.querySelector('[name="type"]');
    var title = form.querySelector('[name="title"]');
    var contentField = form.querySelector(".admin-field > textarea[name=content]").closest(".admin-field");
    select.addEventListener("change", function () {
      syncType(select, title, contentField, isEdit, true);
    });
    syncType(select, title, contentField, isEdit, false);
  }
  var widgetForm = document.getElementById("createForm");
  wireForm(widgetForm, false);
  widgetForm.addEventListener("pafish:entity-open", function (event) {
    var select = widgetForm.elements.type;
    var contentField = widgetForm.elements.content.closest(".admin-field");
    syncType(select, widgetForm.elements.title, contentField, !event.detail.creating, false);
  });
  document.querySelectorAll("[data-widget-add]").forEach(function (button) {
    button.addEventListener("click", function () {
      var type = button.getAttribute("data-widget-add");
      document.getElementById("widgetsModal").dispatchEvent(new CustomEvent("pafish:entity-create", {
        detail: { type: type, title: defaults[type] || "" }
      }));
    });
  });

  document.querySelectorAll("[data-toggle]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      post(base + "/" + row.getAttribute("data-id") + "/toggle")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload("组件状态已更新", "success");
          else pafishNotify((j && j.error) || "操作失败", true);
        })
        .catch(function () { pafishNotify("网络错误", true); });
    });
  });

  document.querySelectorAll("[data-move]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      post(base + "/" + row.getAttribute("data-id") + "/move", { dir: btn.getAttribute("data-move") })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) pafishToastReload("组件顺序已更新", "success"); else pafishNotify((j && j.error) || "操作失败", true); })
        .catch(function () { pafishNotify("网络错误", true); });
    });
  });

  document.querySelectorAll("[data-delete]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var row = btn.closest(".admin-list-row");
      var name = row.querySelector(".admin-list-name").textContent.trim();
      (window.pafishConfirm ? window.pafishConfirm("确定删除组件「" + name + "」？", { title: "删除组件" }) : Promise.resolve(window.confirm("确定删除组件「" + name + "」？"))).then(function (ok) {
        if (!ok) return;
        return post(base + "/" + row.getAttribute("data-id") + "/delete")
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) pafishToastReload("组件已删除", "success");
          else pafishNotify((j && j.error) || "删除失败", true);
        })
        .catch(function () { pafishNotify("网络错误", true); });
      });
    });
  });
})();
</script>
