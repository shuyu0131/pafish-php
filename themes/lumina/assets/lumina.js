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
    var image = event.target.closest("[data-lumina-image]");
    if (image) openLightbox(image.getAttribute("data-lumina-image"));
    if (event.target.closest("[data-lumina-lightbox-close]") || (event.target.matches && event.target.matches("[data-lumina-lightbox]"))) closeLightbox();
    var music = event.target.closest("[data-lumina-music] button");
    if (music) toggleMusic(music);
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") { closeSearch(); closeLightbox(); }
  });

  function lightbox() { return document.querySelector("[data-lumina-lightbox]"); }
  function openLightbox(src) {
    var box = lightbox();
    if (!box || !src) return;
    box.querySelector("img").src = src;
    box.hidden = false;
    box.querySelector("button").focus();
  }
  function closeLightbox() {
    var box = lightbox();
    if (!box) return;
    box.hidden = true;
    box.querySelector("img").removeAttribute("src");
  }
  function toggleMusic(button) {
    var card = button.closest("[data-lumina-music]");
    var audio = card && card.querySelector("audio");
    if (!audio) return;
    if (audio.paused) {
      document.querySelectorAll("[data-lumina-music] audio").forEach(function (item) { if (item !== audio) item.pause(); });
      audio.play().catch(function () {});
      button.setAttribute("aria-label", "暂停");
    } else {
      audio.pause();
      button.setAttribute("aria-label", "播放");
    }
    audio.addEventListener("ended", function () { button.setAttribute("aria-label", "播放"); }, { once: true });
  }
})();
