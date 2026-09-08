/**
 * 前台文章/页面内容增强渲染（与 Vditor 编辑器能力对齐）：
 * - .md-math / .md-math-block：KaTeX 渲染行内/块级数学公式（服务端已实体化，取 textContent 自动解码）
 * - .md-mermaid：mermaid/mindmap/flowchart 围栏代码块 → 图表（渲染失败保留源码文本）
 * 资源按需加载：katex.min.css 动态注入；JS 由 footer.php 引入（复用 public/vendor/vditor/dist/js/）
 */
(function () {
  "use strict";

  function ready(fn) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn);
    } else {
      fn();
    }
  }

  ready(function () {
    var root = document.querySelector(".md-content");
    if (!root) return;

    // ---------- 数学公式（KaTeX） ----------
    var mathEls = root.querySelectorAll(".md-math, .md-math-block");
    if (mathEls.length > 0) {
      // 页面含公式时才注入 KaTeX 样式（避免全站每页加载）
      if (window.PAFISH_KATEX_CSS && !document.getElementById("pafish-katex-css")) {
        var link = document.createElement("link");
        link.id = "pafish-katex-css";
        link.rel = "stylesheet";
        link.href = window.PAFISH_KATEX_CSS;
        document.head.appendChild(link);
      }
      if (window.katex) {
        Array.prototype.forEach.call(mathEls, function (el) {
          var tex = el.textContent.replace(/^\$+|\$+$/g, ""); // 去掉包裹的 $ / $$
          if (!tex.trim()) return;
          try {
            katex.render(tex, el, {
              displayMode: el.classList.contains("md-math-block"),
              throwOnError: false,
            });
          } catch (e) {
            // 渲染失败：保留原文继续展示（KaTeX 自身对 throwOnError=false 亦有容错）
          }
        });
      }
    }

    // ---------- 图表（mermaid / mindmap / flowchart） ----------
    var chartEls = root.querySelectorAll(".md-mermaid");
    if (chartEls.length > 0 && window.mermaid) {
      mermaid.initialize({ startOnLoad: false, securityLevel: "loose" });
      Array.prototype.forEach.call(chartEls, function (el, i) {
        var code = el.textContent;
        if (!code.trim()) return;
        mermaid
          .render("pafish-md-plot-" + i, code)
          .then(function (r) {
            el.outerHTML = r.svg;
          })
          .catch(function () {
            // 渲染失败：源码已留在容器内，保持可读
          });
      });
    }
  });
})();