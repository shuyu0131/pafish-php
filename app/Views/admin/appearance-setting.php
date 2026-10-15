<?php
/**
 * 主题设置页（带分组索引的面板）：
 * 面包屑 → 面板（吸顶工具条：标题｜未保存提示 + 搜索 → 左侧两级分组导航 + 右侧字段 → 末尾保存）
 * → 媒体库弹窗（image 字段"从媒体库选择"）
 *
 * 与插件设置页共用同一套 partial（schema-nav.php / schema-field.php +
 * admin-schema-form.js），区别只在这两页怎么用它：主题页渲染**左侧两级分组导航**
 * （设置项几十条，得靠导航检索），插件页走平铺模式（传 $flat = true）。
 * 备份与恢复不再是独立卡片，而是最后一个分组，由 __backup 合成字段渲染。
 * 变量：$themeName、$manifest、$error、$active、$values
 */
$themeName = (string) ($themeName ?? '');
$manifest = $manifest ?? null;
$error = $error ?? null;
$active = (bool) ($active ?? false);
$values = $values ?? [];
$settings = is_array($settings ?? null) ? $settings : (array) ($manifest['settings'] ?? []);
$title = $manifest['title'] ?? $themeName;

// 字段渲染统一走共享 partial（主题页与插件页必须同一份实现）
$renderField = require __DIR__ . '/partials/schema-field.php';
$buildGroups = require __DIR__ . '/partials/schema-groups.php';

// 页头内容先抓成字符串：能配置时它要「收进」面板成为面板标题栏，
// 不能配置时（主题不可用/未启用）它仍以普通页头出现，页面不至于没有标题。
// 页头只有标题本身——面板标题栏不是介绍页，简介与版本号留给主题列表去交代。
ob_start();
?>
<h1 class="admin-h1"><?= e($title) ?>
  <?php if ($active): ?><span class="badge badge-primary">当前主题</span><?php endif; ?>
</h1>
<?php
$headMain = ob_get_clean();

// 先算分组，再决定怎么渲染——字段区由 schema-nav.php 消费这些分组数据
$fields = [];
if ($manifest !== null && $active) {
    foreach ($settings as $field) {
        if (is_array($field) && isset($field['key'])) {
            $fields[] = $field;
        }
    }
}
// 该主题是否真的声明了设置项：并入备份分组之前判定，用于提示文案
$hasSettings = $fields !== [];

// 备份与恢复并入表单，成为最后一个分组。它不是真实设置项：
// type=backup 不产生值，保存/校验循环遍历的是 theme.json 的 schema
// （Theme::settingsFields），所以这个只在视图层加入的字段对写入路径不可见——
// 它不会被提交，也不会在库里凭空造出一个 __backup 键。
$fields[] = [
    'key' => '__backup',
    'label' => '设置备份',
    'type' => 'backup',
    'group' => '备份与恢复',
    // 分组图标取自组内首个声明 nav_icon 的字段（见 schema-groups.php）
    'nav_icon' => 'download',
    'desc' => $hasSettings
        ? '导出当前主题设置（JSON）留底，或导入备份覆盖——仅接受本主题声明的设置项，其他键自动忽略。“恢复默认”会清空本主题已保存的设置，回到 theme.json 声明的默认值。'
        : '该主题暂无可配置项。“恢复默认”可清除本主题此前保存过的设置。',
];
$built = $buildGroups($fields);
// 只要主题可用且已启用，表单就存在——即便一个设置项都没声明，也还有备份/恢复这一组
$canConfigure = $manifest !== null && $active;
?>
<div class="admin-stack admin-theme-settings">
  <nav aria-label="面包屑">
    <ol class="admin-crumb">
      <li><a href="<?= e(url_to('/admin/appearance')) ?>">主题</a></li>
      <li aria-current="page"><?= e($title) ?></li>
    </ol>
  </nav>
  <p class="admin-backup-msg" id="themeSettingMsg" hidden></p>

  <?php if ($error !== null): ?>
    <div class="card admin-empty">主题“<?= e($themeName) ?>”不可用：<?= e($error) ?></div>
  <?php elseif (!$active): ?>
    <div class="card admin-empty">该主题当前未启用，可先 <a href="<?= e(url_to('/admin/appearance')) ?>">回到主题列表启用</a> 后再配置。</div>
  <?php endif; ?>

  <?php if ($canConfigure): ?>
    <?php /* 分组表单：工具条 + 设置项 + 末尾保存。 */ ?>
    <form class="card admin-form-card admin-schema-form" id="themeSettingForm">
      <div class="admin-page-head">
        <div class="admin-theme-settings-title"><?= $headMain ?><span class="admin-sf-dirty" data-sf-dirty role="status" hidden>未保存</span></div>
        <div class="admin-head-actions">
          <button type="button" class="admin-sf-expandall" data-sf-expandall
                  aria-pressed="false" title="展开全部" aria-label="展开全部">
            <span class="admin-sf-expandall-ico" aria-hidden="true"><?= admin_icon('layout', 13) ?></span>
          </button>
          <div class="admin-sf-search">
            <input type="search" id="sfSearch" placeholder="搜索设置项…" autocomplete="off" data-sf-no-dirty
                   aria-label="搜索设置项">
          </div>
          <button type="submit" class="btn btn-primary">保存</button>
        </div>
      </div>

      <?php /* 面板模式（默认）：渲染左侧两级分组导航，同一时刻只显示一个分组。
               平铺模式是插件设置页的形态，这里不能传 $flat。 */ ?>
      <?php require __DIR__ . '/partials/schema-nav.php'; ?>

      <?php /* 长表单末尾保留保存入口。 */ ?>
      <div class="admin-sf-footer">
        <div class="admin-sf-actions">
          <span class="admin-sf-footer-brand"><?= e($title) ?></span>
          <button type="submit" class="btn btn-primary" id="themeSaveBtn">保存</button>
        </div>
      </div>
    </form>
  <?php else: ?>
    <?php /* 不可配置时（主题不可用 / 未启用）：标题仍以普通页头出现，
             页面不会变成没有标题的孤儿页。 */ ?>
    <div class="card admin-form-card"><div class="admin-page-head"><div><?= $headMain ?></div></div></div>
  <?php endif; ?>
</div>

<div class="admin-modal-backdrop" id="themeMediaModal" hidden>
  <div class="admin-modal" role="dialog" aria-modal="true" aria-label="从媒体库选择">
    <div class="admin-modal-head">
      <div class="admin-modal-tabs"><span class="admin-modal-tab active">媒体库</span></div>
      <button type="button" class="admin-icon-btn" data-theme-close-modal data-modal-close aria-label="关闭"><?= admin_icon('x', 16) ?></button>
    </div>
    <div class="admin-modal-body">
      <input class="input admin-lib-search" type="search" placeholder="搜索媒体…" data-theme-lib-q>
      <div class="admin-lib-grid" data-theme-lib-grid></div>
      <div class="admin-pager admin-lib-pager" data-theme-lib-pager hidden></div>
    </div>
  </div>
</div>

<?php /* 设置页字段行为（show_if、滑块、间距、排版、repeater 等） */ ?>
<script src="<?= e(asset_url('/js/admin-schema-form.js')) ?>"></script>
<?php $themeSettingCsrf = csrf_token(); ?>
<script>
(function () {
  "use strict";
  var CSRF = <?= json_encode($themeSettingCsrf) ?>;
  var NAME = <?= json_encode($themeName) ?>;
  var msg = document.getElementById("themeSettingMsg");

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
        var err = new Error((j && j.error) || "操作失败");
        // 把字段级错误带出去，便于精确定位到具体输入框
        err.fields = (j && j.fields) || null;
        throw err;
      });
  }

  // ---- 字段行为统一交给共享模块 ----
  // 分组导航 / 折叠 / show_if / 颜色 / 图片 / 滑块 / 间距 / 排版 / repeater /
  // 字段级报错 / 提交收集，全部在 public/js/admin-schema-form.js 里，
  // 与插件设置页共用同一份实现。
  PafishSchemaForm.init({
    formId: "themeSettingForm",
    saveUrl: "/admin/appearance/save",
    csrf: CSRF,
    name: NAME,
    saveBtnId: "themeSaveBtn",
    searchId: "sfSearch",
    // 保存不刷新页面，所以不要把提示写进 sessionStorage——
    // 那样用户下次打开任意页面会看到一条早就过期的「已保存」。
    // 只有导入（会 reload）才需要 persistMsg，见下方导入流程。
    onMessage: function (text, isError) { showMsg(text, isError); },
    beforeSubmit: function (warn) {
      // 颜色值的格式校验放在前端先拦一道，服务端仍会二次校验
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

  // ---- 备份与恢复（面板内 backup 字段的三个动作按钮） ----
  // 端点只有本页知道，所以 partial 只输出 data-sf-backup-act，绑定放在这里。
  var backupWrap = document.querySelector("[data-sf-backup]");
  var importInput = backupWrap ? backupWrap.querySelector("[data-sf-backup-file]") : null;
  if (backupWrap) {
    var backupMsg = backupWrap.querySelector("[data-sf-backup-msg]");
    var backupBtn = function (act) {
      return backupWrap.querySelector('[data-sf-backup-act="' + act + '"]');
    };
    // 面板内联提示：动作结果贴着按钮显示，不跳到页面顶部
    var backupSay = function (text, isError) {
      backupMsg.textContent = text;
      backupMsg.hidden = false;
      backupMsg.className = "admin-field-hint admin-sf-backup-msg "
        + (isError ? "admin-backup-msg-error" : "admin-backup-msg-ok");
    };
    // 导出：走浏览器下载，不经过 fetch（要拿 Content-Disposition 的文件名）
    backupBtn("export").addEventListener("click", function () {
      window.location.href = "/admin/appearance/export?name=" + encodeURIComponent(NAME);
    });

    // 导入：选文件 → 二次确认 → POST → 重载（值要重新渲染到表单里）
    backupBtn("import").addEventListener("click", function () { importInput.click(); });
    importInput.addEventListener("change", function () {
      if (!importInput.files || !importInput.files[0]) { return; }
      (window.pafishConfirm ? window.pafishConfirm("导入将覆盖当前主题的全部设置，确定继续吗？\n（建议先导出留底）", { title: "覆盖主题设置" }) : Promise.resolve(window.confirm("确认导入？"))).then(function (ok) {
        if (!ok) { importInput.value = ""; return; }
        var btn = backupBtn("import");
        btn.disabled = true;
        btn.textContent = "导入中…";
        var fd = new FormData();
        fd.append("name", NAME);
        fd.append("file", importInput.files[0]);
        fd.append("_csrf", CSRF);
        post("/admin/appearance/import", fd).then(function (j) {
          persistMsg("已导入 " + j.imported + "/" + j.total + " 项设置。", false);
          location.reload();
        }).catch(function (err) {
          backupSay(err.message, true);
          btn.disabled = false;
          btn.textContent = "导入设置";
          importInput.value = "";
        });
      });
    });

    // 恢复默认：清空已保存的设置（回落到 theme.json 的默认值）。
    // 破坏性操作——必须二次确认，成功后重载让表单显示默认值。
    backupBtn("reset").addEventListener("click", function () {
      (window.pafishConfirm ? window.pafishConfirm("将清空本主题已保存的全部设置并恢复默认值，确定继续吗？", { title: "恢复默认设置" }) : Promise.resolve(window.confirm("确认恢复默认？"))).then(function (ok) {
        if (!ok) { return; }
        var btn = backupBtn("reset");
        btn.disabled = true;
        btn.textContent = "恢复中…";
        var fd = new FormData();
        fd.append("name", NAME);
        fd.append("_csrf", CSRF);
        post("/admin/appearance/reset", fd).then(function (j) {
          persistMsg("已恢复默认设置（清除 " + j.cleared + " 项）。", false);
          location.reload();
        }).catch(function (err) {
          backupSay(err.message, true);
          btn.disabled = false;
          btn.textContent = "恢复默认";
        });
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
