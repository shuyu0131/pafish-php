/**
 * 文章编辑器（对齐 Node post-editor.tsx + category-select.tsx + media-picker.tsx）：
 * - Markdown 工具栏（加粗/斜体/删除线/标题/引用/代码/列表/链接/图片/表格/分隔线）
 * - 编辑 / 分栏 / 预览 三模式（预览走 /api/md-preview 服务端渲染）
 * - slug 联动（未手动修改时随标题生成）、标签点选+新建、封面上传/媒体库
 * - 高级选项：定时发布、置顶、访问密码、外链、分类内置顶、自定义字段
 * - 提交校验 → AJAX 保存 → 跳转编辑页（对齐 Node redirect）
 * - Ctrl+S 快速存草稿；编辑模式每 60 秒自动保存（dirty 检测）
 * - 拖拽/粘贴图片上传；媒体弹窗（本地上传 / 媒体库 24/页 + 500ms 防抖搜索）
 */
(function () {
  "use strict";

  var DATA = window.PAFISH_EDITOR_DATA || {};
  var CSRF = window.PAFISH_EDITOR_CSRF || "";
  if (!DATA.initial) return;

  // ---------- 小工具 ----------
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function isImage(mime) { return (mime || "").indexOf("image/") === 0; }
  function fmtSize(b) {
    if (b < 1024) return b + " B";
    if (b < 1048576) return (b / 1024).toFixed(1) + " KB";
    return (b / 1048576).toFixed(1) + " MB";
  }
  function nowTime() {
    return new Date().toLocaleTimeString("zh-CN", { hour: "2-digit", minute: "2-digit" });
  }
  // 与 PHP Slug::slugify 一致：小写、空白→连字符、仅保留字母数字_-
  function slugify(s) {
    var out = String(s).toLowerCase()
      .replace(/\s+/g, "-")
      .replace(/[^\p{L}\p{N}_-]/gu, "")
      .replace(/-+/g, "-")
      .replace(/^-+|-+$/g, "");
    return out || "post-" + Math.floor(Date.now() / 1000);
  }

  // ---------- 状态 ----------
  var initial = DATA.initial || {};
  var isEdit = !!DATA.isEdit;
  var categories = DATA.categories || [];
  var allTags = DATA.tags || [];

  var slugTouched = false;
  var tagIds = (initial.tagIds || []).map(String);
  var newTagNames = [];
  var pending = null;          // null | draft | publish | schedule | auto
  var lastSavedAt = null;      // 'HH:mm'
  var autosaveFailed = false;
  var currentCategory = String(initial.categoryId || "");
  var mode = "live";           // edit | live | preview
  var previewTimer = null;
  var lastRendered = null;
  var modal = { open: false, mode: "insert", tab: "upload", page: 1, q: "", total: 0, loading: false, searchTimer: null };
  var catOpen = false;
  var catQuery = "";
  var catActive = 0;

  // ---------- DOM ----------
  var els = {
    form: $("#postForm"),
    title: $("#fTitle"),
    slug: $("#fSlug"),
    excerpt: $("#fExcerpt"),
    content: $("#fContent"),
    preview: $(".admin-md-preview"),
    mdBody: $(".admin-md-body"),
    coverUrl: $("#fCoverUrl"),
    coverPreview: $("[data-cover-preview]"),
    coverImg: $("[data-cover-preview] img"),
    coverFile: $("#coverFile"),
    newCatWrap: $("[data-new-cat-wrap]"),
    newCatInput: $("#fNewCategory"),
    catSelect: $("#catSelect"),
    tagsSelected: $("#tagsSelected"),
    tagsAll: $("#tagsAll"),
    tagInput: $("#fTagInput"),
    customFields: $("#customFields"),
    errorBox: $(".admin-editor-error"),
    autosave: $("[data-autosave]"),
    scheduledAt: $("#fScheduledAt"),
    pinned: $("#fPinned"),
    catPinned: $("#fCatPinned"),
    password: $("#fPassword"),
    removePassword: $("#fRemovePassword"),
    externalUrl: $("#fExternalUrl"),
    modalEl: $("#mediaModal"),
    modalError: $("[data-mupload-error]"),
    libGrid: $("[data-lib-grid]"),
    libPager: $("[data-lib-pager]"),
    libQ: $("[data-lib-q]"),
    mediaFile: $("#mediaFile"),
  };

  // ---------- 表单值 / 脏检测（对齐 Node dirty 计算） ----------
  function collectCustomFields() {
    return $$("[data-cf-key]", els.customFields).map(function (row) {
      return { key: row.value.trim(), value: $( "[data-cf-value]", row.closest(".admin-cf-row") ).value.trim() };
    }).filter(function (f) { return f.key !== "" || f.value !== ""; });
  }
  function isDirty() {
    return els.title.value !== (initial.title || "") ||
      els.slug.value !== (initial.slug || "") ||
      els.excerpt.value !== (initial.excerpt || "") ||
      els.content.value !== (initial.content || "") ||
      els.coverUrl.value !== (initial.coverUrl || "") ||
      currentCategory !== (initial.categoryId || "") ||
      JSON.stringify(tagIds) !== JSON.stringify(initial.tagIds || []) ||
      newTagNames.length > 0 ||
      currentCategory === "__new__" ||
      els.pinned.checked !== !!initial.isPinned ||
      els.password.value.trim() !== "" ||
      !!(els.removePassword && els.removePassword.checked) ||
      els.externalUrl.value !== (initial.externalUrl || "") ||
      els.catPinned.checked !== !!initial.categoryPinned ||
      JSON.stringify(collectCustomFields()) !== JSON.stringify(initial.customFields || [{ key: "", value: "" }]);
  }

  // ---------- 提示 ----------
  function showError(msg) {
    els.errorBox.textContent = msg;
    els.errorBox.hidden = false;
  }
  function clearError() { els.errorBox.hidden = true; }
  function updatePendingUI() {
    $$("[data-save]").forEach(function (b) {
      b.disabled = pending !== null;
      if (pending === b.getAttribute("data-save")) {
        b.dataset.origText = b.dataset.origText || b.textContent;
        b.textContent = "保存中…";
      } else if (b.dataset.origText) {
        b.textContent = b.dataset.origText;
      }
    });
    if (!pending) {
      $$("[data-save]").forEach(function (b) { delete b.dataset.origText; });
    }
  }
  function updateAutosave() {
    if (!els.autosave) return;
    if (pending === "auto") {
      els.autosave.textContent = "自动保存中…";
    } else if (autosaveFailed) {
      els.autosave.textContent = "自动保存失败，请手动保存";
    } else if (lastSavedAt !== null) {
      els.autosave.textContent = "已自动保存 " + lastSavedAt + (isDirty() ? "（还有未保存更改）" : "");
    } else {
      els.autosave.textContent = "内容将每 60 秒自动保存，防止意外丢失";
    }
  }

  // ---------- Markdown 插入（光标处） ----------
  function insertMd(md) {
    var ta = els.content;
    var start = ta.selectionStart != null ? ta.selectionStart : ta.value.length;
    var end = ta.selectionEnd != null ? ta.selectionEnd : start;
    var before = ta.value.slice(0, start);
    var insert = (before === "" || /(?:\n\n|\n)$/.test(before)) ? md + "\n" : "\n\n" + md + "\n";
    ta.setRangeText(insert, start, end, "end");
    ta.focus();
    refreshSoon();
  }

  // 行级变换（选区为空时作用于光标所在行）
  function applyBlock(fn) {
    var ta = els.content;
    var start = ta.selectionStart, end = ta.selectionEnd;
    var v = ta.value;
    var ls = v.lastIndexOf("\n", start - 1) + 1;
    var le = v.indexOf("\n", end);
    if (le === -1) le = v.length;
    var lines = v.slice(ls, le).split("\n");
    var out = fn(lines);
    if (out === null) return; // 命令放弃（如已是列表项）
    ta.setRangeText(out.join("\n"), ls, le, "end");
    ta.focus();
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
      var any = lines.some(function (l) { return /^\s*[-*+]\s+/.test(l); });
      if (any) return null;
      return lines.map(function (l) { return l ? "- " + l : l; });
    }); },
    ol: function () { applyBlock(function (lines) {
      var any = lines.some(function (l) { return /^\s*\d+\.\s+/.test(l); });
      if (any) return null;
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
      if (n === "p") {
        return lines.map(function (l) { return l.replace(/^#{1,6}\s+/, ""); });
      }
      var mark = Array(parseInt(n, 10) + 1).join("#");
      return lines.map(function (l) { return l ? mark + " " + l.replace(/^#{1,6}\s+/, "") : l; });
    }); },
  };

  function wrapSel(pre, post, placeholder) {
    var ta = els.content;
    var start = ta.selectionStart, end = ta.selectionEnd;
    var sel = ta.value.slice(start, end) || placeholder;
    ta.setRangeText(pre + sel + post, start, end, "end");
    if (!ta.value.slice(start, end)) {
      var innerStart = start + pre.length;
      ta.setSelectionRange(innerStart, innerStart + placeholder.length);
    }
    ta.focus();
    refreshSoon();
  }

  // ---------- 预览（服务端 Parsedown） ----------
  function refreshSoon() { schedulePreview(400); }
  function schedulePreview(delay) {
    if (mode === "edit") return;
    var content = els.content.value;
    if (content === lastRendered) return;
    clearTimeout(previewTimer);
    previewTimer = setTimeout(doPreview, delay);
  }
  function doPreview() {
    var content = els.content.value;
    lastRendered = content;
    fetch(DATA.previewUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
      body: JSON.stringify({ content: content, _csrf: CSRF }),
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.html != null) els.preview.innerHTML = d.html;
      })
      .catch(function () { /* 预览失败不打扰 */ });
  }

  function setMode(m) {
    mode = m;
    $$(".admin-md-btn[data-mode]").forEach(function (b) {
      b.classList.toggle("admin-md-mode-active", b.getAttribute("data-mode") === m);
    });
    els.mdBody.classList.remove("admin-md-mode-edit", "admin-md-mode-live", "admin-md-mode-preview");
    els.mdBody.classList.add("admin-md-mode-" + m);
    els.content.hidden = m === "preview";
    els.preview.hidden = m === "edit";
    if (m !== "edit") doPreview();
  }

  // ---------- 保存 ----------
  function buildPayload(action) {
    var p = new URLSearchParams();
    p.set("_csrf", CSRF);
    p.set("action", action);
    p.set("title", els.title.value.trim());
    p.set("slug", els.slug.value.trim());
    p.set("excerpt", els.excerpt.value.trim());
    p.set("content", els.content.value);
    p.set("cover_url", els.coverUrl.value.trim());
    p.set("category_id", currentCategory !== "" && currentCategory !== "__new__" ? currentCategory : "");
    p.set("new_category", currentCategory === "__new__" ? els.newCatInput.value.trim() : "");
    p.set("tag_ids", JSON.stringify(tagIds.map(Number)));
    p.set("new_tags", JSON.stringify(newTagNames));
    p.set("is_pinned", els.pinned.checked ? "1" : "");
    p.set("category_pinned", els.catPinned.checked ? "1" : "");
    p.set("password", els.password.value);
    p.set("remove_password", els.removePassword && els.removePassword.checked ? "1" : "");
    p.set("external_url", els.externalUrl.value.trim());
    p.set("custom_fields", JSON.stringify(collectCustomFields()));
    if (action === "schedule") p.set("scheduled_at", els.scheduledAt.value);
    return p;
  }

  function submit(action) {
    if (pending) return;
    clearError();
    if (!els.title.value.trim()) { showError("请填写标题"); return; }
    if (!els.content.value.trim()) { showError("请填写正文内容"); return; }
    if (action === "schedule" && !els.scheduledAt.value) { showError("请选择定时发布时间"); return; }
    pending = action;
    updatePendingUI();

    fetch(DATA.saveUrl, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: buildPayload(action),
    })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error || "保存失败");
        // 对齐 Node：保存后跳转到编辑页（服务端最新状态）
        window.location.href = DATA.editUrl.replace("{id}", d.id);
      })
      .catch(function (e) {
        showError(e.message || "保存失败");
        pending = null;
        updatePendingUI();
      });
  }

  // 自动保存：仅编辑模式，每 60 秒（dirty 且标题/正文非空时）
  function checkAutosave() {
    if (!isEdit || pending) return;
    if (!isDirty() || !els.title.value.trim() || !els.content.value.trim()) return;
    pending = "auto";
    updateAutosave();
    fetch(DATA.saveUrl, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: buildPayload("auto"),
    })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error || "自动保存失败");
        lastSavedAt = nowTime();
        autosaveFailed = false;
      })
      .catch(function () { autosaveFailed = true; })
      .then(function () {
        pending = null;
        updateAutosave();
      });
  }

  // ---------- 分类选择（对齐 category-select.tsx） ----------
  function catOptionList() {
    var q = catQuery.trim().toLowerCase();
    var list = [{ value: "", label: "无分类", depth: -1 }];
    categories.forEach(function (c) {
      if (!q || c.name.toLowerCase().indexOf(q) !== -1) {
        list.push({ value: String(c.id), label: c.name, depth: c.depth });
      }
    });
    list.push({ value: "__new__", label: "＋ 新建分类…", depth: -1 });
    return list;
  }
  function catCurrentLabel() {
    if (currentCategory === "__new__") return "＋ 新建分类…";
    if (currentCategory === "") return "无分类";
    var hit = categories.filter(function (c) { return String(c.id) === currentCategory; })[0];
    return hit ? hit.name : "无分类";
  }
  function renderCatSelect() {
    var list = catOptionList();
    els.catSelect.dataset.open = catOpen ? "1" : "0";
    var html = '<button type="button" class="admin-cat-trigger" id="catTrigger">' +
      '<span class="admin-cat-label">' + esc(catCurrentLabel()) + "</span>" +
      '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" class="admin-cat-chevron"><path d="m6 9 6 6 6-6"/></svg></button>';
    if (catOpen) {
      html += '<div class="admin-cat-panel">';
      if (categories.length >= 6) {
        html += '<div class="admin-cat-search"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>' +
          '<input class="input" id="catSearch" placeholder="搜索分类…" value="' + esc(catQuery) + '"></div>';
      }
      html += '<div class="admin-cat-list" role="listbox">';
      if (list.length === 0) html += '<p class="admin-cat-empty">没有匹配的分类</p>';
      list.forEach(function (opt, i) {
        var sel = opt.value === currentCategory;
        html += '<button type="button" role="option" data-cat-val="' + esc(opt.value) + '"' +
          ' class="admin-cat-item' + (i === catActive ? " admin-cat-active" : "") + (sel ? " admin-cat-selected" : "") + '"' +
          ' style="padding-left:' + (opt.depth > 0 ? 0.65 + opt.depth * 0.95 : 0.65) + 'rem">' +
          (opt.depth > 0 ? '<span class="admin-cat-branch">└</span>' : "") +
          "<span>" + esc(opt.label) + "</span>" +
          (sel ? '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" class="admin-cat-check"><path d="M20 6 9 17l-5-5"/></svg>' : "") +
          "</button>";
      });
      html += "</div></div>";
    }
    els.catSelect.innerHTML = html;
    els.newCatWrap.hidden = currentCategory !== "__new__";

    var trigger = $("#catTrigger", els.catSelect);
    if (trigger) trigger.addEventListener("click", function () { catOpen = !catOpen; renderCatSelect(); });
    var search = $("#catSearch", els.catSelect);
    if (search) {
      search.focus();
      search.addEventListener("input", function () { catQuery = search.value; catActive = 0; renderCatSelect(); });
    }
    $$(".admin-cat-item", els.catSelect).forEach(function (item) {
      item.addEventListener("mousedown", function (e) { e.preventDefault(); chooseCat(item.getAttribute("data-cat-val")); });
      item.addEventListener("mouseenter", function () {
        catActive = $$(".admin-cat-item", els.catSelect).indexOf(item);
        $$(".admin-cat-item", els.catSelect).forEach(function (x, i) { x.classList.toggle("admin-cat-active", i === catActive); });
      });
    });
    if (trigger) {
      trigger.addEventListener("keydown", function (e) {
        if (!catOpen) {
          if (e.key === "Enter" || e.key === " " || e.key === "ArrowDown") { e.preventDefault(); catOpen = true; renderCatSelect(); }
          return;
        }
        var list2 = catOptionList();
        if (e.key === "ArrowDown") { e.preventDefault(); catActive = Math.min(catActive + 1, list2.length - 1); renderCatSelect(); }
        else if (e.key === "ArrowUp") { e.preventDefault(); catActive = Math.max(catActive - 1, 0); renderCatSelect(); }
        else if (e.key === "Enter") { e.preventDefault(); var opt = list2[catActive]; if (opt) chooseCat(opt.value); }
        else if (e.key === "Escape") { catOpen = false; renderCatSelect(); }
      });
    }
  }
  function chooseCat(v) {
    currentCategory = v;
    catOpen = false;
    catQuery = "";
    renderCatSelect();
  }

  // ---------- 标签 ----------
  function toggleTag(id) {
    var i = tagIds.indexOf(String(id));
    if (i === -1) tagIds.push(String(id)); else tagIds.splice(i, 1);
    renderTags();
  }
  function removeNewTag(name) {
    newTagNames = newTagNames.filter(function (n) { return n !== name; });
    renderTags();
  }
  function addTag() {
    var parts = els.tagInput.value.split(/[,，]/).map(function (s) { return s.trim(); }).filter(Boolean);
    if (parts.length === 0) return;
    parts.forEach(function (name) {
      var hit = allTags.filter(function (t) { return t.name === name; })[0];
      if (hit) {
        if (tagIds.indexOf(String(hit.id)) === -1) tagIds.push(String(hit.id));
      } else if (newTagNames.indexOf(name) === -1) {
        newTagNames.push(name);
      }
    });
    els.tagInput.value = "";
    renderTags();
  }
  function renderTags() {
    var sel = "";
    tagIds.forEach(function (id) {
      var t = allTags.filter(function (x) { return String(x.id) === id; })[0];
      if (!t) return;
      sel += '<span class="badge badge-accent admin-tag-chip">' + esc(t.name) +
        '<button type="button" data-tag-remove="' + esc(id) + '" aria-label="移除标签 ' + esc(t.name) + '">×</button></span>';
    });
    newTagNames.forEach(function (n) {
      sel += '<span class="badge badge-accent admin-tag-chip">' + esc(n) +
        '<button type="button" data-newtag-remove="' + esc(n) + '" aria-label="移除标签 ' + esc(n) + '">×</button></span>';
    });
    els.tagsSelected.innerHTML = sel;
    $$("[data-tag-remove]", els.tagsSelected).forEach(function (b) { b.addEventListener("click", function () { toggleTag(b.getAttribute("data-tag-remove")); }); });
    $$("[data-newtag-remove]", els.tagsSelected).forEach(function (b) { b.addEventListener("click", function () { removeNewTag(b.getAttribute("data-newtag-remove")); }); });

    els.tagsAll.innerHTML = allTags.map(function (t) {
      var on = tagIds.indexOf(String(t.id)) !== -1;
      return '<button type="button" class="badge admin-tag-all' + (on ? " badge-accent" : "") + '" data-tag-toggle="' + esc(t.id) + '">' + esc(t.name) + "</button>";
    }).join("");
    $$("[data-tag-toggle]", els.tagsAll).forEach(function (b) { b.addEventListener("click", function () { toggleTag(b.getAttribute("data-tag-toggle")); }); });
  }

  // ---------- 封面 ----------
  function setCover(url) {
    els.coverUrl.value = url;
    renderCoverPreview();
  }
  function renderCoverPreview() {
    var v = els.coverUrl.value.trim();
    if (v) {
      els.coverImg.src = v;
      els.coverPreview.hidden = false;
    } else {
      els.coverPreview.hidden = true;
    }
  }
  function uploadFile(file) {
    var fd = new FormData();
    fd.append("file", file);
    fd.append("_csrf", CSRF);
    return fetch(DATA.uploadUrl, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: fd,
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.j.error || "上传失败");
        return res.j;
      });
  }

  // ---------- 自定义字段 ----------
  function addFieldRow(key, value) {
    var row = document.createElement("div");
    row.className = "admin-cf-row";
    row.innerHTML = '<input class="input" data-cf-key placeholder="键" maxlength="50" value="' + esc(key || "") + '">' +
      '<input class="input" data-cf-value placeholder="值" maxlength="500" value="' + esc(value || "") + '">' +
      '<button type="button" class="admin-icon-btn" data-cf-remove title="删除字段" aria-label="删除字段">×</button>';
    $("[data-cf-remove]", row).addEventListener("click", function () { row.remove(); });
    els.customFields.appendChild(row);
  }
  function renderCustomFields() {
    els.customFields.innerHTML = "";
    var rows = initial.customFields && initial.customFields.length ? initial.customFields : [{ key: "", value: "" }];
    rows.forEach(function (f) { addFieldRow(f.key, f.value); });
  }

  // ---------- 媒体弹窗 ----------
  function openModal(mode2) {
    modal.open = true;
    modal.mode = mode2 || "insert";
    modal.tab = "upload";
    modal.page = 1;
    modal.q = "";
    modal.total = 0;
    modalErrorHidden();
    els.modalEl.hidden = false;
    renderModal();
  }
  function closeModal() {
    modal.open = false;
    els.modalEl.hidden = true;
    if (modal.searchTimer) clearTimeout(modal.searchTimer);
  }
  function modalErrorHidden() {
    els.modalError.hidden = true;
    els.modalError.textContent = "";
  }
  function renderModal() {
    $$("[data-mtab]", els.modalEl).forEach(function (b) {
      b.classList.toggle("active", b.getAttribute("data-mtab") === modal.tab);
    });
    $$("[data-mpanel]", els.modalEl).forEach(function (p) {
      p.hidden = p.getAttribute("data-mpanel") !== modal.tab;
    });
    if (modal.tab === "library") loadLibrary(true);
  }
  function handleUpload(files) {
    var f = files && files[0];
    if (!f || modal.loading) return;
    modal.loading = true;
    modalErrorHidden();
    var hint = $("[data-mupload-hint]");
    if (hint) hint.textContent = "上传中…";
    uploadFile(f)
      .then(function (j) {
        pick(j.url, j.mime, j.originalName || f.name);
      })
      .catch(function (e) {
        els.modalError.textContent = e.message || "上传失败";
        els.modalError.hidden = false;
      })
      .then(function () {
        modal.loading = false;
        if (hint) hint.textContent = "";
        if (els.mediaFile) els.mediaFile.value = "";
      });
  }
  function loadLibrary(reset) {
    if (reset) modal.page = 1;
    modal.loading = true;
    var params = new URLSearchParams({ page: String(modal.page), q: modal.q });
    if (modal.mode === "cover") params.set("type", "image");
    fetch(DATA.uploadsUrl + "?" + params.toString(), { headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (d) { renderLibrary(d.items || [], d.total || 0); })
      .catch(function () { renderLibrary([], 0); })
      .then(function () { modal.loading = false; });
  }
  function renderLibrary(items, total) {
    modal.total = total;
    var grid = els.libGrid;
    if (items.length === 0) {
      grid.innerHTML = '<p class="admin-lib-empty">' + (modal.q ? "没有匹配的媒体" : "媒体库还是空的，先切换到“本地上传”吧") + "</p>";
    } else {
      grid.innerHTML = items.map(function (u) {
        var img = isImage(u.mime);
        var ext = (String(u.url).split(".").pop() || "").toUpperCase();
        var thumb = img
          ? '<img src="' + esc(u.url) + '" alt="' + esc(u.originalName) + '" loading="lazy">'
          : '<span class="admin-lib-ext">' + esc(ext) + "</span>";
        return '<button type="button" class="admin-lib-item" data-lib-pick="' + esc(u.url) + '" data-lib-mime="' + esc(u.mime) + '" data-lib-name="' + esc(u.originalName) + '" title="插入 ' + esc(u.originalName) + "（" + fmtSize(u.size) + "）\">" +
          '<span class="admin-lib-thumb">' + thumb + "</span>" +
          '<span class="admin-lib-name">' + esc(u.originalName) + "</span></button>";
      }).join("");
      $$("[data-lib-pick]", grid).forEach(function (b) {
        b.addEventListener("click", function () {
          pick(b.getAttribute("data-lib-pick"), b.getAttribute("data-lib-mime"), b.getAttribute("data-lib-name"));
        });
      });
    }
    var pages = Math.max(1, Math.ceil(total / 24));
    var pager = els.libPager;
    if (pages <= 1) {
      pager.hidden = true;
    } else {
      pager.hidden = false;
      pager.innerHTML = '<button type="button" class="admin-pager-btn" data-lib-page="' + (modal.page - 1) + '"' + (modal.page <= 1 ? " disabled" : "") + ">上一页</button>" +
        '<span class="admin-pager-info">第 ' + modal.page + " / " + pages + " 页</span>" +
        '<button type="button" class="admin-pager-btn" data-lib-page="' + (modal.page + 1) + '"' + (modal.page >= pages ? " disabled" : "") + ">下一页</button>";
      $$("[data-lib-page]", pager).forEach(function (b) {
        b.addEventListener("click", function () {
          var p = parseInt(b.getAttribute("data-lib-page"), 10);
          if (p >= 1 && p <= pages) { modal.page = p; loadLibrary(false); }
        });
      });
    }
  }
  function pick(url, mime, name) {
    if (modal.mode === "cover") {
      setCover(url);
      closeModal();
      return;
    }
    var label = String(name || "").replace(/\.[^.]+$/, "") || "媒体";
    var md = isImage(mime) ? "![" + label + "](" + url + ")" : "[" + label + "](" + url + ")";
    insertMd(md);
    closeModal();
  }

  // ---------- 初始化 ----------
  function init() {
    // 初始值
    els.slug.value = initial.slug || "";
    els.excerpt.value = initial.excerpt || "";
    els.content.value = initial.content || "";
    els.coverUrl.value = initial.coverUrl || "";
    els.scheduledAt.value = DATA.scheduledAt || "";
    els.externalUrl.value = initial.externalUrl || "";
    if (DATA.isScheduled && els.scheduledAt.value === "" && initial.publishedAt) {
      els.scheduledAt.value = String(initial.publishedAt).slice(0, 16).replace(" ", "T");
    }
    renderCoverPreview();
    renderTags();
    renderCatSelect();
    renderCustomFields();

    // 工具栏
    $$("[data-md]").forEach(function (b) {
      b.addEventListener("click", function () {
        var cmd = b.getAttribute("data-md");
        if (cmd === "media") { openModal("insert"); return; }
        if (TOOLBAR[cmd]) TOOLBAR[cmd]();
      });
    });
    var headingSel = $(".admin-md-select");
    if (headingSel) {
      headingSel.addEventListener("change", function () {
        var v = headingSel.value;
        headingSel.value = "h2";
        if (TOOLBAR.heading) TOOLBAR.heading(v);
      });
    }

    // 模式切换
    $$(".admin-md-btn[data-mode]").forEach(function (b) {
      b.addEventListener("click", function () { setMode(b.getAttribute("data-mode")); });
    });
    setMode("live");

    // 标题 → slug 联动
    els.title.addEventListener("input", function () {
      if (!slugTouched) els.slug.value = slugify(els.title.value);
    });
    els.slug.addEventListener("input", function () { slugTouched = true; });

    // 正文输入 → 预览刷新
    els.content.addEventListener("input", refreshSoon);

    // 提交按钮
    $$("[data-save]").forEach(function (b) {
      b.addEventListener("click", function () { submit(b.getAttribute("data-save")); });
    });
    els.form.addEventListener("submit", function (e) { e.preventDefault(); });

    // Ctrl+S 存草稿 / Ctrl+B 加粗 / Ctrl+I 斜体
    window.addEventListener("keydown", function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "s") {
        e.preventDefault();
        submit("draft");
      }
    });
    els.content.addEventListener("keydown", function (e) {
      if (e.ctrlKey || e.metaKey) {
        var k = e.key.toLowerCase();
        if (k === "b") { e.preventDefault(); TOOLBAR.bold(); }
        else if (k === "i") { e.preventDefault(); TOOLBAR.italic(); }
      }
    });

    // 标签输入
    els.tagInput.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === "," || e.key === "，") {
        e.preventDefault();
        addTag();
      }
    });

    // 封面
    els.coverUrl.addEventListener("input", renderCoverPreview);
    els.coverImg.addEventListener("error", function () { els.coverPreview.hidden = true; });
    $("#btnCoverUpload").addEventListener("click", function () { els.coverFile.click(); });
    els.coverFile.addEventListener("change", function () {
      var f = els.coverFile.files && els.coverFile.files[0];
      if (!f) return;
      uploadFile(f)
        .then(function (j) { setCover(j.url); })
        .catch(function (e) { showError(e.message || "上传失败"); })
        .then(function () { els.coverFile.value = ""; });
    });
    $("#btnCoverPicker").addEventListener("click", function () { openModal("cover"); });

    // 自定义字段
    $("#btnAddField").addEventListener("click", function () { addFieldRow("", ""); });

    // 媒体弹窗
    $$("[data-close-modal]").forEach(function (b) { b.addEventListener("click", closeModal); });
    els.modalEl.addEventListener("click", function (e) {
      if (e.target === els.modalEl) closeModal();
    });
    $$("[data-mtab]", els.modalEl).forEach(function (b) {
      b.addEventListener("click", function () {
        modal.tab = b.getAttribute("data-mtab");
        modalErrorHidden();
        renderModal();
      });
    });
    els.mediaFile.addEventListener("change", function () { handleUpload(els.mediaFile.files); });
    // 拖拽到上传面板
    var uploadPanel = $('[data-mpanel="upload"]', els.modalEl);
    if (uploadPanel) {
      ["dragenter", "dragover"].forEach(function (ev) {
        uploadPanel.addEventListener(ev, function (e) { e.preventDefault(); uploadPanel.classList.add("admin-drop-over"); });
      });
      ["dragleave", "drop"].forEach(function (ev) {
        uploadPanel.addEventListener(ev, function (e) {
          e.preventDefault();
          uploadPanel.classList.remove("admin-drop-over");
        });
      });
      uploadPanel.addEventListener("drop", function (e) { handleUpload(e.dataTransfer.files); });
    }
    els.libQ.addEventListener("input", function () {
      modal.q = els.libQ.value.trim();
      if (modal.searchTimer) clearTimeout(modal.searchTimer);
      modal.searchTimer = setTimeout(function () { loadLibrary(true); }, 500);
    });
    window.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && modal.open) closeModal();
    });

    // 编辑器区域拖拽/粘贴上传
    var mdBody = els.mdBody;
    ["dragenter", "dragover"].forEach(function (ev) {
      mdBody.addEventListener(ev, function (e) {
        if (hasFiles(e)) { e.preventDefault(); mdBody.classList.add("admin-drop-over"); }
      });
    });
    ["dragleave", "drop"].forEach(function (ev) {
      mdBody.addEventListener(ev, function (e) {
        mdBody.classList.remove("admin-drop-over");
        if (hasFiles(e)) {
          e.preventDefault();
          var f = e.dataTransfer.files[0];
          uploadFile(f)
            .then(function (j) {
              var label = (f.name || "").replace(/\.[^.]+$/, "") || "图片";
              insertMd(isImage(j.mime) ? "![" + label + "](" + j.url + ")" : "[" + label + "](" + j.url + ")");
            })
            .catch(function (err) { showError(err.message || "上传失败"); });
        }
      });
    });
    els.content.addEventListener("paste", function (e) {
      var files = e.clipboardData && e.clipboardData.files;
      if (files && files.length > 0) {
        e.preventDefault();
        var f = files[0];
        uploadFile(f)
          .then(function (j) {
            var label = (f.name || "").replace(/\.[^.]+$/, "") || "图片";
            insertMd("![" + label + "](" + j.url + ")");
          })
          .catch(function (err) { showError(err.message || "上传失败"); });
      }
    });

    // 自动保存
    if (isEdit) setInterval(checkAutosave, 60000);
    updateAutosave();
  }

  function hasFiles(e) {
    return e.dataTransfer && e.dataTransfer.types && Array.prototype.indexOf.call(e.dataTransfer.types, "Files") !== -1;
  }

  // 点击外部关闭分类面板
  document.addEventListener("mousedown", function (e) {
    if (catOpen && !els.catSelect.contains(e.target)) {
      catOpen = false;
      renderCatSelect();
    }
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
