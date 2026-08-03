/**
 * 页面编辑器（对齐 Node page-editor 的 MdEditor 精简版）：
 * - Markdown 工具栏（加粗/斜体/标题/引用/代码/列表/链接/图片/表格/分隔线）
 * - 编辑 / 分栏 / 预览三模式（预览走 /api/md-preview 服务端渲染）
 * - 标题 → slug 自动联动（手动改过后不再覆盖）；Ctrl+S 存草稿
 * 配置：window.PAFISH_PAGE_EDITOR { saveUrl, listUrl, previewUrl, isEdit, initialSlug, csrf }
 */
(function () {
  "use strict";
  var DATA = window.PAFISH_PAGE_EDITOR || {};
  var CSRF = DATA.csrf || "";

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function slugify(s) {
    return String(s || "")
      .trim()
      .toLowerCase()
      .replace(/\s+/g, "-")
      .replace(/[^\p{L}\p{N}_-]/gu, "")
      .replace(/-+/g, "-")
      .replace(/^-+|-+$/g, "");
  }

  // ---------- 状态与 DOM ----------
  var form = $("#pageForm");
  var title = $("#fTitle");
  var slug = $("#fSlug");
  var content = $("#fContent");
  var mdBody = $(".admin-md-body");
  var preview = $(".admin-md-preview");
  var errorBox = $(".admin-editor-error");
  var slugTouched = false;
  var mode = "live";       // edit | live | preview
  var previewTimer = null;
  var lastRendered = null;
  var pending = null;      // draft | publish

  function showError(msg) { if (errorBox) { errorBox.textContent = msg; errorBox.hidden = false; } }
  function clearError() { if (errorBox) errorBox.hidden = true; }

  // ---------- Markdown 插入（光标处，与文章编辑器同逻辑） ----------
  function insertMd(md) {
    var start = content.selectionStart != null ? content.selectionStart : content.value.length;
    var end = content.selectionEnd != null ? content.selectionEnd : start;
    var before = content.value.slice(0, start);
    var insert = (before === "" || /(?:\n\n|\n)$/.test(before)) ? md + "\n" : "\n\n" + md + "\n";
    content.setRangeText(insert, start, end, "end");
    content.focus();
    refreshSoon();
  }

  function wrapSel(pre, post, placeholder) {
    var start = content.selectionStart, end = content.selectionEnd;
    var sel = content.value.slice(start, end) || placeholder;
    content.setRangeText(pre + sel + post, start, end, "end");
    var innerStart = start + pre.length;
    content.setSelectionRange(innerStart, innerStart + placeholder.length);
    content.focus();
    refreshSoon();
  }

  function applyBlock(fn) {
    var start = content.selectionStart, end = content.selectionEnd;
    var v = content.value;
    var ls = v.lastIndexOf("\n", start - 1) + 1;
    var le = v.indexOf("\n", end);
    if (le === -1) le = v.length;
    var lines = v.slice(ls, le).split("\n");
    var out = fn(lines);
    if (out === null) return;
    content.setRangeText(out.join("\n"), ls, le, "end");
    content.focus();
    refreshSoon();
  }

  var TOOLBAR = {
    bold: function () { wrapSel("**", "**", "加粗文字"); },
    italic: function () { wrapSel("*", "*", "斜体文字"); },
    strike: function () { wrapSel("~~", "~~", "删除线文字"); },
    "inline-code": function () { wrapSel("`", "`", "code"); },
    link: function () { wrapSel("[", "](https://)", "链接文字"); },
    image: function () { wrapSel("![", "](https://)", "图片描述"); },
    quote: function () { applyBlock(function (lines) { return lines.map(function (l) { return l ? "> " + l : l; }); }); },
    code: function () { applyBlock(function (lines) { return ["```", lines.join("\n"), "```"]; }); },
    ul: function () { applyBlock(function (lines) {
      if (lines.some(function (l) { return /^\s*[-*+]\s+/.test(l); })) return null;
      return lines.map(function (l) { return l ? "- " + l : l; });
    }); },
    ol: function () { applyBlock(function (lines) {
      if (lines.some(function (l) { return /^\s*\d+\.\s+/.test(l); })) return null;
      var n = 0;
      return lines.map(function (l) { return l ? (++n) + ". " + l : l; });
    }); },
    table: function () { applyBlock(function (lines) {
      return lines.length <= 1 && lines[0] === ""
        ? ["| 列 1 | 列 2 |", "| --- | --- |", "| 内容 | 内容 |"]
        : [lines.join("\n"), "", "| 列 1 | 列 2 |", "| --- | --- |", "| 内容 | 内容 |"];
    }); },
    hr: function () { insertMd("---"); },
    heading: function (n) { applyBlock(function (lines) {
      if (n === "p") return lines.map(function (l) { return l.replace(/^#{1,6}\s+/, ""); });
      var mark = Array(parseInt(n, 10) + 1).join("#");
      return lines.map(function (l) { return l ? mark + " " + l.replace(/^#{1,6}\s+/, "") : l; });
    }); },
  };

  // ---------- 预览 ----------
  function refreshSoon() { schedulePreview(400); }
  function schedulePreview(delay) {
    if (mode === "edit") return;
    if (content.value === lastRendered) return;
    clearTimeout(previewTimer);
    previewTimer = setTimeout(doPreview, delay);
  }
  function doPreview() {
    var val = content.value;
    lastRendered = val;
    fetch(DATA.previewUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
      body: JSON.stringify({ content: val, _csrf: CSRF }),
    })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.html != null) preview.innerHTML = d.html; })
      .catch(function () { /* 预览失败不打扰 */ });
  }

  function setMode(m) {
    mode = m;
    $$(".admin-md-btn[data-mode]").forEach(function (b) {
      b.classList.toggle("admin-md-mode-active", b.getAttribute("data-mode") === m);
    });
    mdBody.classList.remove("admin-md-mode-edit", "admin-md-mode-live", "admin-md-mode-preview");
    mdBody.classList.add("admin-md-mode-" + m);
    content.hidden = m === "preview";
    preview.hidden = m === "edit";
    if (m !== "edit") doPreview();
  }

  // ---------- 保存 ----------
  function submit(action) {
    if (pending) return;
    clearError();
    if (!title.value.trim()) { showError("请填写页面标题"); return; }
    pending = action;
    var fd = new FormData(form);
    fd.set("_csrf", CSRF);
    fd.set("status", action === "publish" ? "PUBLISHED" : "DRAFT");
    fetch(form.action, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json().catch(function () { return null; }).then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        pending = null;
        if (res.ok && res.j && res.j.ok) {
          location.href = DATA.listUrl; // 保存成功回列表（与 Node 保存行为一致）
        } else {
          showError((res.j && res.j.error) || "保存失败，请重试");
        }
      })
      .catch(function () { pending = null; showError("网络错误，保存失败"); });
  }

  // ---------- 绑定 ----------
  $$(".admin-md-btn[data-md]").forEach(function (b) {
    b.addEventListener("click", function () {
      var cmd = b.getAttribute("data-md");
      var fn = TOOLBAR[cmd];
      if (cmd === "heading") return; // select 单独绑定
      if (fn) fn();
      else if (cmd === "media") { /* M3f 接入媒体弹窗 */ }
    });
  });
  var headingSel = $(".admin-md-select[data-md='heading']");
  if (headingSel) headingSel.addEventListener("change", function () {
    TOOLBAR.heading(headingSel.value);
    headingSel.value = "h2";
  });
  $$(".admin-md-btn[data-mode]").forEach(function (b) {
    b.addEventListener("click", function () { setMode(b.getAttribute("data-mode")); });
  });
  setMode("live");

  // 标题 → slug 联动（仅新建且未手动改过；对齐文章编辑器行为）
  title.addEventListener("input", function () {
    if (!slugTouched) slug.value = slugify(title.value);
  });
  slug.addEventListener("input", function () { slugTouched = true; });

  content.addEventListener("input", refreshSoon);

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var btn = document.activeElement && document.activeElement.getAttribute("data-save");
    submit(btn === "publish" ? "publish" : "draft");
  });

  // 保存按钮（data-save）+ Ctrl+S 存草稿
  $$("[data-save]").forEach(function (b) {
    b.addEventListener("click", function () { submit(b.getAttribute("data-save")); });
  });
  window.addEventListener("keydown", function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "s") {
      e.preventDefault();
      submit("draft");
    }
  });
})();
