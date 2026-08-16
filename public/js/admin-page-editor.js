/**
 * 页面编辑器（对齐 Node page-editor.tsx 的 MdEditor 精简版）：
 * - Markdown 编辑：Vditor（所见即所得/分屏/源码三模式，中文工具栏，本地化资源）
 * - 拖拽/粘贴图片上传（/api/upload）；非图片文件走 /api/upload 插入下载链接
 * - 媒体弹窗（本地上传 / 媒体库 24/页 + 500ms 防抖搜索），工具栏「插入媒体」按钮打开
 * - 标题 → slug 自动联动（手动改过后不再覆盖）；Ctrl+S 存草稿
 * 配置：window.PAFISH_PAGE_EDITOR { saveUrl, listUrl, previewUrl, uploadUrl, isEdit, initialSlug, csrf }
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
  function isImage(mime) { return (mime || "").indexOf("image/") === 0; }
  function fmtSize(b) {
    if (b < 1024) return b + " B";
    if (b < 1048576) return (b / 1024).toFixed(1) + " KB";
    return (b / 1048576).toFixed(1) + " MB";
  }

  // ---------- 状态与 DOM ----------
  var form = $("#pageForm");
  var title = $("#fTitle");
  var slug = $("#fSlug");
  var content = $("#fContent");
  var errorBox = $(".admin-editor-error");
  var slugTouched = false;
  var pending = null;      // draft | publish
  var modal = { open: false, tab: "upload", page: 1, q: "", total: 0, loading: false, searchTimer: null };

  // 编辑器当前值：Vditor input 回调实时同步回 #fContent（表单兜底）
  function editorValue() { return content.value; }

  // 光标处插入（媒体弹窗用）：Vditor API
  var vditor = null;
  function editorInsert(md) {
    if (vditor && typeof vditor.insertValue === "function") {
      vditor.insertValue(md);
      return;
    }
    content.value += content.value ? "\n\n" + md : md;
  }

  function showError(msg) {
    if (errorBox) { errorBox.textContent = msg; errorBox.hidden = false; }
    // 全局 toast 同步提示（admin-toast.js 已随后台布局加载）
    if (typeof window.pafishNotify === "function") window.pafishNotify(msg);
  }
  function clearError() { if (errorBox) errorBox.hidden = true; }

  // ---------- 上传 ----------
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

  // ---------- 编辑器（Vditor：所见即所得/分屏/源码三模式） ----------
  // 资源缺失兜底：显示原生 textarea 直接编辑
  function editorFallback() {
    var mount = $("#vditorMount");
    if (mount) mount.style.display = "none";
    content.hidden = false;
    content.style.height = "520px";
    content.style.width = "100%";
    content.style.padding = "12px";
  }

  function initEditor() {
    var mount = $("#vditorMount");
    if (!mount) return;
    if (typeof window.Vditor !== "function") { editorFallback(); return; }

    vditor = new Vditor(mount, {
      height: 420,
      mode: "ir",
      value: content.value || "",
      placeholder: "在此输入页面内容（支持 Markdown）…",
      lang: "zh_CN",
      cdn: DATA.assetBase + "/vendor/vditor/dist",
      cache: { enable: false },
      counter: { enable: true },
      toolbar: [
        "headings", "bold", "italic", "strike", "|",
        "line", "quote", "list", "ordered-list", "check", "|",
        "code", "inline-code", "|",
        "upload", "link", "table", "|", "emoji", "|",
        {
          name: "insert-media",
          tip: "插入媒体（本地上传或媒体库）",
          icon: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
          click: function () { openModal(); },
        },
        "|", "undo", "redo", "|", "fullscreen", "edit-mode", "both", "preview", "|",
        "outline",
      ],
      input: function (v) {
        content.value = v || "";
      },
      upload: {
        url: DATA.uploadUrl,
        fieldName: "file[]",
        withCredentials: true,
        headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-Token": CSRF },
        linkToImgUrl: false,
        error: function (msg) { showError(typeof msg === "string" ? msg : "上传失败"); },
      },
      preview: {
        hljs: { style: "github", lineNumber: false },
        math: { engine: "KaTeX" },
      },
    });
  }

  // ---------- 媒体弹窗 ----------
  function openModal() {
    modal.open = true;
    modal.tab = "upload";
    modal.page = 1;
    modal.q = "";
    modal.total = 0;
    var me = $("[data-mupload-error]");
    if (me) { me.hidden = true; me.textContent = ""; }
    $("#mediaModal").hidden = false;
    renderModal();
  }
  function closeModal() {
    modal.open = false;
    $("#mediaModal").hidden = true;
    if (modal.searchTimer) clearTimeout(modal.searchTimer);
  }
  function renderModal() {
    $$("[data-mtab]", $("#mediaModal")).forEach(function (b) {
      b.classList.toggle("active", b.getAttribute("data-mtab") === modal.tab);
    });
    $$("[data-mpanel]", $("#mediaModal")).forEach(function (p) {
      p.hidden = p.getAttribute("data-mpanel") !== modal.tab;
    });
    if (modal.tab === "library") loadLibrary(true);
  }
  function handleUpload(files) {
    var f = files && files[0];
    if (!f || modal.loading) return;
    modal.loading = true;
    var hint = $("[data-mupload-hint]");
    if (hint) hint.textContent = "上传中…";
    uploadFile(f)
      .then(function (j) { pick(j.url, j.mime, j.originalName || f.name); })
      .catch(function (e) {
        var me = $("[data-mupload-error]");
        me.textContent = e.message || "上传失败";
        me.hidden = false;
      })
      .then(function () {
        modal.loading = false;
        if (hint) hint.textContent = "";
        var mf = $("#mediaFile");
        if (mf) mf.value = "";
      });
  }
  function loadLibrary(reset) {
    if (reset) modal.page = 1;
    modal.loading = true;
    var params = new URLSearchParams({ page: String(modal.page), q: modal.q });
    fetch(DATA.uploadsUrl + "?" + params.toString(), { headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (d) { renderLibrary(d.items || [], d.total || 0); })
      .catch(function () { renderLibrary([], 0); })
      .then(function () { modal.loading = false; });
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function renderLibrary(items, total) {
    modal.total = total;
    var grid = $("[data-lib-grid]");
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
    var pager = $("[data-lib-pager]");
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
    var label = String(name || "").replace(/\.[^.]+$/, "") || "媒体";
    editorInsert(isImage(mime) ? "![" + label + "](" + url + ")" : "[" + label + "](" + url + ")");
    closeModal();
  }

  // ---------- 保存 ----------
  function submit(action) {
    if (pending) return;
    clearError();
    if (!title.value.trim()) { showError("请填写页面标题"); return; }
    pending = action;
    var btns = $$("[data-save]");
    btns.forEach(function (b) { b.disabled = true; });
    var fd = new FormData(form);
    fd.set("_csrf", CSRF);
    fd.set("status", action === "publish" ? "PUBLISHED" : "DRAFT");
    fetch(form.action, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json().catch(function () { return null; }).then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        pending = null;
        btns.forEach(function (b) { b.disabled = false; });
        if (res.ok && res.j && res.j.ok) {
          location.href = DATA.listUrl; // 保存成功回列表（与 Node 保存行为一致）
        } else {
          showError((res.j && res.j.error) || "保存失败，请重试");
        }
      })
      .catch(function () {
        pending = null;
        btns.forEach(function (b) { b.disabled = false; });
        showError("网络错误，保存失败");
      });
  }

  // ---------- 绑定 ----------
  // 标题 → slug 联动（仅新建且未手动改过；对齐文章编辑器行为）
  title.addEventListener("input", function () {
    if (!slugTouched) slug.value = slugify(title.value);
  });
  slug.addEventListener("input", function () { slugTouched = true; });

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

  // 媒体弹窗绑定
  $$("[data-close-modal]").forEach(function (b) { b.addEventListener("click", closeModal); });
  var modalEl = $("#mediaModal");
  modalEl.addEventListener("click", function (e) { if (e.target === modalEl) closeModal(); });
  $$("[data-mtab]", modalEl).forEach(function (b) {
    b.addEventListener("click", function () {
      modal.tab = b.getAttribute("data-mtab");
      renderModal();
    });
  });
  $("#mediaFile").addEventListener("change", function () { handleUpload($("#mediaFile").files); });
  var uploadPanel = $('[data-mpanel="upload"]', modalEl);
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
  $("[data-lib-q]").addEventListener("input", function () {
    modal.q = $("[data-lib-q]").value.trim();
    if (modal.searchTimer) clearTimeout(modal.searchTimer);
    modal.searchTimer = setTimeout(function () { loadLibrary(true); }, 500);
  });
  window.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && modal.open) closeModal();
  });

  // 初始化编辑器
  initEditor();
})();
