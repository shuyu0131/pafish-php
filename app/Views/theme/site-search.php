<?php
/**
 * 顶栏搜索（系统默认模板；主题可覆盖 themes/{active}/site-search.php）
 * 折叠式：默认只显示放大镜按钮，点击展开输入框，回车跳 /search?q=xxx
 */
?>
<form class="site-search" action="<?= e(url_to('/search')) ?>" method="get" role="search">
  <button type="button" class="site-search-toggle btn btn-ghost" aria-label="搜索" title="搜索" hidden><?= admin_icon('search', 17) ?></button>
  <input type="search" name="q" class="input site-search-input" placeholder="搜索文章…"
         aria-label="搜索文章" autocomplete="off" maxlength="100">
  <button type="submit" class="site-search-submit" aria-label="提交搜索">↵</button>
</form>
<script>
(function () {
  var form = document.querySelector('.site-search');
  if (!form) return;
  var toggle = form.querySelector('.site-search-toggle');
  var input = form.querySelector('.site-search-input');
  var submit = form.querySelector('.site-search-submit');
  var open = false;

  function setOpen(v) {
    open = v;
    form.classList.toggle('open', v);
    if (v) { input.focus(); }
  }

  toggle.addEventListener('click', function () { setOpen(!open); });
  form.addEventListener('submit', function (e) {
    var q = input.value.trim();
    if (!q) { e.preventDefault(); setOpen(true); input.focus(); }
  });
  input.addEventListener('blur', function () { setTimeout(function () { setOpen(false); }, 150); });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { setOpen(false); }
  });
})();
</script>
