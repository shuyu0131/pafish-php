<?php $posts = is_array($posts ?? null) ? $posts : []; if ($posts !== []): ?>
<aside class="starter-related"><h2>相关文章</h2><ul><?php foreach ($posts as $post): ?><li><a href="<?= e(url_to('/post/' . rawurlencode((string) ($post['slug'] ?? '')))) ?>"><?= e($post['title'] ?? '') ?></a><?php if (!empty($post['published_at'])): ?> <time class="starter-meta"><?= e(format_date($post['published_at'], 'MM-dd')) ?></time><?php endif; ?></li><?php endforeach; ?></ul></aside>
<?php endif; ?>
