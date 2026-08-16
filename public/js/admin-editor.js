/**
 * 文章编辑器（对齐 Node post-editor.tsx + category-select.tsx + media-picker.tsx）：
 * - Markdown 编辑：Vditor（所见即所得/分屏/源码三模式，中文工具栏，本地化资源）
 * - 拖拽/粘贴图片上传（/api/upload，GD 压缩入库）；非图片文件插入下载链接
 * - slug 联动（未手动修改时随标题生成）、标签点选+新建、封面上传/媒体库
 * - 高级选项：定时发布、置顶、访问密码、外链、分类内置顶、自定义字段
 * - 提交校验 → AJAX 保存 → 跳转编辑页（对齐 Node redirect）
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
  var modal = { open: false, mode: "insert", tab: "upload", page: 1, q: "", total: 0, loading: false, searchTimer: null };
  var catOpen = false;
  var catQuery = "";
  var catActive = 0;
  var LUMINA_COMMON_KEYS = ["lumina_location", "lumina_location_address", "lumina_location_city", "lumina_location_poi_id", "lumina_location_lat", "lumina_location_lng", "lumina_private"];
  var LUMINA_TYPE_KEYS = {
    img: ["lumina_photos"],
    live: ["lumina_photos", "lumina_live_photos"],
    video: ["lumina_video_url", "lumina_video_poster"],
    embed: ["lumina_embed_url", "lumina_embed_ratio", "lumina_embed_cover"],
    music: ["lumina_music_url", "lumina_music_title", "lumina_music_artist", "lumina_music_cover"],
    redpacket: ["lumina_redpacket_mode", "lumina_redpacket_total", "lumina_redpacket_count", "lumina_redpacket_title"]
  };
  var LUMINA_KEYS = ["lumina_type"].concat(LUMINA_COMMON_KEYS, Object.keys(LUMINA_TYPE_KEYS).reduce(function (all, type) { return all.concat(LUMINA_TYPE_KEYS[type]); }, []));
  var LUMINA_LABELS = {
    lumina_photos: "图片列表", lumina_live_photos: "实况图视频", lumina_video_url: "视频地址", lumina_video_poster: "视频封面",
    lumina_embed_url: "平台视频", lumina_embed_ratio: "平台视频方向", lumina_embed_cover: "平台视频封面",
    lumina_music_url: "音乐地址", lumina_music_title: "音乐标题", lumina_music_artist: "音乐作者", lumina_music_cover: "音乐封面",
    lumina_location: "地点名称", lumina_location_address: "地点地址", lumina_location_city: "所在城市", lumina_location_poi_id: "地点 POI ID", lumina_location_lat: "纬度", lumina_location_lng: "经度",
    lumina_private: "可见范围", lumina_redpacket_mode: "红包类型", lumina_redpacket_total: "红包总积分",
    lumina_redpacket_count: "红包数量", lumina_redpacket_title: "红包标题"
  };
  var luminaValues = {};

  // ---------- DOM ----------
  var els = {
    form: $("#postForm"),
    title: $("#fTitle"),
    slug: $("#fSlug"),
    excerpt: $("#fExcerpt"),
    content: $("#fContent"),
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
    luminaFields: $("#luminaFields"),
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

  // ---------- 编辑器（Vditor：所见即所得/分屏/源码三模式） ----------
  var vditor = null;
  // 编辑器当前值：Vditor input 回调实时同步回 #fContent（原生表单兜底 / FormData 读取）
  function editorValue() {
    return els.content.value;
  }
  // 光标处插入（媒体弹窗用）：Vditor API（insertValue 会聚焦并渲染选区）
  function editorInsert(md) {
    if (vditor && typeof vditor.insertValue === "function") {
      vditor.insertValue(md);
      return;
    }
    els.content.value += els.content.value ? "\n\n" + md : md;
  }

  // 资源缺失兜底：显示原生 textarea 直接编辑
  function editorFallback() {
    var mount = $("#vditorMount");
    if (mount) mount.style.display = "none";
    els.content.hidden = false;
    els.content.style.height = "520px";
    els.content.style.width = "100%";
    els.content.style.padding = "12px";
  }

  function initEditor() {
    var mount = $("#vditorMount");
    if (!mount) return;
    if (typeof window.Vditor !== "function") { editorFallback(); return; }

    vditor = new Vditor(mount, {
      height: 560,
      mode: "ir",
      value: initial.content || "",
      placeholder: "开始写作…（支持拖拽/粘贴图片上传）",
      lang: "zh_CN",
      cdn: DATA.assetBase + "/vendor/vditor",
      cache: { enable: false }, // 内容走 textarea 与数据库，不用 localStorage 缓存
      counter: { enable: true },
      toolbar: [
        "headings", "bold", "italic", "strike", "|",
        "line", "quote", "list", "ordered-list", "check", "outdent", "indent", "|",
        "code", "inline-code", "insert-after", "insert-before", "|",
        "upload", "link", "table", "|", "emoji", "|",
        {
          name: "insert-media",
          tip: "插入媒体（本地上传或媒体库）",
          icon: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
          click: function () { openModal("insert"); },
        },
        "|", "undo", "redo", "|", "fullscreen", "edit-mode", "both", "preview", "|",
        "outline",
      ],
      input: function (v) {
        els.content.value = v || "";
      },
      upload: {
        url: DATA.uploadUrl,
        fieldName: "file[]",
        withCredentials: true,
        headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-Token": CSRF },
        linkToImgUrl: false, // 粘贴外链图片保留原地址，不强制上传
        error: function (msg) { showError(typeof msg === "string" ? msg : "上传失败"); },
      },
      preview: {
        hljs: { style: "github", lineNumber: false },
        math: { engine: "KaTeX" },
      },
    });
  }

  // ---------- 表单值 / 脏检测（对齐 Node dirty 计算） ----------
  function collectCustomFields() {
    var other = $$("[data-cf-key]", els.customFields).map(function (row) {
      return { key: row.value.trim(), value: $( "[data-cf-value]", row.closest(".admin-cf-row") ).value.trim() };
    }).filter(function (f) { return f.key !== "" || f.value !== ""; });
    return other.concat(collectLuminaFields());
  }
  function isDirty() {
    return els.title.value !== (initial.title || "") ||
      els.slug.value !== (initial.slug || "") ||
      els.excerpt.value !== (initial.excerpt || "") ||
      editorValue() !== (initial.content || "") ||
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
    // 全局 toast 同步提示（admin-toast.js 已随后台布局加载）
    if (typeof window.pafishNotify === "function") window.pafishNotify(msg);
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

  // ---------- 保存 ----------
  function buildPayload(action) {
    var p = new URLSearchParams();
    p.set("_csrf", CSRF);
    p.set("action", action);
    p.set("title", els.title.value.trim());
    p.set("slug", els.slug.value.trim());
    p.set("excerpt", els.excerpt.value.trim());
    p.set("content", editorValue());
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
    if (!editorValue().trim()) { showError("请填写正文内容"); return; }
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
    if (!isDirty() || !els.title.value.trim() || !editorValue().trim()) return;
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
      .catch(function () {
        if (!autosaveFailed) {
          autosaveFailed = true;
          if (typeof window.pafishNotify === "function") window.pafishNotify("自动保存失败，请手动保存");
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
  function luminaInput(key, value) {
    var label = LUMINA_LABELS[key] || key;
    var isLong = key === "lumina_photos" || key === "lumina_live_photos" || key === "lumina_embed_url";
    var note = {
      lumina_photos: "每行一张图片地址，也支持逗号分隔",
      lumina_live_photos: "每行一个视频地址，按图片顺序对应；也可写 图片地址|视频地址",
      lumina_embed_url: "支持 Bilibili、YouTube，或受支持平台的官方 iframe 代码",
      lumina_embed_cover: "可选，仅作编辑记录；前台播放器使用平台封面",
      lumina_location_lat: "填写经纬度后，地点可跳转到腾讯地图",
      lumina_location_lng: "填写经纬度后，地点可跳转到腾讯地图"
    }[key] || "";
    var control;
    if (key === "lumina_embed_ratio") {
      control = '<select class="input" data-lumina-key="' + key + '"><option value="lr"' + (value !== "tb" ? " selected" : "") + '>横屏 16:9</option><option value="tb"' + (value === "tb" ? " selected" : "") + '>竖屏 9:16</option></select>';
    } else if (key === "lumina_private") {
      control = '<select class="input" data-lumina-key="' + key + '"><option value="n"' + (value !== "y" ? " selected" : "") + '>公开</option><option value="y"' + (value === "y" ? " selected" : "") + '>仅自己可看</option></select>';
    } else if (key === "lumina_redpacket_mode") {
      control = '<select class="input" data-lumina-key="' + key + '"><option value="random"' + (value !== "equal" ? " selected" : "") + '>随机</option><option value="equal"' + (value === "equal" ? " selected" : "") + '>等额</option></select>';
    } else if (isLong) {
      control = '<textarea class="input" data-lumina-key="' + key + '" rows="3" maxlength="500">' + esc(value || "") + '</textarea>';
    } else {
      control = '<input class="input" data-lumina-key="' + key + '" maxlength="500" value="' + esc(value || "") + '">';
    }
    return '<label class="admin-lumina-field"><span>' + esc(label) + '</span>' + control + (note ? '<small>' + esc(note) + '</small>' : '') + '</label>';
  }
  function captureLuminaFields() {
    if (!els.luminaFields) return;
    $$('[data-lumina-key]', els.luminaFields).forEach(function (input) {
      luminaValues[input.getAttribute('data-lumina-key')] = input.value;
    });
  }
  function renderLuminaFields() {
    if (!els.luminaFields) return;
    captureLuminaFields();
    var type = luminaValues.lumina_type || "only";
    var fields = (LUMINA_TYPE_KEYS[type] || []).concat(LUMINA_COMMON_KEYS);
    var types = [["only", "纯文字"], ["img", "图文"], ["live", "实况图"], ["video", "视频"], ["embed", "平台视频"], ["music", "音乐"], ["redpacket", "红包"]];
    els.luminaFields.innerHTML = '<label class="admin-lumina-field admin-lumina-type"><span>内容类型</span><select class="input" id="luminaType">' + types.map(function (item) {
      return '<option value="' + item[0] + '"' + (type === item[0] ? ' selected' : '') + '>' + item[1] + '</option>';
    }).join('') + '</select></label>' + fields.map(function (key) { return luminaInput(key, luminaValues[key] || ''); }).join('');
    $("#luminaType", els.luminaFields).addEventListener("change", function () {
      luminaValues.lumina_type = this.value;
      renderLuminaFields();
    });
  }
  function collectLuminaFields() {
    captureLuminaFields();
    var type = luminaValues.lumina_type || "only";
    var active = (LUMINA_TYPE_KEYS[type] || []).concat(LUMINA_COMMON_KEYS);
    var hasValue = active.some(function (key) { return String(luminaValues[key] || "").trim() !== "" && !(key === "lumina_private" && luminaValues[key] === "n"); });
    if (!hasValue && type === "only") return [];
    return [{ key: "lumina_type", value: type }].concat(active.map(function (key) {
      return { key: key, value: String(luminaValues[key] || (key === "lumina_private" ? "n" : "")).trim() };
    }).filter(function (field) { return field.value !== ""; }));
  }
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
    var rows = (initial.customFields || []).filter(function (field) { return LUMINA_KEYS.indexOf(field.key) === -1; });
    if (rows.length === 0) rows = [{ key: "", value: "" }];
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
    editorInsert(md);
    closeModal();
  }

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
    renderCoverPreview();
    renderTags();
    renderCatSelect();
    (initial.customFields || []).forEach(function (field) {
      if (LUMINA_KEYS.indexOf(field.key) !== -1) luminaValues[field.key] = field.value;
    });
    renderLuminaFields();
    renderCustomFields();
    // 以实际控件值为基线，避免打开旧文章就被标记为“未保存”。
    initial.customFields = collectCustomFields();

    // Markdown 编辑器（Vditor，读取 textarea 初始内容）
    initEditor();

    // 标题 → slug 联动
    els.title.addEventListener("input", function () {
      if (!slugTouched) els.slug.value = slugify(els.title.value);
    });
    els.slug.addEventListener("input", function () { slugTouched = true; });

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

    // 自动保存
    if (isEdit) setInterval(checkAutosave, 60000);
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
