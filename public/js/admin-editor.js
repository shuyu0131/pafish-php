/**
 * 文章编辑器交互：分类、标签、媒体选择和自动保存。
 * - Markdown 编辑：轻量源码编辑器，预览按需请求服务端
 * - 拖拽/粘贴图片上传（/api/upload，GD 压缩入库）；非图片文件插入下载链接
 * - slug 联动（未手动修改时随标题生成）、标签点选+新建、封面上传/媒体库
 * - 高级选项：定时发布、置顶、访问密码、外链、分类内置顶、自定义字段
 * - 提交校验 → AJAX 保存 → 发布后跳转列表，草稿留在编辑页
 * - Ctrl+S 快速存草稿；编辑模式每 60 秒自动保存（dirty 检测）
 * - 媒体弹窗（本地上传 / 媒体库 24/页 + 500ms 防抖搜索）
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
  function requestToken() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID().replace(/-/g, "");
    }
    var token = "";
    for (var i = 0; i < 32; i++) token += Math.floor(Math.random() * 16).toString(16);
    return token;
  }
  // ---------- 状态 ----------
  var initial = DATA.initial || {};
  if (!Array.isArray(initial.customFields)) {
    initial.customFields = Object.keys(initial.customFields || {}).map(function (key) {
      return { key: key, value: initial.customFields[key] == null ? "" : String(initial.customFields[key]) };
    });
  }
  if (!initial.extensionMeta || typeof initial.extensionMeta !== "object" || Array.isArray(initial.extensionMeta)) initial.extensionMeta = {};
  var isEdit = !!DATA.isEdit;
  var categories = DATA.categories || [];
  var allTags = DATA.tags || [];

  var slugTouched = isEdit || !!DATA.submissionMode;
  var slugAuto = document.getElementById("fSlugAuto");
  var tagIds = (initial.tagIds || []).map(String);
  var newTagNames = [];
  var pending = null;          // null | draft | publish | schedule | auto
  var manualRequestToken = null;
  var autoRequestToken = null;
  var lastSavedAt = null;      // 'HH:mm'
  var autosaveFailed = false;
  var autosavedPayload = null;
  var currentCategory = String(initial.categoryId || "");
  var modal = { open: false, mode: "insert", tab: "upload", page: 1, q: "", total: 0, uploading: false, request: 0, searchTimer: null };
  var catOpen = false;
  var catQuery = "";
  var catActive = 0;
  var editorExtensions = Array.isArray(window.PAFISH_EDITOR_EXTENSIONS) ? window.PAFISH_EDITOR_EXTENSIONS : [];
  var hiddenCustomFieldKeys = Array.isArray(DATA.hiddenCustomFieldKeys) ? DATA.hiddenCustomFieldKeys.map(String) : [];
  var preservedCustomFields = [];

  // ---------- DOM ----------
  var els = {
    form: $("#postForm"),
    title: $("#fTitle"),
    slug: $("#fSlug"),
    excerpt: $("#fExcerpt"),
    content: $("#fContent"),
    template: $("#fTemplate"),
    coverUrl: $("#fCoverUrl"),
    autoCover: $("#fAutoCover"),
    coverPicker: $("#btnCoverPicker"),
    coverPreview: $("[data-cover-preview]"),
    coverImg: $("[data-cover-preview] img"),
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
    allowComments: $("#fAllowComments"),
    seoTitle: $("#postSeoTitle"),
    metaDescription: $("#postMetaDescription"),
    modalEl: $("#mediaModal"),
    modalError: $("[data-mupload-error]"),
    libGrid: $("[data-lib-grid]"),
    libPager: $("[data-lib-pager]"),
    libQ: $("[data-lib-q]"),
    mediaFile: $("#mediaFile"),
  };

  // ---------- 轻量 Markdown 编辑器 ----------
  // 编辑器当前值实时同步回 #fContent（原生表单兜底 / FormData 读取）
  function editorValue() {
    return window.__pafishEditor ? window.__pafishEditor.getValue() : els.content.value;
  }

  // 资源缺失兜底：显示原生 textarea 直接编辑
  function editorFallback() {
    var mount = $("#markdownMount");
    if (mount) mount.style.display = "none";
    els.content.hidden = false;
    els.content.style.height = "520px";
    els.content.style.width = "100%";
    els.content.style.padding = "12px";
  }

  function initEditor() {
    var mount = $("#markdownMount");
    if (!mount) return;
    if (!window.PafishEditors) { editorFallback(); return; }
    window.__pafishEditor = window.PafishEditors.init({
      type: "post",
      mount: mount,
      field: els.content,
      placeholder: DATA.uploadUrl ? "开始写作…（支持拖拽/粘贴图片上传）" : "开始写作…",
      previewUrl: DATA.previewUrl,
      uploadUrl: DATA.uploadUrl,
      externalMedia: false,
      csrf: CSRF,
      onMedia: function (request) { openModal("insert", request); },
      onChange: function () { autoRequestToken = null; },
    });
    return;
  }

  // ---------- 表单值 / 脏检测 ----------
  function collectCustomFields() {
    var other = $$("[data-cf-key]", els.customFields).map(function (row) {
      return { key: row.value.trim(), value: $( "[data-cf-value]", row.closest(".admin-cf-row") ).value.trim() };
    }).filter(function (f) { return f.key !== "" || f.value !== ""; });
    editorExtensions.forEach(function (extension) {
      if (!extension || typeof extension.collectFields !== "function") return;
      var fields = extension.collectFields();
      if (Array.isArray(fields)) other = other.concat(fields);
    });
    return other.concat(preservedCustomFields);
  }
  function collectExtensionMeta() {
    var values = {};
    $$('[data-extension-meta]').forEach(function (input) {
      var scope = input.getAttribute("data-meta-scope");
      var key = input.getAttribute("data-meta-key");
      if (!scope || !key) return;
      if (!values[scope]) values[scope] = {};
      values[scope][key] = input.value;
    });
    return values;
  }
  function isDirty() {
    if (autosavedPayload !== null) return buildPayload("auto").toString() !== autosavedPayload;
    return els.title.value !== (initial.title || "") ||
      els.slug.value !== (initial.slug || "") ||
      els.excerpt.value !== (initial.excerpt || "") ||
      editorValue() !== (initial.content || "") ||
      document.getElementById('fContentFormat').value !== (initial.contentFormat || 'markdown') ||
      els.template.value !== (initial.template || "default") ||
      els.coverUrl.value !== (initial.coverUrl || "") ||
      els.autoCover.checked !== !!initial.autoCover ||
      currentCategory !== (initial.categoryId || "") ||
      JSON.stringify(tagIds) !== JSON.stringify(initial.tagIds || []) ||
      newTagNames.length > 0 ||
      currentCategory === "__new__" ||
      els.pinned.checked !== !!initial.isPinned ||
      els.password.value.trim() !== "" ||
      !!(els.removePassword && els.removePassword.checked) ||
      els.externalUrl.value !== (initial.externalUrl || "") ||
      els.catPinned.checked !== !!initial.categoryPinned ||
      els.allowComments.checked !== (initial.allowComments !== false) ||
      els.seoTitle.value !== (initial.seoTitle || "") ||
      els.metaDescription.value !== (initial.metaDescription || "") ||
      JSON.stringify(collectCustomFields()) !== JSON.stringify(initial.customFields || [{ key: "", value: "" }]) ||
      JSON.stringify(collectExtensionMeta()) !== JSON.stringify(initial.extensionMeta || {});
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
      els.autosave.textContent = "每 60 秒自动保存";
    }
  }

  // ---------- 保存 ----------
  function buildPayload(action) {
    var p = new URLSearchParams();
    p.set("_csrf", CSRF);
    p.set("action", action);
    if (DATA.submissionMode) p.set("post_id", String(DATA.postId || 0));
    p.set("title", els.title.value.trim());
    p.set("slug", els.slug.value.trim());
    // Keep the server-side collision policy in sync with the title-driven UI.
    // Without this flag an automatically generated slug is treated as manual
    // input and a collision is rejected instead of receiving a -2 suffix.
    if (slugAuto) p.set("slug_auto", slugAuto.value === "1" ? "1" : "0");
    p.set("excerpt", els.excerpt.value.trim());
    p.set("content", editorValue());
    p.set("content_format", $("#fContentFormat").value);
    p.set("template", els.template.value);
    p.set("cover_url", els.coverUrl.value.trim());
    p.set("auto_cover", els.autoCover.checked ? "1" : "0");
    p.set("category_id", currentCategory !== "" && currentCategory !== "__new__" ? currentCategory : "");
    p.set("new_category", currentCategory === "__new__" ? els.newCatInput.value.trim() : "");
    p.set("tag_ids", JSON.stringify(tagIds.map(Number)));
    p.set("new_tags", JSON.stringify(newTagNames));
    p.set("is_pinned", els.pinned.checked ? "1" : "");
    p.set("category_pinned", els.catPinned.checked ? "1" : "");
    p.set("password", els.password.value);
    p.set("remove_password", els.removePassword && els.removePassword.checked ? "1" : "");
    p.set("external_url", els.externalUrl.value.trim());
    p.set("allow_comments", els.allowComments.checked ? "1" : "0");
    p.set("seo_title", els.seoTitle.value.trim());
    p.set("meta_description", els.metaDescription.value.trim());
    p.set("custom_fields", JSON.stringify(collectCustomFields()));
    p.set("extension_meta", JSON.stringify(collectExtensionMeta()));
    if (action === "schedule") p.set("scheduled_at", els.scheduledAt.value);
    return p;
  }

  function submit(action) {
    if (pending) return;
    if (window.__pafishEditor && !window.__pafishEditor.isReady()) { showError("编辑器正在加载或上传，请稍候"); return; }
    clearError();
    if (!els.title.value.trim()) { showError("请填写标题"); return; }
    // 纯外链条目（导航站/书签站）没有自己的正文，填了外链就放行；
    // 服务端同样是这个条件（见 PostsController::savePost）。
    if (!DATA.emptyContentAllowed && !editorValue().trim() && !els.externalUrl.value.trim()) {
      showError("请填写正文内容（纯外链条目请填写外链地址）");
      return;
    }
    if (action === "schedule" && !els.scheduledAt.value) { showError("请选择定时发布时间"); return; }
    pending = action;
    updatePendingUI();
    manualRequestToken = manualRequestToken || requestToken();

    fetch(DATA.saveUrl, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: (function () { var p = buildPayload(action); p.set("_idempotency", manualRequestToken); return p; }()),
    })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error || "保存失败");
        manualRequestToken = null;
        var published = d.status === "PUBLISHED";
        var submissionSent = DATA.submissionMode && action === "publish";
        var destination = DATA.submissionMode
          ? (submissionSent ? DATA.listUrl : DATA.editUrl.replace("{id}", d.id))
          : (published ? DATA.listUrl : DATA.editUrl.replace("{id}", d.id));
        var message = d.message || (published ? "文章已发布" : (action === "draft" ? "草稿已保存" : "文章已保存"));
        pafishToastNavigate(destination, message, "success");
      })
      .catch(function (e) {
        showError(e.message || "保存失败");
        pending = null;
        updatePendingUI();
      });
  }

  // 自动保存：仅编辑模式，每 60 秒（dirty 且标题非空、且正文或外链至少有一项时）
  function checkAutosave() {
    if (DATA.autosaveEnabled === false || !isEdit || pending || (window.__pafishEditor && !window.__pafishEditor.isReady())) return;
    if (!isDirty() || !els.title.value.trim()) return;
    if (!editorValue().trim() && !els.externalUrl.value.trim()) return;
    pending = "auto";
    autoRequestToken = autoRequestToken || requestToken();
    var savedPayload = buildPayload("auto");
    var requestPayload = new URLSearchParams(savedPayload);
    requestPayload.set("_idempotency", autoRequestToken);
    updateAutosave();
    fetch(DATA.saveUrl, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: requestPayload,
    })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error || "自动保存失败");
        lastSavedAt = nowTime();
        autosaveFailed = false;
        autoRequestToken = null;
        // 回填实际存储值：自动取到的图只更新预览，不落进地址框。
        // 请求期间的新输入或移除操作不应被旧响应覆盖。
        if (typeof d.coverUrl === "string" && els.coverUrl.value.trim() === savedPayload.get("cover_url") &&
            (els.autoCover.checked ? "1" : "0") === savedPayload.get("auto_cover")) {
          setAutoCoverUrl(d.coverAuto ? d.coverUrl : "");
          if (!d.coverAuto) {
            setCover(d.coverUrl);
            savedPayload.set("cover_url", d.coverUrl);
          }
        }
        // 基线取发送时的快照；请求期间继续输入的内容仍视为未保存。
        autosavedPayload = savedPayload.toString();
      })
      .catch(function () {
        if (!autosaveFailed) {
          autosaveFailed = true;
          if (typeof window.pafishNotify === "function") window.pafishNotify("自动保存失败，请手动保存", true);
        }
      })
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
    if (DATA.allowNewCategories) list.push({ value: "__new__", label: "＋ 新建分类…", depth: -1 });
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
    var html = '<button type="button" class="admin-cat-trigger" id="catTrigger" title="选择分类" aria-label="选择分类">' +
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
      } else if (!DATA.allowNewTags) {
        showError("只能选择站点已有标签");
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
        '<button type="button" data-tag-remove="' + esc(id) + '" title="移除标签 ' + esc(t.name) + '" aria-label="移除标签 ' + esc(t.name) + '">×</button></span>';
    });
    newTagNames.forEach(function (n) {
      sel += '<span class="badge badge-accent admin-tag-chip">' + esc(n) +
        '<button type="button" data-newtag-remove="' + esc(n) + '" title="移除标签 ' + esc(n) + '" aria-label="移除标签 ' + esc(n) + '">×</button></span>';
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
  function rememberAutoCover() {
    try { localStorage.setItem("pafish_admin_auto_cover", els.autoCover.checked ? "1" : "0"); } catch (error) {}
  }
  function setCover(url) {
    els.coverUrl.value = url;
    els.coverUrl.dispatchEvent(new Event("input", { bubbles: true }));
  }
  // 自动取图的结果只当预览：一旦写进地址框，下次保存就会被当成手动封面存回去，
  // 「自动使用正文首图」于是永远关不掉。
  function setAutoCoverUrl(url) {
    els.coverPicker.setAttribute("data-auto-cover", url || "");
    renderCoverPreview();
  }
  function coverUrlValid(v) {
    // Plain text (including browser-autofilled usernames) must not become a relative image request.
    var valid = /^(?:\/(?!\/)|https?:\/\/)/i.test(v) && !/[\x00-\x20\x7f\\]/.test(v);
    if (valid && v.charAt(0) !== "/") {
      try {
        var parsed = new URL(v);
        valid = !!parsed.hostname && !parsed.username && !parsed.password;
      } catch (error) { valid = false; }
    }
    return valid;
  }
  function renderCoverPreview() {
    var v = els.coverUrl.value.trim();
    var auto = v === "" && els.autoCover.checked ? (els.coverPicker.getAttribute("data-auto-cover") || "") : "";
    var shown = v !== "" ? v : auto;
    var valid = coverUrlValid(shown);
    $("[data-cover-empty]").hidden = valid;
    $("#btnCoverClear").hidden = !v && !auto;
    if (valid) {
      if (els.coverImg.getAttribute("src") !== shown) els.coverImg.src = shown;
      els.coverPreview.hidden = false;
    } else {
      els.coverPreview.hidden = true;
      els.coverImg.removeAttribute("src");
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
    row.innerHTML = '<input class="input admin-cf-key" data-cf-key list="postCustomFieldKeys" placeholder="字段名，如 subtitle" maxlength="50" value="' + esc(key || "") + '">' +
      '<textarea class="input" data-cf-value placeholder="字段值" aria-label="字段值" rows="2" maxlength="500">' + esc(value || "") + '</textarea>' +
      '<button type="button" class="admin-icon-btn admin-icon-danger" data-cf-remove title="删除字段" aria-label="删除字段">×</button>';
    $("[data-cf-key]", row).setAttribute("aria-label", "字段名");
    $("[data-cf-remove]", row).addEventListener("click", function () {
      var next = row.nextElementSibling || row.previousElementSibling;
      row.remove();
      (next ? $("[data-cf-key]", next) : $("#btnAddField")).focus({ preventScroll: true });
      autoRequestToken = null;
    });
    els.customFields.appendChild(row);
    return row;
  }
  function renderCustomFields() {
    els.customFields.innerHTML = "";
    preservedCustomFields = (initial.customFields || []).filter(function (field) {
      return hiddenCustomFieldKeys.indexOf(String(field.key || "")) !== -1;
    });
    var rows = (initial.customFields || []).filter(function (field) {
      return hiddenCustomFieldKeys.indexOf(String(field.key || "")) === -1;
    });
    editorExtensions.forEach(function (extension) {
      if (extension && typeof extension.filterCustomFields === "function") {
        rows = extension.filterCustomFields(rows) || rows;
      }
    });
    rows = rows.filter(function (f) { return f.key || f.value; });
    var suggestions = $("#postCustomFieldKeys");
    suggestions.innerHTML = Array.from(new Set(rows.map(function (f) { return f.key; }).filter(Boolean)))
      .map(function (key) { return '<option value="' + esc(key) + '"></option>'; }).join("");
    rows.forEach(function (f) { addFieldRow(f.key, f.value); });
    var details = $("#customFieldsDetails");
    if (details) details.open = rows.length > 0;
  }

  // ---------- 媒体弹窗 ----------
  function openModal(mode2, request) {
    if (!DATA.uploadUrl || modal.uploading) return;
    modal.open = true;
    modal.mode = mode2 || "insert";
    modal.imagesOnly = modal.mode === 'cover' || !!(request && request.imagesOnly);
    modal.tab = modal.imagesOnly ? "library" : "upload";
    modal.page = 1;
    modal.q = "";
    modal.total = 0;
    $('[data-lib-q]').value = '';
    var mediaTitle = $('#editorMediaTitle');
    var coverMode = modal.imagesOnly;
    if (mediaTitle) mediaTitle.textContent = request && request.title || (modal.mode === 'cover' ? '选择封面' : '插入媒体');
    var mediaFile = $('#mediaFile');
    mediaFile.dataset.fullAccept = mediaFile.dataset.fullAccept || mediaFile.accept;
    mediaFile.accept = coverMode ? 'image/*,.svg' : mediaFile.dataset.fullAccept;
    $('[data-mupload-hint]').textContent = coverMode ? '请选择图片文件' : '图片插入为图片，其他文件插入为下载链接';
    modalErrorHidden();
    els.modalEl.hidden = false;
    renderModal();
  }
  function closeModal() {
    if (window.__pafishEditor) window.__pafishEditor.cancelMedia();
    modal.open = false;
    modal.request++;
    els.modalEl.hidden = true;
    if (modal.searchTimer) clearTimeout(modal.searchTimer);
  }
  function modalErrorHidden() {
    els.modalError.hidden = true;
    els.modalError.textContent = "";
  }
  function renderModal() {
    $$("[data-mtab]", els.modalEl).forEach(function (b) {
      var active = b.getAttribute("data-mtab") === modal.tab;
      b.classList.toggle("active", active);
      b.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    $$("[data-mpanel]", els.modalEl).forEach(function (p) {
      p.hidden = p.getAttribute("data-mpanel") !== modal.tab;
    });
    if (modal.tab === "library") loadLibrary(true);
  }
  function handleUpload(files) {
    var f = files && files[0];
    if (!f || modal.uploading) return;
    if (modal.imagesOnly && !isImage(f.type)) {
      var error = $('[data-mupload-error]'); error.textContent = '请选择图片文件'; error.hidden = false; return;
    }
    modal.uploading = true;
    var panel = $('[data-mpanel="upload"]');
    panel.setAttribute('aria-busy', 'true');
    var browse = $('[data-media-browse]');
    browse.disabled = true;
    $$("[data-mtab]").forEach(function (tab) { tab.disabled = true; });
    $('[data-mupload-error]').hidden = true;
    var help = $('[data-mupload-hint]').textContent;
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
        modal.uploading = false;
        panel.removeAttribute('aria-busy');
        browse.disabled = false;
        $$("[data-mtab]").forEach(function (tab) { tab.disabled = false; });
        if (hint) hint.textContent = help;
        if (els.mediaFile) els.mediaFile.value = "";
      });
  }
  function loadLibrary(reset) {
    if (reset) modal.page = 1;
    var request = ++modal.request;
    var grid = $('[data-lib-grid]'), pager = $('[data-lib-pager]');
    grid.setAttribute('aria-busy', 'true');
    grid.innerHTML = '<p class="admin-lib-empty">正在加载媒体…</p>';
    pager.hidden = true;
    var params = new URLSearchParams({ page: String(modal.page), q: modal.q });
    if (modal.imagesOnly) params.set('type', 'image');
    fetch(window.PafishEditors.query(DATA.uploadsUrl, params), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (!r.ok) throw new Error('媒体加载失败');
        return r.json();
      })
      .then(function (d) {
        if (!Array.isArray(d.items)) throw new Error('媒体数据异常');
        if (request === modal.request && modal.open) renderLibrary(d.items, d.total || 0);
      })
      .catch(function () {
        if (request !== modal.request || !modal.open) return;
        grid.innerHTML = '<div class="admin-lib-empty"><p role="alert">媒体加载失败，请重试</p><button type="button" class="btn btn-outline" data-lib-retry>重新加载</button></div>';
        $('[data-lib-retry]', grid).addEventListener('click', function () { loadLibrary(false); });
      })
      .then(function () { if (request === modal.request) grid.removeAttribute('aria-busy'); });
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
      if (!isImage(mime)) return;
      setCover(url);
      closeModal();
      return;
    }
    if (modal.imagesOnly && !isImage(mime)) return;
    if (window.__pafishEditor && window.__pafishEditor.insertMedia(url, mime, name) === false) return;
    closeModal();
  }

  $('[data-media-browse]').addEventListener('click', function () { $('#mediaFile').click(); });

  // ---------- 初始化 ----------
  function init() {
    // 初始值
    els.slug.value = initial.slug || "";
    els.excerpt.value = initial.excerpt || "";
    els.coverUrl.value = initial.coverUrl || "";
    els.scheduledAt.value = DATA.scheduledAt || "";
    els.externalUrl.value = initial.externalUrl || "";
    if (DATA.isScheduled && els.scheduledAt.value === "" && initial.publishedAt) {
      els.scheduledAt.value = String(initial.publishedAt).slice(0, 16).replace(" ", "T");
    }
    // 已有文章以服务端记录的开关为准，新建才沿用上次的选择
    if (isEdit) {
      els.autoCover.checked = !!initial.coverAuto;
    } else {
      try { els.autoCover.checked = localStorage.getItem("pafish_admin_auto_cover") === "1"; } catch (error) {}
    }
    setAutoCoverUrl(initial.autoCoverUrl || "");
    initial.autoCover = els.autoCover.checked;
    renderTags();
    renderCatSelect();
    editorExtensions.forEach(function (extension) {
      if (extension && typeof extension.init === "function") extension.init({ initial: initial, data: DATA, elements: els });
    });
    renderCustomFields();
    initial.extensionMeta = collectExtensionMeta();
    // 以实际控件值为基线，避免打开旧文章就被标记为“未保存”。
    initial.customFields = collectCustomFields();

    // Markdown 编辑器（读取 textarea 初始内容）
    initEditor();

    // 标题 → slug 联动
    els.title.addEventListener("input", function () {
      if (!slugTouched) {
        if (slugAuto) slugAuto.value = "1";
        window.pafishPreviewSlug(els.title, els.slug, slugAuto);
      }
    });
    els.slug.addEventListener("input", function () { slugTouched = true; if (slugAuto) slugAuto.value = "0"; });
    // 自动保存令牌只对应当前内容；内容变化后必须生成新的请求令牌。
    els.form.addEventListener("input", function () { autoRequestToken = null; });

    // 提交按钮
    $$("[data-save]").forEach(function (b) {
      b.addEventListener("click", function () { submit(b.getAttribute("data-save")); });
    });
    els.form.addEventListener("submit", function (e) { e.preventDefault(); });

    // Ctrl+S 存草稿
    window.addEventListener("keydown", function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "s") {
        e.preventDefault();
        submit("draft");
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
    els.autoCover.addEventListener("change", function () { rememberAutoCover(); renderCoverPreview(); });
    els.coverImg.addEventListener("error", function () {
      els.coverPreview.hidden = true;
      $("[data-cover-empty]").hidden = false;
    });
    $("#btnCoverPicker").addEventListener("click", function () { openModal("cover"); });
    $("#btnCoverClear").addEventListener("click", function () {
      els.autoCover.checked = false;
      rememberAutoCover();
      setAutoCoverUrl("");
      setCover("");
    });

    // 自定义字段
    $("#btnAddField").addEventListener("click", function () { $("[data-cf-key]", addFieldRow("", "")).focus(); });

    // 媒体弹窗
    $$("[data-close-modal]").forEach(function (b) { b.addEventListener("click", closeModal); });
    els.modalEl.addEventListener("click", function (e) {
      if (e.target === els.modalEl) closeModal();
    });
    $$("[data-mtab]", els.modalEl).forEach(function (b) {
      b.addEventListener("click", function () {
        if (modal.uploading) return;
        modal.request++;
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

    // 自动保存
    if (DATA.autosaveEnabled !== false && isEdit) setInterval(checkAutosave, 60000);
    updateAutosave();
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
