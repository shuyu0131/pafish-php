/* Shared optional image fields; upload and lookup use the existing media API. */
(function () {
  "use strict";
  var modal, grid, pager, search, target, page = 1, generation = 0, timer;
  function set(input, value) {
    input.value = value;
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }
  function button(text, action) {
    var b = document.createElement("button");
    b.type = "button"; b.className = "btn btn-outline btn-sm"; b.textContent = text;
    b.addEventListener("click", action);
    return b;
  }
  function ensureModal() {
    if (modal) return;
    modal = document.createElement("div");
    modal.className = "admin-modal-backdrop"; modal.hidden = true;
    modal.innerHTML = '<div class="admin-modal" role="dialog" aria-modal="true" aria-label="选择图片">' +
      '<div class="admin-modal-head"><h2 class="admin-modal-title">选择图片</h2><button type="button" class="admin-icon-btn" data-modal-close aria-label="关闭">×</button></div>' +
      '<div class="admin-modal-body"><input class="input admin-lib-search" type="search" aria-label="搜索图片" placeholder="搜索图片…">' +
      '<div class="admin-lib-grid" aria-live="polite"></div><div class="admin-pager admin-lib-pager"></div></div></div>';
    document.body.appendChild(modal);
    grid = modal.querySelector(".admin-lib-grid"); pager = modal.querySelector(".admin-lib-pager");
    search = modal.querySelector("input");
    search.addEventListener("input", function () {
      generation++; clearTimeout(timer);
      timer = setTimeout(function () { page = 1; load(); }, 250);
    });
    search.addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); clearTimeout(timer); page = 1; load(); } });
  }
  function load() {
    var request = ++generation;
    grid.textContent = "加载中…"; pager.replaceChildren();
    fetch(pafishApi("/uploads?page=") + page + "&type=image&q=" + encodeURIComponent(search.value.trim()))
      .then(function (r) { if (!r.ok) throw Error("媒体库加载失败"); return r.json(); })
      .then(function (data) {
        if (request !== generation || modal.hidden) return;
        grid.replaceChildren();
        (data.items || []).forEach(function (item) {
          if (!item.mime || item.mime.indexOf("image/") !== 0) return;
          var cell = button("", function () { if (target) set(target, item.url); modal.hidden = true; });
          cell.className = "admin-lib-item"; cell.title = item.originalName;
          var img = document.createElement("img"); img.src = item.url; img.alt = item.originalName; img.loading = "lazy";
          cell.appendChild(img); grid.appendChild(cell);
        });
        if (!grid.childElementCount) grid.textContent = "没有找到图片";
        page = data.page;
        if (page > 1) pager.appendChild(button("上一页", function () { page--; load(); }));
        if (page < data.pages) pager.appendChild(button("下一页", function () { page++; load(); }));
      }).catch(function (e) { if (request === generation) grid.textContent = e.message; });
  }
  document.querySelectorAll("[data-image-field]").forEach(function (field) {
    var input = field.querySelector("[data-image-value]"), file = field.querySelector("[data-image-file]");
    var upload = field.querySelector("[data-image-upload]"), status = field.querySelector("[data-image-status]");
    var form = field.closest("form"), busy = false, revision = 0;
    input.addEventListener("input", function () { revision++; });
    form.addEventListener("reset", function () { revision++; status.textContent = ""; });
    field.querySelector("[data-image-clear]").addEventListener("click", function () { set(input, ""); status.textContent = ""; });
    field.querySelector("[data-image-library]").addEventListener("click", function () {
      ensureModal(); target = input; page = 1; search.value = ""; modal.hidden = false; load();
    });
    upload.addEventListener("click", function () { file.click(); });
    form.addEventListener("submit", function (e) {
      if (busy) { e.preventDefault(); e.stopImmediatePropagation(); status.textContent = "图片正在上传，请稍候"; }
    }, true);
    file.addEventListener("change", function () {
      var selected = file.files && file.files[0];
      if (!selected || busy) return;
      var oldValue = input.value, record = form.querySelector('input[name="id"]');
      var oldId = record ? record.value : "";
      var oldRevision = revision;
      var data = new FormData(); data.append("file", selected);
      data.append("_csrf", form.querySelector('[name="_csrf"]').value);
      busy = true; upload.disabled = true; status.textContent = "上传中…";
      fetch(pafishApi("/upload"), { method: "POST", body: data, headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (r) { return r.json().then(function (j) {
          if (!r.ok || !j.ok || !j.url || !j.mime || j.mime.indexOf("image/") !== 0) throw Error(j.error || "请选择图片文件");
          if (revision === oldRevision && input.value === oldValue && (!record || record.value === oldId)) {
            set(input, j.url); status.textContent = "图片已上传";
          } else { status.textContent = ""; }
        }); }).catch(function (e) { status.textContent = e.message || "上传失败"; })
        .finally(function () { busy = false; upload.disabled = false; file.value = ""; });
    });
  });
})();
