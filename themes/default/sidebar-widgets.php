<?php
/**
 * 默认主题侧栏组件（覆盖系统 app/Views/theme/sidebar-widgets.php，只对默认主题生效）
 *
 * 与系统模板的区别：
 * - 分类：名称 + 文章数；按层级排序，当前分类（分类页 / 所属文章页）高亮；没有文章的分类不显示
 * - 最新评论：查询补上评论 id，链接能定位到具体评论（系统模板缺 c.id，锚点总是 #comment-0）
 * - 按设备显示：主题设置「电脑端 / 移动端隐藏的组件」按组件类型隐藏，「移动端侧栏」可整块隐藏；
 *   以 960px 为界（侧栏移到正文下方的断点），规则在 style.css 的 .is-hide-mobile / .is-hide-desktop
 * 其他组件的 HTML 与系统模板一致。系统模板以后有改动时，记得同步到这里。
 *
 * 可用数据：$area（组件区域）；$defaultSidebarWidgets 可选，传入时不读数据库（default-sidebar.php 用它渲染默认分类）
 */
use Pafish\Core\DB;
use Pafish\Services\MicroStatuses;

$area = preg_match('/^[a-z0-9_-]{1,50}$/', (string) ($area ?? 'sidebar')) === 1 ? (string) ($area ?? 'sidebar') : 'sidebar';

// 「移动端侧栏：不显示」——整块隐藏；每个请求只输出一次
if (empty($GLOBALS['pafish_default_sidebar_style_done'])) {
    $GLOBALS['pafish_default_sidebar_style_done'] = true;
    if (theme_value('sidebar_mobile', 'below') === 'hide') {
        echo '<style>@media (max-width:959.98px){.app-sidebar{display:none!important}}</style>';
    }
}

$widgets = is_array($defaultSidebarWidgets ?? null) ? $defaultSidebarWidgets : widget_items($area);
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

$readTypes = static function (string $key): array {
    $value = json_decode(theme_value($key, '[]'), true);
    return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
};
$hideMobile = $readTypes('sidebar_hide_mobile');
$hideDesktop = $readTypes('sidebar_hide_desktop');

// 当前分类：分类页按路径匹配；文章页取文章所属分类
$currentPath = nav_current_path();
$ctxPost = $GLOBALS['pafish_tpl_ctx']['post'] ?? null;
$currentCategoryId = is_array($ctxPost) ? (int) ($ctxPost['category_id'] ?? 0) : 0;

foreach ($widgets as $widget):
    if (!is_array($widget)) {
        continue;
    }
    $type = (string) ($widget['type'] ?? '');
    $title = trim((string) ($widget['title'] ?? '')) !== '' ? $widget['title'] : ($typeTitles[$type] ?? '');
    $classes = 'sidebar-widget';
    if ($type === 'categories') {
        $classes .= ' widget-categories';
    }
    if (in_array($type, $hideMobile, true)) {
        $classes .= ' is-hide-mobile';
    }
    if (in_array($type, $hideDesktop, true)) {
        $classes .= ' is-hide-desktop';
    }
    echo '<div class="' . $classes . '">';
    if ($title !== ''):
        echo '<h3 class="sidebar-widget-title">' . e($title) . '</h3>';
    endif;

    switch ($type) {
        case 'categories':
            $rows = DB::fetchAll(
                "SELECT c.id, c.name, c.slug, c.parent_id,
                        (SELECT COUNT(*) FROM posts p
                          WHERE p.category_id = c.id AND p.status = 'PUBLISHED'
                            AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= NOW())) AS cnt
                 FROM categories c ORDER BY c.sort_order ASC, c.id ASC"
            );
            // 有文章的分类及其上级才显示
            $byId = [];
            foreach ($rows as $row) {
                $byId[(int) $row['id']] = $row;
            }
            $keep = [];
            foreach ($rows as $row) {
                if ((int) $row['cnt'] <= 0) {
                    continue;
                }
                $id = (int) $row['id'];
                $guard = 0;
                while ($id > 0 && isset($byId[$id]) && !isset($keep[$id]) && $guard++ < 10) {
                    $keep[$id] = true;
                    $id = (int) ($byId[$id]['parent_id'] ?? 0);
                }
            }
            // 按层级排序：上级之后紧跟下级
            $children = [];
            foreach ($rows as $row) {
                if (!isset($keep[(int) $row['id']])) {
                    continue;
                }
                $parent = (int) ($row['parent_id'] ?? 0);
                $children[isset($keep[$parent]) ? $parent : 0][] = $row;
            }
            $ordered = [];
            $walk = static function (int $parentId, int $depth) use (&$walk, &$ordered, $children): void {
                foreach ($children[$parentId] ?? [] as $row) {
                    $ordered[] = [$row, $depth];
                    if ($depth < 5) {
                        $walk((int) $row['id'], $depth + 1);
                    }
                }
            };
            $walk(0, 0);
            $limit = (int) ($widget['limit'] ?? 0);
            if ($limit > 0) {
                $ordered = array_slice($ordered, 0, $limit);
            }
            echo '<ul class="widget-list widget-cats">';
            foreach ($ordered as [$cat, $depth]):
                $url = content_url('category', $cat);
                $isCurrent = (int) $cat['id'] === $currentCategoryId
                    || (parse_url($url, PHP_URL_PATH) ?: '') === $currentPath;
                echo '<li class="widget-cat' . ($depth > 0 ? ' widget-cat-child' : '') . ($isCurrent ? ' is-current' : '') . '"'
                    . ($depth > 0 ? ' style="--depth:' . min(2, $depth) . '"' : '') . '>'
                    . '<a href="' . e($url) . '"' . ($isCurrent ? ' aria-current="page"' : '') . '>'
                    . '<span class="widget-cat-name">' . e($cat['name']) . '</span>'
                    . '<span class="widget-count">' . (int) $cat['cnt'] . '</span></a></li>';
            endforeach;
            echo '</ul>';
            break;

        case 'tags':
            $tags = DB::fetchAll(
                "SELECT t.id, t.name, t.slug, (SELECT COUNT(*) FROM post_tags pt JOIN posts p ON p.id = pt.post_id
                   WHERE pt.tag_id = t.id AND p.status = 'PUBLISHED' AND p.deleted_at IS NULL) AS cnt
                 FROM tags t ORDER BY cnt DESC, t.id ASC LIMIT 30"
            );
            echo '<div class="widget-tags">';
            foreach ($tags as $tag):
                echo '<a class="widget-tag" href="' . e(content_url('tag', $tag)) . '">' . e($tag['name']) . '</a>';
            endforeach;
            echo '</div>';
            break;

        case 'recent_posts':
        case 'hot_posts':
            $orderBy = $type === 'hot_posts' ? 'p.view_count DESC' : 'p.published_at DESC';
            $posts = DB::fetchAll(
                "SELECT p.id, p.title, p.slug, p.published_at, p.created_at FROM posts p
                 WHERE p.status = 'PUBLISHED' AND p.deleted_at IS NULL
                   AND (p.published_at IS NULL OR p.published_at <= NOW())
                 ORDER BY {$orderBy} LIMIT 5"
            );
            echo '<ul class="widget-list">';
            foreach ($posts as $p):
                echo '<li><a href="' . e(content_url('post', $p)) . '">' . e($p['title']) . '</a></li>';
            endforeach;
            echo '</ul>';
            break;

        case 'recent_comments':
            // 只取公开且未加密文章下的评论（与系统模板一致）
            $publicPost = \Pafish\Services\Posts::publicWhere('p');
            $comments = DB::fetchAll(
                "SELECT c.id, c.author_name, c.content, p.id AS post_id, p.slug AS post_slug, p.title AS post_title, p.published_at, p.created_at
                 FROM comments c JOIN posts p ON p.id = c.post_id
                 WHERE c.status = 'APPROVED' AND {$publicPost}
                   AND (p.password IS NULL OR p.password = '')
                 ORDER BY c.created_at DESC LIMIT 5"
            );
            echo '<ul class="widget-list widget-comments">';
            foreach ($comments as $c):
                echo '<li><span class="widget-comment-author">' . e($c['author_name']) . '</span>：'
                    . '<a href="' . e(content_url('post', ['id' => $c['post_id'], 'slug' => $c['post_slug'], 'published_at' => $c['published_at'], 'created_at' => $c['created_at']]) . '#comment-' . (int) $c['id']) . '">'
                    . e(mb_strimwidth(strip_tags($c['content']), 0, 40, '…')) . '</a></li>';
            endforeach;
            echo '</ul>';
            break;

        case 'recent_micro_statuses':
            $microItems = MicroStatuses::latest(5);
            echo '<ul class="widget-list widget-micro-statuses">';
            foreach ($microItems as $micro):
                echo '<li><a href="' . e(url_to('/micro')) . '">' . e(mb_strimwidth(\Pafish\Services\Content::plain((string) $micro['content'], (string) ($micro['contentFormat'] ?? 'markdown')), 0, 60, '…')) . '</a><small class="widget-muted">' . e(format_date($micro['publishedAt'] ?? null)) . '</small></li>';
            endforeach;
            echo '</ul>';
            break;

        case 'custom':
            // 逐行渲染：整行是 [文本](http(s)://或mailto:链接) 则渲染外链，否则纯文本
            foreach (preg_split('/\r?\n/', (string) ($widget['content'] ?? '')) as $line) {
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
