<?php
/** 用户中心只展示核心提供的数据；提交类业务需要主题 provider 或插件支持。 */
$user = is_array($user ?? null) ? $user : [];
$posts = is_array($posts ?? null) ? $posts : [];
$stats = is_array($stats ?? null) ? $stats : [];
$reactions = is_array($reactions ?? null) ? $reactions : [];
$comments = is_array($comments ?? null) ? $comments : [];
$pointTransactions = is_array($pointTransactions ?? null) ? $pointTransactions : [];
get_header();
?>
<section class="starter-profile">
  <header class="starter-page-head"><p class="starter-kicker">个人中心</p><h1 class="starter-title"><?= e($user['nickname'] ?? $user['username'] ?? '用户') ?></h1><?php if (!empty($user['description'])): ?><p class="starter-description"><?= e($user['description']) ?></p><?php endif; ?></header>
  <div class="starter-profile-stats"><span>文章 <?= (int) ($stats['posts'] ?? count($posts)) ?></span><span>浏览 <?= (int) ($stats['views'] ?? 0) ?></span><span>评论 <?= (int) ($stats['comments'] ?? 0) ?></span><?php if (!empty($pointsEnabled)): ?><span>积分 <?= (int) ($pointsBalance ?? 0) ?></span><?php endif; ?></div>
  <h2>公开文章</h2>
  <?php if ($posts === []): ?><p class="starter-empty">暂无公开文章。</p><?php else: ?><div class="starter-post-list"><?php foreach ($posts as $post): ?><?= render_partial('entry-card', ['post' => $post]) ?><?php endforeach; ?></div><?php endif; ?>
  <?php if ($reactions !== []): ?><section><h2>最近互动</h2><ul><?php foreach ($reactions as $reaction): ?><li><?= ($reaction['kind'] ?? '') === 'favorite' ? '收藏了' : '赞过' ?> <a href="<?= e(url_to('/post/' . rawurlencode((string) ($reaction['slug'] ?? '')))) ?>"><?= e($reaction['title'] ?? '') ?></a></li><?php endforeach; ?></ul></section><?php endif; ?>
  <?php if ($comments !== []): ?><section><h2>最近评论</h2><ul><?php foreach ($comments as $comment): ?><li><a href="<?= e(url_to('/post/' . rawurlencode((string) ($comment['post_slug'] ?? '')) . '#comments')) ?>"><?= e($comment['post_title'] ?? '文章') ?></a><p><?= e($comment['content'] ?? '') ?></p><time class="starter-meta"><?= e(format_date($comment['created_at'] ?? null)) ?></time></li><?php endforeach; ?></ul></section><?php endif; ?>
  <?php if (!empty($pointsEnabled) && $pointTransactions !== []): ?><section><h2>积分记录</h2><ul><?php foreach ($pointTransactions as $transaction): ?><li><?= e($transaction['reason'] ?? $transaction['description'] ?? '积分变动') ?>：<?= (int) ($transaction['amount'] ?? 0) ?> <time class="starter-meta"><?= e(format_date($transaction['created_at'] ?? null)) ?></time></li><?php endforeach; ?></ul></section><?php endif; ?>
</section>
<?php get_footer(); ?>
