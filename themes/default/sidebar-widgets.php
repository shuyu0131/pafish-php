<?php
/**
 * 默认主题的侧边栏组件（覆盖系统模板）。
 *
 * 与系统模板的区别只有两点：
 * - 组件取不到任何内容时整块不输出，不会留下一个空标题；
 * - 每个组件带 sidebar-widget--{type} 类名，样式可以直接按类型写。
 *
 * 显示哪些组件、按什么顺序、在哪个区域，仍然完全由后台「组件管理」决定：
 * widget_items() 只返回 visible = 1 且 area 匹配的记录，并按其 sort_order 排序。
 */
use Pafish\Core\DB;
use Pafish\Services\MicroStatuses;

$area = preg_match('/^[a-z0-9_-]{1,50}$/', (string) ($area ?? 'sidebar')) === 1 ? (string) ($area ?? 'sidebar') : 'sidebar';
$widgets = widget_items($area);
if (!$widgets) {
    return;
}

$typeTitles = [
    'categories' => '分类',
    'tags' => '标签',
    'recent_posts' => '最新文章',
    'hot_posts' => '热门文章',
    'recent_comments' => '最新评论',
    'recent_micro_statuses' => '最新微语',
    'custom' => '自定义',
];

$renderBody = static function (array $widget): string {
    $type = (string) ($widget['type'] ?? '');

    switch ($type) {
        case 'categories':
            $cats = DB::fetchAll(
                "SELECT c.id, c.name, c.slug, c.parent_id,
                        (SELECT COUNT(*) FROM posts p
                          WHERE p.category_id = c.id AND p.status = 'PUBLISHED'
                            AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= NOW())) AS cnt
                 FROM categories c ORDER BY c.sort_order ASC, c.id ASC"
            );
            if ($cats === []) {
                return '';
            }
            $html = '<ul class="widget-list">';
            foreach ($cats as $cat) {
                $html .= '<li class="widget-cat' . ($cat['parent_id'] ? ' widget-cat-child' : '') . '">'
                    . '<a href="' . e(content_url('category', $cat)) . '">' . e($cat['name']) . '</a>'
                    . '<span class="widget-count">' . (int) $cat['cnt'] . '</span></li>';
            }
            return $html . '</ul>';

        case 'tags':
            $tags = DB::fetchAll(
                "SELECT t.id, t.name, t.slug, (SELECT COUNT(*) FROM post_tags pt JOIN posts p ON p.id = pt.post_id
                   WHERE pt.tag_id = t.id AND p.status = 'PUBLISHED' AND p.deleted_at IS NULL) AS cnt
                 FROM tags t ORDER BY cnt DESC, t.id ASC LIMIT 30"
            );
            if ($tags === []) {
                return '';
            }
            $html = '<div class="widget-tags">';
            foreach ($tags as $tag) {
                $html .= '<a class="widget-tag" href="' . e(content_url('tag', $tag)) . '">' . e($tag['name']) . '</a>';
            }
            return $html . '</div>';

        case 'recent_posts':
        case 'hot_posts':
            $orderBy = $type === 'hot_posts' ? 'p.view_count DESC' : 'p.published_at DESC';
            $posts = DB::fetchAll(
                "SELECT p.title, p.slug, p.published_at, p.created_at FROM posts p
                 WHERE p.status = 'PUBLISHED' AND p.deleted_at IS NULL
                   AND (p.published_at IS NULL OR p.published_at <= NOW())
                 ORDER BY {$orderBy} LIMIT 5"
            );
            if ($posts === []) {
                return '';
            }
            $html = '<ul class="widget-list widget-posts">';
            foreach ($posts as $p) {
                $html .= '<li><a href="' . e(content_url('post', $p)) . '">' . e($p['title']) . '</a></li>';
            }
            return $html . '</ul>';

        case 'recent_comments':
            $comments = DB::fetchAll(
                "SELECT c.author_name, c.content, p.slug AS post_slug, p.title AS post_title, p.published_at, p.created_at
                 FROM comments c JOIN posts p ON p.id = c.post_id
                 WHERE c.status = 'APPROVED'
                 ORDER BY c.created_at DESC LIMIT 5"
            );
            if ($comments === []) {
                return '';
            }
            $html = '<ul class="widget-list widget-comments">';
            foreach ($comments as $c) {
                $html .= '<li><span class="widget-comment-author">' . e($c['author_name']) . '</span>：'
                    . '<a href="' . e(content_url('post', ['slug' => $c['post_slug'], 'published_at' => $c['published_at'], 'created_at' => $c['created_at']]) . '#comment-' . (int) $c['id']) . '">'
                    . e(mb_strimwidth(strip_tags($c['content']), 0, 40, '…')) . '</a></li>';
            }
            return $html . '</ul>';

        case 'recent_micro_statuses':
            $microItems = MicroStatuses::latest(5);
            if ($microItems === []) {
                return '';
            }
            $html = '<ul class="widget-list widget-micro-statuses">';
            foreach ($microItems as $micro) {
                $html .= '<li><a href="' . e(url_to('/micro')) . '">'
                    . e(mb_strimwidth(\Pafish\Services\Content::plain((string) $micro['content'], (string) ($micro['contentFormat'] ?? 'markdown')), 0, 60, '…'))
                    . '</a><small class="widget-muted">' . e(format_date($micro['publishedAt'] ?? null)) . '</small></li>';
            }
            return $html . '</ul>';

        case 'custom':
            // 逐行渲染：整行是 [文本](http(s)://或mailto:链接) 则渲染外链，否则纯文本。
            $lines = [];
            foreach (preg_split('/\r?\n/', (string) ($widget['content'] ?? '')) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                if (preg_match('/^\[(.+)\]\((https?:\/\/[^)\s]+|mailto:[^)\s]+)\)$/', $line, $m)) {
                    $lines[] = '<p class="widget-custom-line"><a href="' . e($m[2]) . '" target="_blank" rel="noreferrer">' . e($m[1]) . '</a></p>';
                } else {
                    $lines[] = '<p class="widget-custom-line">' . e($line) . '</p>';
                }
            }
            return implode('', $lines);

        default:
            return '';
    }
};

foreach ($widgets as $widget):
    $type = (string) ($widget['type'] ?? '');
    $body = $renderBody($widget);
    if ($body === '') {
        continue;
    }
    $title = trim((string) ($widget['title'] ?? '')) !== '' ? (string) $widget['title'] : ($typeTitles[$type] ?? '');
    $typeClass = preg_replace('/[^a-z_]/', '', $type) ?: 'custom';
    ?>
    <div class="sidebar-widget sidebar-widget--<?= e($typeClass) ?>">
      <?php if ($title !== ''): ?><h3 class="sidebar-widget-title"><?= e($title) ?></h3><?php endif; ?>
      <?= $body ?>
    </div>
    <?php
endforeach;
