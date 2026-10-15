<?php
/**
 * 插件设置页：
 * 返回链接 → 标题（manifest.title）→ 不可用提示 → 朴素单栏表单
 * 变量：$pluginName、$manifest、$error、$values
 *
 * 这里是**普通表单**，不是主题设置页那种"选项面板"：
 * 标签在上、控件在下，分组降级成小节标题，没有左侧导航、没有吸顶条、没有搜索框，
 * 也没有「有设置已修改」那条未保存胶囊（emlog 的插件设置页同样没有）。
 * 插件配置一般十几项，套面板会让每一项都要先找分组——比直接顺排还慢。
 * 字段渲染、校验、show_if、repeater 仍与主题设置页共用同一份实现
 * （partials/ + admin-schema-form.js），外壳也共用 .admin-plain-form。
 */
$pluginName = (string) ($pluginName ?? '');
$manifest = $manifest ?? null;
$error = $error ?? null;
$values = $values ?? [];
$settings = is_array($settings ?? null) ? $settings : (array) ($manifest['settings'] ?? []);
$title = $manifest['title'] ?? $pluginName;
$supportsNotificationTest = (bool) ($supportsNotificationTest ?? false);
$supportsStorageTest = (bool) ($supportsStorageTest ?? false);
$pluginActive = (bool) ($active ?? false);
$hasCustomSettingsPage = is_array($manifest) && in_array('plugin_setting', (array) ($manifest['injects'] ?? []), true);

// 字段渲染统一走共享 partial（主题页与插件页必须同一份实现）
$renderField = require __DIR__ . '/partials/schema-field.php';
$buildGroups = require __DIR__ . '/partials/schema-groups.php';

ob_start();
?>
<h1 class="admin-h1"><?= e($title) ?></h1>
<?php if ($pluginActive && is_string($manifest['frontendUrl'] ?? null) && trim((string) $manifest['frontendUrl']) !== ''): ?>
  <p class="admin-page-sub">前台地址：<a href="<?= e(url_to((string) $manifest['frontendUrl'])) ?>" target="_blank" rel="noopener noreferrer"><?= e(url_to((string) $manifest['frontendUrl'])) ?></a></p>
<?php endif; ?>
<?php
$headMain = ob_get_clean();

$fields = [];
if ($manifest !== null) {
    foreach ($settings as $field) {
        if (is_array($field) && isset($field['key'])) {
            $fields[] = $field;
        }
    }
}
$built = $buildGroups($fields);
$canConfigure = $pluginActive && $manifest !== null && $error === null && $fields !== [];
?>
<div class="admin-stack admin-plugin-settings-page">
  <nav aria-label="面包屑">
    <ol class="admin-crumb">
      <li><a href="<?= e(url_to('/admin/plugins')) ?>">插件</a></li>
      <?php /* 末项用 manifest 的显示名，与下方 h1 一致；manifest 缺失时退回目录名。 */ ?>
      <li aria-current="page"><?= e($title) ?></li>
    </ol>
  </nav>
  <p class="admin-backup-msg" id="pluginSettingMsg" hidden></p>

  <?php if ($error !== null): ?>
    <div class="card admin-empty">插件“<?= e($pluginName) ?>”不可用：<?= e($error) ?></div>
  <?php endif; ?>

  <?php if ($canConfigure): ?>
      <?php /* 与主题设置页同一套外壳（admin-plain-form + 平铺分组），区别只有一处：
             这里没有搜索框——插件配置通常十几项，滚动就能看完。
             操作按钮全部落在表单末尾的操作区，页头只留标题与描述。 */ ?>
      <form class="card admin-form-card admin-plain-form" id="pluginSettingForm">
        <div class="admin-page-head">
          <div><?= $headMain ?></div>
        </div>

        <?php /* 平铺模式：不渲染分组导航，每个分组直接作为一条小节标题顺排 */ ?>
        <?php $flat = true; require __DIR__ . '/partials/schema-nav.php'; ?>

        <?php /* 操作区：测试类动作在主操作左边，保存恒定在最右——
                 顺序在窄屏换行后也保持一致。
                 这里**不放**「有设置已修改」那条橙色胶囊：它属主题设置页的
                 面板形态（配置几十项、改一项不容易记住），插件设置页是十来项
                 的朴素表单，emlog 的插件设置页也没有这一条。 */ ?>
        <div class="admin-sf-footer">
          <div class="admin-sf-actions">
            <?php if ($supportsNotificationTest): ?>
              <button type="button" class="btn btn-success" id="pluginTestNotificationBtn">发送测试通知</button>
            <?php endif; ?>
            <?php if ($supportsStorageTest): ?>
              <button type="button" class="btn btn-success" id="pluginTestStorageBtn">测试存储连接</button>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" id="pluginSaveBtn">保存</button>
          </div>
        </div>
      </form>
  <?php else: ?>
    <?php /* 不可配置时（插件不可用 / 无设置项）：标题仍以普通页头出现 */ ?>
    <div class="card admin-form-card"><div class="admin-page-head"><div><?= $headMain ?></div></div></div>
    <?php if ($error === null && $manifest !== null): ?>
      <?php if (!$pluginActive): ?>
        <div class="card admin-empty">该插件当前未启用，请先 <a href="<?= e(url_to('/admin/plugins')) ?>">返回插件列表启用</a> 后再配置。</div>
      <?php elseif ($fields === [] && !$hasCustomSettingsPage): ?>
        <div class="card admin-empty">该插件没有可配置的设置项。</div>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

  <?php /* 扩展注入点：插件在这里补自己设置页里的额外区块（如分类管理）。 */ ?>
  <?php if ($pluginActive && $error === null): ?>
    <?= \Pafish\Services\Plugin::renderInjection('plugin_setting', ['pluginName' => $pluginName]) ?>
  <?php endif; ?>
</div>

<!-- 媒体库选择弹窗（与主题设置页共用交互） -->
<div class="admin-modal-backdrop" id="pluginMediaModal" hidden>
  <div class="admin-modal" role="dialog" aria-modal="true" aria-label="从媒体库选择">
    <div class="admin-modal-head">
      <div class="admin-modal-tabs"><span class="admin-modal-tab active">媒体库</span></div>
      <button type="button" class="admin-icon-btn" data-plugin-close-modal data-modal-close aria-label="关闭"><?= admin_icon('x', 16) ?></button>
    </div>
    <div class="admin-modal-body">
      <input class="input admin-lib-search" type="search" placeholder="搜索媒体…" data-plugin-lib-q>
      <div class="admin-lib-grid" data-plugin-lib-grid></div>
      <div class="admin-pager admin-lib-pager" data-plugin-lib-pager hidden></div>
    </div>
  </div>
</div>

<?php /* 设置页字段行为（show_if、滑块、间距、排版、repeater 等） */ ?>
<script src="<?= e(asset_url('/js/admin-schema-form.js')) ?>"></script>
<?php $pluginSettingCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($pluginSettingCsrf) ?>;
  var NAME = <?= json_encode($pluginName) ?>;
  var msg = document.getElementById("pluginSettingMsg");

  /* 结果提示优先走全局顶部居中的提示条（与 emlog 后台自带的 cocoMessage 同位置，
     长页面滚到底保存也能看到）；#pluginSettingMsg 是同一个位置上的页内兜底，
     照 emlog 把插件设置页的提示块渲染在表单上方的做法，供没有 toast 时落位。 */
  function showMsg(text, isError) {
    if (typeof window.pafishToast === "function") {
      window.pafishToast(text, isError ? "error" : "success");
      return;
    }
    msg.textContent = text;
    msg.className = "admin-backup-msg " + (isError ? "admin-backup-msg-error" : "admin-backup-msg-ok");
    msg.hidden = false;
  }
  function persistMsg(text, isError) {
    try { sessionStorage.setItem("pluginSettingMsg", JSON.stringify({ t: text, e: isError ? 1 : 0 })); } catch (e) {}
  }
  try {
    var saved = sessionStorage.getItem("pluginSettingMsg");
    if (saved) {
      sessionStorage.removeItem("pluginSettingMsg");
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
        var err = new Error((j && j.error) || "操作失败");
        err.fields = (j && j.fields) || null;
        throw err;
      });
  }

  // ---- 字段行为统一交给共享模块 ----
  // 与主题设置页共用 public/js/admin-schema-form.js：
  // show_if / 颜色 / 图片 / 滑块 / 间距 / 排版 / repeater / 字段级报错 / 提交收集。
  // 本页没有分组导航与搜索框，所以不传 searchId（模块会自行跳过）。
  PafishSchemaForm.init({
    formId: "pluginSettingForm",
    saveUrl: "/admin/plugins/save-settings",
    csrf: CSRF,
    name: NAME,
    saveBtnId: "pluginSaveBtn",
    onMessage: function (text, isError) { showMsg(text, isError); },
    beforeSubmit: function (warn) {
      var hexInputs = document.querySelectorAll(".admin-sf-hex");
      for (var i = 0; i < hexInputs.length; i++) {
        var hv = hexInputs[i].value.trim();
        if (hv !== "" && !/^#[0-9a-fA-F]{6}$/.test(hv)) {
          warn("颜色值 " + hv + " 不合法（需 #rrggbb）", true);
          return false;
        }
      }
    }
  });

  // ---- 插件测试通知 ----
  var testNotificationBtn = document.getElementById("pluginTestNotificationBtn");
  if (testNotificationBtn) {
    testNotificationBtn.addEventListener("click", function () {
      testNotificationBtn.disabled = true;
      testNotificationBtn.textContent = "发送中…";
      var fd = new FormData();
      fd.append("name", NAME);
      fd.append("_csrf", CSRF);
      post("/admin/plugins/test-notification", fd).then(function (payload) {
        var result = payload.result || {};
        var total = Number(result.total || 0);
        var succeeded = Number(result.success || 0);
        if (total === 0) throw new Error("未配置可用的通知通道");
        var failed = Array.isArray(result.results) ? result.results.filter(function (item) { return !item.ok; }) : [];
        if (failed.length) {
          var first = failed[0] || {};
          var detail = first.error || first.response || (first.status ? "HTTP " + first.status : "请求失败");
          showMsg("测试完成：" + succeeded + "/" + total + " 个通道成功。" + (first.channel || "通知通道") + "：" + detail, true);
          return;
        }
        showMsg("测试完成：" + succeeded + "/" + total + " 个通道成功", false);
      }).catch(function (err) {
        showMsg(err.message, true);
      }).finally(function () {
        testNotificationBtn.disabled = false;
        testNotificationBtn.textContent = "发送测试通知";
      });
    });
  }

  var testStorageBtn = document.getElementById("pluginTestStorageBtn");
  if (testStorageBtn) {
    testStorageBtn.addEventListener("click", function () {
      testStorageBtn.disabled = true;
      testStorageBtn.textContent = "测试中…";
      var fd = new FormData(); fd.append("name", NAME); fd.append("_csrf", CSRF);
      post("/admin/plugins/test-storage", fd).then(function (payload) {
        var r = payload.result || {};
        if (!r.ok) throw new Error(r.error || "存储连接测试失败");
        showMsg(r.message || "存储连接测试成功", false);
      }).catch(function (err) { showMsg(err.message, true); }).finally(function () {
        testStorageBtn.disabled = false; testStorageBtn.textContent = "测试存储连接";
      });
    });
  }

  // ---- 媒体库弹窗（image 字段） ----
  var modal = document.getElementById("pluginMediaModal");
  var libGrid = document.querySelector("[data-plugin-lib-grid]");
  var libPager = document.querySelector("[data-plugin-lib-pager]");
  var libQ = document.querySelector("[data-plugin-lib-q]");
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
  document.querySelectorAll("[data-plugin-close-modal]").forEach(function (btn) {
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
