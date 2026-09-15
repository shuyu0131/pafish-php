<?php
/** 默认主题首页：独立的文章列表版式，文章详情仍由系统 post.php 渲染。 */
$GLOBALS['default_theme_home'] = true;
get_header();
?>
<div class="default-home-content">
  <header class="default-home-heading"><h1>最新文章</h1><span aria-hidden="true"></span></header>
  <?php if (!$posts): ?>
    <div class="default-home-empty"><p><?= e($emptyText ?? '还没有文章，敬请期待') ?></p></div>
  <?php else: ?>
    <section class="default-home-post-list" aria-label="最新文章">
      <?php foreach ($posts as $post): ?>
        <?php
        $external = trim((string) ($post['external_url'] ?? ''));
        $href = $external !== '' ? $external : url_to('/post/' . rawurlencode((string) $post['slug']));
        ?>
        <article class="default-home-post">
          <div class="default-home-post-meta">
            <time datetime="<?= e((string) ($post['published_at'] ?? '')) ?>"><?= e(format_date($post['published_at'] ?? null, 'yyyy.MM.dd')) ?></time>
            <?php if (!empty($post['category_slug'])): ?><a href="<?= e(url_to('/category/' . rawurlencode((string) $post['category_slug']))) ?>"># <?= e((string) $post['category_name']) ?></a><?php endif; ?>
          </div>
          <a class="default-home-post-body" href="<?= e($href) ?>"<?= $external !== '' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
            <h2><?= e((string) ($post['title'] ?? '')) ?></h2>
            <?php if (!empty($post['excerpt'])): ?><p><?= e((string) $post['excerpt']) ?></p><?php endif; ?>
            <span>阅读全文 <b aria-hidden="true">→</b></span>
          </a>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
  <?php if (($totalPages ?? 1) > 1): ?>
    <?php $separator = str_contains((string) ($listBaseUrl ?? ''), '?') ? '&' : '?'; ?>
    <nav class="default-home-pagination" aria-label="文章分页">
      <?php if (($pageNum ?? 1) > 1): ?><a href="<?= e(($listBaseUrl ?? url_to('/')) . $separator . 'page=' . ((int) $pageNum - 1)) ?>">上一页</a><?php else: ?><span>上一页</span><?php endif; ?>
      <span><?= (int) ($pageNum ?? 1) ?> / <?= (int) $totalPages ?></span>
      <?php if (($pageNum ?? 1) < $totalPages): ?><a href="<?= e(($listBaseUrl ?? url_to('/')) . $separator . 'page=' . ((int) $pageNum + 1)) ?>">下一页</a><?php else: ?><span>下一页</span><?php endif; ?>
    </nav>
  <?php endif; ?>
</div>
<?php get_footer();
unset($GLOBALS['default_theme_home']);
