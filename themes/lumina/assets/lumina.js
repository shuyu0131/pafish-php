(function () {
  "use strict";

  function openSearch() {
    var dialog = document.querySelector("[data-lumina-search-dialog]");
    if (!dialog) return;
    dialog.hidden = false;
    window.setTimeout(function () {
      var input = dialog.querySelector("input");
      if (input) input.focus();
    }, 0);
  }
  function closeSearch() {
    var dialog = document.querySelector("[data-lumina-search-dialog]");
    if (dialog) dialog.hidden = true;
  }

  document.addEventListener("click", function (event) {
    if (event.target.closest("[data-lumina-search-open]")) openSearch();
    if (event.target.closest("[data-lumina-search-close]")) closeSearch();
    var dialog = event.target.matches ? event.target.matches("[data-lumina-search-dialog]") : false;
    if (dialog) closeSearch();
    if (event.target.closest("[data-lumina-back-top]")) window.scrollTo({ top: 0, behavior: "smooth" });
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") closeSearch();
  });
})();
