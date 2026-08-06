/**
 * 页面编辑器（对齐 Node page-editor.tsx 的 MdEditor 精简版）：
 * - Markdown 编辑：@uiw/react-md-editor（中文工具栏/分栏预览/全屏，react18 内置单文件）
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

  // 编辑器当前值：React onChange 实时同步回 #fContent（表单兜底）
  function editorValue() { return content.value; }

  // 光标处插入（媒体弹窗 / 拖拽粘贴上传用）：优先编辑器 API（对齐 Node api.replaceSelection）
  function editorInsert(md) {
    var api = window.__pafishMdApi;
    if (api && typeof api.replaceSelection === "function") {
      try {
        api.replaceSelection(md);
        return;
      } catch (e) { /* 回退 DOM 方式 */ }
    }
    var real = $(".w-md-editor-text-input");
    if (real) {
      var start = real.selectionStart != null ? real.selectionStart : real.value.length;
      var end = real.selectionEnd != null ? real.selectionEnd : start;
      real.setRangeText(md, start, end, "end");
      real.dispatchEvent(new Event("input", { bubbles: true })); // 触发 React onChange
      real.focus();
      return;
    }
    content.value += md;
  }

  function showError(msg) { if (errorBox) { errorBox.textContent = msg; errorBox.hidden = false; } }
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

  // ---------- 编辑器（@uiw/react-md-editor，React 挂载） ----------
  // 资源缺失兜底：显示原生 textarea 直接编辑
  function editorFallback() {
    var mount = $("#mdEditorMount");
    if (mount) mount.style.display = "none";
    content.hidden = false;
    content.style.height = "520px";
    content.style.width = "100%";
    content.style.padding = "12px";
  }

  function initEditor() {
    var mount = $("#mdEditorMount");
    if (!mount) return;
    var MDEditor = window.MDEditor, React = window.React, ReactDOM = window.ReactDOM;
    if (!MDEditor || !React || !ReactDOM) { editorFallback(); return; }

    var Comp = MDEditor.default || MDEditor;
    var cn = window.PAFISH_MD_CN || { commands: [], extra: [] };

    // 媒体插入命令：工具栏按钮打开弹窗（对齐 Node insertMediaCommand）
    var insertMediaCommand = {
      name: "insert-media",
      keyCommand: "insert-media",
      buttonProps: { "aria-label": "插入媒体", title: "插入媒体（本地上传或媒体库）" },
      icon: React.createElement("svg", { viewBox: "0 0 24 24", width: 14, height: 14, fill: "none", stroke: "currentColor", strokeWidth: 2, strokeLinecap: "round", strokeLinejoin: "round" },
        React.createElement("path", { d: "M16 5h6" }),
        React.createElement("path", { d: "M19 2v6" }),
        React.createElement("path", { d: "M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h10" }),
        React.createElement("path", { d: "m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" })
      ),
      execute: function (_state, api) {
        window.__pafishMdApi = api;
        openModal();
      },
    };

    // 图片/文件上传并插入（拖拽与粘贴共用）
    function handleFile(f) {
      if (!f) return;
      uploadFile(f)
        .then(function (j) {
          if (isImage(f.type)) {
            editorInsert("![图片](" + j.url + ")");
          } else {
            var label = (f.name || "").replace(/\.[^.]+$/, "") || "文件";
            editorInsert("[" + label + "](" + j.url + ")");
          }
        })
        .catch(function (err) { showError(err.message || "上传失败"); });
    }

    // 包装组件：受控循环（@uiw 内部 state 与 value prop 同步，不回传会导致输入被回滚）
    var PafishEditor = function (props) {
      var st = React.useState(props.initialValue);
      var value = st[0];
      var setValue = st[1];
      return React.createElement(Comp, Object.assign({}, props.mdProps, {
        value: value,
        onChange: function (v) {
          setValue(v || "");
          props.onChange(v || "");
        },
      }));
    };

    var el = React.createElement(PafishEditor, {
      initialValue: content.value,
      onChange: function (v) { content.value = v || ""; },
      mdProps: {
        height: 420,
        preview: "edit",
        commands: (cn.commands || []).concat([insertMediaCommand]),
        extraCommands: cn.extra || [],
        visibleDragbar: false,
        textareaProps: { placeholder: "在此输入页面内容（支持 Markdown）…" },
        onPaste: function (e) {
          var files = e.clipboardData && e.clipboardData.files;
          if (!files || !files.length) return;
          e.preventDefault();
          handleFile(files[0]);
        },
        onDrop: function (e) {
          var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
          if (!f) return;
          e.preventDefault();
          handleFile(f);
        },
      },
    });

    var root = ReactDOM.createRoot(mount);
    root.render(el);
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
