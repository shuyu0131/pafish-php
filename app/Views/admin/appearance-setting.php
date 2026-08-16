<?php
/**
 * 主题设置页（对齐 Node admin/appearance/[name]/page.tsx + schema-form + settings-import-export）：
 * 返回链接 → 标题（manifest.title v{version} + 当前主题徽章）→ 不可用/未启用提示 → 分组 Tab 表单（8 类型）
 * → 备份卡（导出 JSON / 导入恢复）→ 媒体库弹窗（image 字段"从媒体库选择"）
 * 变量：$themeName、$manifest、$error、$active、$values
 */
$themeName = (string) ($themeName ?? '');
$manifest = $manifest ?? null;
$error = $error ?? null;
$active = (bool) ($active ?? false);
$values = $values ?? [];
$title = $manifest['title'] ?? $themeName;
$version = $manifest['version'] ?? '';
$description = is_string($manifest['description'] ?? null) ? $manifest['description'] : '';
?>
<div class="admin-stack">
  <p><a class="admin-back-link" href="<?= e(url_to('/admin/appearance')) ?>">← 返回主题列表</a></p>
  <div class="admin-page-head">
    <div>
      <h1 class="admin-h1"><?= e($title) ?>
        <?php if ($version !== ''): ?><span class="admin-theme-version">v<?= e($version) ?></span><?php endif; ?>
        <?php if ($active): ?><span class="badge badge-primary">当前主题</span><?php endif; ?>
      </h1>
    </div>
  </div>
  <p class="admin-backup-msg" id="themeSettingMsg" hidden></p>

  <?php if ($error !== null): ?>
    <div class="card admin-empty">主题“<?= e($themeName) ?>”不可用：<?= e($error) ?></div>
  <?php elseif (!$active): ?>
    <div class="card admin-empty">该主题当前未启用，可先 <a href="<?= e(url_to('/admin/appearance')) ?>">回到主题列表启用</a> 后再配置。</div>
  <?php endif; ?>

  <?php if ($manifest !== null && $active): ?>
    <?php
    $fields = [];
    foreach (($manifest['settings'] ?? []) as $field) {
        if (is_array($field) && isset($field['key'])) {
            $fields[] = $field;
        }
    }
    // 分组（保持 manifest 首现顺序；缺省"常规"）
    $groups = [];
    foreach ($fields as $field) {
        $g = (string) ($field['group'] ?? '常规');
        if (!isset($groups[$g])) {
            $groups[$g] = [];
        }
        $groups[$g][] = $field;
    }
    $groupNames = array_keys($groups);
    ?>
    <?php if ($fields === []): ?>
      <div class="card admin-empty">该主题没有可配置的设置项。</div>
    <?php else: ?>
      <form class="card admin-form-card" id="themeSettingForm" novalidate>
        <?php if (count($groups) > 1): ?>
          <div class="admin-sf-tabs">
            <?php foreach ($groupNames as $i => $g): ?>
              <button type="button" class="admin-sf-tab<?= $i === 0 ? ' active' : '' ?>" data-sf-tab="<?= e($g) ?>"><?= e($g) ?></button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php foreach ($groupNames as $gi => $g): ?>
          <div class="admin-sf-group<?= count($groups) > 1 && $gi > 0 ? ' hidden' : '' ?>" data-sf-group="<?= e($g) ?>">
            <?php foreach ($groups[$g] as $field): ?>
              <?php
              $key = (string) $field['key'];
              $label = (string) $field['label'];
              $type = (string) ($field['type'] ?? 'text');
              $value = array_key_exists($key, $values) ? (string) $values[$key] : (string) ($field['default'] ?? '');
              $placeholder = is_string($field['placeholder'] ?? null) ? $field['placeholder'] : '';
              $options = is_array($field['options'] ?? null) ? $field['options'] : [];
              $showIf = is_array($field['show_if'] ?? null) ? $field['show_if'] : null;
              ?>
              <div class="admin-sf-field"<?= $showIf !== null ? ' data-show-if-key="' . e((string) $showIf['key']) . '" data-show-if-value="' . e((string) $showIf['value']) . '"' : '' ?>>
                <label class="admin-sf-label" for="sf-<?= e($key) ?>"><?= e($label) ?></label>
                <?php if ($type === 'text'): ?>
                  <input type="text" class="input" id="sf-<?= e($key) ?>" data-sf-key="<?= e($key) ?>" data-sf-type="text"
                         value="<?= e($value) ?>"<?= $placeholder !== '' ? ' placeholder="' . e($placeholder) . '"' : '' ?> autocomplete="off">
                <?php elseif ($type === 'textarea'): ?>
                  <textarea class="input admin-sf-textarea" id="sf-<?= e($key) ?>" rows="4" data-sf-key="<?= e($key) ?>" data-sf-type="textarea"
                            placeholder="<?= e($placeholder) ?>"><?= e($value) ?></textarea>
                <?php elseif ($type === 'checkbox'): ?>
                  <label class="admin-sf-check">
                    <input type="checkbox" data-sf-key="<?= e($key) ?>" data-sf-type="checkbox"<?= $value === '1' ? ' checked' : '' ?>>
                    <span><?= e($label) ?></span>
                  </label>
                <?php elseif ($type === 'switcher'): ?>
                  <label class="admin-sf-switch">
                    <input type="checkbox" class="admin-sf-switch-input" data-sf-key="<?= e($key) ?>" data-sf-type="switcher"<?= $value === '1' ? ' checked' : '' ?>>
                    <span class="admin-sf-switch-track"></span>
                  </label>
                <?php elseif ($type === 'select'): ?>
                  <select class="input" id="sf-<?= e($key) ?>" data-sf-key="<?= e($key) ?>" data-sf-type="select">
                    <?php foreach ($options as $optValue => $optLabel): ?>
                      <option value="<?= e((string) $optValue) ?>"<?= (string) $optValue === $value ? ' selected' : '' ?>><?= e((string) $optLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif ($type === 'radio'): ?>
                  <div class="admin-sf-radio-group">
                    <?php foreach ($options as $optValue => $optLabel): ?>
                      <label class="admin-sf-radio">
                        <input type="radio" name="sf-radio-<?= e($key) ?>" data-sf-key="<?= e($key) ?>" data-sf-type="radio"
                               value="<?= e((string) $optValue) ?>"<?= (string) $optValue === $value ? ' checked' : '' ?>>
                        <span><?= e((string) $optLabel) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                <?php elseif ($type === 'color'): ?>
                  <div class="admin-sf-color">
                    <input type="color" class="admin-sf-color-picker" data-sf-key="<?= e($key) ?>" data-sf-type="color" value="<?= e($value !== '' ? $value : '#000000') ?>">
                    <input type="text" class="input admin-sf-hex" data-sf-key="<?= e($key) ?>" data-sf-type="hex" placeholder="#rrggbb" value="<?= e($value) ?>" autocomplete="off">
                  </div>
                <?php elseif ($type === 'image'): ?>
                  <div class="admin-sf-image">
                    <input type="text" class="input" id="sf-<?= e($key) ?>" data-sf-key="<?= e($key) ?>" data-sf-type="image"
                           value="<?= e($value) ?>" placeholder="<?= e($placeholder !== '' ? $placeholder : '/uploads/xxx 或外部图片 URL') ?>" autocomplete="off">
                    <button type="button" class="btn btn-outline btn-sm admin-sf-lib" data-target="<?= e($key) ?>">从媒体库选择</button>
                    <button type="button" class="btn btn-ghost btn-sm admin-sf-image-clear" data-target="<?= e($key) ?>">清除</button>
                    <p class="admin-field-hint"><?= $value === '' ? '无图片' : '已设置' ?></p>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <p class="admin-field-hint">所有主题设置保存后立即对前台生效。</p>
        <div class="admin-sf-actions">
          <button type="submit" class="btn btn-primary" id="themeSaveBtn">保存</button>
        </div>
      </form>
    <?php endif; ?>

    <div class="card admin-form-card">
      <h2 class="admin-card-title">设置备份与恢复</h2>
      <p class="admin-field-hint">导出当前主题设置（JSON），或导入备份恢复——仅接受本主题声明的设置项，其他键自动忽略。</p>
      <div class="admin-sf-actions">
        <a class="btn btn-outline" href="<?= e(url_to('/admin/appearance/export?name=' . rawurlencode($themeName))) ?>">导出设置</a>
        <input type="file" id="themeImportInput" accept=".json" hidden>
        <button type="button" class="btn btn-outline" id="themeImportBtn">导入设置</button>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- 媒体库选择弹窗（对齐编辑器弹窗：浏览 /api/uploads + 点击选中） -->
<div class="admin-modal-backdrop" id="themeMediaModal" hidden>
  <div class="admin-modal" role="dialog" aria-modal="true" aria-label="从媒体库选择">
    <div class="admin-modal-head">
      <div class="admin-modal-tabs"><span class="admin-modal-tab active">媒体库</span></div>
      <button type="button" class="admin-icon-btn" data-theme-close-modal aria-label="关闭"><?= admin_icon('x', 16) ?></button>
    </div>
    <div class="admin-modal-body">
      <input class="input admin-lib-search" type="search" placeholder="搜索媒体…" data-theme-lib-q>
      <div class="admin-lib-grid" data-theme-lib-grid></div>
      <div class="admin-pager admin-lib-pager" data-theme-lib-pager hidden></div>
    </div>
  </div>
</div>

<?php $themeSettingCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($themeSettingCsrf) ?>;
  var NAME = <?= json_encode($themeName) ?>;
  var msg = document.getElementById("themeSettingMsg");

  function showMsg(text, isError) {
    msg.textContent = text;
    msg.className = "admin-backup-msg " + (isError ? "admin-backup-msg-error" : "admin-backup-msg-ok");
    msg.hidden = false;
  }
  function persistMsg(text, isError) {
    try { sessionStorage.setItem("themeSettingMsg", JSON.stringify({ t: text, e: isError ? 1 : 0 })); } catch (e) {}
  }
  try {
    var saved = sessionStorage.getItem("themeSettingMsg");
    if (saved) {
      sessionStorage.removeItem("themeSettingMsg");
      var m = JSON.parse(saved);
      showMsg(m.t, !!m.e);
    }
  } catch (e) {}

  function post(url, fd) {
    return fetch(url, {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) return j;
        throw new Error((j && j.error) || "操作失败");
      });
  }

  // ---- 分组 Tab ----
  var tabBtns = document.querySelectorAll(".admin-sf-tab");
  tabBtns.forEach(function (btn) {
    btn.addEventListener("click", function () {
      tabBtns.forEach(function (b) { b.classList.remove("active"); });
      btn.classList.add("active");
      document.querySelectorAll(".admin-sf-group").forEach(function (g) {
        g.classList.toggle("hidden", g.dataset.sfGroup !== btn.dataset.sfTab);
      });
    });
  });

  // ---- show_if 联动（依赖字段变化时刷新显示） ----
  function refreshShowIf() {
    var getVal = function (key) {
      var el = document.querySelector('[data-sf-key="' + key + '"]');
      if (!el) { return null; }
      var t = el.dataset.sfType;
      if (t === "checkbox" || t === "switcher") { return el.checked ? "1" : "0"; }
      if (t === "radio") { return el.checked ? el.value : null; }
      return el.value;
    };
    document.querySelectorAll("[data-show-if-key]").forEach(function (field) {
      var v = getVal(field.dataset.showIfKey);
      field.style.display = v === field.dataset.showIfValue ? "" : "none";
    });
  }
  document.querySelectorAll("[data-sf-key]").forEach(function (el) {
    el.addEventListener("input", refreshShowIf);
    el.addEventListener("change", refreshShowIf);
  });
  refreshShowIf();

  // ---- color：双向同步 + 校验 ----
  document.querySelectorAll(".admin-sf-color-picker").forEach(function (picker) {
    var hex = picker.parentElement.querySelector(".admin-sf-hex");
    picker.addEventListener("input", function () { hex.value = picker.value; });
    hex.addEventListener("input", function () {
      if (/^#[0-9a-fA-F]{6}$/.test(hex.value.trim())) { picker.value = hex.value.trim(); }
    });
  });

  // ---- image：清除 ----
  document.querySelectorAll(".admin-sf-image-clear").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var key = btn.dataset.target;
      document.querySelector('[data-sf-key="' + key + '"][data-sf-type="image"]').value = "";
      refreshShowIf();
    });
  });

  // ---- 保存 ----
  var form = document.getElementById("themeSettingForm");
  if (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      // 颜色校验
      var hexInputs = document.querySelectorAll(".admin-sf-hex");
      for (var i = 0; i < hexInputs.length; i++) {
        var hv = hexInputs[i].value.trim();
        if (hv !== "" && !/^#[0-9a-fA-F]{6}$/.test(hv)) {
          showMsg("颜色值 " + hv + " 不合法（需 #rrggbb）", true);
          return;
        }
      }
      var saveBtn = document.getElementById("themeSaveBtn");
      saveBtn.disabled = true;
      saveBtn.textContent = "保存中…";
      var fd = new FormData();
      fd.append("name", NAME);
      fd.append("_csrf", CSRF);
      document.querySelectorAll("[data-sf-key]").forEach(function (el) {
        var t = el.dataset.sfType;
        if (t === "hex") { return; } // 颜色值从 picker 对应 key 收（hex 本身无独立 key 提交）
        if (t === "color") {
          var hex = document.querySelector('.admin-sf-hex[data-sf-key="' + el.dataset.sfKey + '"]');
          var v = hex ? hex.value.trim() : "";
          if (v) { fd.append(el.dataset.sfKey, v); }
          return;
        }
        if (t === "checkbox" || t === "switcher") {
          fd.append(el.dataset.sfKey, el.checked ? "1" : "0");
          return;
        }
        if (t === "radio") {
          if (el.checked) { fd.append(el.dataset.sfKey, el.value); }
          return;
        }
        fd.append(el.dataset.sfKey, el.value.trim());
      });
      post("/admin/appearance/save", fd).then(function () {
        saveBtn.disabled = false;
        saveBtn.textContent = "保存";
        showMsg("✓ 已保存", false);
      }).catch(function (err) {
        showMsg(err.message, true);
        saveBtn.disabled = false;
        saveBtn.textContent = "保存";
      });
    });
  }

  // ---- 导入设置 ----
  var importInput = document.getElementById("themeImportInput");
  var importBtn = document.getElementById("themeImportBtn");
  if (importBtn) {
    importBtn.addEventListener("click", function () { importInput.click(); });
    importInput.addEventListener("change", function () {
      if (!importInput.files || !importInput.files[0]) { return; }
      if (!window.confirm("导入将覆盖当前主题的全部设置，确定继续吗？\n（建议先导出留底）")) {
        importInput.value = "";
        return;
      }
      importBtn.disabled = true;
      importBtn.textContent = "导入中…";
      var fd = new FormData();
      fd.append("name", NAME);
      fd.append("file", importInput.files[0]);
      fd.append("_csrf", CSRF);
      post("/admin/appearance/import", fd).then(function (j) {
        persistMsg("已导入 " + j.imported + "/" + j.total + " 项设置。", false);
        location.reload();
      }).catch(function (err) {
        showMsg(err.message, true);
        importBtn.disabled = false;
        importBtn.textContent = "导入设置";
        importInput.value = "";
      });
    });
  }

  // ---- 媒体库弹窗（image 字段） ----
  var modal = document.getElementById("themeMediaModal");
  var libGrid = document.querySelector("[data-theme-lib-grid]");
  var libPager = document.querySelector("[data-theme-lib-pager]");
  var libQ = document.querySelector("[data-theme-lib-q]");
  var libTarget = null;
  var libPage = 1;
  var libTotalPages = 1;

  function loadLib() {
    libGrid.innerHTML = '<p class="admin-muted admin-modal-hint">加载中…</p>';
    fetch(pafishApi("/uploads?page=") + libPage + "&q=" + encodeURIComponent(libQ.value.trim()))
      .then(function (r) { return r.json(); })
      .then(function (j) {
        libGrid.innerHTML = "";
        if (!j.items || j.items.length === 0) {
          libGrid.innerHTML = '<p class="admin-muted admin-modal-hint">没有找到媒体文件</p>';
          libPager.hidden = true;
          return;
        }
        j.items.forEach(function (item) {
          var cell = document.createElement("button");
          cell.type = "button";
          cell.className = "admin-lib-item";
          cell.dataset.url = item.url;
          cell.title = item.original_name;
          if (item.mime && item.mime.indexOf("image/") === 0) {
            var img = document.createElement("img");
            img.src = item.url;
            img.alt = item.original_name;
            img.loading = "lazy";
            cell.appendChild(img);
          } else {
            var span = document.createElement("span");
            span.textContent = item.original_name;
            cell.appendChild(span);
          }
          cell.addEventListener("click", function () {
            if (libTarget) {
              var input = document.querySelector('[data-sf-key="' + libTarget + '"][data-sf-type="image"]');
              if (input) { input.value = cell.dataset.url; }
            }
            modal.hidden = true;
          });
          libGrid.appendChild(cell);
        });
        libTotalPages = Math.max(1, Math.ceil((j.total || 0) / (j.pageSize || 48)));
        if (libTotalPages > 1) {
          libPager.hidden = false;
          libPager.innerHTML = "";
          for (var p = 1; p <= libTotalPages; p++) {
            var a = document.createElement("button");
            a.type = "button";
            a.className = "admin-pager-btn" + (p === libPage ? " active" : "");
            a.textContent = p;
            a.addEventListener("click", function () { libPage = parseInt(this.textContent, 10); loadLib(); });
            libPager.appendChild(a);
          }
        } else {
          libPager.hidden = true;
        }
      })
      .catch(function () {
        libGrid.innerHTML = '<p class="admin-muted admin-modal-hint">媒体库加载失败</p>';
      });
  }

  document.querySelectorAll(".admin-sf-lib").forEach(function (btn) {
    btn.addEventListener("click", function () {
      libTarget = btn.dataset.target;
      libPage = 1;
      libQ.value = "";
      modal.hidden = false;
      loadLib();
    });
  });
  document.querySelectorAll("[data-theme-close-modal]").forEach(function (btn) {
    btn.addEventListener("click", function () { modal.hidden = true; });
  });
  modal.addEventListener("click", function (e) {
    if (e.target === modal) { modal.hidden = true; }
  });
  libQ.addEventListener("keydown", function (e) {
    if (e.key === "Enter") { libPage = 1; loadLib(); }
  });
})();
</script>
