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

  function render() {
    var isDark = resolved() === "dark";
    var label = isDark ? "切换到亮色" : "切换到暗色";
    toggle.setAttribute("aria-label", label);
    toggle.setAttribute("title", label);
    if (icon) icon.textContent = isDark ? "☀️" : "🌙";
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
