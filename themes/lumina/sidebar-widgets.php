<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$avatar = lumina_theme_image('avatar_image', lumina_asset_url('img/tx.png'));
$profileName = trim(theme_value('profile_name')) ?: site_name();
$bio = trim(theme_value('profile_bio')) ?: (string) settings('site_subtitle', '');
$postCount = (int) DB::value("SELECT COUNT(*) FROM posts WHERE status = 'PUBLISHED' AND deleted_at IS NULL AND (published_at IS NULL OR published_at <= NOW())");
$commentCount = (int) DB::value("SELECT COUNT(*) FROM comments WHERE status = 'APPROVED'");
$categories = DB::fetchAll("SELECT c.name, c.slug, COUNT(p.id) AS cnt FROM categories c LEFT JOIN posts p ON p.category_id = c.id AND p.status = 'PUBLISHED' AND p.deleted_at IS NULL GROUP BY c.id ORDER BY cnt DESC, c.id ASC LIMIT 7");
$recent = DB::fetchAll("SELECT title, slug FROM posts WHERE status = 'PUBLISHED' AND deleted_at IS NULL AND (published_at IS NULL OR published_at <= NOW()) ORDER BY published_at DESC LIMIT 5");
?>
<section class="lumina-sidecard">
  <div class="lumina-sidecard-cover"></div>
  <div class="lumina-sidecard-body">
    <img class="lumina-sidecard-avatar" src="<?= e($avatar) ?>" alt="">
    <h2><?= e($profileName) ?></h2>
    <?php if ($bio !== ''): ?><p><?= e($bio) ?></p><?php endif; ?>
    <div class="lumina-side-stats"><div><strong><?= $postCount ?></strong><span>文章</span></div><div><strong><?= $commentCount ?></strong><span>评论</span></div><div><strong><?= count($categories) ?></strong><span>分类</span></div></div>
  </div>
</section>
<?php if ($categories !== []): ?>
<section class="lumina-sidecard"><h2 class="lumina-sidecard-title">分类</h2><ul class="lumina-side-list"><?php foreach ($categories as $category): ?><li><a href="<?= e(url_to('/category/' . rawurlencode((string) $category['slug']))) ?>"><?= e((string) $category['name']) ?></a><em><?= (int) $category['cnt'] ?></em></li><?php endforeach; ?></ul></section>
<?php endif; ?>
<?php if ($recent !== []): ?>
<section class="lumina-sidecard"><h2 class="lumina-sidecard-title">最近文章</h2><ul class="lumina-side-list"><?php foreach ($recent as $item): ?><li><a href="<?= e(url_to('/post/' . rawurlencode((string) $item['slug']))) ?>"><?= e((string) $item['title']) ?></a></li><?php endforeach; ?></ul></section>
<?php endif; ?>
