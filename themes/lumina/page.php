<?php
// pafish: emlog guard removed
$lumina_page_js = 'page';
$lumina_page_identity = 'page';
// pafish: header already loaded by get_header()
$lumina_avatar = lumina_opt('default_avatar', lumina_tpl_base() . 'assets/img/tx.png');
if ($lumina_avatar === '') {
    $lumina_avatar = lumina_tpl_base() . 'assets/img/tx.png';
}
$lumina_avatar = lumina_resolve_url($lumina_avatar, lumina_tpl_base() . 'assets/img/tx.png');
$profile_uid = isset($author) && is_numeric($author) ? (int)$author : 1;
$profile_user = lumina_get_user_info($profile_uid);
$profile_name = lumina_get_user_name($profile_uid, $blogname);
$profile_desc = '';
if (!empty($profile_user)) {
    if (!empty($profile_user['description'])) {
        $profile_desc = $profile_user['description'];
    } elseif (!empty($profile_user['description_orig'])) {
        $profile_desc = $profile_user['description_orig'];
    }
}
if ($profile_desc === '') {
    $profile_desc = $bloginfo;
}
$profile_avatar = lumina_get_user_avatar($profile_uid, $lumina_avatar);

$pc_layout = lumina_opt('home_layout_pc', 'single');
if ($pc_layout === 'triple') {
    $pc_layout = 'double';
}
if (!in_array($pc_layout, ['single', 'double'], true)) {
    $pc_layout = 'single';
}
$show_sidebar = ($pc_layout !== 'single');
$sidebar_stats = [
    'logs' => 0,
    'comments' => 0,
    'likes' => 0,
];
$sidebar_sorts = [];
$sidebar_tags = [];
if ($show_sidebar && class_exists('Cache')) {
    $CACHE = null;
    if ($CACHE) {
        $sta_cache = $CACHE->readCache('sta');
        if (is_array($sta_cache)) {
            $sidebar_stats['logs'] = isset($sta_cache['lognum']) ? (int)$sta_cache['lognum'] : 0;
            $sidebar_stats['comments'] = isset($sta_cache['comnum_all']) ? (int)$sta_cache['comnum_all'] : 0;
            $sidebar_stats['likes'] = isset($sta_cache['like_num']) ? (int)$sta_cache['like_num'] : 0;
        }

        $sort_cache = $CACHE->readCache('sort');
        if (is_array($sort_cache)) {
            foreach ($sort_cache as $sort) {
                if (!is_array($sort) || !empty($sort['pid'])) {
                    continue;
                }
                $sid = isset($sort['sid']) ? (int)$sort['sid'] : 0;
                $name = isset($sort['sortname']) ? $sort['sortname'] : '';
                if ($sid > 0 && $name !== '') {
                    $sidebar_sorts[] = [
                        'name' => htmlspecialchars($name),
                        'url' => url_to('/category/' . rawurlencode((string)($sid))),
                        'count' => isset($sort['lognum']) ? (int)$sort['lognum'] : 0,
                    ];
                }
                if (count($sidebar_sorts) >= 8) {
                    break;
                }
            }
        }

        $tag_cache = $CACHE->readCache('tags');
        if (is_array($tag_cache)) {
            foreach ($tag_cache as $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                $name = isset($tag['tagname']) ? $tag['tagname'] : '';
                $url = isset($tag['tagurl']) ? $tag['tagurl'] : '';
                if ($name !== '' && $url !== '') {
                    $sidebar_tags[] = [
                        'name' => htmlspecialchars($name),
                        'url' => url_to('/tag/' . rawurlencode((string)($url))),
                    ];
                }
                if (count($sidebar_tags) >= 12) {
                    break;
                }
            }
        }
    }
}
$sidebar_contact = trim((string)lumina_opt('contact_info', ''));
if ($sidebar_contact === '') {
    $sidebar_contact = $profile_desc;
}
$sidebar_copyright = lumina_sidebar_copyright_source(isset($footer_info) ? $footer_info : '');
$sidebar_copyright_html = lumina_prepare_sidebar_copyright($sidebar_copyright);
$sidebar_icp = trim((string)lumina_opt('sidebar_icp', ''));
if ($sidebar_icp === '') {
    $sidebar_icp = isset($icp) ? $icp : '';
}
?>
<div class="centent lumina-layout lumina-layout-single lumina-layout-pc-<?= $pc_layout ?>" data-lumina-pjax-container>
    <div class="lumina-layout-wrap">
    <div class="sh-main">
        <div class="sh-page-content">
            <h1><?= $log_title ?></h1>
            <div class="sh-log-content">
                <?= $log_content ?>
            </div>
        </div>
        <?php lumina_render_main_footer(); ?>
    </div>

    <?php if ($show_sidebar): ?>
        <aside class="lumina-aside lumina-aside-right">
            <?php lumina_render_sidebar_profile_card($profile_uid, $profile_avatar, $lumina_avatar, $profile_name, $sidebar_contact, $sidebar_stats); ?>
            <?php lumina_render_sidebar_extra_cards($sidebar_sorts, $sidebar_tags, $sidebar_copyright_html, $sidebar_icp); ?>
        </aside>
    <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
