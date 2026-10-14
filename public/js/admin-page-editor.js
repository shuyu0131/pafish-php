/**
 * 页面编辑器交互：Markdown 编辑、媒体插入和自动保存。
 * - Markdown 编辑：轻量源码编辑器，预览按需请求服务端
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
  var modal = { open: false, tab: "upload", page: 1, q: "", total: 0, uploading: false, request: 0, searchTimer: null };

  // 编辑器当前值实时同步回 #fContent（表单兜底）
  function editorValue() { return content.value; }

  function editorInsert(md) {
    if (window.PAFISH_MARKDOWN_EDITOR && typeof window.PAFISH_MARKDOWN_EDITOR.insert === "function") {
      window.PAFISH_MARKDOWN_EDITOR.insert(md);
      return;
    }
    content.value += content.value ? "\n\n" + md : md;
  }

  function showError(msg) {
    if (errorBox) { errorBox.textContent = msg; errorBox.hidden = false; }
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

  // ---------- 轻量 Markdown 编辑器 ----------
  // 资源缺失兜底：显示原生 textarea 直接编辑
  function editorFallback() {
    var mount = $("#markdownMount");
    if (mount) mount.style.display = "none";
    content.hidden = false;
    content.style.height = "520px";
    content.style.width = "100%";
    content.style.padding = "12px";
  }

  function initEditor() {
    var mount = $("#markdownMount");
    if (!mount) return;
    if (!window.PAFISH_MARKDOWN_EDITOR || typeof window.PAFISH_MARKDOWN_EDITOR.init !== "function") { editorFallback(); return; }
    window.__pafishMarkdownEditor = window.PAFISH_MARKDOWN_EDITOR.init({
      mount: mount,
      field: content,
      placeholder: "在此输入页面内容（支持 Markdown）…",
      previewUrl: DATA.previewUrl,
      uploadUrl: DATA.uploadUrl,
      csrf: CSRF,
      onMedia: openModal,
    });
    return;
  }

  // ---------- 媒体弹窗 ----------
  function openModal() {
    if (modal.uploading) return;
    modal.open = true;
    modal.tab = "upload";
    modal.page = 1;
    modal.q = "";
    modal.total = 0;
    $('[data-lib-q]').value = '';
    var mediaTitle = $('#editorMediaTitle');
    var coverMode = modal.mode === 'cover';
    if (mediaTitle) mediaTitle.textContent = coverMode ? '选择封面' : '插入媒体';
    var mediaFile = $('#mediaFile');
    mediaFile.dataset.fullAccept = mediaFile.dataset.fullAccept || mediaFile.accept;
    mediaFile.accept = coverMode ? 'image/*,.svg' : mediaFile.dataset.fullAccept;
    $('[data-mupload-hint]').textContent = coverMode ? '仅支持图片文件，用作文章封面' : '图片插入为图片，其他文件插入为下载链接';
    var me = $("[data-mupload-error]");
    if (me) { me.hidden = true; me.textContent = ""; }
    $("#mediaModal").hidden = false;
    renderModal();
  }
  function closeModal() {
    modal.open = false;
    modal.request++;
    $("#mediaModal").hidden = true;
    if (modal.searchTimer) clearTimeout(modal.searchTimer);
  }
  function renderModal() {
    $$("[data-mtab]", $("#mediaModal")).forEach(function (b) {
      var active = b.getAttribute("data-mtab") === modal.tab;
      b.classList.toggle("active", active);
      b.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    $$("[data-mpanel]", $("#mediaModal")).forEach(function (p) {
      p.hidden = p.getAttribute("data-mpanel") !== modal.tab;
    });
    if (modal.tab === "library") loadLibrary(true);
  }
  function handleUpload(files) {
    var f = files && files[0];
    if (!f || modal.uploading) return;
    if (modal.mode === 'cover' && !isImage(f.type)) {
      var error = $('[data-mupload-error]'); error.textContent = '请选择图片作为封面'; error.hidden = false; return;
    }
    modal.uploading = true;
    var panel = $('[data-mpanel="upload"]');
    panel.setAttribute('aria-busy', 'true');
    var browse = $('[data-media-browse]');
    browse.disabled = true;
    $$("[data-mtab]").forEach(function (tab) { tab.disabled = true; });
    $('[data-mupload-error]').hidden = true;
    var help = $('[data-mupload-hint]').textContent;
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
        modal.uploading = false;
        panel.removeAttribute('aria-busy');
        browse.disabled = false;
        $$("[data-mtab]").forEach(function (tab) { tab.disabled = false; });
        if (hint) hint.textContent = help;
        var mf = $("#mediaFile");
        if (mf) mf.value = "";
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
    if (modal.mode === 'cover') params.set('type', 'image');
    fetch(DATA.uploadsUrl + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
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
          pafishToastNavigate(DATA.listUrl, "页面已保存", "success");
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

  $('[data-media-browse]').addEventListener('click', function () { $('#mediaFile').click(); });

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
      if (modal.uploading) return;
      modal.request++;
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
