<?php
// pafish: emlog guard removed
$lumina_page_js = 'view';
require_once __DIR__ . '/module.php';
$fields = isset($fields) && is_array($fields) ? $fields : [];
$lumina_page_identity = 'detail';
if (lumina_is_private_log($fields) && !lumina_can_view_private_log($author)) {
    http_response_code(404); echo render("error", ["status" => 404, "message" => "内容不存在", "backUrl" => url_to("/")]); exit;
}
// pafish: header already loaded by get_header()
$Like_Model = new Like_Model();
$clientIp = getIp();
$allowRemark = isset($allow_remark) ? $allow_remark : 'y';
$lumina_cover = lumina_resolve_url(lumina_opt('header_cover', lumina_tpl_base() . 'assets/img/homeimg.jpg'), lumina_tpl_base() . 'assets/img/homeimg.jpg');
$allowGuest = (settings('comments_require_login', 'false') === 'true' ? 'n' : 'y') === 'n';
$needCaptcha = (!is_logged_in()) && (settings('comments_captcha_enabled', 'true') !== 'false' ? 'y' : 'n') === 'y';
$verifyCode = $needCaptcha ? '<img src="' . lumina_blog_base() . 'include/lib/checkcode.php" id="captcha" class="captcha" /><input name="imgcode" type="text" class="captcha_input" size="5" tabindex="5" />' : '';
$lumina_avatar = lumina_opt('default_avatar', lumina_tpl_base() . 'assets/img/tx.png');
if ($lumina_avatar === '') {
    $lumina_avatar = lumina_tpl_base() . 'assets/img/tx.png';
}
$lumina_avatar = lumina_resolve_url($lumina_avatar, lumina_tpl_base() . 'assets/img/tx.png');
$author_name = lumina_get_user_name($author, blog_author($author));
$author_avatar = lumina_get_user_avatar($author, $lumina_avatar);
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
$authorStats = null;
if ($show_sidebar && class_exists('Database')) {
    $db = \Pafish\Core\DB;
    $table_blog = DB_PREFIX . 'blog';
    $authorStats = $db->once_fetch_array("SELECT COUNT(*) AS total_logs, COALESCE(SUM(comnum),0) AS total_comments, COALESCE(SUM(like_count),0) AS total_likes FROM `$table_blog` WHERE author={$profile_uid} AND hide='n' AND checked='y'");
    if (is_array($authorStats)) {
        $sidebar_stats['logs'] = isset($authorStats['total_logs']) ? (int)$authorStats['total_logs'] : 0;
        $sidebar_stats['comments'] = isset($authorStats['total_comments']) ? (int)$authorStats['total_comments'] : 0;
        $sidebar_stats['likes'] = isset($authorStats['total_likes']) ? (int)$authorStats['total_likes'] : 0;
    }
}
$backUrl = lumina_blog_base();
if (!empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    if (strpos($ref, lumina_blog_base()) === 0) {
        $backUrl = $ref;
    }
}
$canManage = is_logged_in() && ((int)(current_user()['id'] ?? 0) == $author || (class_exists('User') && in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true)));
$canTop = is_logged_in() && class_exists('User') && (in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true) || User::isAdmin());
$token = csrf_token();
$topState = isset($top) && ($top === 'y' || $top === '1' || $top === 1) ? 'y' : 'n';
$isTop = $topState === 'y';
$hideState = isset($hide) ? $hide : 'n';
$privateState = lumina_is_private_log($fields) ? 'y' : 'n';
$isPrivate = $privateState === 'y';
$postActionUrl = lumina_user_url();
$hideUrl = lumina_blog_base() . 'admin/article.php?action=del&gid=' . $logid . '&token=' . $token;
$pubUrl = lumina_blog_base() . 'admin/article.php?action=pub&gid=' . $logid;
$delUrl = lumina_blog_base() . 'admin/article.php?action=del&gid=' . $logid . '&rm=1&token=' . $token;
?>
<div class="centent lumina-layout lumina-layout-single lumina-layout-pc-<?= $pc_layout ?>" data-lumina-pjax-container>
    <div class="lumina-layout-wrap">
    <div class="sh-main">
        <div class="sh-main-head">
            <div class="sh-main-head-top" id="sh-main-head-top">
                <div class="sh-main-head-top-left">
                    <div class="sh-main-head-top-left-s lumina-top-hit" data-lumina-back-url="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" onclick="return window.luminaNavigateBack ? window.luminaNavigateBack(this,event) : (location.href='<?= $backUrl ?>', false)">
                        <i class="iconfont icon-weibiaoti al-sxb lumina-top-icon" id="top-left-1"></i>
                    </div>
                    <div class="sh-view-head-top-left-s">
                        <span class="setup-view-title" id="setup-view-title">详情</span>
                    </div>
                </div>
                <?php if (!empty($lumina_logo)): ?>
                    <div class="sh-main-head-top-center">
                        <a href="<?= lumina_blog_base() ?>" class="lumina-site-logo-link" title="<?= htmlspecialchars($site_title, ENT_QUOTES) ?>">
                            <img class="lumina-site-logo" src="<?= $lumina_logo ?>" alt="<?= htmlspecialchars($site_title, ENT_QUOTES) ?>">
                        </a>
                    </div>
                <?php endif; ?>
                <div class="sh-main-head-top-right">
                    <?php if ($canManage): ?>
                        <div class="sh-main-head-top-right-s lumina-top-hit" onclick="viewsetk()">
                            <i class="iconfont icon-gengduo al-sxb lumina-top-icon" id="top-right-more" data-top-icon-role="more" data-icon-lock="1"></i>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sh-main-head-img" style="background-image:url(<?= $lumina_cover ?>)"></div>
        </div>

        <div class="sh-main-head-headimg">
            <div class="sh-main-head-headimg-tx">
                <h4><?= $author_name ?></h4>
                <a href="<?= lumina_profile_link($author) ?>">
                    <img src="<?= $author_avatar ?>" alt="avatar" onerror="this.onerror=null;this.src='<?= $lumina_avatar ?>'">
                </a>
            </div>
            <?php
            $author_info = lumina_get_user_info($author);
            $author_desc = '';
            if (!empty($author_info)) {
                if (!empty($author_info['description'])) {
                    $author_desc = $author_info['description'];
                } elseif (!empty($author_info['description_orig'])) {
                    $author_desc = $author_info['description_orig'];
                }
            }
            if ($author_desc === '') {
                $author_desc = $bloginfo;
            }
            ?>
            <?php if ($author_desc !== ''): ?>
                <div class="sh-main-head-headimg-qm">
                    <p><?= $author_desc ?></p>
                </div>
            <?php endif; ?>
        </div>

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
                            <div class="sh-pinglun-fs-right-fs" id="sh-pinglun-fs-right-fs" onclick="fasongv()">
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

        <?php
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
        $redpacketState = lumina_prepare_redpacket_state($logid, $fields);
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

        $content_images = lumina_extract_images($log_content);
        $content_videos = lumina_extract_videos($log_content);
        $should_render_media = true;
        $media_images = $content_images;
        $media_video = !empty($content_videos) ? $content_videos[0] : '';

        if ($lumina_type === 'only' || $lumina_type === 'text') {
            $lumina_type = '';
        }

        if ($lumina_type === 'live') {
            if (empty($media_images) && !empty($lumina_photos)) {
                $media_images = $lumina_photos;
            }
            if (empty($media_images) && !empty($log_cover)) {
                $media_images = [$log_cover];
            }
        } elseif ($lumina_type === 'img') {
            if (empty($media_images) && !empty($lumina_photos)) {
                $media_images = $lumina_photos;
            }
            if (empty($media_images) && !empty($log_cover)) {
                $media_images = [$log_cover];
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
            } elseif (!empty($log_cover)) {
                $media_type = 'img';
                $media_images = [$log_cover];
            } elseif ($media_music !== '') {
                $media_type = 'music';
            } elseif (!empty($linkCardState['url'])) {
                $media_type = 'link';
            }
        }

        $media_images = array_slice($media_images, 0, 20);
        $livePhotoMap = $lumina_type === 'live' ? lumina_parse_live_photo_map($lumina_live_photos_raw, $media_images) : [];
        $coun = count($media_images);
        if ($coun === 1) {
            $tusty = 'grid-template-columns:1fr;width:min(72%,360px);';
        } elseif ($coun === 2 || $coun === 4) {
            $tusty = 'grid-template-columns:1fr 1fr;width:min(72%,360px);';
        } else {
            $tusty = 'grid-template-columns:1fr 1fr 1fr;';
        }

        $likeList = $Like_Model->getList($logid);
        $liked = $Like_Model->isLiked($logid, (!is_logged_in()) ? 0 : (int)(current_user()['id'] ?? 0), $clientIp);
        $likeIcon = $liked ? 'iconfont icon-aixin2 ri-sxdzlikehs' : 'iconfont icon-aixin ri-sxdzlike';
        $likeText = $liked ? '取消' : '赞';
        $commentStacks = isset($comments['commentStacks']) ? $comments['commentStacks'] : [];
        $hasLikes = !empty($likeList);
        $hasComments = !empty($commentStacks);
        ?>

        <div class="sh-content" id="sh-content-<?= $logid ?>">
            <div class="sh-content-left">
                <a href="<?= lumina_profile_link($author) ?>">
                    <img src="<?= $author_avatar ?>" alt="avatar" onerror="this.onerror=null;this.src='<?= $lumina_avatar ?>'">
                </a>
            </div>
            <div class="sh-content-right">
                <div class="sh-content-right-head">
                    <div class="sh-content-right-head-title">
                        <p>
                            <a class="sh-author-link" href="<?= url_to('/author/' . rawurlencode((string)($author))) ?>"><?= $author_name ?></a>
                            <?= lumina_log_status_badges_html($isTop, $isPrivate) ?>
                        </p>
                        <div class="sh-content-right-head-title-ad" style="display:none;"><p>AD</p></div>
                    </div>
                    <?php
                    $detail_html = lumina_prepare_article_text_html($log_content, true, $media_type !== 'only');
                    $detail_plain = trim(preg_replace('/\s+/', '', strip_tags($detail_html)));
                    $detail_len = function_exists('mb_strlen') ? mb_strlen($detail_plain, 'UTF-8') : strlen($detail_plain);
                    ?>
                    <?php if ($detail_plain !== ''): ?>
                        <div class="sh-content-right-article">
                            <?php if ($detail_len > 100): ?>
                                <span class="wzndhycyc" id="sh-content-qwdid-<?= $logid ?>"><?= $detail_html ?></span>
                                <a href="JavaScript:;" class="sh-content-quanwenan" id="sh-content-quanwenan-<?= $logid ?>" lang="0" onclick="return quanwenan(this,event)">全文</a>
                            <?php else: ?>
                                <span><?= $detail_html ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($should_render_media && $media_type === 'img' && $coun > 0) : ?>
                        <div class="sh-content-right-img" id="imglib-<?= $logid ?>" style="<?= $tusty ?>">
                            <?php foreach ($media_images as $img) : ?>
                                <?php
                                $imgUrl = htmlspecialchars($img, ENT_QUOTES);
                                $liveVideo = lumina_live_photo_video($img, $livePhotoMap);
                                $liveVideoAttr = $liveVideo !== '' ? ' data-live-video="' . htmlspecialchars($liveVideo, ENT_QUOTES) . '"' : '';
                                $fancyboxAttr = $liveVideo === '' ? ' data-fancybox="gallery' . $logid . '"' : '';
                                $liveClass = $liveVideo !== '' ? ' is-live-photo' : '';
                                ?>
                                <a href="<?= $imgUrl ?>" class="sh-content-right-img-pic<?= $liveClass ?>"<?= $fancyboxAttr ?> data-caption=""<?= $liveVideoAttr ?>>
                                    <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= $imgUrl ?>" alt="">
                                    <?php if ($liveVideo !== ''): ?><span class="lumina-live-badge">LIVE</span><video class="lumina-live-video" src="<?= htmlspecialchars($liveVideo, ENT_QUOTES) ?>" muted playsinline webkit-playsinline preload="metadata" loop></video><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($should_render_media && $media_type === 'redpacket') : ?>
                        <?= lumina_render_redpacket_card($logid, $author_name, $redpacketState) ?>
                    <?php elseif ($should_render_media && $media_type === 'link' && !empty($linkCardState['url'])) : ?>
                        <?= lumina_render_link_card($linkCardState) ?>
                    <?php elseif ($should_render_media && $media_type === 'embed' && !empty($embedVideoState['src'])) : ?>
                        <?= lumina_render_embed_video($embedVideoState) ?>
                    <?php elseif ($should_render_media && $media_type === 'video' && $media_video) : ?>
                        <div class="sh-video">
                            <video class="sh-content-video" poster="<?= htmlspecialchars($lumina_video_poster) ?>" src="<?= htmlspecialchars($media_video, ENT_QUOTES) ?>" controls preload="metadata" playsinline></video>
                        </div>
                    <?php elseif ($should_render_media && $media_type === 'music' && $media_music) : ?>
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

                    <?php // log_related 插件挂载点（应用商店审核要求，供相关文章类插件使用）。
                    // 未安装此类插件时 doAction 无输出，不影响页面布局 ?>
                    <?php do_action("log_related", $logid); ?>
                </div>

                <?php if ($lumina_location !== '') : ?>
                    <div class="sh-content-right-gps"><a href="<?= htmlspecialchars($lumina_location_url, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer" title="<?= htmlspecialchars($lumina_location, ENT_QUOTES) ?>"><?= htmlspecialchars($lumina_location) ?></a></div>
                <?php endif; ?>

                <div class="sh-content-right-time">
                    <div class="sh-content-right-time-left"><span><?= smartDate($date) ?></span></div>
                    <div class="sh-content-right-time-right">
                        <div class="sh-content-right-time-right-left" id="pl-<?= $logid ?>" name="pl">
                            <div class="sh-content-right-time-right-left-z" onclick="dinazanv()">
                                <i class="<?= $likeIcon ?>" id="tiezimg-<?= $logid ?>"></i>
                                <span id="tiezdz-<?= $logid ?>"><?= $likeText ?></span>
                            </div>
                            <p></p>
                            <?php if ($allowRemark === 'y'): ?>
                                <div class="sh-content-right-time-right-left-y" id="<?= $logid ?>" onclick="plkkg()">
                                    <i class="iconfont icon-pinglun2 ri-sxdzcomm"></i>
                                    <span>评论</span>
                                </div>
                            <?php else: ?>
                                <div class="sh-content-right-time-right-left-y" id="<?= $logid ?>">
                                    <i class="iconfont icon-pinglun2 ri-sxdzcomm"></i>
                                    <span>评论关闭</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="sh-content-right-time-right-right" id="<?= $logid ?>" onclick="plk()">
                            <p class="zp1"></p>
                            <p></p>
                        </div>
                    </div>
                </div>

                <div class="sh-log-meta">
                    <div class="sh-log-tags"><?= blog_tag($logid) ?></div>
                </div>

                <div class="sh-zanp" id="zanss-<?= $logid ?>" style="<?= ($hasLikes || $hasComments) ? '' : 'display:none;' ?>">
                    <?php $guestCount = 0; ?>
                    <div class="sh-zanp-zan" id="zans-<?= $logid ?>" style="<?= $hasLikes ? '' : 'display:none;' ?>">
                        <div class="sh-zanp-zan-left"><i class="iconfont icon-aixin ri-sxwzlike"></i></div>
                        <ul class="sh-zanp-zan-right" id="zlbeh-<?= $logid ?>">
                            <?php if ($hasLikes): ?>
                                <?php foreach ($likeList as $like): ?>
                                    <?php if ((int)$like['uid'] === 0): ?>
                                        <?php $guestCount++; ?>
                                    <?php else: ?>
                                        <li data-name="<?= htmlspecialchars($like['poster']) ?>"><?= htmlspecialchars($like['poster']) ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if ($guestCount > 0): ?>
                                    <li id="fkzan-<?= $logid ?>"><?= $guestCount ?>位访客</li>
                                <?php endif; ?>
                            <?php endif; ?>
                          </ul>
                      </div>
                    <?php if ($hasComments): ?>
                        <?php lumina_render_comments_simple($comments, $logid, $allowRemark); ?>
                    <?php else: ?>
                        <ul class="sh-zanp-pl" id="sh-zanp-pl-<?= $logid ?>" style="display:none;"></ul>
                    <?php endif; ?>
                </div>

                <?php if ($allowRemark === 'y'): ?>
                    <!-- comment form handled by popup panel -->
                <?php endif; ?>
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
<?php if ($canManage): ?>
    <div class="sh-view-set" id="sh-view-set" data-back-url="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>">
        <div class="sh-view-set-wk" id="sh-view-set-wk" onclick="viewsetg()">
            <div class="sh-view-set-wk-con" id="sh-view-set-wk-con" onclick="event.stopPropagation()">
                <div class="sh-view-set-wk-con-title" style="margin-bottom: 1px;" onclick="location.href='<?= lumina_user_url('', ['edit' => $logid]) ?>'">
                    <span>编辑</span>
                </div>
                <div class="sh-view-set-wk-con-title" style="margin-bottom: 1px;" data-hide-state="<?= $hideState ?>" data-hide-url="<?= $hideUrl ?>" data-pub-url="<?= $pubUrl ?>" onclick="luminaTogglePrivate(this)">
                    <span><?= $hideState === 'y' ? '取消草稿' : '设为草稿' ?></span>
                </div>
                <?php if ($canTop): ?>
                    <div class="sh-view-set-wk-con-title" style="margin-bottom: 1px;" data-action-url="<?= htmlspecialchars($postActionUrl, ENT_QUOTES) ?>" data-blogid="<?= (int)$logid ?>" data-token="<?= htmlspecialchars($token, ENT_QUOTES) ?>" data-top-state="<?= $topState ?>" onclick="luminaToggleTop(this)">
                        <span><?= $topState === 'y' ? '取消置顶' : '设为置顶' ?></span>
                    </div>
                <?php endif; ?>
                <div class="sh-view-set-wk-con-title" style="margin-bottom: 1px;" data-action-url="<?= htmlspecialchars($postActionUrl, ENT_QUOTES) ?>" data-blogid="<?= (int)$logid ?>" data-token="<?= htmlspecialchars($token, ENT_QUOTES) ?>" data-private-state="<?= $privateState ?>" onclick="luminaToggleOnlyMe(this)">
                    <span><?= $privateState === 'y' ? '取消仅自己可看' : '仅自己可看' ?></span>
                </div>
                <div class="sh-view-set-wk-con-title" style="margin-bottom: 1px;" data-del-url="<?= $delUrl ?>" onclick="luminaDeleteLog(this)">
                    <span>删除</span>
                </div>
                <div class="sh-view-set-wk-con-title" style="margin-top: 5px;padding-bottom: 10px;" onclick="viewsetg()">
                    <span>取消</span>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
