/**
 * pafish 亮暗主题切换（对应 Node 版 next-themes 行为）
 * - localStorage 记忆用户选择（light / dark），未选择时跟随系统 prefers-color-scheme
 * - .dark 类加到 <html> 上，配合 CSS 变量双色组
 * - head 内联脚本（header.php）负责首帧防闪烁，本文件负责交互
 */
(function () {
  "use strict";
  var STORAGE_KEY = "pafish-theme";
  var mq = window.matchMedia("(prefers-color-scheme: dark)");

  function stored() {
    try {
      return localStorage.getItem(STORAGE_KEY);
    } catch (e) {
      return null;
    }
  }

  function resolved() {
    var s = stored();
    if (s === "light" || s === "dark") return s;
    return mq.matches ? "dark" : "light";
  }

  function apply() {
    var dark = resolved() === "dark";
    document.documentElement.classList.toggle("dark", dark);
    return dark;
  }

  apply();

  var toggle = document.querySelector(".theme-toggle");
  if (!toggle) return;

  toggle.hidden = false;
  var icon = toggle.querySelector(".theme-toggle-icon");
  // lucide Moon/Sun 内联 SVG（与后端 admin_icon() 输出同款，避免依赖图标字体/CDN）
  var SVG_MOON = '<svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>';
  var SVG_SUN = '<svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>';

  function render() {
    var isDark = resolved() === "dark";
    var label = isDark ? "切换到亮色" : "切换到暗色";
    toggle.setAttribute("aria-label", label);
    toggle.setAttribute("title", label);
    if (icon) icon.innerHTML = isDark ? SVG_SUN : SVG_MOON;
  }

  render();

  toggle.addEventListener("click", function () {
    var next = resolved() === "dark" ? "light" : "dark";
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch (e) { /* 隐私模式等场景忽略 */ }
    apply();
    render();
  });

  // 未手动选择时跟随系统切换
  mq.addEventListener("change", function () {
    var s = stored();
    if (s !== "light" && s !== "dark") {
      apply();
      render();
    }
  });
})();

/**
 * 文章图片放大预览（对应 Node 版 markdown-render 的图片缩放）：
 * 点击 .md-content 内图片弹出全屏遮罩，ESC / 点遮罩关闭
 */
(function () {
  "use strict";
  var overlay = null;

  function close() {
    if (!overlay) return;
    overlay.remove();
    overlay = null;
  }

  function open(src, alt) {
    close();
    overlay = document.createElement("div");
    overlay.className = "img-zoom";
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-label", "图片预览");
    overlay.addEventListener("click", close);
    var img = document.createElement("img");
    img.src = src;
    img.alt = alt || "";
    img.addEventListener("click", function (e) { e.stopPropagation(); });
    overlay.appendChild(img);
    document.body.appendChild(overlay);
  }

  document.addEventListener("click", function (e) {
    var img = e.target.closest ? e.target.closest(".md-content img") : null;
    if (img && img.src) open(img.src, img.alt);
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") close();
  });
})();
