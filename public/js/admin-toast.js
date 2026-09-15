/**
 * 后台统一操作提示。
 * 可供所有异步页面直接调用：window.pafishToast('操作完成', 'success')。
 */
(function () {
  "use strict";

  var ICONS = {
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>',
    error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>',
    warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>'
  };
  var TITLES = { success: "成功", error: "错误", warning: "警告", info: "提示" };

  function normalizeType(type) {
    return ICONS[type] ? type : "info";
  }

  function normalizePayload(message, type) {
    if (message && typeof message === "object") {
      var objectType = normalizeType(message.type || type);
      return {
        title: String(message.title || TITLES[objectType]),
        content: String(message.content || message.message || ""),
        type: objectType
      };
    }
    type = normalizeType(type);
    return { title: TITLES[type], content: String(message || ""), type: type };
  }

  function bind(toast) {
    if (!toast || toast.dataset.toastReady === "1") return;
    toast.dataset.toastReady = "1";
    var timer = null;

    function dismiss() {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
      toast.classList.remove("admin-toast-show");
      window.setTimeout(function () { toast.remove(); }, 240);
    }
    function start() {
      if (timer) window.clearTimeout(timer);
      toast.classList.add("admin-toast-show");
      timer = window.setTimeout(dismiss, 4000);
    }

    toast.addEventListener("mouseenter", function () {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
    });
    toast.addEventListener("mouseleave", function () {
      if (!timer) start();
    });
    var close = toast.querySelector(".admin-toast-close");
    if (close) {
      close.addEventListener("click", function (event) {
        event.stopPropagation();
        dismiss();
      });
    }
    toast.addEventListener("click", dismiss);
    start();
  }

  function getStack() {
    var stack = document.querySelector("[data-toast-stack]");
    if (!stack) {
      stack = document.createElement("div");
      stack.className = "admin-toast-stack";
      stack.setAttribute("data-toast-stack", "");
      document.body.appendChild(stack);
    }
    return stack;
  }

  window.pafishToast = function (message, type) {
    if (!document.body) return;
    var payload = normalizePayload(message, type);
    var toast = document.createElement("div");
    toast.className = "admin-toast admin-toast-" + payload.type;
    toast.setAttribute("data-toast", "");
    toast.setAttribute("role", payload.type === "error" ? "alert" : "status");
    toast.setAttribute("aria-live", "polite");
    toast.innerHTML = '<span class="admin-toast-icon">' + ICONS[payload.type] + '</span><span class="admin-toast-content"><strong class="admin-toast-title"></strong><span class="admin-toast-msg"></span></span><button type="button" class="admin-toast-close" aria-label="关闭提示" title="关闭">&times;</button>';
    toast.querySelector(".admin-toast-title").textContent = payload.title;
    toast.querySelector(".admin-toast-msg").textContent = payload.content;
    getStack().appendChild(toast);
    bind(toast);
  };

  /** 统一异步结果提示：true=错误（红）/ false=成功（绿），替代页面里零散的原生 alert */
  window.pafishNotify = function (message, isError) {
    window.pafishToast(String(message || ""), isError ? "error" : "success");
  };

  window.pafishToastReload = function (message, type, delay) {
    try {
      sessionStorage.setItem("pafishToastPending", JSON.stringify({
        message: String(message || ""),
        type: normalizeType(type || "success")
      }));
    } catch (e) {}
    window.setTimeout(function () { window.location.reload(); }, Number(delay) || 520);
  };

  window.pafishToastNavigate = function (url, message, type, delay) {
    try {
      sessionStorage.setItem("pafishToastPending", JSON.stringify({
        message: String(message || ""),
        type: normalizeType(type || "success")
      }));
    } catch (e) {}
    window.setTimeout(function () { window.location.href = url; }, Number(delay) || 520);
  };

  function init() {
    document.querySelectorAll("[data-toast]").forEach(bind);
    try {
      var pending = sessionStorage.getItem("pafishToastPending");
      if (pending) {
        sessionStorage.removeItem("pafishToastPending");
        var parsed = JSON.parse(pending);
        window.pafishToast(parsed, parsed.type);
      }
    } catch (e) {}
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
