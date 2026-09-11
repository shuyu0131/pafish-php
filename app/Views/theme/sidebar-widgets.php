<?php
/**
 * 侧边栏组件区（系统默认模板；主题可覆盖 themes/{active}/sidebar-widgets.php）
 * 6 种组件：categories / tags / recent_posts / hot_posts / recent_comments / custom
 */
use Pafish\Core\DB;

$widgets = widget_items();
if (!$widgets) {
    return;
}

$typeTitles = [
    'categories' => '分类',
    'tags' => '标签',
    'recent_posts' => '最新文章',
    'hot_posts' => '热门文章',
    'recent_comments' => '最新评论',
    'custom' => '自定义',
];

foreach ($widgets as $widget):
    $type = $widget['type'];
    $title = trim((string) ($widget['title'] ?? '')) !== '' ? $widget['title'] : ($typeTitles[$type] ?? '');
    echo '<div class="sidebar-widget">';
    if ($title !== ''):
        echo '<h3 class="sidebar-widget-title">' . e($title) . '</h3>';
    endif;

    switch ($type) {
        case 'categories':
            // 分类列表（含文章数，树形缩进）
            $cats = DB::fetchAll(
                "SELECT c.id, c.name, c.slug, c.parent_id,
                        (SELECT COUNT(*) FROM posts p
                          WHERE p.category_id = c.id AND p.status = 'PUBLISHED'
                            AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= NOW())) AS cnt
                 FROM categories c ORDER BY c.sort_order ASC, c.id ASC"
            );
            echo '<ul class="widget-list">';
            foreach ($cats as $cat):
                echo '<li class="widget-cat' . ($cat['parent_id'] ? ' widget-cat-child' : '') . '">'
                    . '<a href="' . e(url_to('/category/' . rawurlencode((string) $cat['slug']))) . '">' . e($cat['name']) . '</a>'
                    . '<span class="widget-count">' . (int) $cat['cnt'] . '</span></li>';
            endforeach;
            echo '</ul>';
            break;

        case 'tags':
            $tags = DB::fetchAll(
                "SELECT t.name, t.slug, (SELECT COUNT(*) FROM post_tags pt JOIN posts p ON p.id = pt.post_id
                   WHERE pt.tag_id = t.id AND p.status = 'PUBLISHED' AND p.deleted_at IS NULL) AS cnt
                 FROM tags t ORDER BY cnt DESC, t.id ASC LIMIT 30"
            );
            echo '<div class="widget-tags">';
            foreach ($tags as $tag):
                echo '<a class="widget-tag" href="' . e(url_to('/tag/' . rawurlencode((string) $tag['slug']))) . '">' . e($tag['name']) . '</a>';
            endforeach;
            echo '</div>';
            break;

        case 'recent_posts':
        case 'hot_posts':
            $orderBy = $type === 'hot_posts' ? 'p.view_count DESC' : 'p.published_at DESC';
            $posts = DB::fetchAll(
                "SELECT p.title, p.slug FROM posts p
                 WHERE p.status = 'PUBLISHED' AND p.deleted_at IS NULL
                   AND (p.published_at IS NULL OR p.published_at <= NOW())
                 ORDER BY {$orderBy} LIMIT 5"
            );
            echo '<ul class="widget-list">';
            foreach ($posts as $p):
                echo '<li><a href="' . e(url_to('/post/' . rawurlencode((string) $p['slug']))) . '">' . e($p['title']) . '</a></li>';
            endforeach;
            echo '</ul>';
            break;

        case 'recent_comments':
            $comments = DB::fetchAll(
                "SELECT c.author_name, c.content, c.post_id, p.slug AS post_slug, p.title AS post_title
                 FROM comments c JOIN posts p ON p.id = c.post_id
                 WHERE c.status = 'APPROVED'
                 ORDER BY c.created_at DESC LIMIT 5"
            );
            echo '<ul class="widget-list widget-comments">';
            foreach ($comments as $c):
                echo '<li><span class="widget-comment-author">' . e($c['author_name']) . '</span>：'
                    . '<a href="' . e(url_to('/post/' . rawurlencode((string) $c['post_slug']) . '#comment-' . (int) $c['id'])) . '">'
                    . e(mb_strimwidth(strip_tags($c['content']), 0, 40, '…')) . '</a></li>';
            endforeach;
            echo '</ul>';
            break;

        case 'custom':
            // 逐行渲染：整行是 [文本](http(s)://或mailto:链接) 则渲染外链，否则纯文本
            foreach (preg_split('/\r?\n/', (string) $widget['content']) as $line) {
                if (preg_match('/^\[(.+)\]\((https?:\/\/[^)\s]+|mailto:[^)\s]+)\)$/', $line, $m)) {
                    echo '<p class="widget-custom-line"><a href="' . e($m[2]) . '" target="_blank" rel="noreferrer">' . e($m[1]) . '</a></p>';
                } else {
                    echo '<p class="widget-custom-line">' . e($line) . '</p>';
                }
            }
            break;
    }
    echo '</div>';
endforeach;
