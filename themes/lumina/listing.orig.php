<?php
// pafish: emlog guard removed
$lumina_author_id = isset($author) && is_numeric($author) ? (int)$author : 0;
$lumina_is_author_page = $lumina_author_id > 0;
$lumina_page_js = $lumina_is_author_page ? 'home' : 'index';
$lumina_page_identity = $lumina_is_author_page ? 'author' : 'home';
// pafish: header already loaded by get_header()
$lumina_cover = lumina_resolve_url(lumina_opt('header_cover', lumina_tpl_base() . 'assets/img/homeimg.jpg'), lumina_tpl_base() . 'assets/img/homeimg.jpg');
$authorId = $lumina_author_id;
$isAuthorPage = $authorId > 0;
$isHomeList = !$isAuthorPage && empty($_GET['sort']) && empty($_GET['tag']) && empty($_GET['record']) && empty($_GET['keyword']);
$isSortList = !$isAuthorPage && !empty($_GET['sort']) && empty($_GET['tag']) && empty($_GET['record']) && empty($_GET['keyword']);
$profile_uid = $isAuthorPage ? $authorId : 1;
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
if ($profile_desc === '' && !$isAuthorPage) {
    $profile_desc = $bloginfo;
}
$lumina_avatar = lumina_opt('default_avatar', lumina_tpl_base() . 'assets/img/tx.png');
if ($lumina_avatar === '') {
    $lumina_avatar = lumina_tpl_base() . 'assets/img/tx.png';
}
$lumina_avatar = lumina_resolve_url($lumina_avatar, lumina_tpl_base() . 'assets/img/tx.png');
$profile_avatar = lumina_get_user_avatar($profile_uid, $lumina_avatar);
$profile_cover = '';
if (!empty($profile_user) && isset($profile_user['homeimg'])) {
    $profile_cover = trim((string)$profile_user['homeimg']);
}
if ($isAuthorPage) {
    if ($profile_cover === '' || $profile_cover === '-1') {
        $profile_cover = lumina_get_user_cover($profile_uid, '');
    }
    if ($profile_cover !== '' && $profile_cover !== '-1') {
        $lumina_cover = lumina_resolve_url($profile_cover, $lumina_cover);
    }
}
$canEditCover = $isAuthorPage && is_logged_in() && (int)(current_user()['id'] ?? 0) === (int)$profile_uid;
$coverToken = $canEditCover ? csrf_token() : '';
$Log_Model = new Log_Model();
$Like_Model = new Like_Model();
$Comment_Model = new Comment_Model();
$authorPageNum = isset($page) ? max(1, (int)$page) : 1;
$authorPerPage = isset($index_lognum) ? max(1, (int)$index_lognum) : 10;
if ($isAuthorPage) {
    $logs = $Log_Model->getLogsForHome("and author={$authorId} order by top desc, date desc", $authorPageNum, $authorPerPage);
}
$clientIp = getIp();
$allowGuest = (settings('comments_require_login', 'false') === 'true' ? 'n' : 'y') === 'n';
$needCaptcha = (!is_logged_in()) && (settings('comments_captcha_enabled', 'true') !== 'false' ? 'y' : 'n') === 'y';
$verifyCode = $needCaptcha ? '<img src="' . lumina_blog_base() . 'include/lib/checkcode.php" id="captcha" class="captcha" /><input name="imgcode" type="text" class="captcha_input" size="5" tabindex="5" />' : '';
$noticeItems = [];
$noticeCount = 0;
$noticeDeleted = is_logged_in() ? lumina_notice_get_deleted_keys((int)(current_user()['id'] ?? 0), 500) : [];
if (is_logged_in()) {
    $rawNoticeItems = lumina_get_notice_feed((int)(current_user()['id'] ?? 0), 30, 30);
    if (is_array($rawNoticeItems)) {
        foreach ($rawNoticeItems as $item) {
            $type = isset($item['type']) ? $item['type'] : '';
            $gid = isset($item['gid']) ? (int)$item['gid'] : 0;
            $poster = isset($item['poster']) ? $item['poster'] : '';
            $dateKey = isset($item['date']) ? (int)$item['date'] : 0;
            $idPart = isset($item['cid']) ? (int)$item['cid'] : (isset($item['id']) ? (int)$item['id'] : 0);
            $keySeed = $type . '|' . $gid . '|' . $idPart . '|' . $poster . '|' . $dateKey;
            $noticeKey = md5($keySeed);
            if ($noticeKey && isset($noticeDeleted[$noticeKey])) {
                continue;
            }
            $item['notice_key'] = $noticeKey;
            $noticeItems[] = $item;
        }
    }
    $noticeCount = count($noticeItems);
}
$noticeToken = is_logged_in() ? csrf_token() : '';
$friendLinks = lumina_get_friend_links();
$friendLinksEnabled = !empty($friendLinks['enabled']) && !empty($friendLinks['total']);
if (isset($logs) && is_array($logs) && !empty($logs)) {
    $logs = array_values(array_filter($logs, function ($value) {
        $fields = isset($value['fields']) && is_array($value['fields']) ? $value['fields'] : [];
        if (!lumina_is_private_log($fields)) {
            return true;
        }
        $author = isset($value['author']) ? (int)$value['author'] : 0;
        return lumina_can_view_private_log($author);
    }));
    if ($isAuthorPage) {
        foreach ($logs as $idx => $logItem) {
            $timestamp = lumina_log_timestamp($logItem);
            $logs[$idx]['_lumina_timestamp'] = $timestamp;
            $logs[$idx]['_lumina_year'] = $timestamp > 0 ? lumina_blog_date('Y', $timestamp) : '';
            $logs[$idx]['_lumina_show_year'] = '';
        }
        usort($logs, function ($a, $b) {
            $at = isset($a['top']) && ($a['top'] === 'y' || $a['top'] === '1' || $a['top'] === 1) ? 1 : 0;
            $bt = isset($b['top']) && ($b['top'] === 'y' || $b['top'] === '1' || $b['top'] === 1) ? 1 : 0;
            if ($at !== $bt) {
                return $bt - $at;
            }
            $ad = isset($a['_lumina_timestamp']) ? (int)$a['_lumina_timestamp'] : 0;
            $bd = isset($b['_lumina_timestamp']) ? (int)$b['_lumina_timestamp'] : 0;
            if ($ad === $bd) {
                return 0;
            }
            return $bd > $ad ? 1 : -1;
        });
        $lastNormalYear = '';
        foreach ($logs as $idx => $logItem) {
            $isTopItem = isset($logItem['top']) && ($logItem['top'] === 'y' || $logItem['top'] === '1' || $logItem['top'] === 1);
            $year = isset($logItem['_lumina_year']) ? (string)$logItem['_lumina_year'] : '';
            if ($isTopItem || $year === '') {
                continue;
            }
            if ($year !== $lastNormalYear) {
                $logs[$idx]['_lumina_show_year'] = $year;
                $lastNormalYear = $year;
            }
        }
    }
}

$home_layout = 'single';
$pc_layout = lumina_opt('home_layout_pc', 'single');
if ($pc_layout === 'triple') {
    $pc_layout = 'double';
}
if (!in_array($pc_layout, ['single', 'double'], true)) {
    $pc_layout = 'single';
}
if ($isAuthorPage) {
    $home_layout = 'single';
}
$show_sidebar = ($pc_layout !== 'single');
$home_comment_limit = (int)lumina_opt('home_comment_limit', 6);
if ($home_comment_limit < 0) {
    $home_comment_limit = 0;
}
if (class_exists('Option')) {
    if ($home_comment_limit === 0) {
        $home_comment_limit = (int)Option::get('comment_pnum');
        if ($home_comment_limit < 0) {
            $home_comment_limit = 0;
        }
    }
}
$lumina_list_text_limit = (int)lumina_opt('list_text_limit', 120);
if ($lumina_list_text_limit < 0) {
    $lumina_list_text_limit = 0;
}
$lumina_pagination_enabled = (lumina_opt('list_pagination', 'n') === 'y');
$lumina_next_page_url = '';
if (isset($page, $total_pages, $pageurl) && $page && $total_pages && $pageurl) {
    if ((int)$page < (int)$total_pages) {
        $lumina_next_page_url = $pageurl . ((int)$page + 1);
    }
}
$sidebar_stats = [
    'logs' => 0,
    'comments' => 0,
    'likes' => 0,
];
$sidebar_sorts = [];
$sidebar_tags = [];
if (class_exists('Cache')) {
    $CACHE = null;
    if ($CACHE) {
        if ($show_sidebar) {
            $sta_cache = $CACHE->readCache('sta');
            if (is_array($sta_cache)) {
                $sidebar_stats['logs'] = isset($sta_cache['lognum']) ? (int)$sta_cache['lognum'] : 0;
                $sidebar_stats['comments'] = isset($sta_cache['comnum_all']) ? (int)$sta_cache['comnum_all'] : 0;
                $sidebar_stats['likes'] = isset($sta_cache['like_num']) ? (int)$sta_cache['like_num'] : 0;
            }
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

        if ($show_sidebar) {
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
}
if ($show_sidebar && $sidebar_stats['logs'] === 0 && !empty($logs)) {
    $sidebar_stats['logs'] = count($logs);
}

$sidebar_contact = '';
if ($profile_uid === 1) {
    $sidebar_contact = trim((string)lumina_opt('contact_info', ''));
    if ($sidebar_contact === '') {
        $sidebar_contact = $profile_desc;
    }
} else {
    $sidebar_contact = $profile_desc;
}
$sidebar_contact = trim((string)$sidebar_contact);
$sidebar_copyright = lumina_sidebar_copyright_source(isset($footer_info) ? $footer_info : '');
$sidebar_copyright_html = lumina_prepare_sidebar_copyright($sidebar_copyright);
$sidebar_icp = trim((string)lumina_opt('sidebar_icp', ''));
if ($sidebar_icp === '') {
    $sidebar_icp = isset($icp) ? $icp : '';
}
$quick_nav_items = (!$isAuthorPage && ($isHomeList || $isSortList)) ? lumina_get_quick_nav_items($sidebar_sorts) : [];
$authorStats = null;
if ($show_sidebar && $isAuthorPage && class_exists('Database')) {
    $db = \Pafish\Core\DB;
    $table_blog = DB_PREFIX . 'blog';
    $authorStats = $db->once_fetch_array("SELECT COUNT(*) AS total_logs, COALESCE(SUM(comnum),0) AS total_comments, COALESCE(SUM(like_count),0) AS total_likes FROM `$table_blog` WHERE author={$profile_uid} AND hide='n' AND checked='y'");
    if (is_array($authorStats)) {
        $sidebar_stats['logs'] = isset($authorStats['total_logs']) ? (int)$authorStats['total_logs'] : 0;
        $sidebar_stats['comments'] = isset($authorStats['total_comments']) ? (int)$authorStats['total_comments'] : 0;
        $sidebar_stats['likes'] = isset($authorStats['total_likes']) ? (int)$authorStats['total_likes'] : 0;
    }
}
?>
<div class="centent lumina-layout lumina-layout-<?= $home_layout ?> lumina-layout-pc-<?= $pc_layout ?>" data-lumina-pjax-container>
    <div class="lumina-layout-wrap">
        <div class="sh-main">
        <div class="sh-main-head">
            <div class="sh-main-head-top" id="sh-main-head-top">
                <div class="sh-main-head-top-left">
                    <?php if (!$isHomeList): ?>
                        <div class="sh-main-head-top-left-s lumina-top-hit" data-lumina-back-url="<?= htmlspecialchars(lumina_blog_base(), ENT_QUOTES) ?>" onclick="return window.luminaNavigateBack ? window.luminaNavigateBack(this,event) : (location.href='<?= lumina_blog_base() ?>', false)">
                            <i class="iconfont icon-weibiaoti al-sxb lumina-top-icon" id="top-left-1"></i>
                        </div>
                    <?php endif; ?>
                    <?php if ($lumina_top_bgm_enabled): ?>
                        <div class="lumina-top-bgm" id="lumina-top-bgm" data-autoplay="<?= $lumina_top_bgm_autoplay ? 'y' : 'n' ?>">
                            <button type="button" class="lumina-top-bgm-toggle" data-action="top-bgm-toggle" aria-label="播放顶部背景音乐">
                                <i class="iconfont icon-sa4f56 lumina-top-bgm-icon lumina-top-bgm-icon-play" aria-hidden="true"></i>
                                <i class="iconfont icon-iconstop lumina-top-bgm-icon lumina-top-bgm-icon-pause" aria-hidden="true"></i>
                            </button>
                            <span class="lumina-top-bgm-progress" aria-hidden="true">
                                <span class="lumina-top-bgm-progress-bar"></span>
                            </span>
                            <audio id="lumina-top-bgm-audio" preload="metadata" loop src="<?= htmlspecialchars($lumina_top_bgm_url, ENT_QUOTES) ?>"></audio>
                        </div>
                    <?php endif; ?>
                    <?php if ($friendLinksEnabled): ?>
                        <div class="sh-main-head-top-left-s lumina-top-hit lumina-top-link-btn" onclick="kqlink()" title="<?= htmlspecialchars($friendLinks['title'], ENT_QUOTES) ?>">
                            <svg class="lumina-top-icon-links" data-top-icon-role="links" viewBox="0 0 1024 1024" aria-hidden="true" focusable="false">
                                <path d="M117.6 347.3h113.9c11 0 19.9-8.9 19.9-19.9 0-11-8.9-19.9-19.9-19.9H117.6c-11 0-19.9 8.9-19.9 19.9 0.1 10.9 9 19.9 19.9 19.9z m113.9 144.9H117.6c-11 0-19.9 8.9-19.9 19.9 0 11 8.9 19.9 19.9 19.9h113.9c11 0 19.9-8.9 19.9-19.9 0-11-8.9-19.9-19.9-19.9z m0 184.1H117.6c-11 0-19.9 8.9-19.9 19.9 0 11 8.9 19.9 19.9 19.9h113.9c11 0 19.9-8.9 19.9-19.9 0-11-8.9-19.9-19.9-19.9z m607.4-595h-591c-49 0-88.7 39.7-88.7 88.7v65.1h40.4v-54.7c0-38.1 21-59.1 59.1-59.1H829c38.1 0 59.1 21 59.1 59.1v663.4c0 38.1-21 59.1-59.1 59.1H258.7c-38.1 0-59.1-21-59.1-59.1v-54.7h-40.4v64.1c0 49 39.7 88.6 88.7 88.6h591c49 0 88.7-39.7 88.7-88.6V169.9c0-48.9-39.7-88.6-88.7-88.6z m-213.1 531c51.5-28.9 86.6-83.5 86.6-146.8 0-93.4-75.7-169-169-169s-169 75.7-169 169c0 63.4 35.2 118.1 86.9 147-49.8 21.5-99.4 61.8-120 112h42.9c27.5-51.8 91-87.7 153.1-90H550.2c62.2 2.3 125.6 38.2 153.1 90h42.9c-20.6-50.3-70.4-90.8-120.4-112.2zM413.5 465.5c0-71.8 58.2-129.9 129.9-129.9 71.8 0 129.9 58.2 129.9 129.9 0 71.8-58.2 129.9-129.9 129.9-71.8 0-129.9-58.2-129.9-129.9z"></path>
                            </svg>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($lumina_logo)): ?>
                    <div class="sh-main-head-top-center">
                        <a href="<?= lumina_blog_base() ?>" class="lumina-site-logo-link" title="<?= htmlspecialchars($site_title, ENT_QUOTES) ?>">
                            <img class="lumina-site-logo" src="<?= $lumina_logo ?>" alt="<?= htmlspecialchars($site_title, ENT_QUOTES) ?>">
                        </a>
                    </div>
                <?php endif; ?>
                <div class="sh-main-head-top-right">
                    <?php if (is_logged_in()): ?>
                        <div class="sh-main-head-top-right-s lumina-top-hit">
                            <a href="<?= lumina_user_url() ?>" title="发布/个人中心">
                                <i class="iconfont icon-xiangji1 al-sxb lumina-top-icon lumina-top-icon-camera"></i>
                            </a>
                        </div>
                        <div class="sh-main-head-top-right-s lumina-top-hit">
                            <a href="<?= lumina_user_url('profile') ?>" title="个人设置">
                                <i class="iconfont icon-a31shezhi al-sxb lumina-top-icon lumina-top-icon-settings" id="top-right-2" data-icon-lock="1"></i>
                            </a>
                        </div>
                        <div class="sh-main-head-top-right-s lumina-top-hit" onclick="kqnews()">
                            <?php if ($noticeCount > 0): ?><p class="xiaoxhd" id="lumina-notice-badge"></p><?php endif; ?>
                            <i class="iconfont icon-lingdang al-sxb lumina-top-icon" id="top-right-1" data-top-icon-role="notice"></i>
                        </div>
                    <?php else: ?>
                        <div class="sh-main-head-top-right-s lumina-top-hit">
                            <a href="<?= lumina_blog_base() ?>admin/account.php?action=signin" title="登录">
                                <i class="iconfont icon-account-circle-line al-sxb lumina-top-icon"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sh-main-head-img" style="background-image:url(<?= $lumina_cover ?>)">
                <?php if ($canEditCover): ?>
                    <div class="sh-main-head-top-scba" id="lumina-cover-btn" title="更换封面">
                        <i class="iconfont icon-tianjiatupian al-sxb2"></i>
                    </div>
                    <input type="file" id="lumina-cover-file" accept="image/*" style="display:none" data-upload-url="<?= htmlspecialchars(lumina_user_url(), ENT_QUOTES) ?>" data-token="<?= htmlspecialchars($coverToken, ENT_QUOTES) ?>">
                <?php endif; ?>
            </div>
        </div>
        <div class="sh-main-head-headimg">
            <div class="sh-main-head-headimg-tx">
                <h4><?= $profile_name ?></h4>
                <a href="<?= lumina_profile_link($profile_uid) ?>">
                    <img src="<?= $profile_avatar ?>" alt="avatar" onerror="this.onerror=null;this.src='<?= $lumina_avatar ?>'">
                </a>
            </div>
            <?php if ($profile_desc !== ''): ?>
                <div class="sh-main-head-headimg-qm"><p><?= $profile_desc ?></p></div>
            <?php endif; ?>
        </div>
        <?php lumina_render_quick_nav($quick_nav_items, $isHomeList); ?>

        <div style="display:none;position: absolute;top: -100%;" id="pinglunkfk">
            <div class="sh-pinglunkuang" id="pinglunkuang">
                <div class="sh-pinglun" id="sh-pinglun">
                    <?php if ((!is_logged_in()) && $allowGuest): ?>
                        <div class="sh-plk-yk" id="sh-plk-yk" style="display:none;">
                            <div class="sh-plk-yk-z" style="margin-left: 8px;"><input id="vis_name" type="text" value="" maxlength="49" minlength="1" placeholder="昵称*" autocomplete="off"></div>
                            <div class="sh-plk-yk-zz"><input id="vis_email" type="text" value="" maxlength="128" minlength="1" placeholder="邮箱*" autocomplete="off"></div>
                            <div class="sh-plk-yk-z" style="margin-right: 8px;"><input id="vis_url" type="text" value="" maxlength="255" minlength="1" placeholder="网站" autocomplete="off"></div>
                        </div>
                    <?php endif; ?>
                    <div class="sh-pinglun-s">
                        <textarea name="comment" id="bletext" class="form-controll" placeholder="评论" required spellcheck="false" maxlength="500"></textarea>
                    </div>
                    <?php if ($verifyCode !== ''): ?>
                        <div class="sh-comment-verify"><?= $verifyCode ?></div>
                    <?php endif; ?>
                    <div class="sh-pinglun-biao" id="biaoqing">
                        <?php lumina_render_emoji_panel(); ?>
                    </div>
                    <div class="sh-pinglun-fs">
                        <div class="sh-pinglun-fs-right">
                            <?php if ((!is_logged_in()) && $allowGuest): ?>
                                <div class="sh-pinglun-fs-right-bqimg" id="ykkg" onclick="ykkg()">
                                    <i class="iconfont icon-yonghu1 ri-sxbqxz" id="sh-pinglun-fs-right-ykkgb" title="游客模式"></i>
                                </div>
                            <?php endif; ?>
                            <div class="sh-pinglun-fs-right-bqimg" id="bqkg" onclick="bqkg()">
                                <i class="iconfont icon-biaoqing ri-sxbqxz" id="sh-pinglun-fs-right-bqimg"></i>
                            </div>
                            <div class="sh-pinglun-fs-right-fs" id="sh-pinglun-fs-right-fs" onclick="fasong()">
                                <span>发送</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="huifucanshu">
                    <p id="sh-tieid">-</p>
                    <p id="sh-tiehf">-</p>
                    <p id="sh-tieea">-</p>
                    <p id="sh-tiepid">0</p>
                </div>
            </div>
        </div>

        <?php do_action('index_loglist_top'); ?>

        <div class="sh-nrbk" id="sh-nrbk" style="width:100%">
            <?php if ($isAuthorPage): ?>
                <?php if (!empty($logs)) : ?>
                    <div class="sh-homecontent lumina-author-list">
                        <?php foreach ($logs as $value) : ?>
                            <?php
                            $isTop = isset($value['top']) && ($value['top'] === 'y' || $value['top'] === '1' || $value['top'] === 1);
                            $fields = isset($value['fields']) && is_array($value['fields']) ? $value['fields'] : [];
                            $isPrivate = lumina_is_private_log($fields);
                            $lumina_type = isset($fields['lumina_type']) ? $fields['lumina_type'] : '';
                            $lumina_photos = isset($fields['lumina_photos']) ? lumina_parse_list($fields['lumina_photos']) : [];
                            $lumina_live_photos_raw = isset($fields['lumina_live_photos']) ? $fields['lumina_live_photos'] : '';
                            $lumina_video = isset($fields['lumina_video_url']) ? $fields['lumina_video_url'] : '';
                            $lumina_video_poster = isset($fields['lumina_video_poster']) ? $fields['lumina_video_poster'] : '';
                            $lumina_music = isset($fields['lumina_music_url']) ? $fields['lumina_music_url'] : '';
                            $lumina_music_title = isset($fields['lumina_music_title']) ? $fields['lumina_music_title'] : '';
                            $lumina_music_artist = isset($fields['lumina_music_artist']) ? $fields['lumina_music_artist'] : '';
                            $lumina_music_cover = isset($fields['lumina_music_cover']) ? $fields['lumina_music_cover'] : '';
                            $lumina_video_list = lumina_parse_media_list($lumina_video);
                            $lumina_music_list = lumina_parse_music_list($lumina_music, $lumina_music_title);
                            $music_item = !empty($lumina_music_list) ? $lumina_music_list[0] : ['url' => '', 'title' => $lumina_music_title, 'artist' => '', 'cover' => ''];
                            $author_name = lumina_get_user_name($value['author'], blog_author($value['author']));
                            $media_music = isset($music_item['url']) ? $music_item['url'] : '';
                            $media_music_title = isset($music_item['title']) ? $music_item['title'] : $lumina_music_title;
                            $item_music_cover = isset($music_item['cover']) ? $music_item['cover'] : '';
                            $item_music_artist = isset($music_item['artist']) ? $music_item['artist'] : '';
                            $media_music_cover = lumina_resolve_url($item_music_cover !== '' ? $item_music_cover : $lumina_music_cover, lumina_tpl_base() . 'assets/img/musicba.jpg');
                            $media_music_artist = trim((string)$item_music_artist) !== '' ? $item_music_artist : (trim((string)$lumina_music_artist) !== '' ? $lumina_music_artist : $author_name);
                            $lumina_location = isset($fields['lumina_location']) ? trim((string)$fields['lumina_location']) : '';
                            $lumina_location_address = isset($fields['lumina_location_address']) ? trim((string)$fields['lumina_location_address']) : '';
                            $lumina_location_lat = isset($fields['lumina_location_lat']) ? trim((string)$fields['lumina_location_lat']) : '';
                            $lumina_location_lng = isset($fields['lumina_location_lng']) ? trim((string)$fields['lumina_location_lng']) : '';
                            $lumina_location_url = lumina_location_url($lumina_location, $lumina_location_lat, $lumina_location_lng, $lumina_location_address);
                            $redpacketState = lumina_prepare_redpacket_state($value['logid'], $fields);
                            $redpacketTitle = $redpacketState['title'];
                            $redpacketMode = $redpacketState['mode'];
                            $redpacketTotal = $redpacketState['total'];
                            $redpacketCount = $redpacketState['count'];
                            $redpacketRemain = $redpacketState['remain'];
                            $redpacketRemainCount = $redpacketState['remain_count'];
                            $redpacketStatus = $redpacketState['status'];
                            $redpacketClaimed = $redpacketState['claimed'];
                            $redpacketClaimedAmount = $redpacketState['claimed_amount'];
                            $redpacket = $redpacketState['raw'];
                            $linkCardState = lumina_prepare_link_card_state($fields);
                            $embedVideoState = lumina_prepare_embed_video_state($fields);

                            $lumina_log_content = isset($value['log_content']) ? $value['log_content'] : '';
                            $lumina_full_content = $lumina_log_content;
                            $fullLog = $Log_Model->getOneLogForHome($value['logid'], true, true);
                            if ($fullLog && !empty($fullLog['log_content'])) {
                                $lumina_full_content = $fullLog['log_content'];
                            }
                            $content_images = lumina_extract_images($lumina_full_content);
                            $content_videos = lumina_extract_videos($lumina_full_content);

                            $media_images = $content_images;
                            $media_video = !empty($content_videos) ? $content_videos[0] : '';

                            if ($lumina_type === 'only' || $lumina_type === 'text') {
                                $lumina_type = '';
                            }

                            if ($lumina_type === 'live') {
                                if (empty($media_images) && !empty($lumina_photos)) {
                                    $media_images = $lumina_photos;
                                }
                                if (empty($media_images) && !empty($value['log_cover'])) {
                                    $media_images = [$value['log_cover']];
                                }
                            } elseif ($lumina_type === 'img') {
                                if (empty($media_images) && !empty($lumina_photos)) {
                                    $media_images = $lumina_photos;
                                }
                                if (empty($media_images) && !empty($value['log_cover'])) {
                                    $media_images = [$value['log_cover']];
                                }
                            } elseif ($lumina_type === 'video') {
                                if (empty($media_video) && !empty($lumina_video_list)) {
                                    $media_video = $lumina_video_list[0];
                                }
                            }

                            $media_type = 'only';
                            if ($lumina_type === 'redpacket') {
                                $media_type = 'redpacket';
                            } elseif ($lumina_type === 'embed' && !empty($embedVideoState['src'])) {
                                $media_type = 'embed';
                            } elseif ($lumina_type === 'video' && $media_video !== '') {
                                $media_type = 'video';
                            } elseif (($lumina_type === 'img' || $lumina_type === 'live') && !empty($media_images)) {
                                $media_type = 'img';
                            } elseif ($lumina_type === 'music' && $media_music !== '') {
                                $media_type = 'music';
                            } elseif ($lumina_type === 'link' && !empty($linkCardState['url'])) {
                                $media_type = 'link';
                            } else {
                                if ($media_video !== '') {
                                    $media_type = 'video';
                                } elseif (!empty($embedVideoState['src'])) {
                                    $media_type = 'embed';
                                } elseif (!empty($media_images)) {
                                    $media_type = 'img';
                                } elseif (!empty($lumina_video_list)) {
                                    $media_type = 'video';
                                    $media_video = $lumina_video_list[0];
                                } elseif (!empty($lumina_photos)) {
                                    $media_type = 'img';
                                    $media_images = $lumina_photos;
                                } elseif (!empty($value['log_cover'])) {
                                    $media_type = 'img';
                                    $media_images = [$value['log_cover']];
                                } elseif ($media_music !== '') {
                                    $media_type = 'music';
                                } elseif (!empty($linkCardState['url'])) {
                                    $media_type = 'link';
                                }
                            }

                            $desc_source_raw = $value['log_description'] ? $value['log_description'] : $lumina_full_content;
                            $desc_source = lumina_strip_media_html($desc_source_raw);
                            $desc_plain = lumina_clean_text($desc_source);
                            $desc_text = $desc_plain;
                            $desc_html = $desc_plain === '' ? '' : lumina_render_markdown($desc_source, true);
                            $logTimestamp = isset($value['_lumina_timestamp']) ? (int)$value['_lumina_timestamp'] : lumina_log_timestamp($value);
                            $yearLabel = isset($value['_lumina_year']) ? (string)$value['_lumina_year'] : ($logTimestamp > 0 ? lumina_blog_date('Y', $logTimestamp) : '');
                            $monthLabel = $logTimestamp > 0 ? lumina_blog_date('m', $logTimestamp) : '';
                            $dayLabel = $logTimestamp > 0 ? lumina_blog_date('d', $logTimestamp) : '';
                            $givenDate = $yearLabel . '-' . $monthLabel . '-' . $dayLabel;
                            $givenYmd = $yearLabel . $monthLabel . $dayLabel;
                            if ($givenYmd === lumina_blog_date('Ymd')) {
                                $dayLabel = '今天';
                                $monthLabel = '';
                            } elseif ($givenYmd === lumina_blog_date('Ymd', time() - 86400)) {
                                $dayLabel = '昨天';
                                $monthLabel = '';
                            } else {
                                $monthLabel = $monthLabel . '月';
                            }
                            $wzsdbs = '';
                            $imgTotal = count($media_images);
                            $livePhotoMap = $lumina_type === 'live' ? lumina_parse_live_photo_map($lumina_live_photos_raw, $media_images) : [];
                            $media_images = array_slice($media_images, 0, 9);
                            $imgCount = count($media_images);
                            ?>
                            <?php if (!empty($value['_lumina_show_year'])): ?>
                                <h2 class="sh-homecontent-timed" id="lumina-author-year-<?= htmlspecialchars($value['_lumina_show_year'], ENT_QUOTES) ?>" data-author-year="<?= htmlspecialchars($value['_lumina_show_year'], ENT_QUOTES) ?>"><?= htmlspecialchars($value['_lumina_show_year']) ?><span class="sh-homecontent-timed-n">年</span></h2>
                            <?php endif; ?>
                            <div class="sh-homecontent-lie<?= $isTop ? ' is-top' : '' ?>" data-author-year="<?= htmlspecialchars($yearLabel, ENT_QUOTES) ?>">
                                <div class="sh-homecontent-left">
                                    <div class="sh-homecontent-left-time" lang="year-<?= $givenDate ?>">
                                        <?php if ($isTop): ?>
                                            <span class="homecontent-left-time-h is-top">置顶</span>
                                        <?php else: ?>
                                            <span class="homecontent-left-time-h"><?= $dayLabel ?></span>
                                            <span class="homecontent-left-time-y"><?= $monthLabel ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($lumina_location !== ''): ?>
                                        <a class="sh-homecontent-left-time-dw" href="<?= htmlspecialchars($lumina_location_url, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer" title="<?= htmlspecialchars($lumina_location, ENT_QUOTES) ?>"><?= htmlspecialchars($lumina_location) ?></a>
                                    <?php endif; ?>
                                </div>
                                <div class="sh-homecontent-right-wk">
                                    <div class="sh-homecontent-right-lie" data-lumina-ajax-href="<?= htmlspecialchars($value['log_url'], ENT_QUOTES) ?>" onclick="return window.luminaAjaxCardClick ? window.luminaAjaxCardClick(this,event) : (location.href='<?= $value['log_url'] ?>', false)">
                                        <?php if ($media_type === 'img' && $imgCount > 0) : ?>
                                            <div class="homecontent-right-tw">
                                                <div class="homecontent-right-tw-img<?= $imgCount === 1 ? ' is-single' : '' ?>">
                                                    <?php if ($imgCount === 1): ?>
                                                        <?php $liveVideo = lumina_live_photo_video($media_images[0], $livePhotoMap); ?>
                                                        <div class="homecontent-right-tw-img-wk">
                                                            <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= htmlspecialchars($media_images[0], ENT_QUOTES) ?>" alt="">
                                                            <?php if ($liveVideo !== ''): ?><span class="lumina-live-badge">LIVE</span><?php endif; ?>
                                                        </div>
                                                        <?= $wzsdbs ?>
                                                    <?php elseif ($imgCount === 2): ?>
                                                        <?php $liveVideo = lumina_live_photo_video($media_images[0], $livePhotoMap); ?>
                                                        <div class="homecontent-right-tw-img-wk" style="grid-row: 1 / 3;">
                                                            <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= htmlspecialchars($media_images[0], ENT_QUOTES) ?>" alt="">
                                                            <?php if ($liveVideo !== ''): ?><span class="lumina-live-badge">LIVE</span><?php endif; ?>
                                                        </div>
                                                        <?php $liveVideo = lumina_live_photo_video($media_images[1], $livePhotoMap); ?>
                                                        <div class="homecontent-right-tw-img-wk" style="grid-row: 1 / 3;">
                                                            <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= htmlspecialchars($media_images[1], ENT_QUOTES) ?>" alt="">
                                                            <?php if ($liveVideo !== ''): ?><span class="lumina-live-badge">LIVE</span><?php endif; ?>
                                                        </div>
                                                        <?= $wzsdbs ?>
                                                    <?php elseif ($imgCount === 3): ?>
                                                        <?php $liveVideo = lumina_live_photo_video($media_images[0], $livePhotoMap); ?>
                                                        <div class="homecontent-right-tw-img-wk" style="grid-row: 1 / 3;">
                                                            <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= htmlspecialchars($media_images[0], ENT_QUOTES) ?>" alt="">
                                                            <?php if ($liveVideo !== ''): ?><span class="lumina-live-badge">LIVE</span><?php endif; ?>
                                                        </div>
                                                        <?php $liveVideo = lumina_live_photo_video($media_images[1], $livePhotoMap); ?>
                                                        <div class="homecontent-right-tw-img-wk">
                                                            <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= htmlspecialchars($media_images[1], ENT_QUOTES) ?>" alt="">
                                                            <?php if ($liveVideo !== ''): ?><span class="lumina-live-badge">LIVE</span><?php endif; ?>
                                                        </div>
                                                        <?php $liveVideo = lumina_live_photo_video($media_images[2], $livePhotoMap); ?>
                                                        <div class="homecontent-right-tw-img-wk">
                                                            <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= htmlspecialchars($media_images[2], ENT_QUOTES) ?>" alt="">
                                                            <?php if ($liveVideo !== ''): ?><span class="lumina-live-badge">LIVE</span><?php endif; ?>
                                                        </div>
                                                        <?= $wzsdbs ?>
                                                    <?php else: ?>
                                                        <?php for ($i = 0; $i < min(4, $imgCount); $i++): ?>
                                                            <?php $liveVideo = lumina_live_photo_video($media_images[$i], $livePhotoMap); ?>
                                                            <div class="homecontent-right-tw-img-wk">
                                                                <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= htmlspecialchars($media_images[$i], ENT_QUOTES) ?>" alt="">
                                                                <?php if ($liveVideo !== ''): ?><span class="lumina-live-badge">LIVE</span><?php endif; ?>
                                                            </div>
                                                        <?php endfor; ?>
                                                        <?= $wzsdbs ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php elseif ($media_type === 'redpacket') : ?>
                                            <?php /* redpacket in author page uses text-style block below */ ?>
                                        <?php elseif ($media_type === 'embed' && !empty($embedVideoState['src'])) : ?>
                                            <div class="homecontent-right-tw lumina-embed-preview-tw<?= isset($embedVideoState['ratio']) && $embedVideoState['ratio'] === 'tb' ? ' lumina-video-portrait' : '' ?>">
                                                <?= lumina_render_embed_video($embedVideoState, true, true) ?>
                                            </div>
                                        <?php elseif ($media_type === 'video' && $media_video) : ?>
                                            <div class="homecontent-right-tw">
                                                <div class="homecontent-right-tw-video">
                                                    <video class="homecontent-right-tw-videoau" poster="<?= htmlspecialchars($lumina_video_poster) ?>" src="<?= htmlspecialchars($media_video, ENT_QUOTES) ?>" playsinline="" webkit-playsinline="" preload="metadata" muted="" loop=""></video>
                                                    <span class="sh-video-span" style="left: 4px;bottom: 4px;">MP4</span>
                                                    <?= $wzsdbs ?>
                                                </div>
                                            </div>
                                        <?php elseif ($media_type === 'music' && $media_music) : ?>
                                            <div class="sh-homecontent-right-lie-musicwk">
                                                <?php if ($desc_text !== ''): ?>
                                                    <div class="sh-homecontent-right-lie-music-title"><?= $desc_text ?></div>
                                                <?php endif; ?>
                                                <div class="lumina-music-card lumina-music-card-compact" data-track-title="<?= htmlspecialchars($media_music_title !== '' ? $media_music_title : '未命名音乐', ENT_QUOTES) ?>" data-track-artist="<?= htmlspecialchars($media_music_artist, ENT_QUOTES) ?>" data-track-cover="<?= htmlspecialchars($media_music_cover, ENT_QUOTES) ?>">
                                                    <div class="lumina-music-left">
                                                        <div class="lumina-music-cover">
                                                            <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= htmlspecialchars($media_music_cover, ENT_QUOTES) ?>" alt="">
                                                        </div>
                                                    <div class="lumina-music-info">
                                                        <div class="lumina-music-title"><?= htmlspecialchars($media_music_title !== '' ? $media_music_title : '未命名音乐') ?></div>
                                                        <div class="lumina-music-subtitle"><?= htmlspecialchars($media_music_artist) ?></div>
                                                    </div>
                                                    </div>
                                                    <button type="button" class="lumina-music-btn" aria-label="播放">
                                                        <i class="iconfont icon-sa4f56 lumina-music-icon-play"></i>
                                                        <i class="iconfont icon-iconstop lumina-music-icon-pause"></i>
                                                    </button>
                                                    <audio class="lumina-music-audio" preload="metadata" src="<?= htmlspecialchars($media_music, ENT_QUOTES) ?>"></audio>
                                                </div>
                                            </div>
                                        <?php elseif ($media_type === 'link' && !empty($linkCardState['url'])) : ?>
                                            <div class="sh-homecontent-right-lie-linkwk">
                                                <?php if ($desc_text !== ''): ?>
                                                    <div class="sh-homecontent-right-lie-music-title"><?= $desc_text ?></div>
                                                <?php endif; ?>
                                                <?= lumina_render_link_card($linkCardState, true, false, false) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($media_type !== 'music' && $media_type !== 'link' && $media_type !== 'embed'): ?>
                                            <?php
                                            $is_redpacket_text = ($media_type === 'redpacket');
                                            $is_only_text = ($media_type === 'only');
                                            $desc_plain_trim = trim($desc_plain);
                                            $has_desc = ($desc_plain_trim !== '');
                                            ?>
                                            <?php if ($desc_html !== '' || ($media_type === 'img' && $imgCount > 0) || $is_redpacket_text): ?>
                                                <div class="homecontent-right-nr<?= $is_only_text ? ' homecontent-right-nr-only' : '' ?><?= $is_redpacket_text ? ' homecontent-right-nr-redpacket' : '' ?>"<?= $is_only_text ? ' style="min-height: 10px;background: var(--fgxys);position:relative;"' : '' ?>>
                                                <?php if ($is_redpacket_text): ?>
                                                    <?php
                                                    $rpRemainText = ($redpacketCount > 0) ? ('剩余 ' . $redpacketRemainCount . '/' . $redpacketCount) : '';
                                                    $rpClaimedText = ($redpacketClaimedAmount > 0) ? ('已领取 ' . $redpacketClaimedAmount . ' 积分') : '';
                                                    ?>
                                                    <div class="homecontent-right-nr-redpacket-body">
                                                        <?php if ($has_desc): ?>
                                                            <div class="homecontent-right-nr-text homecontent-right-nr-textjw"><?= $desc_html ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($rpRemainText !== '' || $rpClaimedText !== ''): ?>
                                                            <div class="homecontent-right-nr-meta">
                                                                <?php if ($rpRemainText !== ''): ?><span><?= $rpRemainText ?></span><?php endif; ?>
                                                                <?php if ($rpClaimedText !== ''): ?><span><?= $rpClaimedText ?></span><?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <i class="iconfont icon-hongbao homecontent-right-nr-icon" aria-hidden="true"></i>
                                                    <?php else: ?>
                                                        <?php if ($desc_html !== ''): ?>
                                                            <div class="homecontent-right-nr-text<?= $is_only_text ? ' homecontent-right-nr-textjw' : '' ?>"<?= $is_only_text ? ' style="margin: 10px;"' : '' ?>><?= $desc_html ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($media_type === 'img' && $imgCount > 0): ?>
                                                            <p class="homecontent-right-nr-tus">共<?= $imgTotal ?>张</p>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <script>
                        if (typeof wzcsql === 'function') { wzcsql(); }
                    </script>
                <?php else : ?>
                    <div class="sh-empty">No posts.</div>
                <?php endif; ?>
            <?php else: ?>
                <?php if (!empty($logs)) : ?>
                    <?php foreach ($logs as $value) : ?>
                    <?php
                    $fields = isset($value['fields']) && is_array($value['fields']) ? $value['fields'] : [];
                    $lumina_type = isset($fields['lumina_type']) ? $fields['lumina_type'] : '';
                    $lumina_photos = isset($fields['lumina_photos']) ? lumina_parse_list($fields['lumina_photos']) : [];
                    $lumina_live_photos_raw = isset($fields['lumina_live_photos']) ? $fields['lumina_live_photos'] : '';
                    $lumina_video = isset($fields['lumina_video_url']) ? $fields['lumina_video_url'] : '';
                    $lumina_video_poster = isset($fields['lumina_video_poster']) ? $fields['lumina_video_poster'] : '';
                    $lumina_music = isset($fields['lumina_music_url']) ? $fields['lumina_music_url'] : '';
                    $lumina_music_title = isset($fields['lumina_music_title']) ? $fields['lumina_music_title'] : '';
                    $lumina_music_artist = isset($fields['lumina_music_artist']) ? $fields['lumina_music_artist'] : '';
                    $lumina_music_cover = isset($fields['lumina_music_cover']) ? $fields['lumina_music_cover'] : '';
                    $lumina_video_list = lumina_parse_media_list($lumina_video);
                    $lumina_music_list = lumina_parse_music_list($lumina_music, $lumina_music_title);
                    $music_item = !empty($lumina_music_list) ? $lumina_music_list[0] : ['url' => '', 'title' => $lumina_music_title, 'artist' => '', 'cover' => ''];
                    $author_name = lumina_get_user_name($value['author'], blog_author($value['author']));
                    $media_music = isset($music_item['url']) ? $music_item['url'] : '';
                    $media_music_title = isset($music_item['title']) ? $music_item['title'] : $lumina_music_title;
                    $item_music_cover = isset($music_item['cover']) ? $music_item['cover'] : '';
                    $item_music_artist = isset($music_item['artist']) ? $music_item['artist'] : '';
                    $media_music_cover = lumina_resolve_url($item_music_cover !== '' ? $item_music_cover : $lumina_music_cover, lumina_tpl_base() . 'assets/img/musicba.jpg');
                    $media_music_artist = trim((string)$item_music_artist) !== '' ? $item_music_artist : (trim((string)$lumina_music_artist) !== '' ? $lumina_music_artist : $author_name);
                    $lumina_location = isset($fields['lumina_location']) ? trim((string)$fields['lumina_location']) : '';
                    $lumina_location_address = isset($fields['lumina_location_address']) ? trim((string)$fields['lumina_location_address']) : '';
                    $lumina_location_lat = isset($fields['lumina_location_lat']) ? trim((string)$fields['lumina_location_lat']) : '';
                    $lumina_location_lng = isset($fields['lumina_location_lng']) ? trim((string)$fields['lumina_location_lng']) : '';
                    $lumina_location_url = lumina_location_url($lumina_location, $lumina_location_lat, $lumina_location_lng, $lumina_location_address);
                    $redpacketState = lumina_prepare_redpacket_state($value['logid'], $fields);
                    $redpacketTitle = $redpacketState['title'];
                    $redpacketMode = $redpacketState['mode'];
                    $redpacketTotal = $redpacketState['total'];
                    $redpacketCount = $redpacketState['count'];
                    $redpacketRemain = $redpacketState['remain'];
                    $redpacketRemainCount = $redpacketState['remain_count'];
                    $redpacketStatus = $redpacketState['status'];
                    $redpacketClaimed = $redpacketState['claimed'];
                    $redpacketClaimedAmount = $redpacketState['claimed_amount'];
                    $redpacket = $redpacketState['raw'];
                    $linkCardState = lumina_prepare_link_card_state($fields);
                    $embedVideoState = lumina_prepare_embed_video_state($fields);

                    $lumina_log_content = isset($value['log_content']) ? $value['log_content'] : '';
                    $lumina_full_content = $lumina_log_content;
                    $fullLog = $Log_Model->getOneLogForHome($value['logid'], true, true);
                    if ($fullLog && !empty($fullLog['log_content'])) {
                        $lumina_full_content = $fullLog['log_content'];
                    }
                    $content_images = lumina_extract_images($lumina_full_content);
                    $content_videos = lumina_extract_videos($lumina_full_content);

                    $media_images = $content_images;
                    $media_video = !empty($content_videos) ? $content_videos[0] : '';

                    if ($lumina_type === 'only' || $lumina_type === 'text') {
                        $lumina_type = '';
                    }

                    if ($lumina_type === 'live') {
                        if (empty($media_images) && !empty($lumina_photos)) {
                            $media_images = $lumina_photos;
                        }
                        if (empty($media_images) && !empty($value['log_cover'])) {
                            $media_images = [$value['log_cover']];
                        }
                    } elseif ($lumina_type === 'img') {
                        if (empty($media_images) && !empty($lumina_photos)) {
                            $media_images = $lumina_photos;
                        }
                        if (empty($media_images) && !empty($value['log_cover'])) {
                            $media_images = [$value['log_cover']];
                        }
                    } elseif ($lumina_type === 'video') {
                        if (empty($media_video) && !empty($lumina_video_list)) {
                            $media_video = $lumina_video_list[0];
                        }
                    }

                    $media_type = 'only';
                    if ($lumina_type === 'redpacket') {
                        $media_type = 'redpacket';
                    } elseif ($lumina_type === 'embed' && !empty($embedVideoState['src'])) {
                        $media_type = 'embed';
                    } elseif ($lumina_type === 'video' && $media_video !== '') {
                        $media_type = 'video';
                    } elseif (($lumina_type === 'img' || $lumina_type === 'live') && !empty($media_images)) {
                        $media_type = 'img';
                    } elseif ($lumina_type === 'music' && $media_music !== '') {
                        $media_type = 'music';
                    } elseif ($lumina_type === 'link' && !empty($linkCardState['url'])) {
                        $media_type = 'link';
                    } else {
                        if ($media_video !== '') {
                            $media_type = 'video';
                        } elseif (!empty($embedVideoState['src'])) {
                            $media_type = 'embed';
                        } elseif (!empty($media_images)) {
                            $media_type = 'img';
                        } elseif (!empty($lumina_video_list)) {
                            $media_type = 'video';
                            $media_video = $lumina_video_list[0];
                        } elseif (!empty($lumina_photos)) {
                            $media_type = 'img';
                            $media_images = $lumina_photos;
                        } elseif (!empty($value['log_cover'])) {
                            $media_type = 'img';
                            $media_images = [$value['log_cover']];
                        } elseif ($media_music !== '') {
                            $media_type = 'music';
                        } elseif (!empty($linkCardState['url'])) {
                            $media_type = 'link';
                        }
                    }

                    if (count($lumina_photos) > count($media_images)) {
                        $media_images = $lumina_photos;
                    }

                    $media_images_all = $media_images;
                    $livePhotoMap = $lumina_type === 'live' ? lumina_parse_live_photo_map($lumina_live_photos_raw, $media_images_all) : [];
                    $imgTotal = count($media_images_all);
                    $media_images = array_slice($media_images_all, 0, 9);
                    $coun = count($media_images);
                    if ($coun === 1) {
                        $tusty = 'grid-template-columns:1fr;width:min(72%,360px);';
                    } elseif ($coun === 2 || $coun === 4) {
                        $tusty = 'grid-template-columns:1fr 1fr;width:min(72%,360px);';
                    } else {
                        $tusty = 'grid-template-columns:1fr 1fr 1fr;';
                    }

                    $likeList = $Like_Model->getList($value['logid']);
                    $liked = $Like_Model->isLiked($value['logid'], (!is_logged_in()) ? 0 : (int)(current_user()['id'] ?? 0), $clientIp);
                    $likeIcon = $liked ? 'iconfont icon-aixin2 ri-sxdzlikehs' : 'iconfont icon-aixin ri-sxdzlike';
                    $likeText = $liked ? '取消' : '赞';
                    $allowRemark = isset($value['allow_remark']) ? $value['allow_remark'] : 'y';
                    $authorAvatar = getEmUserAvatar($value['author'], '');
                    $isTop = isset($value['top']) && ($value['top'] === 'y' || $value['top'] === '1' || $value['top'] === 1);
                    $isPrivate = lumina_is_private_log($fields);
                    $commentsData = $Comment_Model->getComments($value['logid'], 'n', 1);
                    $commentStacks = isset($commentsData['commentStacks']) ? $commentsData['commentStacks'] : [];
                    if (isset($commentsData['comments']) && is_array($commentsData['comments'])) {
                        $commentTotal = count($commentsData['comments']);
                    } else {
                        $commentTotal = is_array($commentStacks) ? count($commentStacks) : 0;
                    }
                    $commentMoreStyle = ($home_comment_limit > 0 && $commentTotal > $home_comment_limit) ? 'display:flex' : (!empty($commentStacks) ? 'display:flex' : 'display:none');
                    $hasLikes = !empty($likeList);
                    $hasComments = !empty($commentStacks);

                    $detail_html = lumina_prepare_article_text_html($lumina_full_content, true, $media_type !== 'only');
                    $detail_plain = trim(preg_replace('/\s+/u', ' ', strip_tags($detail_html)));
                    $detail_len = function_exists('mb_strlen') ? mb_strlen($detail_plain, 'UTF-8') : strlen($detail_plain);
                    $detail_has_more = ($lumina_list_text_limit > 0 && $detail_len > $lumina_list_text_limit);
                    if ($detail_has_more) {
                        if (function_exists('mb_substr')) {
                            $detail_preview = mb_substr($detail_plain, 0, $lumina_list_text_limit, 'UTF-8');
                        } else {
                            $detail_preview = substr($detail_plain, 0, $lumina_list_text_limit);
                        }
                        $detail_preview = $detail_preview . '...';
                    } else {
                        $detail_preview = $detail_plain;
                    }
                    ?>
                    <div class="sh-content" id="sh-content-<?= $value['logid'] ?>">
                        <div class="sh-content-left">
                            <a href="<?= htmlspecialchars(lumina_profile_link($value['author']), ENT_QUOTES) ?>">
                                <img src="<?= $authorAvatar ?>" alt="avatar" onerror="this.onerror=null;this.src='<?= $lumina_avatar ?>'">
                            </a>
                        </div>
                        <div class="sh-content-right">
                            <div class="sh-content-right-head">
                                <div class="sh-content-right-head-title">
                                    <p>
                                        <a class="sh-author-link" href="<?= url_to('/author/' . rawurlencode((string)($value['author']))) ?>"><?= $author_name ?></a>
                                        <?= lumina_log_status_badges_html($isTop, $isPrivate) ?>
                                    </p>
                                    <div class="sh-content-right-head-title-ad" style="display:none;"><p>AD</p></div>
                                </div>
                                <?php if ($detail_plain !== ''): ?>
                                    <div class="sh-content-right-article">
                                        <?php if ($detail_has_more): ?>
                                            <span class="lumina-text-preview" id="sh-content-preview-<?= $value['logid'] ?>"><?= htmlspecialchars($detail_preview, ENT_QUOTES) ?></span>
                                            <span class="lumina-text-full" id="sh-content-full-<?= $value['logid'] ?>" style="display:none;"><?= $detail_html ?></span>
                                            <a href="JavaScript:;" class="sh-content-quanwenan" data-preview="sh-content-preview-<?= $value['logid'] ?>" data-full="sh-content-full-<?= $value['logid'] ?>" data-open="0" onclick="return quanwenan(this,event)">全文</a>
                                        <?php else: ?>
                                            <span><?= $detail_html ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($media_type === 'img' && $coun > 0) : ?>
                                    <div class="sh-content-right-img" id="imglib-<?= $value['logid'] ?>" style="<?= $tusty ?>">
                                        <?php foreach ($media_images as $idx => $img) : ?>
                                            <?php
                                            $imgUrl = htmlspecialchars($img, ENT_QUOTES);
                                            $liveVideo = lumina_live_photo_video($img, $livePhotoMap);
                                            $liveVideoAttr = $liveVideo !== '' ? ' data-live-video="' . htmlspecialchars($liveVideo, ENT_QUOTES) . '"' : '';
                                            $fancyboxAttr = $liveVideo === '' ? ' data-fancybox="gallery' . $value['logid'] . '"' : '';
                                            $liveClass = $liveVideo !== '' ? ' is-live-photo' : '';
                                            ?>
                                            <?php if ($imgTotal > 9 && $idx === 8): ?>
                                                <a href="<?= $imgUrl ?>" class="sh-content-right-img-pic<?= $liveClass ?>"<?= $fancyboxAttr ?><?= $liveVideoAttr ?>>
                                                    <span class="sh-content-right-img-pic-mask">+<?= $imgTotal - 9 ?></span>
                                                    <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= $imgUrl ?>" alt="">
                                                    <?php if ($liveVideo !== ''): ?><span class="lumina-live-badge">LIVE</span><video class="lumina-live-video" src="<?= htmlspecialchars($liveVideo, ENT_QUOTES) ?>" muted playsinline webkit-playsinline preload="metadata" loop></video><?php endif; ?>
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= $imgUrl ?>" class="sh-content-right-img-pic<?= $liveClass ?>"<?= $fancyboxAttr ?><?= $liveVideoAttr ?>>
                                                    <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= $imgUrl ?>" alt="">
                                                    <?php if ($liveVideo !== ''): ?><span class="lumina-live-badge">LIVE</span><video class="lumina-live-video" src="<?= htmlspecialchars($liveVideo, ENT_QUOTES) ?>" muted playsinline webkit-playsinline preload="metadata" loop></video><?php endif; ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php if ($imgTotal > 9): ?>
                                            <?php for ($i = 9; $i < $imgTotal; $i++): ?>
                                                <?php $imgUrl = htmlspecialchars($media_images_all[$i], ENT_QUOTES); ?>
                                                <a href="<?= $imgUrl ?>" class="sh-content-right-img-pic" data-fancybox="gallery<?= $value['logid'] ?>" style="display:none;">
                                                    <img src="<?= $imgUrl ?>" data-src="<?= $imgUrl ?>" alt="">
                                                </a>
                                            <?php endfor; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($media_type === 'redpacket') : ?>
                                    <?= lumina_render_redpacket_card($value['logid'], $author_name, $redpacketState) ?>
                                <?php elseif ($media_type === 'link' && !empty($linkCardState['url'])) : ?>
                                    <?= lumina_render_link_card($linkCardState, false, true, false) ?>
                                <?php elseif ($media_type === 'embed' && !empty($embedVideoState['src'])) : ?>
                                    <?= lumina_render_embed_video($embedVideoState) ?>
                                <?php elseif ($media_type === 'video' && $media_video) : ?>
                                    <div class="sh-video">
                                        <video class="sh-content-video" poster="<?= htmlspecialchars($lumina_video_poster) ?>" src="<?= htmlspecialchars($media_video, ENT_QUOTES) ?>" controls preload="metadata" playsinline></video>
                                    </div>
                                <?php elseif ($media_type === 'music' && $media_music) : ?>
                                    <div class="lumina-music-card" data-track-title="<?= htmlspecialchars($media_music_title !== '' ? $media_music_title : '未命名音乐', ENT_QUOTES) ?>" data-track-artist="<?= htmlspecialchars($media_music_artist, ENT_QUOTES) ?>" data-track-cover="<?= htmlspecialchars($media_music_cover, ENT_QUOTES) ?>">
                                        <div class="lumina-music-left">
                                            <div class="lumina-music-cover">
                                                <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= htmlspecialchars($media_music_cover, ENT_QUOTES) ?>" alt="">
                                            </div>
                                        <div class="lumina-music-info">
                                            <div class="lumina-music-title"><?= htmlspecialchars($media_music_title !== '' ? $media_music_title : '未命名音乐') ?></div>
                                            <div class="lumina-music-subtitle"><?= htmlspecialchars($media_music_artist) ?></div>
                                        </div>
                                        </div>
                                        <button type="button" class="lumina-music-btn" aria-label="播放">
                                            <i class="iconfont icon-sa4f56 lumina-music-icon-play"></i>
                                            <i class="iconfont icon-iconstop lumina-music-icon-pause"></i>
                                        </button>
                                        <audio class="lumina-music-audio" preload="metadata" src="<?= htmlspecialchars($media_music, ENT_QUOTES) ?>"></audio>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($lumina_location !== '') : ?>
                                <div class="sh-content-right-gps"><a href="<?= htmlspecialchars($lumina_location_url, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation();" title="<?= htmlspecialchars($lumina_location, ENT_QUOTES) ?>"><?= htmlspecialchars($lumina_location) ?></a></div>
                            <?php endif; ?>

                            <div class="sh-content-right-time">
                                <div class="sh-content-right-time-left">
                                    <span><?= smartDate($value['date']) ?></span>
                                </div>
                                <div class="sh-content-right-time-right">
                                    <div class="sh-content-right-time-right-left" id="pl-<?= $value['logid'] ?>" name="pl">
                                        <div class="sh-content-right-time-right-left-z" onclick="dinazan()">
                                            <i class="<?= $likeIcon ?>" id="tiezimg-<?= $value['logid'] ?>"></i>
                                            <span id="tiezdz-<?= $value['logid'] ?>"><?= $likeText ?></span>
                                        </div>
                                        <p></p>
                                        <?php if ($allowRemark === 'y'): ?>
                                            <div class="sh-content-right-time-right-left-y" id="<?= $value['logid'] ?>" onclick="plkkg()">
                                                <i class="iconfont icon-pinglun2 ri-sxdzcomm"></i>
                                                <span>评论</span>
                                            </div>
                                        <?php else: ?>
                                            <div class="sh-content-right-time-right-left-y" id="<?= $value['logid'] ?>">
                                                <i class="iconfont icon-pinglun2 ri-sxdzcomm"></i>
                                                <span>评论关闭</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sh-content-right-time-right-right" id="<?= $value['logid'] ?>" onclick="plk()">
                                        <p class="zp1"></p>
                                        <p></p>
                                    </div>
                                </div>
                            </div>

                            <div class="sh-zanp" id="zanss-<?= $value['logid'] ?>" style="<?= ($hasLikes || $hasComments) ? '' : 'display:none;' ?>">
                                <?php $guestCount = 0; ?>
                                <div class="sh-zanp-zan" id="zans-<?= $value['logid'] ?>" style="<?= $hasLikes ? '' : 'display:none;' ?>">
                                    <div class="sh-zanp-zan-left"><i class="iconfont icon-aixin ri-sxwzlike"></i></div>
                                    <ul class="sh-zanp-zan-right" id="zlbeh-<?= $value['logid'] ?>">
                                        <?php if ($hasLikes): ?>
                                            <?php foreach ($likeList as $like): ?>
                                                <?php if ((int)$like['uid'] === 0): ?>
                                                    <?php $guestCount++; ?>
                                                <?php else: ?>
                                                    <li data-name="<?= htmlspecialchars($like['poster']) ?>"><?= htmlspecialchars($like['poster']) ?></li>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            <?php if ($guestCount > 0): ?>
                                                <li id="fkzan-<?= $value['logid'] ?>"><?= $guestCount ?>位访客</li>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </ul>
                                </div>

                                <?php if ($hasComments): ?>
                                    <?php lumina_render_comments_simple($commentsData, $value['logid'], $allowRemark, $home_comment_limit); ?>
                                <?php else: ?>
                                    <ul class="sh-zanp-pl" id="sh-zanp-pl-<?= $value['logid'] ?>" style="display:none;"></ul>
                                <?php endif; ?>

                                <?php if ($hasComments): ?>
                                    <div class="sh-zanp-pl-ku">
                                        <a href="<?= $value['log_url'] ?>" class="sh-zanp-pl-gd" style="<?= $commentMoreStyle ?>">
                                            <p class="zp1"></p>
                                            <p class="zp1"></p>
                                            <p></p>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <div class="sh-empty">No posts.</div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if (!$lumina_pagination_enabled): ?>
            <div class="footer lumina-list-more">
                <button type="button" class="footer-text" id="footer-text-zt" data-state="idle" data-next="<?= htmlspecialchars($lumina_next_page_url, ENT_QUOTES) ?>">查看更多</button>
                <span class="footer-text" id="footer-text-hqgd" style="display:none;"><?= !empty($logs) ? count($logs) : 0 ?></span>
            </div>
        <?php endif; ?>
        <?php if ($lumina_pagination_enabled && !empty($page_url)): ?>
            <div class="sh-page lumina-page-nav"><?= $page_url ?></div>
        <?php endif; ?>
        <div class="sh-page" id="lumina-page-nav" style="display:none;" data-next="<?= htmlspecialchars($lumina_next_page_url, ENT_QUOTES) ?>" data-page-base="<?= isset($pageurl) ? htmlspecialchars((string)$pageurl, ENT_QUOTES) : '' ?>" data-current-page="<?= isset($page) ? (int)$page : 1 ?>" data-total-pages="<?= isset($total_pages) ? (int)$total_pages : 1 ?>"><?= $page_url ?></div>
        <?php lumina_render_main_footer(); ?>
    </div>

    <?php if ($show_sidebar): ?>
        <?php if ($home_layout === 'triple'): ?>
            <aside class="lumina-aside lumina-aside-left">
                <?php lumina_render_sidebar_profile_card($profile_uid, $profile_avatar, $lumina_avatar, $profile_name, $sidebar_contact, $sidebar_stats); ?>
            </aside>
        <?php endif; ?>

        <aside class="lumina-aside lumina-aside-right">
            <?php if ($home_layout !== 'triple'): ?>
                <?php lumina_render_sidebar_profile_card($profile_uid, $profile_avatar, $lumina_avatar, $profile_name, $sidebar_contact, $sidebar_stats); ?>
            <?php endif; ?>

            <?php lumina_render_sidebar_extra_cards($sidebar_sorts, $sidebar_tags, $sidebar_copyright_html, $sidebar_icp); ?>

        </aside>
    <?php endif; ?>
    </div>

    <?php if (is_logged_in()): ?>
        <div class="sh-news" id="sh-news" onclick="gbnews()">
            <div class="sh-news-main" id="sh-news-main" onclick="hfljurl()">
                <div class="sh-news-main-top">
                    <div class="sh-news-main-top-xiaoxih"><span>消息盒子</span></div>
                    <div class="sh-news-main-top-div sh-news-main-top-div2" onclick="js_menu()">
                        <i class="iconfont icon-xialajiantouxiao ri-sxhqx"></i>
                        <div id="js_menu" class="sh-news-main-top-div-menu" style="display: none;">
                            <a href="JavaScript:;" onclick="xxscyd()" class="iconfont icon-icon-09 ri-sxhs">全部已读</a>
                            <a href="JavaScript:;" onclick="xxsczt()" class="iconfont icon-xuanze ri-sxhs" id="xxsczt" lang="0">选择消息</a>
                            <a href="JavaScript:;" onclick="xxscztSelected()" class="iconfont icon-shanchu ri-sxhs">删除所选</a>
                            <a href="JavaScript:;" onclick="xxscztqb()" class="iconfont icon-shanchu ri-sxhs">删除所有</a>
                        </div>
                    </div>
                    <div class="sh-news-main-top-div" onclick="gbnews()"><i class="iconfont icon-quxiao ri-sxhqx"></i></div>
                </div>
                <div class="sh-news-con" id="sh-news-con">
                    <?php if (empty($noticeItems)): ?>
                        <div class="lumina-empty">暂无消息</div>
                    <?php else: ?>
                        <?php $noticeIndex = 0; ?>
                        <?php foreach ($noticeItems as $item): ?>
                            <?php
                            $noticeIndex++;
                            $gid = isset($item['gid']) ? (int)$item['gid'] : 0;
                            $log = $gid > 0 ? $Log_Model->getOneLogForHome($gid, true, true) : null;
                            $cover = '';
                            $previewText = '';
                            if ($log && !empty($log['log_cover'])) {
                                $cover = $log['log_cover'];
                            } elseif ($log && !empty($log['log_content'])) {
                                $imgs = lumina_extract_images($log['log_content']);
                                if (!empty($imgs)) {
                                    $cover = $imgs[0];
                                }
                            }
                            if ($log && !empty($log['log_description'])) {
                                $previewText = lumina_clean_text($log['log_description']);
                            } elseif ($log && !empty($log['log_content'])) {
                                $previewText = lumina_clean_text($log['log_content']);
                            }
                            $coverHtml = $cover !== ''
                                ? '<img src="' . lumina_tpl_base() . 'assets/img/thumbnailbg.svg" data-src="' . htmlspecialchars($cover, ENT_QUOTES) . '" alt="动态封面">'
                                : '<div class="sh-xxliebwb"><span>' . htmlspecialchars($previewText) . '</span></div>';
                            $poster = isset($item['poster']) ? htmlspecialchars($item['poster']) : '一名游客';
                            if ($poster === '') {
                                $poster = '一名游客';
                            }
                            $rawDate = isset($item['date']) ? (int)$item['date'] : 0;
                            $dateText = $rawDate ? smartDate($rawDate) : '';
                            $type = isset($item['type']) ? $item['type'] : 'comment';
                            $title = isset($item['title']) ? htmlspecialchars($item['title']) : '';
                            $contentHtml = '';
                            if ($type === 'comment' && isset($item['comment'])) {
                                $contentHtml = lumina_parse_emoji(parseUBB(htmlClean($item['comment'])));
                            }
                            $avatar = $lumina_avatar;
                            if ($type === 'comment') {
                                $avatar = lumina_comment_avatar(isset($item['uid']) ? (int)$item['uid'] : 0, isset($item['mail']) ? $item['mail'] : '', $lumina_avatar);
                            } else {
                                if (!empty($item['avatar'])) {
                                    $avatar = lumina_resolve_avatar($item['avatar'], $lumina_avatar);
                                } elseif (!empty($item['uid'])) {
                                    $avatar = lumina_get_user_avatar((int)$item['uid'], $lumina_avatar);
                                } elseif (!empty($item['mail'])) {
                                    $avatar = lumina_comment_avatar(0, $item['mail'], $lumina_avatar);
                                }
                            }
                            $itemHref = $gid ? Url::log($gid) : '';
                            $titleText = $type === 'like' ? '新的点赞' : '新的评论';
                            $lineText = $type === 'like' ? '给你点了赞！' : '评论了你：';
                            $keySeed = $type . '|' . $gid . '|' . (isset($item['cid']) ? (int)$item['cid'] : (isset($item['id']) ? (int)$item['id'] : 0)) . '|' . $poster . '|' . $rawDate;
                            $noticeKey = isset($item['notice_key']) ? $item['notice_key'] : md5($keySeed);
                            if ($noticeKey && isset($noticeDeleted[$noticeKey])) {
                                continue;
                            }
                            ?>
                            <div class="sh-news-con-lie" id="xx-<?= $noticeIndex ?>" lang="<?= $itemHref ?>" data-href="<?= htmlspecialchars($itemHref, ENT_QUOTES) ?>" data-key="<?= $noticeKey ?>" onclick="mesgxq()">
                                <div class="sh-news-con-lie-left">
                                    <p id="xxztx-<?= $noticeIndex ?>" class="xiaoxhd"></p>
                                    <div class="sh-news-con-lie-left-imgt">
                                        <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= $avatar ?>" alt="头像" class="sh-news-con-lie-left-img">
                                    </div>
                                </div>
                                <div class="sh-news-con-lie-right">
                                    <p class="sh-news-con-lie-right-title" id="xxtzidtitle-<?= $noticeIndex ?>">
                                        <?= $poster ?>
                                        <span class="sh-news-con-lie-right-time"><?= $dateText ?></span>
                                    </p>
                                    <p class="sh-news-con-lie-right-text" id="xxtzidtext-<?= $noticeIndex ?>" lang="<?= $titleText ?>"><?= $lineText ?></p>
                                    <?php if ($contentHtml !== ''): ?>
                                        <p class="sh-news-con-lie-right-text"><?= $contentHtml ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="sh-xxliebfm"><?= $coverHtml ?><div class="delmes" id="del-<?= $noticeIndex ?>" onclick="demes()">删除</div></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="sh-news-tishi">
                    <P>共<span id="xxtzsul"><?= $noticeCount ?></span>条消息，未读<span id="xxtzwd"><?= $noticeCount ?></span>条</P>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($friendLinksEnabled): ?>
        <div class="sh-link" id="sh-link" onclick="gblink()">
            <div class="sh-link-main" id="sh-link-main" onclick="hfljurl()">
                <div class="sh-link-main-top">
                    <div class="sh-news-main-top-xiaoxih"><span><?= htmlspecialchars($friendLinks['title'], ENT_QUOTES) ?></span></div>
                    <div class="sh-news-main-top-div" onclick="gblink()"><i class="iconfont icon-quxiao ri-sxhqx"></i></div>
                </div>
                <div class="sh-link-con" id="sh-link-con">
                    <?php foreach ($friendLinks['items'] as $link): ?>
                        <a class="sh-link-con-lie" href="<?= htmlspecialchars($link['url'], ENT_QUOTES) ?>" target="_blank" rel="nofollow noopener noreferrer">
                            <span class="sh-link-con-lie-left">
                                <?php if ($link['avatar'] !== ''): ?>
                                    <img class="sh-link-con-lie-left-img" src="<?= htmlspecialchars($link['avatar'], ENT_QUOTES) ?>" alt="">
                                <?php else: ?>
                                    <i class="iconfont icon-lianjie1 lumina-link-noimg" aria-hidden="true"></i>
                                <?php endif; ?>
                            </span>
                            <span class="lumina-link-item-main">
                                <span class="sh-link-con-lie-right-title"><?= htmlspecialchars($link['name'], ENT_QUOTES) ?></span>
                                <?php if ($link['desc'] !== ''): ?>
                                    <span class="lumina-link-desc"><?= htmlspecialchars($link['desc'], ENT_QUOTES) ?></span>
                                <?php endif; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="sh-link-tishi">
                    <p>共 <?= (int)$friendLinks['total'] ?> 个链接</p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php if (is_logged_in()): ?>
    <script>
        window.LUMINA_NOTICE_TOKEN = '<?= $noticeToken ?>';
        window.LUMINA_NOTICE_URL = '<?= lumina_user_url() ?>';
    </script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
