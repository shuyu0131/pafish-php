(function () {
  "use strict";

  var csrf = document.querySelector("[data-store-update-csrf]");
  var buttons = document.querySelectorAll(".admin-store-update");
  if (!csrf || !buttons.length) return;

  function post(formData) {
    return fetch(csrf.dataset.storeUpdateUrl, {
      method: "POST",
      body: formData,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    }).then(function (response) {
      return response.json();
    }).then(function (result) {
      if (result && result.ok) return result;
      throw new Error((result && result.error) || "更新失败");
    });
  }

  buttons.forEach(function (button) {
    button.addEventListener("click", function () {
      var title = button.dataset.title || button.dataset.name;
      var current = button.dataset.localVersion || "当前版本";
      var version = button.dataset.version || "";
      var changelog = button.dataset.changelog || "";
      var message = "将“" + title + "”从 v" + current + " 更新到 v" + version + "？";
      var detail = "更新失败会自动恢复旧版本。" + (changelog ? "\n\n更新内容：\n" + changelog : "");
      var confirmation = window.pafishConfirm
        ? window.pafishConfirm(message, { title: "更新应用", accept: "确认更新", danger: false, detail: detail })
        : Promise.resolve(window.confirm(message + "\n" + detail));

      confirmation.then(function (accepted) {
        if (!accepted) return;
        var originalText = button.textContent;
        button.disabled = true;
        button.classList.add("is-loading");
        button.textContent = "正在更新";
        var formData = new FormData();
        formData.append("kind", button.dataset.kind);
        formData.append("name", button.dataset.name);
        formData.append("_csrf", csrf.dataset.storeUpdateCsrf);
        return post(formData).then(function (result) {
          var notice = "已更新 " + result.title + " 到 v" + result.version;
          if (window.pafishToastReload) window.pafishToastReload(notice, "success");
          else window.location.reload();
        }).catch(function (error) {
          button.disabled = false;
          button.classList.remove("is-loading");
          button.textContent = originalText;
          if (window.pafishToast) window.pafishToast(error.message, "error");
          else window.alert(error.message);
        });
      });
    });
  });
})();
