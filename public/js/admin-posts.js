(function () {
  "use strict";

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

  var batchBar = document.querySelector(".admin-batch-bar");
  var allCheck = document.getElementById("adminCheckAll");
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll(".admin-row-check"));
  var countEl = batchBar ? batchBar.querySelector(".admin-batch-count b") : null;
  var opSelect = batchBar ? batchBar.querySelector(".admin-batch-op") : null;
  var moveSelect = batchBar ? batchBar.querySelector(".admin-batch-move") : null;
  var applyBtn = batchBar ? batchBar.querySelector(".admin-batch-apply") : null;

  function syncBatchControls() {
    if (!opSelect || !moveSelect) return;
    moveSelect.hidden = opSelect.value !== "move";
  }
  if (opSelect) { opSelect.addEventListener("change", syncBatchControls); syncBatchControls(); }

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

  if (batchBar) {
    batchBar.addEventListener("submit", function (e) {
      e.preventDefault();
      var ids = selectedIds();
      if (ids.length === 0) return;
      var submitter = e.submitter || applyBtn;
      if (!submitter) return;
      var op = opSelect ? opSelect.value : submitter.value;
      if (op === "move") {
        var moveSel = moveSelect;
        if (!moveSel.value) { pafishNotify("请先选择要移动到的分类", true); return; }
      }
      var selectedOption = opSelect ? opSelect.options[opSelect.selectedIndex] : submitter;
      var confirmText = (selectedOption && selectedOption.dataset.batchConfirm) || submitter.dataset.batchConfirm || "";
      var ask = confirmText.replace(/\{n\}/g, String(ids.length));
      var confirmed = !confirmText
        ? Promise.resolve(true)
        : (window.pafishConfirm
          ? window.pafishConfirm(ask, { title: "批量操作确认" })
          : Promise.resolve(window.confirm(ask)));
      confirmed.then(function (ok) {
        if (!ok) return;
        submitter.disabled = true;
        var fd = new FormData(batchBar);
        fd.set("ids", JSON.stringify(ids));
        fd.set("op", op);
        fetch(batchBar.action, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
          .then(function (r) { return r.json().catch(function () { return {}; }); })
          .then(function (d) {
            if (d && d.ok) { pafishToastReload("批量操作已完成", "success"); return; }
            submitter.disabled = false;
            pafishNotify((d && d.error) || "操作失败", true);
          })
          .catch(function () {
            submitter.disabled = false;
            pafishNotify("网络错误，请重试", true);
          });
      });
    });
  }

  document.querySelectorAll(".admin-inline-form").forEach(function (f) {
    var btn = f.querySelector("button[type=submit]");
    var original = btn ? btn.innerHTML : "";
    var armed = false;
    var timer = null;
    f.addEventListener("submit", function (e) {
      e.preventDefault();
      var confirmText = f.dataset.confirm || "";
      if (confirmText && !armed) {
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
          if (d && d.ok) { pafishToastReload("操作已完成", "success"); return; }
          disarm();
          pafishNotify((d && d.error) || "操作失败", true);
        })
        .catch(function () {
          disarm();
          pafishNotify("网络错误，请重试", true);
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
