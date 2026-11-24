<?php
/** 默认主题首页自定义幻灯片。图片、文案和链接可选，空项不展示。 */
use Pafish\Services\Content;

// 旧 pinned 配置也按关闭处理；不查询文章，不影响置顶列表。
if (theme_value('home_carousel_source', 'off') !== 'custom') {
    return;
}
$intervalSec = (int) theme_value('home_carousel_interval', '6');
if (!in_array($intervalSec, [0, 4, 6, 8], true)) {
    $intervalSec = 6;
}
$metaShown = json_decode(theme_value('home_carousel_meta', '["category","excerpt"]'), true);
$metaShown = is_array($metaShown) ? $metaShown : [];
$showCategory = in_array('category', $metaShown, true);
$showExcerpt = in_array('excerpt', $metaShown, true);

$slides = [];

$rows = json_decode(theme_value('home_carousel_slides', '[]'), true);
foreach (is_array($rows) ? array_slice($rows, 0, 6) : [] as $row) {
    if (!is_array($row)) {
        continue;
    }
    $image = Content::safeImageUrl($row['image'] ?? null);
    $title = trim((string) ($row['title'] ?? ''));
    $description = $showExcerpt ? trim((string) ($row['desc'] ?? '')) : '';
    $label = $showCategory ? trim((string) ($row['label'] ?? '')) : '';
    if ($image === null && $title === '' && $description === '' && $label === '') {
        continue;
    }
    $url = Content::safeLinkUrl($row['url'] ?? null) ?? '';
    $slides[] = [
        'image' => $image,
        'title' => $title,
        'desc' => $description,
        'url' => $url,
        'external' => $url !== '' && preg_match('~^https?://~i', $url) === 1,
        'label' => $label,
    ];
}

if ($slides === []) {
    return;
}
$count = count($slides);
$pad = static fn (int $n): string => str_pad((string) $n, 2, '0', STR_PAD_LEFT);
?>
<section class="home-carousel is-custom<?= $count === 1 ? ' is-single' : '' ?>" data-carousel data-interval="<?= $count > 1 ? $intervalSec : 0 ?>" aria-roledescription="carousel" aria-label="精选内容">
  <div class="home-carousel-track"<?= $count > 1 ? ' tabindex="0"' : '' ?>>
    <?php foreach ($slides as $i => $slide):
      $hasCopy = $slide['title'] !== '' || $slide['desc'] !== '' || $slide['label'] !== '';
      $accessibleName = $slide['title'] ?: ($slide['desc'] ?: ($slide['label'] ?: '查看幻灯片 ' . ($i + 1)));
    ?>
      <article class="home-slide<?= $slide['image'] === null ? ' is-text' : '' ?><?= !$hasCopy ? ' is-image-only' : '' ?>" role="group" aria-roledescription="slide" aria-label="<?= $i + 1 ?> / <?= $count ?>">
        <?php if ($slide['url'] !== ''): ?>
          <a class="home-slide-link" href="<?= e($slide['url']) ?>" aria-label="<?= e($accessibleName) ?>"<?= $slide['external'] ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
        <?php else: ?>
          <div class="home-slide-link">
        <?php endif; ?>
          <?php if ($slide['image'] !== null): ?>
          <div class="home-slide-media">
            <img class="home-slide-img" src="<?= e($slide['image']) ?>" alt="<?= !$hasCopy && $slide['url'] === '' ? e('幻灯片 ' . ($i + 1)) : '' ?>" <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?> decoding="async">
          </div>
          <?php endif; ?>
          <?php if ($hasCopy): ?>
          <div class="home-slide-copy">
          <?php if ($slide['title'] !== ''): ?><h2 class="home-slide-title"><?= e($slide['title']) ?></h2><?php endif; ?>
          <?php if ($slide['desc'] !== ''): ?>
            <p class="home-slide-desc"><?= e($slide['desc']) ?></p>
          <?php endif; ?>
          <?php if ($slide['label'] !== ''): ?>
            <div class="home-slide-meta">
              <?php if ($slide['label'] !== ''): ?><span class="home-slide-cat"><?= e($slide['label']) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>
          </div>
          <?php endif; ?>
        <?= $slide['url'] !== '' ? '</a>' : '</div>' ?>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($count > 1): ?>
    <div class="home-carousel-foot">
      <span class="home-carousel-count" aria-live="off"><b data-carousel-current>01</b> / <?= $pad($count) ?></span>
      <button type="button" class="home-carousel-btn" data-carousel-prev aria-label="上一张"><?= admin_icon('arrow-left', 18) ?></button>
      <button type="button" class="home-carousel-btn" data-carousel-next aria-label="下一张"><?= admin_icon('arrow-right', 18) ?></button>
    </div>
  <?php endif; ?>
</section>
<script>
(function () {
  'use strict';
  var reduceMotion = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  function init(root) {
    if (root.dataset.carouselReady === '1') return;
    root.dataset.carouselReady = '1';
    var track = root.querySelector('.home-carousel-track');
    var slides = Array.prototype.slice.call(track.children);
    var count = slides.length;
    if (count < 2) return;
    var counter = root.querySelector('[data-carousel-current]');
    var current = 0;
    var timer = null;
    var holds = 0;
    var interval = (parseInt(root.dataset.interval || '0', 10) || 0) * 1000;

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function mark(index) {
      current = index;
      if (counter) counter.textContent = pad(index + 1);
      resizeTrack();
    }
    // 当前卡片 = 左边缘离滚动位置最近的那张；滚到最右端时算最后一张（最后一张无法对齐到左边）
    function nearest() {
      var max = track.scrollWidth - track.clientWidth;
      if (track.scrollLeft >= max - 2) return count - 1;
      var best = 0, dist = Infinity;
      slides.forEach(function (slide, i) {
        var d = Math.abs(slide.offsetLeft - track.scrollLeft);
        if (d < dist) { dist = d; best = i; }
      });
      return best;
    }
    function target(index) {
      return Math.min(slides[index].offsetLeft, track.scrollWidth - track.clientWidth);
    }
    function resizeTrack() {
      // 保持当前项自然高度；纯文字项不被其他大图撑出空白。
      track.style.height = slides[current].offsetHeight + 'px';
    }
    var guard = null;
    function go(index) {
      index = (index + count) % count;
      var left = target(index);
      var from = track.scrollLeft;
      track.scrollTo({ left: left, behavior: reduceMotion ? 'auto' : 'smooth' });
      mark(index);
      // 个别环境（后台窗口、部分 WebView）平滑滚动不会执行：没动就直接跳过去
      clearTimeout(guard);
      guard = setTimeout(function () {
        if (Math.abs(track.scrollLeft - from) < 1 && Math.abs(left - from) >= 1) {
          track.scrollTo({ left: left, behavior: 'auto' });
          mark(index);
        }
      }, 600);
    }

    var tick = null;
    track.addEventListener('scroll', function () {
      if (tick) cancelAnimationFrame(tick);
      tick = requestAnimationFrame(function () { mark(nearest()); });
    }, { passive: true });

    var prev = root.querySelector('[data-carousel-prev]');
    var next = root.querySelector('[data-carousel-next]');
    if (prev) prev.addEventListener('click', function () { go(current - 1); });
    if (next) next.addEventListener('click', function () { go(current + 1); });
    track.addEventListener('keydown', function (e) {
      if (e.target !== track) return;
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(current - 1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); go(current + 1); }
    });
    window.addEventListener('resize', function () {
      track.scrollTo({ left: target(current), behavior: 'auto' });
      resizeTrack();
    });
    if (window.ResizeObserver) {
      var observer = new ResizeObserver(resizeTrack);
      slides.forEach(function (slide) { observer.observe(slide); });
    }
    slides.forEach(function (slide) {
      var image = slide.querySelector('img');
      if (image) image.addEventListener('load', resizeTrack);
    });
    resizeTrack();

    // 自动播放：任何「正在看 / 正在操作」的信号都会暂停
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function start() {
      stop();
      if (!interval || reduceMotion || holds > 0 || document.hidden || !document.body.contains(root)) return;
      timer = setInterval(function () { go(current + 1); }, interval);
    }
    function hold() { holds++; stop(); }
    function release() { holds = Math.max(0, holds - 1); start(); }
    root.addEventListener('mouseenter', hold);
    root.addEventListener('mouseleave', release);
    root.addEventListener('focusin', hold);
    root.addEventListener('focusout', release);
    root.addEventListener('touchstart', function () { stop(); }, { passive: true });
    document.addEventListener('visibilitychange', start);
    start();
  }

  function initAll() {
    document.querySelectorAll('[data-carousel]').forEach(init);
  }
  initAll();
  if (!window.__pafishCarouselBound) {
    window.__pafishCarouselBound = true;
    document.addEventListener('pafish:pjax:after', initAll);
  }
})();
</script>
