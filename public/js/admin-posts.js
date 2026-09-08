/* 后台文章列表交互：
   - 筛选/排序/每页条数变更跳转（保留现有参数、重置页码；per_page 写 cookie）
   - checkbox 选择 → 批量操作栏（全选 indeterminate）
   - 批量操作 fetch 提交（confirm 文案 {n} 替换；移动需选分类）
   - 单行操作 fetch 提交；删除按钮二次点击确认（对齐 DeleteButton） */
(function () {
  "use strict";

  // ---------- URL 工具 ----------
  function currentParams() {
    return new URLSearchParams(location.search);
  }
  function buildUrl(patch, options) {
    var p = currentParams();
    for (var k in patch) {
      if (Object.prototype.hasOwnProperty.call(patch, k)) {
        var v = patch[k];
        if (v === undefined || v === "" || v === null) p.delete(k);
        else p.set(k, v);
      }
    }
    var s = p.toString();
    return s ? "/admin/posts?" + s : "/admin/posts";
  }

  // ---------- 筛选 select ----------
  document.querySelectorAll(".admin-filter-select[data-filter-url]").forEach(function (sel) {
    sel.addEventListener("change", function () {
      var key = sel.dataset.filterUrl;
      var v = sel.value;
      var patch = {};
      patch[key] = v === "" ? undefined : v;
      patch.page = undefined;
      location.href = buildUrl(patch);
    });
  });

  // ---------- 每页条数（cookie 记忆一年） ----------
  var perSel = document.getElementById("adminPerPage");
  if (perSel) {
    perSel.addEventListener("change", function () {
      var v = perSel.value;
      document.cookie = "admin_posts_per_page=" + v + "; path=/; max-age=31536000";
      var patch = { per: v === "20" ? undefined : v };
      patch.page = undefined;
      location.href = buildUrl(patch);
    });
  }

  // ---------- 选择与批量操作栏 ----------
  var batchBar = document.querySelector(".admin-batch-bar");
  var allCheck = document.getElementById("adminCheckAll");
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll(".admin-row-check"));
  var countEl = batchBar ? batchBar.querySelector(".admin-batch-count b") : null;

  function selectedIds() {
    return rowChecks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
  }
  function updateBatchBar() {
    if (!batchBar) return;
    var n = selectedIds().length;
    if (n > 0) {
      batchBar.hidden = false;
      if (countEl) countEl.textContent = n;
    } else {
      batchBar.hidden = true;
    }
    if (allCheck) {
      var some = rowChecks.some(function (c) { return c.checked; });
      var all = some && rowChecks.every(function (c) { return c.checked; });
      allCheck.checked = all;
      allCheck.indeterminate = some && !all;
    }
  }
  rowChecks.forEach(function (c) { c.addEventListener("change", updateBatchBar); });
  if (allCheck) {
    allCheck.addEventListener("change", function () {
      rowChecks.forEach(function (c) { c.checked = allCheck.checked; });
      updateBatchBar();
    });
  }
  var clearBtn = batchBar ? batchBar.querySelector("[data-batch-clear]") : null;
  if (clearBtn) {
    clearBtn.addEventListener("click", function () {
      rowChecks.forEach(function (c) { c.checked = false; });
      updateBatchBar();
    });
  }

  // ---------- 批量操作提交 ----------
  if (batchBar) {
    batchBar.addEventListener("submit", function (e) {
      e.preventDefault();
      var ids = selectedIds();
      if (ids.length === 0) return;
      var submitter = e.submitter;
      if (!submitter) return;
      var op = submitter.value;
      if (op === "move") {
        var moveSel = batchBar.querySelector(".admin-batch-move");
        if (!moveSel.value) { pafishNotify("请先选择要移动到的分类"); return; }
      }
      var confirmText = submitter.dataset.batchConfirm || "";
      var ask = confirmText.replace(/\{n\}/g, String(ids.length));
      var confirmed = !confirmText
        ? Promise.resolve(true)
        : (window.pafishConfirm
          ? window.pafishConfirm(ask, { title: "批量操作确认" })
          : Promise.resolve(window.confirm(ask)));
      confirmed.then(function (ok) {
        if (!ok) return;
        submitter.disabled = true; // 提交期间防重复点击（成功刷新 / 失败恢复）
        var fd = new FormData(batchBar);
        fd.set("ids", JSON.stringify(ids));
        fd.set("op", op);
        fetch(batchBar.action, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
          .then(function (r) { return r.json().catch(function () { return {}; }); })
          .then(function (d) {
            if (d && d.ok) { location.reload(); return; }
            submitter.disabled = false;
            pafishNotify((d && d.error) || "操作失败");
          })
          .catch(function () {
            submitter.disabled = false;
            pafishNotify("网络错误，请重试");
          });
      });
    });
  }

  // ---------- 单行操作（二次确认 + fetch） ----------
  document.querySelectorAll(".admin-inline-form").forEach(function (f) {
    var btn = f.querySelector("button[type=submit]");
    var original = btn ? btn.innerHTML : "";
    var armed = false;
    var timer = null;
    f.addEventListener("submit", function (e) {
      e.preventDefault();
      var confirmText = f.dataset.confirm || "";
      if (confirmText && !armed) {
        // 二次点击确认（对齐 DeleteButton：第一次点击变红显示「确认？」）
        armed = true;
        btn.classList.add("admin-confirm-armed");
        btn.title = "再次点击确认删除";
        btn.textContent = "确认？";
        timer = setTimeout(function () { disarm(); }, 3000);
        return;
      }
      if (btn) {
        btn.disabled = true; // 提交期间防重复点击（成功刷新 / 失败恢复）
        btn.textContent = "提交中…";
      }
      fetch(f.action, { method: "POST", body: new FormData(f), headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (d) {
          if (d && d.ok) { location.reload(); return; }
          disarm();
          pafishNotify((d && d.error) || "操作失败");
        })
        .catch(function () {
          disarm();
          pafishNotify("网络错误，请重试");
        });
    });
    function disarm() {
      armed = false;
      if (btn) {
        btn.disabled = false;
        btn.classList.remove("admin-confirm-armed");
        btn.title = "";
        btn.innerHTML = original;
      }
    }
  });
})();
