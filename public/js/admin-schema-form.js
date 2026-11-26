/** 主题设置页和插件设置页共用的字段交互。 */
(function () {
  "use strict";

  /** data-sf-type 属于「真值在隐藏域」的类型（show_if 取当前值用） */
  var HIDDEN_VALUE_TYPES = [
    "repeater", "spacing", "typography", "background", "border",
    "button_set-value", "palette-value", "image_select-value", "image_select-multi",
    "checkboxes-value",
  ];
  /**
   * 提交时直接从隐藏域取值的类型。
   *
   * 比 HIDDEN_VALUE_TYPES 少 background / border：这两个要由各自的子控件
   * 现场合成 JSON，若同时也读隐藏域就会重复 append 同一个 key。
   */
  var HIDDEN_COLLECT_TYPES = [
    "button_set-value", "palette-value", "image_select-value", "image_select-multi",
    "checkboxes-value",
  ];
  /** 这些类型的值由各自的分组控件收集，通用循环要跳过 */
  var SKIP_IN_GENERIC_COLLECT = [
    "hex", "color-picker",
    "slider", "slider-num",
    "spacing", "typography", "repeater", "background", "border",
    "button_set", "button_set-value",
    "palette", "palette-value",
    "image_select", "image_select-value", "image_select-multi",
    "checkboxes", "checkboxes-value",
    "comp-color", "comp-hex", "comp-part",
  ];

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function init(opts) {
    opts = opts || {};
    var form = document.getElementById(opts.formId);
    if (!form) { return null; }

    // 平铺模式（单栏设置页）：所有分组同屏顺排，没有分组导航。
    // 平铺模式清除分组 hash，避免隐藏其他分组。
    var flatMode = !!form.querySelector(".admin-sf-layout-flat");

    var CSRF = opts.csrf || "";
    var settingPartLabels = {
      top: window.pafishT('js.setting_part_top'), right: window.pafishT('js.setting_part_right'),
      bottom: window.pafishT('js.setting_part_bottom'), left: window.pafishT('js.setting_part_left'),
      family: window.pafishT('js.setting_part_family'), size: window.pafishT('js.setting_part_size'),
      weight: window.pafishT('js.setting_part_weight'), line_height: window.pafishT('js.setting_part_line_height'),
      letter_spacing: window.pafishT('js.setting_part_letter_spacing'), style: window.pafishT('js.setting_part_style'),
      transform: window.pafishT('js.setting_part_transform'), color: window.pafishT('js.setting_part_color'),
      image: window.pafishT('js.setting_part_image'), repeat: window.pafishT('js.setting_part_repeat'),
      position: window.pafishT('js.setting_part_position'), attachment: window.pafishT('js.setting_part_attachment'),
      width: window.pafishT('js.setting_part_width')
    };
    var msgEl = opts.msgId ? document.getElementById(opts.msgId) : null;
    var M = Object.assign({ saving: window.pafishT("common.saving"), saved: window.pafishT("js.saved"), save: window.pafishT("common.save") }, opts.messages || {});

    function showMsg(text, isError) {
      if (typeof opts.onMessage === "function") { opts.onMessage(text, isError); return; }
      if (typeof window.pafishToast === "function") { window.pafishToast(text, isError ? "error" : "success"); return; }
      if (!msgEl) { return; }
      msgEl.textContent = text;
      msgEl.className = "admin-backup-msg " + (isError ? "admin-backup-msg-error" : "admin-backup-msg-ok");
      msgEl.hidden = false;
    }

    function post(url, fd) {
      return fetch(url, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) { return j; }
          var err = new Error((j && j.error) || window.pafishT("common.operation_failed"));
          err.fields = (j && j.fields) || null;
          throw err;
        });
    }

    // ---------- 字段级错误 ----------
    function clearFieldErrors() {
      form.querySelectorAll(".admin-sf-field.has-error").forEach(function (f) {
        f.classList.remove("has-error");
        var tip = f.querySelector(".admin-sf-field-error");
        if (tip) { tip.remove(); }
      });
      form.querySelectorAll(".admin-sf-nav-error").forEach(function (b) { b.remove(); });
    }

    function setGroupHidden(group, hidden) {
      group.classList.toggle("hidden", hidden);
      group.hidden = hidden;
    }

    function switchGroup(name) {
      // 平铺模式没有分组切换，只需把可能残留的 hidden 清掉
      if (flatMode) {
        form.querySelectorAll(".admin-sf-group").forEach(function (g) { setGroupHidden(g, false); });
        return;
      }
      form.querySelectorAll("[data-sf-tab]").forEach(function (b) {
        var on = b.dataset.sfTab === name;
        b.classList.toggle("active", on);
        b.classList.toggle("is-active", on);
        if (b.closest("[role=tablist]")) {
          b.setAttribute("aria-selected", on ? "true" : "false");
          b.setAttribute("tabindex", on ? "0" : "-1");
        } else { b.setAttribute("aria-current", on ? "true" : "false"); }
      });
      // 切到子级时把父级展开，否则当前项会藏在收起的父级里
      openParentOf(name);
      form.querySelectorAll(".admin-sf-group").forEach(function (g) {
        setGroupHidden(g, g.dataset.sfGroup !== name);
      });
      syncHash(name);
    }

    // hashchange 是异步的，自己写的 hash 不该再触发一次 switchGroup。
    // 记录 hash 更新状态，避免重复触发切换。
    var suppressHashChange = false;

    // 把当前分组写回地址栏。replaceState 不产生历史记录：
    // 否则点几次分组后"后退"要按很多次才能离开设置页。
    function syncHash(name) {
      if (flatMode || !name) { return; }
      var target = "#tab=" + encodeURIComponent(name);
      if (window.location.hash === target) { return; }
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, "", target);
      } else {
        suppressHashChange = true;
        window.location.hash = target;
      }
    }

    // ---------- #tab=父/子 锚点路由 ----------
    // 刷新页面或收藏链接后能回到同一处。用 hash 而不是 history API：
    // 后台是服务端渲染的多页应用，hash 不会触发导航，也不需要服务端配合。
    // 分组名本身含 `/`（"外观/颜色"），而 `/` 在 hash 里是合法字符，无需转义。
    var HASH_PREFIX = "#tab=";
    var paneNames = [];
    form.querySelectorAll(".admin-sf-group").forEach(function (g) {
      if (g.dataset.sfGroup) { paneNames.push(g.dataset.sfGroup); }
    });

    function groupFromHash() {
      var h = window.location.hash || "";
      if (h.indexOf(HASH_PREFIX) !== 0) { return null; }
      var raw = h.slice(HASH_PREFIX.length);
      try { raw = decodeURIComponent(raw); } catch (e) { /* 保留原样 */ }
      // 只认真实存在的分组，避免手工改成不存在的名字导致整页空白
      return paneNames.indexOf(raw) !== -1 ? raw : null;
    }

    // 展开全部状态：panel.is-expanded 一次性显示所有 section
    var expandBtn = form.querySelector("[data-sf-expandall]");
    var expanded = false;

    function applyExpanded() {
      form.classList.toggle("is-expanded", expanded);
      form.querySelectorAll(".admin-sf-group").forEach(function (g) {
        // 展开全部时所有面板可见；否则回到"只显示当前项"
        setGroupHidden(g, expanded ? false : g.dataset.sfGroup !== activeGroup());
      });
      if (expandBtn) {
        expandBtn.setAttribute("aria-pressed", expanded ? "true" : "false");
        // 图标按钮的状态通过 title、aria-label 和 CSS 高亮表达。
        expandBtn.title = expanded ? window.pafishT("js.collapse_all") : window.pafishT("js.expand_all");
        expandBtn.setAttribute("aria-label", expanded ? window.pafishT("js.collapse_all") : window.pafishT("js.expand_all"));
      }
    }

    function activeGroup() {
      var on = form.querySelector("[data-sf-tab].active");
      return on ? on.dataset.sfTab : (paneNames[0] || "");
    }

    if (expandBtn) {
      expandBtn.addEventListener("click", function () {
        expanded = !expanded;
        applyExpanded();
      });
    }

    // 启动时按 hash 定位；没有 hash 就维持默认的第一个分组
    var initial = flatMode ? null : groupFromHash();
    if (initial !== null) { switchGroup(initial); }
    else { syncHash(activeGroup()); }
    // 平铺模式已无分组语义。清掉历史遗留的 #tab=，否则刷新或从收藏进入时
    // 它会再次把别的分组藏起来（分组导航版留下的地址会被书签/前进记录沿用）。
    if (flatMode && window.history && window.history.replaceState
        && (window.location.hash || "").indexOf(HASH_PREFIX) === 0) {
      window.history.replaceState(null, "", window.location.pathname + window.location.search);
    }
    // 只认自己的 hash 前缀；地址栏被手动改动或前进/后退时同步
    window.addEventListener("hashchange", function () {
      if (flatMode) { return; }
      if (suppressHashChange) { suppressHashChange = false; return; }
      var name = groupFromHash();
      if (name !== null && name !== activeGroup()) {
        if (expanded) { expanded = false; applyExpanded(); }
        switchGroup(name);
      }
    });

    function markFieldErrors(fields) {
      clearFieldErrors();
      var first = null;
      var badGroups = {};   // 分组名 → 错误数
      Object.keys(fields || {}).forEach(function (key) {
        var input = form.querySelector('[data-sf-key="' + key + '"]');
        if (!input) { return; }
        var wrap = input.closest(".admin-sf-field");
        if (!wrap) { return; }
        wrap.classList.add("has-error");
        var tip = document.createElement("p");
        tip.className = "admin-sf-field-error";
        tip.textContent = fields[key];
        wrap.appendChild(tip);
        var grp = wrap.closest(".admin-sf-group");
        if (grp) {
          var gname = grp.dataset.sfGroup || "";
          badGroups[gname] = (badGroups[gname] || 0) + 1;
        }
        if (!first) { first = input; }
      });

      // 导航项上标出错误：否则错误藏在别的分组里，用户只看到"保存失败"却找不到位置
      var countErrors = function (btn, n) {
        var badge = btn.querySelector(".admin-sf-nav-error");
        // 一级父级的徽章要挂在按钮里的文字容器上，否则会被箭头挤位
        var host = btn.querySelector(".admin-sf-navlabel") || btn;
        if (n > 0) {
          if (!badge) {
            badge = document.createElement("span");
            badge.className = "admin-sf-nav-error";
            host.appendChild(badge);
          }
          badge.textContent = String(n);
          badge.title = n + window.pafishT("js.items_need_attention");
        } else if (badge) {
          badge.remove();
        }
      };

      // 二级：直接按名字统计
      form.querySelectorAll("[data-sf-tab]").forEach(function (btn) {
        countErrors(btn, badGroups[btn.dataset.sfTab] || 0);
      });
      // 一级：汇总它名下所有子分组的错误数（父级自身不是 [data-sf-tab]）
      form.querySelectorAll("[data-sf-navgrp]").forEach(function (grp) {
        var parent = grp.dataset.sfNavgrp;
        var total = 0;
        Object.keys(badGroups).forEach(function (g) {
          if (g.indexOf(parent + "/") === 0) { total += badGroups[g]; }
        });
        var toggle = grp.querySelector("[data-sf-toggle]");
        if (toggle) { countErrors(toggle, total); }
        // 父级下有错就展开，免得错误藏在收起的子级里
        if (total > 0) {
          grp.classList.add("is-open");
          if (toggle) { toggle.setAttribute("aria-expanded", "true"); }
        }
      });

      // 切到第一个有问题的分组，并聚焦该字段
      var firstGrp = first && first.closest ? first.closest(".admin-sf-group") : null;
      if (firstGrp && firstGrp.classList.contains("hidden")) { switchGroup(firstGrp.dataset.sfGroup); }
      else if (firstGrp && !firstGrp.classList.contains("hidden")) { /* 已在当前分组 */ }
      else if (Object.keys(badGroups).length) { switchGroup(Object.keys(badGroups)[0]); }
      if (first && first.focus) { first.focus(); }
    }

    // ---------- 脏状态：改了但没保存 ----------
    var dirtyEl = form.querySelector("[data-sf-dirty]");
    var isDirty = false;
    function setDirty(on) {
      if (isDirty === on) { return; }
      isDirty = on;
      if (dirtyEl) {
        dirtyEl.hidden = !on;
      }
    }
    form.addEventListener("input", function (e) {
      // 搜索框也在 form 内，但"搜了一下"不算"改了设置"——
      // 否则一搜索就报"有修改尚未保存"，离开页面还会被 beforeunload 拦下。
      if (e.target && e.target.closest && e.target.closest("[data-sf-no-dirty]")) { return; }
      setDirty(true);
    });
    form.addEventListener("change", function (e) {
      if (e.target && e.target.closest && e.target.closest("[data-sf-no-dirty]")) { return; }
      setDirty(true);
    });
    // 增删/复制/上下移 repeater 行不触发 input，单独兜一层
    form.addEventListener("click", function (e) {
      var t = e.target;
      if (t && t.closest && t.closest(".admin-sf-rep-add, .admin-sf-rep-del, .admin-sf-rep-up, .admin-sf-rep-down, .admin-sf-rep-copy")) {
        setDirty(true);
      }
    });
    // 带着未保存的修改离开页面时拦一道
    window.addEventListener("beforeunload", function (e) {
      if (!isDirty) { return; }
      e.preventDefault();
      e.returnValue = "";
    });
    // 站内导航也检查未保存的修改。
    document.addEventListener("click", function (e) {
      if (!isDirty) { return; }
      var a = e.target && e.target.closest && e.target.closest("a[href]");
      if (!a) { return; }
      var href = a.getAttribute("href") || "";
      // 页内锚点、新窗口、下载、纯 JS 链接不拦
      if (href.charAt(0) === "#" || a.target === "_blank" || a.hasAttribute("download")) { return; }
      if (/^(javascript:|mailto:|tel:)/i.test(href)) { return; }
      if (!window.confirm(window.pafishT("js.you_have_unsaved_settings_leave_this_page_and"))) {
        e.preventDefault();
        e.stopPropagation();
      }
    }, true);

    // ---------- 分组导航 ----------
    // 一级 = 分组入口；有子级的一级是纯容器，点击只做展开/收起，不切换内容。
    // 子级展开状态由祖先决定，所以切到子级时要把父级也展开。
    function openParentOf(name) {
      if (name.indexOf("/") === -1) { return; }
      var parent = name.split("/")[0];
      form.querySelectorAll("[data-sf-navgrp]").forEach(function (grp) {
        if (grp.dataset.sfNavgrp === parent) {
          grp.classList.add("is-open");
          var t = grp.querySelector("[data-sf-toggle]");
          if (t) { t.setAttribute("aria-expanded", "true"); }
        }
      });
    }

    form.querySelectorAll("[data-sf-toggle]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var grp = btn.closest("[data-sf-navgrp]");
        if (!grp) { return; }
        var open = grp.classList.toggle("is-open");
        btn.setAttribute("aria-expanded", open ? "true" : "false");
      });
    });

    var tabBtns = form.querySelectorAll("[data-sf-tab]");
    tabBtns.forEach(function (btn) {
      btn.addEventListener("click", function () {
        // 手动切换分组时退出"展开全部"，否则点了导航界面看起来毫无变化，很困惑
        if (expanded) { expanded = false; applyExpanded(); }
        switchGroup(btn.dataset.sfTab);
      });
    });

    form.querySelectorAll("[data-sf-tablist]").forEach(function (tabList) {
      tabList.addEventListener("keydown", function (event) {
        var tabs = Array.prototype.slice.call(tabList.querySelectorAll('[role="tab"]'));
        var current = tabs.indexOf(event.target.closest('[role="tab"]'));
        if (current < 0 || tabs.length === 0) { return; }
        var next = current;
        if (event.key === "ArrowRight") { next = (current + 1) % tabs.length; }
        else if (event.key === "ArrowLeft") { next = (current - 1 + tabs.length) % tabs.length; }
        else if (event.key === "Home") { next = 0; }
        else if (event.key === "End") { next = tabs.length - 1; }
        else { return; }
        event.preventDefault();
        tabs[next].focus();
        switchGroup(tabs[next].dataset.sfTab);
      });
    });

    // ---------- group 折叠区块 ----------
    form.querySelectorAll("[data-sf-collapse]").forEach(function (wrap) {
      var head = wrap.querySelector(".admin-sf-grouphead");
      if (!head) { return; }
      head.addEventListener("click", function () {
        var collapsed = wrap.classList.toggle("is-collapsed");
        head.setAttribute("aria-expanded", collapsed ? "false" : "true");
      });
    });

    /**
     * 把字段「暴露」出来：展开它所在的折叠区块、切到它所在的分组。
     * 原生校验失败时，如果字段藏在收起/隐藏的区域里，Chrome 无法聚焦它，
     * 于是**不显示任何提示、直接放弃提交**——用户点了保存却什么都没发生。
     */
    function revealField(el) {
      var box = el.closest && el.closest(".admin-sf-groupwrap.is-collapsed");
      while (box) {
        box.classList.remove("is-collapsed");
        var head = box.querySelector(".admin-sf-grouphead");
        if (head) { head.setAttribute("aria-expanded", "true"); }
        box = box.parentElement && box.parentElement.closest
          ? box.parentElement.closest(".admin-sf-groupwrap.is-collapsed")
          : null;
      }
      var grp = el.closest && el.closest(".admin-sf-group");
      if (grp && grp.classList.contains("hidden")) { switchGroup(grp.dataset.sfGroup); }
    }

    // invalid 事件不冒泡，使用捕获阶段监听。
    form.addEventListener("invalid", function (e) {
      if (e.target && e.target.closest) {
        var f = e.target.closest(".admin-sf-field");
        var g = f && f.closest(".admin-sf-group");
        if (g) { openParentOf(g.dataset.sfGroup || ""); }
        revealField(e.target);
      }
    }, true);

    // ---------- show_if 联动 ----------
    function getVal(key) {
      // 视觉选择型与复合型的值存放在隐藏域中。
      var hid = form.querySelector('input[type="hidden"][data-sf-key="' + key + '"]');
      if (hid && HIDDEN_VALUE_TYPES.indexOf(hid.dataset.sfType) !== -1) {
        return hid.value;
      }
      var el = form.querySelector('[data-sf-key="' + key + '"]');
      if (!el) { return null; }
      var t = el.dataset.sfType;
      if (t === "checkbox" || t === "switcher") { return el.checked ? "1" : "0"; }
      if (t === "radio") {
        var picked = form.querySelector('[data-sf-key="' + key + '"]:checked');
        return picked ? picked.value : null;
      }
      return el.value;
    }

    function matchRule(v, rule) {
      var target = rule.value;
      var op = rule.op || "eq";
      var values = rule.values || [];
      if (op === "ne") { return v !== null && v !== target; }
      if (op === "truthy") { return v !== null && v !== "" && v !== "0"; }
      if (op === "in") { return v !== null && values.indexOf(v) !== -1; }
      if (op === "notin") { return v !== null && values.indexOf(v) === -1; }
      return v === target;
    }

    function refreshShowIf() {
      form.querySelectorAll("[data-show-if], [data-show-if-key]").forEach(function (field) {
        var spec = null;
        try { spec = JSON.parse(field.dataset.showIf || "null"); } catch (e) { spec = null; }

        // 兼容：没有 data-show-if 时退回旧的单条件属性
        if (!spec || !Array.isArray(spec.rules) || spec.rules.length === 0) {
          if (!field.dataset.showIfKey) { return; }
          var legacy = [];
          try { legacy = JSON.parse(field.dataset.showIfValues || "[]"); } catch (e) { legacy = []; }
          spec = { relation: "and", rules: [{
            key: field.dataset.showIfKey,
            value: field.dataset.showIfValue,
            op: field.dataset.showIfOp || "eq",
            values: legacy
          }] };
        }

        var results = spec.rules.map(function (rule) {
          return matchRule(getVal(rule.key), rule);
        });
        // 多条件：and 全部满足 / or 任一满足（relation/logic）
        var show = spec.relation === "or"
          ? results.some(Boolean)
          : results.every(Boolean);
        field.style.display = show ? "" : "none";
      });
    }
    form.querySelectorAll("[data-sf-key]").forEach(function (el) {
      el.addEventListener("input", refreshShowIf);
      el.addEventListener("change", refreshShowIf);
    });
    refreshShowIf();

    // 长复选项组即时筛选：保留所有已勾选项，只隐藏不匹配项，
    // 这样用户搜索后不会误以为之前的选择被清空。
    form.querySelectorAll("[data-sf-checkbox-search]").forEach(function (searchInput) {
      var wrap = searchInput.closest("[data-sf-checks]");
      if (!wrap) { return; }
      var options = Array.prototype.slice.call(wrap.querySelectorAll("[data-sf-check-option]"));
      var empty = wrap.querySelector("[data-sf-checkbox-empty]");
      var count = wrap.querySelector("[data-sf-checkbox-count]");
      function applyCheckboxSearch() {
        var query = searchInput.value.trim().toLocaleLowerCase();
        var visible = 0;
        options.forEach(function (option) {
          var text = (option.dataset.sfSearchText || option.textContent)
            .replace(/\s+/g, " ").trim().toLocaleLowerCase();
          var show = query === "" || text.indexOf(query) !== -1;
          option.hidden = !show;
          if (show) { visible++; }
        });
        if (empty) { empty.hidden = visible !== 0; }
        if (count) { count.textContent = query === "" ? options.length + window.pafishT("js.items_12de8e") : window.pafishT("js.matches") + visible + window.pafishT("js.items_12de8e"); }
      }
      searchInput.addEventListener("input", applyCheckboxSearch);
      searchInput.addEventListener("keydown", function (event) {
        if (event.key === "Escape") { searchInput.value = ""; applyCheckboxSearch(); }
      });
      applyCheckboxSearch();
    });

    // ---------- color：双向同步 ----------
    form.querySelectorAll(".admin-sf-color-picker").forEach(function (picker) {
      var hex = picker.parentElement.querySelector(".admin-sf-hex");
      if (!hex) { return; }
      picker.addEventListener("input", function () { hex.value = picker.value; });
      hex.addEventListener("input", function () {
        if (/^#[0-9a-fA-F]{6}$/.test(hex.value.trim())) { picker.value = hex.value.trim(); }
      });
    });

    // 顶层和动态重复行共用同一图片控件，不依赖字段名或行号定位。
    function syncImagePreview(input) {
      var wrap = input && input.closest ? input.closest(".admin-sf-image") : null;
      var preview = wrap && wrap.querySelector("[data-sf-image-preview]");
      if (!preview) { return; }
      var value = input.value.trim();
      var previewWrap = preview.closest(".admin-sf-image-preview-wrap");
      preview.hidden = value === "";
      if (previewWrap) { previewWrap.hidden = value === ""; }
      if (value !== "") { preview.src = value; }
    }
    function initImage(wrap) {
      var input = wrap.querySelector('[data-sf-type="image"], [data-sub-type="image"]');
      if (!input) { return; }
      input.addEventListener("input", function () { syncImagePreview(input); });
      input.addEventListener("change", function () { syncImagePreview(input); });
      syncImagePreview(input);
      var clear = wrap.querySelector('.admin-sf-image-clear');
      if (clear) { clear.addEventListener('click', function () {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        refreshShowIf();
      }); }
      var btn = wrap.querySelector('.admin-sf-upload');
      var fileInput = wrap.querySelector('input[type="file"]');
      if (!btn || !fileInput) { return; }
      btn.addEventListener("click", function () { fileInput.click(); });
      fileInput.addEventListener("change", function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) { return; }
        var label = btn.textContent;
        btn.disabled = true;
        btn.textContent = window.pafishT("js.uploading");
        var fd = new FormData();
        fd.append("file", file);
        fd.append("_csrf", CSRF);
        fetch(window.pafishApi ? window.pafishApi("/upload") : "/api/upload", {
          method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" }
        }).then(function (r) { return r.json(); }).then(function (j) {
          if (!j || !j.ok || !j.url) { throw new Error((j && j.error) || window.pafishT("js.upload_failed")); }
          if (!form.contains(input)) {
            showMsg(window.pafishT('js.image_uploaded_row_changed'), false);
            return;
          }
          input.value = j.url;
          input.dispatchEvent(new Event("input", { bubbles: true }));
          input.dispatchEvent(new Event("change", { bubbles: true }));
          refreshShowIf();
          showMsg(window.pafishT("js.image_uploaded_save_settings_to_apply"), false);
        }).catch(function (err) {
          showMsg(err.message || window.pafishT("js.upload_failed"), true);
        }).finally(function () {
          btn.disabled = false;
          btn.textContent = label;
          fileInput.value = "";
        });
      });
    }
    form.querySelectorAll('.admin-sf-image').forEach(initImage);

    // ---------- slider：range ↔ number 双向 ----------
    form.querySelectorAll("[data-sf-slider-wrap]").forEach(function (wrap) {
      var range = wrap.querySelector('input[type="range"]');
      var num = wrap.querySelector(".admin-sf-range-num");
      var out = wrap.querySelector(".admin-sf-range-val");
      if (!range || !num) { return; }
      var unit = "";
      if (out && out.textContent) {
        var mt = out.textContent.match(/[^\d.\-]+$/);
        if (mt) { unit = mt[0]; }
      }
      function paint(v) { if (out) { out.textContent = v + unit; } }
      function sync(src, dst) {
        var v = src.value;
        if (v === "") { return; }
        dst.value = v;
        paint(v);
      }
      range.addEventListener("input", function () { sync(range, num); });
      num.addEventListener("input", function () {
        var v = parseFloat(num.value);
        var mn = parseFloat(range.min), mx = parseFloat(range.max);
        if (!isNaN(v) && v >= mn && v <= mx) { sync(num, range); }
      });
    });

    // ---------- repeater：动态行 ----------
    var repeaterCommits = [];
    form.addEventListener("mouseup", function () {
      form.querySelectorAll("[data-sf-repeater]").forEach(function (wrap) { if (wrap.sfDisarm) { wrap.sfDisarm(); } });
    });
    function fieldDefaults(fields) {
      var values = {};
      fields.forEach(function (sf) {
        if (sf.type === "group") { Object.assign(values, fieldDefaults(sf.fields || [])); return; }
        var value = sf.default;
        var list = sf.type === "repeater" || sf.type === "checkboxes" || (sf.type === "image_select" && sf.multiple);
        if (list || ["spacing", "typography", "background", "border"].indexOf(sf.type) !== -1) {
          try { values[sf.key] = typeof value === "string" ? JSON.parse(value || (list ? "[]" : "{}")) : value; }
          catch (e) { values[sf.key] = list ? [] : {}; }
          if (values[sf.key] == null) { values[sf.key] = list ? [] : {}; }
        } else if (sf.type !== "heading" && sf.type !== "subheading") {
          values[sf.key] = value == null ? (sf.type === "switcher" || sf.type === "checkbox" ? "0" : "") : String(value);
        }
      });
      return values;
    }
    function initRepeater(wrap) {
      var hid = wrap.querySelector('input[type="hidden"][data-sf-type="repeater"]');
      var rowsBox = wrap.querySelector(".admin-sf-rep-rows");
      var addBtn = wrap.querySelector(".admin-sf-rep-add");
      var countEl = wrap.querySelector(".admin-sf-rep-count");
      var maxItems = parseInt(wrap.dataset.sfMax || "20", 10);
      var minItems = parseInt(wrap.dataset.sfMin || "0", 10);
      var titleKey = wrap.dataset.sfTitleKey || "";
      if (!hid || !rowsBox) { return; }

      var subFields = [];
      try { subFields = JSON.parse(wrap.dataset.sfSubFields || "[]"); } catch (e) { subFields = []; }

      // 手柄图标由模板里的 admin_icon('grip') 提供（图标只在 helpers.php 定义一次）；
      // 取不到就退回一个字符，不影响拖动本身。
      var tplEl = wrap.querySelector(".admin-sf-rep-tpl");
      var dragIcon = tplEl ? tplEl.innerHTML : "⠿";

      function readRows() {
        try {
          var v = JSON.parse(hid.value || "[]");
          return Array.isArray(v) ? v : [];
        } catch (e) { return []; }
      }
      function writeRows(rows) {
        hid.value = JSON.stringify(rows);
        if (countEl) { countEl.textContent = rows.length + " / " + maxItems + window.pafishT("js.rows"); }
        if (addBtn) { addBtn.disabled = rows.length >= maxItems; }
      }
      function rowTitle(row, i) {
        var t = titleKey ? String(row[titleKey] || "").trim() : "";
        return t !== "" ? t : window.pafishT("js.no_f49534") + (i + 1) + window.pafishT("js.rows");
      }

      function renderRow(row, index, rowCount) {
        var item = document.createElement("div");
        item.className = "admin-sf-rep-row";
        item.dataset.index = String(index);

        var head = document.createElement("div");
        head.className = "admin-sf-rep-head";
        head.innerHTML = ("<span class=\"admin-sf-rep-drag\" title=\"" + window.pafishI18n.escape(window.pafishT("js.drag_to_reorder")) + "\">") + dragIcon + '</span>'
          + '<span class="admin-sf-rep-title">' + esc(rowTitle(row, index)) + '</span>'
          + '<button type="button" class="admin-sf-rep-up" aria-label="' + esc(window.pafishT('js.move_repeater_row_up', {n: index + 1})) + '"'
          + (index === 0 ? ' disabled' : '') + '>↑</button>'
          + '<button type="button" class="admin-sf-rep-down" aria-label="' + esc(window.pafishT('js.move_repeater_row_down', {n: index + 1})) + '"'
          + (index >= rowCount - 1 ? ' disabled' : '') + '>↓</button>'
          + '<button type="button" class="admin-sf-rep-copy" aria-label="' + esc(window.pafishT('js.duplicate_repeater_row', {n: index + 1})) + '"'
          + (rowCount >= maxItems ? ' disabled title="' + esc(window.pafishT('js.maximum_repeater_rows', {n: maxItems})) + '"' : '') + (">" + window.pafishI18n.escape(window.pafishT("js.duplicate")) + "</button>")
          + '<button type="button" class="admin-sf-rep-del" aria-label="' + esc(window.pafishT('js.delete_repeater_row', {n: index + 1})) + '"'
          + (rowCount <= minItems ? ' disabled title="' + esc(window.pafishT('js.minimum_repeater_rows', {n: minItems})) + '"' : '') + (">" + window.pafishI18n.escape(window.pafishT("common.delete")) + "</button>");
        item.appendChild(head);

        var body = document.createElement("div");
        body.className = "admin-sf-rep-body";
        function renderSubField(sf, host) {
          if (sf.type === "heading" || sf.type === "subheading") {
            var heading = document.createElement("h4"); heading.textContent = sf.label; host.appendChild(heading); return;
          }
          if (sf.type === "group") {
            var group = document.createElement("fieldset"); group.className = "admin-sf-rep-group";
            if (sf.show_if) { group.dataset.sfRowIf = JSON.stringify(sf.show_if); }
            var legend = document.createElement("legend"); legend.textContent = sf.label; group.appendChild(legend);
            (sf.fields || []).forEach(function (child) { renderSubField(child, group); }); host.appendChild(group); return;
          }
          var cell = document.createElement(["image", "icon", "checkboxes", "repeater", "spacing", "typography", "background", "border"].indexOf(sf.type) !== -1 ? "div" : "label");
          cell.className = "admin-sf-rep-cell";
          if (sf.show_if) { cell.dataset.sfRowIf = JSON.stringify(sf.show_if); }
          var lab = document.createElement("span");
          lab.className = "admin-sf-rep-lab";
          lab.textContent = sf.label || sf.key;
          cell.appendChild(lab);

          if (sf.type === "repeater") {
            cell.classList.add("admin-sf-rep-cell-wide");
            var child = document.createElement("div"); child.className = "admin-sf-repeater";
            child.dataset.sfRepeater = sf.key; child.dataset.sfRepNested = "1";
            child.dataset.sfMax = String(sf.max_items || 20); child.dataset.sfMin = String(sf.min_items || 0);
            child.dataset.sfTitleKey = sf.title_key || ""; child.dataset.sfSubFields = JSON.stringify(sf.fields || []);
            var hidden = document.createElement("input"); hidden.type = "hidden";
            hidden.dataset.sfType = "repeater"; hidden.dataset.subKey = sf.key; hidden.dataset.subType = "repeater";
            var childValue = row[sf.key] == null ? sf.default : row[sf.key];
            hidden.value = typeof childValue === "string" ? childValue : JSON.stringify(childValue || []);
            child.appendChild(hidden);
            var childRows = document.createElement("div"); childRows.className = "admin-sf-rep-rows"; child.appendChild(childRows);
            var childAdd = document.createElement("button"); childAdd.type = "button"; childAdd.className = "btn btn-outline btn-sm admin-sf-rep-add";
            childAdd.textContent = sf.add_label || window.pafishT("js.add_setting_row"); child.appendChild(childAdd);
            var childCount = document.createElement("span"); childCount.className = "admin-field-hint admin-sf-rep-count"; child.appendChild(childCount);
            cell.appendChild(child); host.appendChild(cell); initRepeater(child); return;
          }
          if (["spacing", "typography", "background", "border"].indexOf(sf.type) !== -1) {
            cell.classList.add("admin-sf-rep-cell-wide");
            var compound = document.createElement("div"); compound.className = "admin-sf-rep-compound";
            var parts = sf.type === "spacing" ? sf.sides || [] : sf.fields || [];
            var stored = row[sf.key] == null ? sf.default : row[sf.key];
            if (typeof stored === "string") { try { stored = JSON.parse(stored); } catch (e) { stored = {}; } }
            stored = stored || {};
            parts.forEach(function (part) {
              var partLabel = document.createElement("label"); partLabel.className = "admin-sf-rep-cell";
              var caption = document.createElement("span"); caption.textContent = settingPartLabels[part] || part; partLabel.appendChild(caption);
              var choices = (sf.choices || {})[part] || (part === "weight" ? sf.weight_options : null);
              var input = document.createElement(choices ? "select" : "input"); input.className = "input"; input.dataset.subPart = part;
              if (choices) { Object.keys(choices).forEach(function (key) { var option = document.createElement("option"); option.value = key; option.textContent = choices[key]; input.appendChild(option); }); }
              input.value = stored[part] == null ? "" : String(stored[part]); partLabel.appendChild(input); compound.appendChild(partLabel);
            });
            var compoundValue = document.createElement("input"); compoundValue.type = "hidden";
            compoundValue.dataset.subKey = sf.key; compoundValue.dataset.subType = sf.type;
            compoundValue.sfRead = function () { var value = {}; compound.querySelectorAll("[data-sub-part]").forEach(function (input) { if (input.value !== "") { value[input.dataset.subPart] = input.value; } }); return value; };
            cell.appendChild(compoundValue); cell.appendChild(compound); host.appendChild(cell); return;
          }

          // 多选使用勾选列表；候选项较多时直接在列表内搜索，
          // 不依赖原生多选框的 Ctrl/Command 操作。
          if (sf.type === "checkboxes") {
            var options = sf.options || {};
            var optionKeys = Object.keys(options);
            var selectedValues = row[sf.key];
            if (!Array.isArray(selectedValues) && typeof selectedValues === "string") {
              try { selectedValues = JSON.parse(selectedValues); } catch (e) { selectedValues = []; }
            }
            selectedValues = Array.isArray(selectedValues) ? selectedValues.map(String) : [];

            var checks = document.createElement("div");
            var searchable = optionKeys.length >= 10;
            checks.className = "admin-sf-checks admin-sf-rep-checks" + (searchable ? " is-searchable" : "");
            if (searchable) {
              var tools = document.createElement("div");
              tools.className = "admin-sf-checks-tools";
              var search = document.createElement("input");
              search.type = "search";
              search.className = "input admin-sf-checks-search";
              search.placeholder = window.pafishT("js.search_options");
              search.setAttribute("aria-label", window.pafishT("common.search") + (sf.label || sf.key) + window.pafishT("js.options"));
              search.autocomplete = "off";
              search.dataset.sfRepSearch = "";
              search.dataset.sfNoDirty = "";
              var count = document.createElement("span");
              count.className = "admin-sf-checks-count";
              count.setAttribute("aria-live", "polite");
              tools.appendChild(search);
              tools.appendChild(count);
              checks.appendChild(tools);

              var empty = document.createElement("span");
              empty.className = "admin-sf-checks-empty";
              empty.hidden = true;
              empty.textContent = window.pafishT("js.no_matching_options");
              checks.appendChild(empty);
            }

            var list = document.createElement("div");
            list.className = "admin-sf-checks-list";
            var labels = [];
            optionKeys.forEach(function (optionValue) {
              var optionLabel = String(options[optionValue]);
              var option = document.createElement("label");
              option.className = "admin-sf-check";
              option.dataset.sfRepCheckOption = "";
              option.dataset.sfSearchText = (optionValue + " " + optionLabel).toLocaleLowerCase();
              var input = document.createElement("input");
              input.type = "checkbox";
              input.checked = selectedValues.indexOf(optionValue) !== -1;
              input.dataset.subKey = sf.key;
              input.dataset.subType = "checkboxes";
              input.dataset.subValue = optionValue;
              var text = document.createElement("span");
              text.textContent = optionLabel;
              option.appendChild(input);
              option.appendChild(text);
              list.appendChild(option);
              labels.push(option);
            });
            checks.appendChild(list);

            if (searchable) {
              var searchInput = checks.querySelector("[data-sf-rep-search]");
              var countEl = checks.querySelector(".admin-sf-checks-count");
              var emptyEl = checks.querySelector(".admin-sf-checks-empty");
              function filterRepeaterChoices() {
                var query = searchInput.value.trim().toLocaleLowerCase();
                var visible = 0;
                labels.forEach(function (option) {
                  var show = query === "" || option.dataset.sfSearchText.indexOf(query) !== -1;
                  option.hidden = !show;
                  if (show) { visible++; }
                });
                countEl.textContent = query === "" ? labels.length + window.pafishT("js.items_12de8e") : window.pafishT("js.matches") + visible + window.pafishT("js.items_12de8e");
                emptyEl.hidden = visible !== 0;
              }
              searchInput.addEventListener("input", filterRepeaterChoices);
              searchInput.addEventListener("keydown", function (event) {
                if (event.key === "Escape") { searchInput.value = ""; filterRepeaterChoices(); }
              });
              filterRepeaterChoices();
            }

            cell.appendChild(checks);
            host.appendChild(cell);
            return;
          }

          var val = row[sf.key] == null ? "" : String(row[sf.key]);
          var inp;
          if (sf.type === "textarea" || sf.type === "code") {
            inp = document.createElement("textarea");
            inp.rows = Number(sf.rows || 2);
            inp.value = val;
          } else if (["select", "radio", "button_set", "image_select", "palette"].indexOf(sf.type) !== -1) {
            inp = document.createElement("select");
            if (sf.type === "image_select" && sf.multiple) { inp.multiple = true; }
            Object.keys(sf.options || {}).forEach(function (optionValue) {
              var option = document.createElement("option");
              option.value = optionValue;
              option.textContent = sf.options[optionValue];
              option.selected = inp.multiple
                ? (Array.isArray(row[sf.key]) ? row[sf.key].map(String).indexOf(optionValue) !== -1 : false)
                : optionValue === val;
              inp.appendChild(option);
            });
          } else if (sf.type === "color") {
            inp = document.createElement("input");
            inp.type = "color";
            inp.value = /^#[0-9a-fA-F]{6}$/.test(val) ? val : "#000000";
          } else if (sf.type === "slider") {
            inp = document.createElement("input");
            inp.type = "range";
            inp.min = sf.min == null ? "0" : sf.min;
            inp.max = sf.max == null ? "100" : sf.max;
            inp.step = sf.step == null ? "1" : sf.step;
            inp.value = val === "" ? (sf.default == null ? inp.min : sf.default) : val;
          } else if (sf.type === "switcher" || sf.type === "checkbox") {
            inp = document.createElement("input");
            inp.type = "checkbox";
            inp.checked = val === "1";
          } else if (sf.type === "radio") {
            inp = document.createElement("select");
            Object.keys(sf.options || {}).forEach(function (optionValue) {
              var option = document.createElement("option");
              option.value = optionValue;
              option.textContent = sf.options[optionValue];
              option.selected = optionValue === val;
              inp.appendChild(option);
            });
          } else {
            inp = document.createElement("input");
            inp.type = sf.type === "password" ? "password" : "text";
            inp.value = val;
          }
          inp.className = "input";
          inp.dataset.subKey = sf.key;
          inp.dataset.subType = sf.type || "text";
          if (sf.placeholder) { inp.placeholder = sf.placeholder; }
          if (sf.rules && sf.rules.required) { inp.required = true; }
          if (sf.rules && sf.rules.maxlength) { inp.maxLength = Number(sf.rules.maxlength); }
          if (sf.rules && sf.rules.minlength) { inp.minLength = Number(sf.rules.minlength); }
          if (sf.type === "image_select" && sf.multiple) {
            inp.classList.add("admin-sf-rep-multi");
          }
          if (sf.type === "image") {
            cell.classList.add('admin-sf-rep-cell-wide');
            var imageWrap = document.createElement('div'); imageWrap.className = 'admin-sf-image admin-sf-rep-image';
            var previewWrap = document.createElement('div'); previewWrap.className = 'admin-sf-image-preview-wrap';
            var imagePreview = document.createElement('img'); imagePreview.className = 'admin-sf-image-preview';
            imagePreview.alt = ''; imagePreview.dataset.sfImagePreview = '';
            previewWrap.appendChild(imagePreview); imageWrap.appendChild(previewWrap);
            inp.classList.add('admin-sf-image-value');
            inp.setAttribute('aria-label', sf.label || sf.key);
            inp.placeholder = sf.placeholder || window.pafishT('js.image_input_placeholder');
            imageWrap.appendChild(inp);
            [['admin-sf-lib', 'js.choose_from_media_library'], ['admin-sf-upload', 'js.upload_image'], ['admin-sf-image-clear', 'common.clear']].forEach(function (spec) {
              var button = document.createElement('button'); button.type = 'button';
              button.className = 'btn btn-outline btn-sm ' + spec[0]; button.textContent = window.pafishT(spec[1]);
              imageWrap.appendChild(button);
            });
            var uploadInput = document.createElement('input'); uploadInput.type = 'file';
            uploadInput.accept = 'image/*,.svg'; uploadInput.hidden = true; uploadInput.dataset.sfNoDirty = '';
            imageWrap.appendChild(uploadInput); cell.appendChild(imageWrap); initImage(imageWrap);
          }
          if (sf.type === "icon") {
            var iconWrap = document.createElement("span");
            iconWrap.className = "admin-sf-rep-icon";
            iconWrap.dataset.sfIconField = "";
            var preview = document.createElement("span");
            preview.className = "admin-sf-icon-preview";
            preview.dataset.sfIconPreview = "";
            preview.setAttribute("aria-hidden", "true");
            var glyph = document.createElement("i");
            glyph.className = val;
            preview.appendChild(glyph);
            inp.classList.add("admin-sf-icon-value");
            inp.dataset.iconInput = "";
            inp.dataset.iconLibrary = sf.library || "remixicon";
            inp.dataset.iconCustom = JSON.stringify(Array.isArray(sf.custom_icons) ? sf.custom_icons : []);
            inp.setAttribute("aria-label", sf.label || sf.key || window.pafishT("js.icon_class"));
            inp.placeholder = "ri-home-line / fa fa-home";
            var pick = document.createElement("button");
            pick.type = "button";
            pick.className = "btn btn-outline btn-sm";
            pick.dataset.iconPicker = "";
            pick.textContent = window.pafishT("common.select");
            var clear = document.createElement("button");
            clear.type = "button";
            clear.className = "btn btn-ghost btn-sm admin-sf-icon-clear";
            clear.dataset.iconClear = "";
            clear.textContent = window.pafishT("common.clear");
            iconWrap.appendChild(preview);
            iconWrap.appendChild(inp);
            iconWrap.appendChild(pick);
            iconWrap.appendChild(clear);
            cell.appendChild(iconWrap);
          } else if (sf.type !== 'image') {
            cell.appendChild(inp);
          }
          if (sf.desc) {
            var hint = document.createElement('span'); hint.className = 'admin-field-hint'; hint.textContent = sf.desc; cell.appendChild(hint);
          }
          host.appendChild(cell);
        }
        subFields.forEach(function (sf) { renderSubField(sf, body); });
        item.appendChild(body);
        body.addEventListener("input", commit);
        body.addEventListener("change", commit);

        head.querySelector(".admin-sf-rep-del").addEventListener("click", function () {
          commit();
          var rows = readRows();
          if (rows.length <= minItems) { return; }
          rows.splice(index, 1);
          writeRows(rows);
          renderAll();
        });
        head.querySelector(".admin-sf-rep-up").addEventListener("click", function () {
          if (index <= 0) { return; }
          commit();
          var rows = readRows();
          var t = rows[index - 1]; rows[index - 1] = rows[index]; rows[index] = t;
          writeRows(rows);
          renderAll();
        });
        head.querySelector(".admin-sf-rep-down").addEventListener("click", function () {
          commit();
          var rows = readRows();
          if (index >= rows.length - 1) { return; }
          var t = rows[index + 1]; rows[index + 1] = rows[index]; rows[index] = t;
          writeRows(rows);
          renderAll();
        });
        head.querySelector(".admin-sf-rep-copy").addEventListener("click", function () {
          if (readRows().length >= maxItems) { return; }
          // 先收集当前 DOM，确保刚编辑但还未触发 input 的控件也进入副本。
          commit();
          var rows = readRows();
          if (index >= rows.length || rows.length >= maxItems) { return; }
          var duplicate;
          try { duplicate = JSON.parse(JSON.stringify(rows[index])); }
          catch (e) { return; }
          rows.splice(index + 1, 0, duplicate);
          writeRows(rows);
          renderAll();
          var newRow = rowsBox.children[index + 1];
          var firstControl = newRow && newRow.querySelector("[data-sub-key]");
          if (firstControl && firstControl.focus) { firstControl.focus(); }
        });
        return item;
      }

      // 把 DOM 上的编辑结果写回隐藏 JSON（保留顺序）
      function commit() {
        var rows = [];
        rowsBox.querySelectorAll(".admin-sf-rep-row").forEach(function (item) {
          if (item.parentElement !== rowsBox) { return; }
          var obj = {};
          item.querySelectorAll("[data-sub-key]").forEach(function (inp) {
            if (inp.closest(".admin-sf-rep-row") !== item) { return; }
            var t = inp.dataset.subType;
            if (t === "repeater") { var childWrap = inp.parentElement; if (childWrap.sfCommit) { childWrap.sfCommit(); } try { obj[inp.dataset.subKey] = JSON.parse(inp.value); } catch (e) { obj[inp.dataset.subKey] = []; } }
            else if (inp.sfRead) { obj[inp.dataset.subKey] = inp.sfRead(); }
            else if (t === "switcher" || t === "checkbox") { obj[inp.dataset.subKey] = inp.checked ? "1" : "0"; }
            else if (t === "checkboxes") {
              if (!Object.prototype.hasOwnProperty.call(obj, inp.dataset.subKey)) { obj[inp.dataset.subKey] = []; }
              if (inp.checked) { obj[inp.dataset.subKey].push(inp.dataset.subValue || ""); }
            } else if (inp.multiple) { obj[inp.dataset.subKey] = Array.prototype.map.call(inp.selectedOptions, function (option) { return option.value; }); }
            else { obj[inp.dataset.subKey] = inp.value; }
          });
          rows.push(obj);
        });
        writeRows(rows);
        Array.from(rowsBox.children).forEach(function (item, i) {
          var t = item.querySelector(".admin-sf-rep-title");
          if (t) { t.textContent = rowTitle(rows[i] || {}, i); }
          item.querySelectorAll("[data-sf-row-if]").forEach(function (cell) {
            if (cell.closest(".admin-sf-rep-row") !== item) { return; }
            var spec; try { spec = JSON.parse(cell.dataset.sfRowIf); } catch (e) { return; }
            var results = (spec.rules || []).map(function (rule) {
              var row = rows[i] || {};
              return matchRule(Object.prototype.hasOwnProperty.call(row, rule.key) ? String(row[rule.key]) : getVal(rule.key), rule);
            });
            cell.hidden = spec.relation === "or" ? !results.some(Boolean) : !results.every(Boolean);
          });
        });
      }

      function renderAll() {
        // 若正打开的自定义下拉位于即将替换的行内，先收起并还原到所属控件。
        wrap.dispatchEvent(new CustomEvent("pafish:controls-destroy", { bubbles: true, detail: { repeater: wrap } }));
        var rows = readRows();
        rowsBox.innerHTML = "";
        rows.forEach(function (row, i) { rowsBox.appendChild(renderRow(row, i, rows.length)); });
        writeRows(rows);
        commit();
        // 每次增删、复制、移动或首次渲染后都初始化新行内的搜索下拉等共享控件。
        wrap.dispatchEvent(new CustomEvent("pafish:controls-init", { bubbles: true, detail: { repeater: wrap } }));
      }

      // ---------- 拖动排序 ----------
      // 只有按在手柄上才把行标成可拖：不这样，在输入框里选一段文字往外拉
      // 也会被当成排行。原生拖动在触屏上不触发，触屏仍走 ↑↓ 两个按钮。
      var armed = null, dragEl = null;
      rowsBox.addEventListener("mousedown", function (e) {
        var h = e.target.closest && e.target.closest(".admin-sf-rep-drag");
        if (!h || h.closest("[data-sf-repeater]") !== wrap) { return; }
        armed = h.closest(".admin-sf-rep-row");
        if (armed) { armed.draggable = true; }
      });
      // 按了手柄又没真拖（只是点了一下）——松开就撤销，不然这一行一直是可拖状态
      wrap.sfDisarm = function () {
        if (armed) { armed.draggable = false; armed = null; }
      };
      rowsBox.addEventListener("dragstart", function (e) {
        var row = e.target.closest && e.target.closest(".admin-sf-rep-row");
        if (!row || row !== armed || !row.draggable) { return; }
        dragEl = row;
        row.classList.add("is-dragging");
        e.dataTransfer.effectAllowed = "move";
        // Firefox 不写入数据就不派发后面的 dragover/drop
        try { e.dataTransfer.setData("text/plain", ""); } catch (err) {}
      });
      rowsBox.addEventListener("dragover", function (e) {
        if (!dragEl) { return; }
        e.preventDefault();
        e.dataTransfer.dropEffect = "move";
        var over = e.target.closest && e.target.closest(".admin-sf-rep-row");
        if (!over || over === dragEl || over.parentElement !== rowsBox) { return; }
        // 落点在该行的上半还是下半，决定插到它前面还是后面
        var b = over.getBoundingClientRect();
        rowsBox.insertBefore(dragEl, (e.clientY - b.top) > b.height / 2 ? over.nextSibling : over);
      });
      rowsBox.addEventListener("drop", function (e) { e.preventDefault(); });
      rowsBox.addEventListener("dragend", function () {
        if (!dragEl) { return; }
        dragEl.classList.remove("is-dragging");
        dragEl.draggable = false;
        dragEl = null;
        armed = null;
        var before = hid.value;
        commit();     // 按 DOM 顺序写回隐藏 JSON
        renderAll();  // 换序后重建行头按钮。
        if (hid.value !== before) { setDirty(true); }
      });

      if (addBtn) {
        addBtn.addEventListener("click", function () {
          commit();
          var rows = readRows();
          if (rows.length >= maxItems) { return; }
          var blank = fieldDefaults(subFields);
          rows.push(blank);
          writeRows(rows);
          renderAll();
          var last = rowsBox.querySelector(".admin-sf-rep-row:last-child input, .admin-sf-rep-row:last-child textarea");
          if (last) { last.focus(); }
        });
      }

      wrap.sfCommit = commit;
      renderAll();
      return commit;
    }
    form.querySelectorAll("[data-sf-repeater]").forEach(function (wrap) {
      if (!wrap.hasAttribute("data-sf-rep-nested")) { repeaterCommits.push(initRepeater(wrap)); }
    });

    // ---------- 视觉选择型：button_set / image_select / palette ----------
    // 三者都是「按钮组 + 一个隐藏域」，真值统一放隐藏域，
    // 收集时只读隐藏域，渲染与提交不会各说各话。
    function setHidden(key, value) {
      var hid = form.querySelector('input[type="hidden"][data-sf-key="' + key + '"]');
      if (hid) { hid.value = value; }
    }

    form.querySelectorAll('[data-sf-type="button_set"], [data-sf-type="palette"]').forEach(function (btn) {
      btn.addEventListener("click", function () {
        var key = btn.dataset.sfKey;
        var box = btn.parentElement;
        box.querySelectorAll('[data-sf-type="button_set"], [data-sf-type="palette"]').forEach(function (b) {
          b.classList.remove("is-on");
          b.setAttribute("aria-checked", "false");
        });
        btn.classList.add("is-on");
        btn.setAttribute("aria-checked", "true");
        setHidden(key, btn.dataset.sfValue || "");
        setDirty(true);
        refreshShowIf();
      });
    });

    form.querySelectorAll('[data-sf-type="image_select"]').forEach(function (btn) {
      btn.addEventListener("click", function () {
        var key = btn.dataset.sfKey;
        var box = btn.parentElement;
        var multiple = box.dataset.multiple === "1";
        if (multiple) {
          btn.classList.toggle("is-on");
          var on = btn.classList.contains("is-on");
          btn.setAttribute("aria-checked", on ? "true" : "false");
          var picked = [];
          box.querySelectorAll('[data-sf-type="image_select"].is-on').forEach(function (b) {
            picked.push(b.dataset.sfValue || "");
          });
          setHidden(key, JSON.stringify(picked));
        } else {
          box.querySelectorAll('[data-sf-type="image_select"]').forEach(function (b) {
            b.classList.remove("is-on");
            b.setAttribute("aria-checked", "false");
          });
          btn.classList.add("is-on");
          btn.setAttribute("aria-checked", "true");
          setHidden(key, btn.dataset.sfValue || "");
        }
        setDirty(true);
        refreshShowIf();
      });
    });

    form.querySelectorAll('[data-sf-type="checkboxes"]').forEach(function (box) {
      box.addEventListener("change", function () {
        var key = box.dataset.sfKey;
        var wrap = box.closest(".admin-sf-checks");
        if (!wrap) { return; }
        var picked = [];
        wrap.querySelectorAll('input[data-sf-type="checkboxes"]:checked').forEach(function (b) {
          picked.push(b.dataset.sfValue || "");
        });
        setHidden(key, JSON.stringify(picked));
        setDirty(true);
        refreshShowIf();
      });
    });

    // ---------- 复合视觉型：background / border ----------
    form.querySelectorAll("[data-sf-comp-wrap]").forEach(function (wrap) {
      // 取色器 ↔ 十六进制文本 双向同步
      wrap.querySelectorAll('[data-sf-type="comp-color"]').forEach(function (picker) {
        var hex = picker.parentElement.querySelector(".admin-sf-hex");
        if (!hex) { return; }
        picker.addEventListener("input", function () { hex.value = picker.value; });
        hex.addEventListener("input", function () {
          if (/^#[0-9a-fA-F]{3,8}$/.test(hex.value.trim())) { picker.value = hex.value.trim(); }
        });
      });
      wrap.querySelectorAll(".admin-sf-comp-clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
          var inp = btn.parentElement.querySelector("input");
          if (inp) { inp.value = ""; }
          refreshShowIf();
        });
      });
    });

    // ---------- 代码型：整理缩进 ----------
    form.querySelectorAll("[data-sf-code-wrap]").forEach(function (wrap) {
      var area = wrap.querySelector(".admin-sf-code-area");
      var btn = wrap.querySelector(".admin-sf-code-tidy");
      if (!area) { return; }
      // Tab 键插入两个空格，而不是跳出输入框
      area.addEventListener("keydown", function (e) {
        if (e.key !== "Tab") { return; }
        e.preventDefault();
        var s = area.selectionStart;
        var epos = area.selectionEnd;
        area.value = area.value.slice(0, s) + "  " + area.value.slice(epos);
        area.selectionStart = area.selectionEnd = s + 2;
      });
      if (btn) {
        btn.addEventListener("click", function () {
          // 按大括号层级重新缩进：只做空格处理，不改动内容
          var depth = 0;
          area.value = area.value.split("\n").map(function (line) {
            var t = line.trim();
            if (/^[}\])]/.test(t)) { depth = Math.max(0, depth - 1); }
            var out = t === "" ? "" : "  ".repeat(depth) + t;
            if (/[{[(]$/.test(t)) { depth++; }
            return out;
          }).join("\n");
          setDirty(true);
        });
      }
    });

    // ---------- 字段说明提示（悬停触发） ----------
    // 说明浮层：**悬停即出**，深色浮层。
    //
    // 为什么要挂到 body 而不是原地 display:block：
    //   字段行、折叠块、两列栅格都带 overflow，内嵌浮层会被裁掉；
    //   而且浮层的宽度由内容决定，原地定位无法在贴近视口边缘时翻边。
    //   所以用**全局单例**，靠 getBoundingClientRect 算位置。
    (function initTips() {
      var tips = form.querySelectorAll("[data-sf-tip]");
      if (!tips.length) { return; }

      var tip = document.createElement("div");
      tip.className = "admin-tip";
      tip.setAttribute("role", "tooltip");
      document.body.appendChild(tip);

      var current = null;
      var GAP = 9;

      function place(el) {
        var text = el.getAttribute("data-sf-tip") || "";
        if (!text) { return; }
        tip.textContent = text;
        // 先显示出来才量得到尺寸
        tip.style.left = "-9999px";
        tip.style.top = "0";
        tip.classList.add("is-on");

        var r = el.getBoundingClientRect();
        var tw = tip.offsetWidth;
        var th = tip.offsetHeight;

        // 水平：默认与触发元素居中对齐，再夹进视口，避免贴边被切
        var left = r.left + r.width / 2 - tw / 2;
        var maxLeft = window.innerWidth - tw - 8;
        if (left < 8) { left = 8; }
        if (left > maxLeft) { left = Math.max(8, maxLeft); }

        // 垂直：优先在上方；上方放不下（或会顶到顶栏）就翻到下方
        var above = r.top - th - GAP;
        var below = r.bottom + GAP;
        var flip = above < 8 && below + th < window.innerHeight - 8;
        tip.classList.toggle("is-below", flip);
        var top = flip ? below : above;
        if (top < 8) { top = 8; }

        tip.style.left = Math.round(left) + "px";
        tip.style.top = Math.round(top) + "px";
        // 箭头对准触发元素中心（浮层被夹到边缘时，中心已不对齐）
        var arrowLeft = r.left + r.width / 2 - left;
        arrowLeft = Math.max(10, Math.min(tw - 10, arrowLeft));
        tip.style.setProperty("--tip-arrow", Math.round(arrowLeft) + "px");
      }

      function show(el) {
        if (current === el) { return; }
        current = el;
        place(el);
      }
      function hide(el) {
        if (el && current !== el) { return; }
        current = null;
        tip.classList.remove("is-on", "is-below");
      }

      tips.forEach(function (el) {
        // mouseenter 不冒泡，正好避免"浮层自己冒泡又触发一遍"
        el.addEventListener("mouseenter", function () { show(el); });
        el.addEventListener("mouseleave", function () { hide(el); });
        // 键盘可达：Tab 到 ? 时同样显示（鼠标悬停之外补齐无障碍）
        el.addEventListener("focus", function () { show(el); });
        el.addEventListener("blur", function () { hide(el); });
        // 点一下也能收起，避免触屏设备上浮层赖着不走
        el.addEventListener("click", function (e) {
          e.preventDefault();
          hide(el);
        });
      });

      // 滚动/缩放时位置会失效 → 直接收起，比跟随着抖更好
      window.addEventListener("scroll", function () { hide(null); }, true);
      window.addEventListener("resize", function () { hide(null); });
      // Esc 收起
      document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") { hide(null); }
      });
    })();

    // ---------- 窄屏分组抽屉（结构降级） ----------
    // 窄屏把导航整体收掉、改成右侧滑入抽屉：
    // 侧栏是 translateX(100%) 的 off-canvas，
    // 由内容区顶部的 ☰ 唤出、点遮罩或 × 关闭。
    (function initNavDrawer() {
      var side = document.querySelector(".admin-sf-side");
      var trigger = document.querySelector("[data-sf-navtrigger]");
      var backdrop = document.querySelector("[data-sf-navbackdrop]");
      var closeBtn = document.querySelector("[data-sf-navclose]");
      if (!side || !trigger) { return; }

      // 只有窄屏才是抽屉。桌面端这些元素被 CSS 收掉，此时不该改 body 状态。
      function isNarrow() {
        return window.matchMedia("(max-width: 900px)").matches;
      }

      function open() {
        side.classList.add("is-open");
        trigger.setAttribute("aria-expanded", "true");
        if (backdrop) { backdrop.hidden = false; backdrop.classList.add("is-open"); }
      }
      function close() {
        side.classList.remove("is-open");
        trigger.setAttribute("aria-expanded", "false");
        if (backdrop) {
          backdrop.classList.remove("is-open");
          backdrop.hidden = true;
        }
      }
      function isOpen() { return side.classList.contains("is-open"); }

      trigger.addEventListener("click", function () {
        if (isOpen()) { close(); } else { open(); }
      });
      if (closeBtn) { closeBtn.addEventListener("click", close); }
      if (backdrop) { backdrop.addEventListener("click", close); }
      document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && isOpen()) { close(); }
      });

      // 选中某个分组后自动收起：否则抽屉挡着内容，用户以为没反应
      side.addEventListener("click", function (e) {
        var t = e.target.closest("[data-sf-tab]");
        if (t && isNarrow()) { close(); }
      });

      // 从窄屏切换到桌面时关闭抽屉。
      var mq = window.matchMedia("(max-width: 900px)");
      var onChange = function () { if (!isNarrow()) { close(); } };
      if (mq.addEventListener) { mq.addEventListener("change", onChange); }
      else if (mq.addListener) { mq.addListener(onChange); }
    })();

    // ---------- 搜索设置项 ----------
    // 设置项一多，靠滚动找一项是最费劲的事，所以页头右侧常驻一个搜索框
    // （只有分组多到需要导航的设置页才给，见各页模板）。
    // 搜索时**临时展开所有分组**：否则命中项若在别的分组里，用户会以为没搜到。
    var searchEl = opts.searchId ? document.getElementById(opts.searchId) : null;
    var layoutEl = form.querySelector(".admin-sf-layout") || document.querySelector(".admin-sf-layout");
    var searchLabels = [];

    function collectSearchText() {
      searchLabels = [];
      form.querySelectorAll(".admin-sf-field").forEach(function (f) {
        var lab = f.querySelector(".admin-sf-label");
        var desc = f.querySelector(".admin-sf-desc");
        var key = "";
        var first = f.querySelector("[data-sf-key]");
        if (first) { key = first.dataset.sfKey || ""; }
        // 把类型名也纳入检索：搜「边框」能找到 border 类型的字段，
        // 否则站长只能记住标签文字才能搜到。
        searchLabels.push({
          el: f,
          text: ((lab ? lab.textContent : "") + " " + (desc ? desc.textContent : "") + " "
            + key + " " + (f.dataset.sfFieldType || ""))
            .toLowerCase().replace(/\s+/g, " ")
        });
      });
    }

    // 某组内所有字段都被过滤掉时，把整组（连标题）一起收起，
    // 否则搜索后会剩下一串只有标题、下面空空的"空分组"。
    // 两种模式都要收：平铺模式靠标题认组，带导航的模式在搜索时也会平铺。
    function markEmptyGroups() {
      form.querySelectorAll(".admin-sf-group").forEach(function (g) {
        var any = false;
        Array.prototype.forEach.call(g.children, function (kid) {
          if (kid.classList.contains("admin-sf-group-title")) { return; }
          if (kid.style.display !== "none") { any = true; }
        });
        g.classList.toggle("admin-sf-group-empty", !any);
      });
    }

    // 无结果提示显示在内容区。
    var noResultEl = null;
    function setNoResult(on, query) {
      if (!on) {
        if (noResultEl) { noResultEl.hidden = true; }
        return;
      }
      if (!noResultEl) {
        var panes = form.querySelector(".admin-sf-panes") || form;
        noResultEl = document.createElement("p");
        noResultEl.className = "admin-sf-noresult";
        noResultEl.hidden = true;
        // 追加在分组之后：无结果时所有分组都已收起，它自然顶在内容区开头。
        panes.appendChild(noResultEl);
      }
      noResultEl.textContent = window.pafishT("js.no_settings_match") + query + window.pafishT("js.try_another_keyword");
      noResultEl.hidden = false;
    }

    function applySearch() {
      if (!searchEl) { return; }
      var q = searchEl.value.trim().toLowerCase();
      if (q === "") {
        searchLabels.forEach(function (item) { item.el.style.display = ""; });
        markEmptyGroups();
        setNoResult(false);
        if (layoutEl) { layoutEl.classList.remove("is-searching"); }
        // 回到搜索前的呈现：本来"展开全部"就继续展开，否则回到当前选中分组。
        if (expanded) { applyExpanded(); }
        else { switchGroup(activeGroup()); }
        return;
      }
      if (layoutEl) { layoutEl.classList.add("is-searching"); }
      // 搜索期间所有分组都展开，命中项才不会被藏在别的 tab 里
      form.querySelectorAll(".admin-sf-group").forEach(function (g) { setGroupHidden(g, false); });
      var hit = 0;
      searchLabels.forEach(function (item) {
        var on = item.text.indexOf(q) !== -1;
        item.el.style.display = on ? "" : "none";
        if (on) { hit++; }
      });
      markEmptyGroups();
      setNoResult(hit === 0, searchEl.value.trim());
    }

    if (searchEl) {
      collectSearchText();
      searchEl.addEventListener("input", applySearch);
      searchEl.addEventListener("keydown", function (e) {
        if (e.key === "Escape") { searchEl.value = ""; applySearch(); }
      });
    }

    // ---------- 工具条吸顶 ----------
    // 吸不吸由 CSS 说了算（只有模板设置页那条规则给 position:sticky），
    // 这里只补两件 CSS 做不到的事：把停靠位置对齐到顶栏的实际下沿，
    // 以及只在真的吸住那一刻才加投影——刚进页面时工具条还在卡片里，不该有浮起感。
    (function initStickyHead() {
      var head = form.querySelector(".admin-page-head");
      if (!head) { return; }
      var bar = document.querySelector(".admin-topbar");
      var ticking = false;
      function sync() {
        ticking = false;
        // 读计算值而不是自己判断断点：断点只在 CSS 里维护一份，
        // 插件设置页这类保持 static 的页面走到这里就直接退出。
        if (window.getComputedStyle(head).position !== "sticky") {
          head.classList.remove("is-stuck");
          return;
        }
        var box = bar ? bar.getBoundingClientRect() : null;
        var limit = box && box.height ? Math.round(box.bottom) : 56;
        head.style.setProperty("--sf-head-top", limit + "px");
        head.classList.toggle("is-stuck", head.getBoundingClientRect().top <= limit + 1);
      }
      function onScroll() {
        if (ticking) { return; }
        ticking = true;
        window.requestAnimationFrame(sync);
      }
      window.addEventListener("scroll", onScroll, { passive: true });
      window.addEventListener("resize", onScroll);
      sync();
    })();

    // 底部操作区静态落在表单末尾，不浮起——
    // 悬浮条会把滚动中的内容永久压住一截，静态条填到底就地保存更干净。
    // 任何滚动位置仍可由吸顶工具条上的保存按钮提交。

    // ---------- 收集为 FormData ----------
    function collectTo(fd) {
      // repeater 先把 DOM 编辑结果同步回隐藏域
      repeaterCommits.forEach(function (fn) { fn(); });

      form.querySelectorAll("[data-sf-key]").forEach(function (el) {
        var t = el.dataset.sfType;
        var key = el.dataset.sfKey;
        if (SKIP_IN_GENERIC_COLLECT.indexOf(t) !== -1) { return; }
        if (t === "color") {
          var hex = form.querySelector('.admin-sf-hex[data-sf-key="' + key + '"]');
          var v = hex ? hex.value.trim() : "";
          if (v) { fd.append(key, v); }
          return;
        }
        if (t === "checkbox" || t === "switcher") { fd.append(key, el.checked ? "1" : "0"); return; }
        if (t === "radio") { if (el.checked) { fd.append(key, el.value); } return; }
        fd.append(key, el.value.trim());
      });

      // 视觉选择型：真值在隐藏域
      form.querySelectorAll('input[type="hidden"][data-sf-key]').forEach(function (hid) {
        if (HIDDEN_COLLECT_TYPES.indexOf(hid.dataset.sfType) === -1) { return; }
        fd.append(hid.dataset.sfKey, hid.value || "");
      });

      // 复合视觉型：从各子控件合成 JSON
      form.querySelectorAll("[data-sf-comp-wrap]").forEach(function (wrap) {
        var obj = {};
        wrap.querySelectorAll("[data-sf-part]").forEach(function (inp) {
          // 颜色以十六进制文本框为准，取色器只是它的可视化入口
          if (inp.dataset.sfType === "comp-color") { return; }
          var v = (inp.value || "").trim();
          if (v !== "") { obj[inp.dataset.sfPart] = v; }
        });
        fd.append(wrap.dataset.sfCompWrap, JSON.stringify(obj));
      });

      form.querySelectorAll("[data-sf-slider-wrap]").forEach(function (wrap) {
        var num = wrap.querySelector(".admin-sf-range-num");
        if (num) { fd.append(num.dataset.sfKey, num.value.trim()); }
      });

      form.querySelectorAll("[data-sf-spacing-wrap]").forEach(function (wrap) {
        var obj = {};
        wrap.querySelectorAll("[data-sf-side]").forEach(function (inp) {
          var v = inp.value.trim();
          if (v !== "") { obj[inp.dataset.sfSide] = v; }
        });
        fd.append(wrap.dataset.sfSpacingWrap, JSON.stringify(obj));
      });

      form.querySelectorAll("[data-sf-typo-wrap]").forEach(function (wrap) {
        var obj = {};
        wrap.querySelectorAll("[data-sf-part]").forEach(function (inp) {
          var v = (inp.value || "").trim();
          if (v !== "") { obj[inp.dataset.sfPart] = v; }
        });
        fd.append(wrap.dataset.sfTypoWrap, JSON.stringify(obj));
      });

      form.querySelectorAll("[data-sf-repeater]").forEach(function (wrap) {
        if (wrap.hasAttribute("data-sf-rep-nested")) { return; }
        var hid = wrap.querySelector('input[type="hidden"][data-sf-type="repeater"]');
        if (hid) { fd.append(hid.dataset.sfKey, hid.value || "[]"); }
      });
      return fd;
    }

    // ---------- 提交 ----------
    // 提交飞行途中锁住可改区域：CSS 的 .is-saving 负责变淡与 pointer-events，
    // 在标记错误前恢复 inert，便于聚焦错误字段。
    var saving = false;
    var savePanes = form.querySelector(".admin-sf-panes");
    var saveSide = form.querySelector(".admin-sf-side");
    function setSaving(on) {
      saving = on;
      form.classList.toggle("is-saving", on);
      [savePanes, saveSide].forEach(function (el) {
        if (!el) { return; }
        try { el.inert = on; } catch (e) { /* 老浏览器不支持就略过，CSS 那半仍然生效 */ }
      });
    }

    // 保存按钮：原生校验不通过时自己接管，保证一定有可见反馈。
    // 收起区块中的字段也要显示校验错误。
    // 页头与表单末尾各有一个保存按钮，守卫要绑定到全部 submit 按钮上，
    // 不能只认页尾那一个。
    form.querySelectorAll('button[type="submit"]').forEach(function (guardBtn) {
      guardBtn.addEventListener("click", function (e) {
        if (form.checkValidity()) { return; }
        e.preventDefault();
        var first = form.querySelector(":invalid");
        if (first) {
          revealField(first);
          if (first.focus) { first.focus(); }
          if (first.reportValidity) { first.reportValidity(); }
        }
        showMsg(window.pafishT("js.a_required_field_is_missing_or_invalid_the"), true);
      });
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (saving) { return; }   // 飞行中再点一次不重复提交
      if (typeof opts.beforeSubmit === "function" && opts.beforeSubmit(showMsg) === false) { return; }

      var fd = new FormData();
      fd.append("_csrf", CSRF);
      if (opts.name) { fd.append(opts.nameField || "name", opts.name); }
      collectTo(fd);

      var btns = form.querySelectorAll('button[type="submit"]');
      btns.forEach(function (b) { b.disabled = true; b.textContent = M.saving; });
      setSaving(true);

      post(opts.saveUrl, fd).then(function (j) {
        setSaving(false);   // 先解锁：下面可能要切分组、聚焦字段
        clearFieldErrors();
        setDirty(false);
        showMsg(M.saved, false);
        if (typeof opts.onSaved === "function") { opts.onSaved(j); }
      }).catch(function (err) {
        setSaving(false);
        if (err.fields) {
          markFieldErrors(err.fields);
          showMsg(err.message + window.pafishT("js.located") + Object.keys(err.fields).length + window.pafishT("js.fields"), true);
        } else {
          showMsg(err.message, true);
        }
      }).finally(function () {
        setSaving(false);
        btns.forEach(function (b) { b.disabled = false; b.textContent = M.save; });
      });
    });

    return {
      refreshShowIf: refreshShowIf,
      markFieldErrors: markFieldErrors,
      clearFieldErrors: clearFieldErrors,
      switchGroup: switchGroup,
      collectTo: collectTo,
      setDirty: setDirty,
      showMsg: showMsg
    };
  }

  window.PafishSchemaForm = { init: init, esc: esc };
})();
