/**
 * 微语编辑器交互：Markdown 编辑与图片上传。
 *
 * 与文章、页面编辑器共用 Vditor，配置从 window.PAFISH_MICRO_EDITOR 读，
 * 不依赖 PAFISH_EDITOR_DATA（那份是文章专用的，含标题/别名/分类等字段）。
 * 表单是原生提交，编辑器值经 input 回调同步回隐藏的 #fContent。
 * 配置：window.PAFISH_MICRO_EDITOR { uploadUrl, assetBase, csrf }
 */
(function () {
  "use strict";

  var DATA = window.PAFISH_MICRO_EDITOR || {};
  var CSRF = DATA.csrf || "";

  function $(sel, root) { return (root || document).querySelector(sel); }

  var content = $("#fContent");
  if (!content) return;
  var form = $("#microForm");

  // 与 emlog 一样，提交后立即锁定按钮；服务端令牌负责处理网络重试和重复请求。
  if (form) {
    form.addEventListener("submit", function (event) {
      if (form.dataset.submitting === "1") {
        event.preventDefault();
        return;
      }
      form.dataset.submitting = "1";
      Array.prototype.forEach.call(form.querySelectorAll("button[type=submit]"), function (button) {
        button.disabled = true;
      });
    });
  }

  function editorHeight() {
    if (window.matchMedia("(max-width: 767px)").matches) {
      return Math.max(320, Math.min(440, window.innerHeight - 210));
    }
    return 520;
  }

  /** Vditor 资源缺失时退回原生 textarea，保证还能写。 */
  function editorFallback() {
    var mount = $("#vditorMount");
    if (mount) mount.hidden = true;
    content.hidden = false;
    content.className = "input";
    content.rows = 14;
    content.style.width = "100%";
    content.style.padding = "12px";
  }

  function initEditor() {
    var mount = $("#vditorMount");
    if (!mount) return;
    if (typeof window.Vditor !== "function") { editorFallback(); return; }

    var vditor = new Vditor(mount, {
      height: editorHeight(),
      mode: "ir",
      value: content.value || "",
      placeholder: "记录此刻想说的话……（支持拖拽/粘贴图片上传）",
      lang: "zh_CN",
      cdn: DATA.assetBase + "/vendor/vditor",
      cache: { enable: false }, // 内容走 textarea 与数据库，不用 localStorage 缓存
      counter: { enable: true },
      toolbar: [
        "headings", "bold", "italic", "strike", "|",
        "line", "quote", "list", "ordered-list", "check", "|",
        "code", "inline-code", "|",
        "upload", "link", "table", "|", "emoji", "|",
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
        linkToImgUrl: false, // 粘贴外链图片保留原地址，不强制上传
        error: function (msg) {
          if (typeof window.pafishNotify === "function") {
            window.pafishNotify(typeof msg === "string" ? msg : "上传失败", true);
            return;
          }
          window.alert(typeof msg === "string" ? msg : "上传失败");
        },
      },
      preview: {
        hljs: { style: "github", lineNumber: false },
        math: { engine: "KaTeX" },
      },
    });

    // 暴露给同页的扩展注入脚本（如插件往正文追加 %标签），避免它们直接改隐藏域导致编辑器不同步。
    window.__xhsMicroVditor = vditor;
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initEditor);
  } else {
    initEditor();
  }
})();
