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

  var batchBar = document.querySelector(".admin-batch-bar");
  var allCheck = document.getElementById("adminCheckAll");
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll(".admin-row-check"));
  var countEl = batchBar ? batchBar.querySelector(".admin-batch-count-number") : null;
  var opSelect = batchBar ? batchBar.querySelector(".admin-batch-op") : null;
  var moveSelect = batchBar ? batchBar.querySelector(".admin-batch-move") : null;
  var applyBtn = batchBar ? batchBar.querySelector(".admin-batch-apply") : null;

  function syncBatchControls() {
    if (!opSelect || !moveSelect) return;
    var moveHidden = opSelect.value !== "move";
    moveSelect.hidden = moveHidden;
    var moveControl = moveSelect.closest ? moveSelect.closest(".admin-control") : null;
    if (moveControl) moveControl.hidden = moveHidden;
  }
  if (opSelect) { opSelect.addEventListener("change", syncBatchControls); syncBatchControls(); }

  function selectedIds() {
    return rowChecks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
  }
  function updateBatchBar() {
    if (!batchBar) return;
    var n = selectedIds().length;
    batchBar.hidden = n === 0;
    if (countEl) countEl.textContent = n;
    [opSelect, moveSelect, applyBtn, batchBar.querySelector("[data-batch-clear]")].forEach(function (control) {
      if (!control) return;
      control.disabled = n === 0;
      var wrapper = control.closest ? control.closest(".admin-control") : null;
      var trigger = wrapper && wrapper.querySelector(".admin-control-trigger");
      if (trigger) trigger.disabled = n === 0;
    });
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
  updateBatchBar();

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
        if (!moveSel.value) { pafishNotify(window.pafishT("js.select_a_destination_category_first"), true); return; }
      }
      var selectedOption = opSelect ? opSelect.options[opSelect.selectedIndex] : submitter;
      var confirmText = (selectedOption && selectedOption.dataset.batchConfirm) || submitter.dataset.batchConfirm || "";
      var ask = confirmText.replace(/\{n\}/g, String(ids.length));
      var confirmed = !confirmText
        ? Promise.resolve(true)
        : (window.pafishConfirm
          ? window.pafishConfirm(ask, { title: window.pafishT("js.confirm_batch_action") })
          : Promise.resolve(window.confirm(ask)));
      confirmed.then(function (ok) {
        if (!ok) return;
        submitter.disabled = true;
        var fd = new FormData(batchBar);
        fd.delete("ids");
        ids.forEach(function (id) { fd.append("ids[]", id); });
        fd.set("op", op);
        fetch(batchBar.action, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
          .then(function (r) { return r.json().catch(function () { return {}; }); })
          .then(function (d) {
            if (d && d.ok) { pafishToastReload(window.pafishT("js.batch_operation_complete"), "success"); return; }
            submitter.disabled = false;
            pafishNotify((d && d.error) || window.pafishT("common.operation_failed"), true);
          })
          .catch(function () {
            submitter.disabled = false;
            pafishNotify(window.pafishT("common.network_error_retry"), true);
          });
      });
    });
  }

  document.querySelectorAll(".admin-inline-form").forEach(function (f) {
    var btn = f.querySelector("button[type=submit]");
    f.addEventListener("submit", function (e) {
      e.preventDefault();
      var confirmText = f.dataset.confirm || "";
      var confirmed = !confirmText
        ? Promise.resolve(true)
        : (window.pafishConfirm
          ? window.pafishConfirm(confirmText, { title: window.pafishT("js.confirm_action"), danger: true })
          : Promise.resolve(window.confirm(confirmText)));
      confirmed.then(function (ok) {
        if (!ok) return;
        if (btn) {
          btn.disabled = true;
          btn.dataset.originalHtml = btn.dataset.originalHtml || btn.innerHTML;
          btn.textContent = window.pafishT("js.submitting");
        }
        fetch(f.action, { method: "POST", body: new FormData(f), headers: { "X-Requested-With": "XMLHttpRequest" } })
          .then(function (r) { return r.json().catch(function () { return {}; }); })
          .then(function (d) {
            if (d && d.ok) { pafishToastReload(window.pafishT("js.operation_complete"), "success"); return; }
            restoreButton();
            pafishNotify((d && d.error) || window.pafishT("common.operation_failed"), true);
          })
          .catch(function () {
            restoreButton();
            pafishNotify(window.pafishT("common.network_error_retry"), true);
          });
      });
    });
    function restoreButton() {
      if (!btn) return;
      btn.disabled = false;
      if (btn.dataset.originalHtml) btn.innerHTML = btn.dataset.originalHtml;
    }
  });
})();
