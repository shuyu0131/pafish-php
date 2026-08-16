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
  function bindLivePhotos(root) {
    (root || document).querySelectorAll(".lumina-gallery-item.is-live").forEach(function (item) {
      if (item.dataset.luminaLiveBound === "1") return;
      item.dataset.luminaLiveBound = "1";
      var video = item.querySelector("video");
      if (!video) return;
      item.addEventListener("mouseenter", function () { video.play().catch(function () {}); });
      item.addEventListener("mouseleave", function () { video.pause(); });
    });
  }

  var navigating = false;
  function setFeedStatus(feed, message) {
    var status = feed && feed.querySelector("[data-lumina-feed-status]");
    if (status) status.textContent = message || "";
  }
  function loadFeed(url, push, scroll) {
    if (navigating) return;
    var feed = document.querySelector("[data-lumina-feed]");
    if (!feed || !window.fetch || !window.DOMParser) { window.location.href = url; return; }
    navigating = true;
    feed.setAttribute("aria-busy", "true");
    setFeedStatus(feed, "正在加载…");
    fetch(url, { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (response) {
        if (!response.ok) throw new Error("request failed");
        return response.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, "text/html");
        var nextFeed = doc.querySelector("[data-lumina-feed]");
        if (!nextFeed) throw new Error("feed missing");
        feed.replaceWith(nextFeed);
        document.title = doc.title || document.title;
        if (push) history.pushState({ luminaFeed: true }, "", url);
        bindLivePhotos(nextFeed);
        setFeedStatus(nextFeed, "已加载");
        if (scroll) nextFeed.scrollIntoView({ block: "start", behavior: "smooth" });
      })
      .catch(function () { window.location.href = url; })
      .finally(function () { navigating = false; });
  }
  function isSmoothPaginationEnabled() {
    var app = document.querySelector("[data-lumina-smooth-pagination]");
    return app && app.getAttribute("data-lumina-smooth-pagination") === "1";
  }
  document.addEventListener("click", function (event) {
    var link = event.target.closest("[data-lumina-feed] .lumina-pagination a[href]");
    if (!link || !isSmoothPaginationEnabled() || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    var url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin) return;
    event.preventDefault();
    loadFeed(url.href, true, true);
  });
  window.addEventListener("popstate", function () {
    if (isSmoothPaginationEnabled()) loadFeed(window.location.href, false, false);
  });
  bindLivePhotos(document);
  document.addEventListener("click", function (event) {
    var button = event.target.closest("[data-lumina-redpacket-claim]");
    if (!button || button.disabled) return;
    var card = button.closest("[data-lumina-redpacket]");
    if (!card) return;
    button.disabled = true;
    var data = new FormData();
    data.append("_csrf", card.getAttribute("data-csrf") || "");
    fetch(pafishApi("/redpacket/" + card.getAttribute("data-post-id") + "/claim"), { method: "POST", body: data, credentials: "same-origin" })
      .then(function (response) { return response.json().then(function (body) { return { status: response.status, body: body }; }); })
      .then(function (result) {
        if (result.status === 401) {
          window.location.href = (result.body.login_url || "/login") + "?from=" + encodeURIComponent(location.pathname + location.search);
          return;
        }
        if (!result.body.ok) throw new Error(result.body.error || "领取失败");
        var summary = card.querySelector("[data-lumina-redpacket-summary]");
        if (summary) summary.textContent = result.body.remaining_points + " 积分 · 剩余 " + result.body.remaining_count + " 份";
        var note = document.createElement("em");
        note.textContent = "已领取 " + result.body.amount + " 积分";
        button.replaceWith(note);
      })
      .catch(function (error) { button.disabled = false; button.textContent = error.message || "领取失败"; });
  });
})();
