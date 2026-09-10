<?php
// pafish: emlog guard removed
/*
Template Name: Lumina微光主题
Version: 1.2
Template Url: https://www.emlog.net/template/detail/1260
Description: 一款仿微信朋友圈的模板
Author: 属余
Author Url: https://www.emlog.net/author/index/858
*/
require_once __DIR__ . '/helpers.php';
if (
    isset($logid, $author, $fields) &&
    is_array($fields) &&
    lumina_is_private_log($fields) &&
    !lumina_can_view_private_log($author)
) {
    http_response_code(404); echo render("error", ["status" => 404, "message" => "内容不存在", "backUrl" => url_to("/")]); exit;
}

$lumina_favicon = lumina_resolve_url(lumina_opt('site_favicon', lumina_tpl_base() . 'assets/img/favicon.png'), lumina_tpl_base() . 'assets/img/favicon.png');
$lumina_favicon = lumina_versioned_url($lumina_favicon, defined('__LUMINA_DIR__') ? __LUMINA_DIR__ . 'assets/img/favicon.png' : '');
$lumina_favicon_path = parse_url($lumina_favicon, PHP_URL_PATH);
$lumina_favicon_ext = strtolower((string)pathinfo((string)$lumina_favicon_path, PATHINFO_EXTENSION));
$lumina_favicon_type = '';
if ($lumina_favicon_ext === 'png') {
    $lumina_favicon_type = 'image/png';
} elseif ($lumina_favicon_ext === 'svg') {
    $lumina_favicon_type = 'image/svg+xml';
} elseif ($lumina_favicon_ext === 'ico') {
    $lumina_favicon_type = 'image/x-icon';
}
$lumina_logo = trim((string)lumina_opt('site_logo', ''));
$lumina_logo = $lumina_logo !== '' ? lumina_resolve_url($lumina_logo, '') : '';
$lumina_iconfont = lumina_asset_url('assets/iconfont/iconfont.css');
$lumina_vam_icon_styles = array();
if (defined('dirname(__DIR__, 2)') && defined('lumina_blog_base()')) {
    $lumina_vam_icon_assets = array('remixicon.min.css', 'font-awesome.min.css');
    foreach ($lumina_vam_icon_assets as $lumina_vam_icon_asset) {
        $lumina_vam_icon_file = rtrim(dirname(__DIR__, 2), '/\\') . '/content/plugins/vaimi_options/assets/css/' . $lumina_vam_icon_asset;
        if (is_file($lumina_vam_icon_file)) {
            $lumina_vam_icon_styles[] = rtrim(lumina_blog_base(), '/') . '/content/plugins/vaimi_options/assets/css/' . $lumina_vam_icon_asset . '?v=' . filemtime($lumina_vam_icon_file);
        }
    }
}
$lumina_bg = lumina_opt('background_image', '');
$lumina_cover = lumina_resolve_url(lumina_opt('header_cover', lumina_tpl_base() . 'assets/img/homeimg.jpg'), lumina_tpl_base() . 'assets/img/homeimg.jpg');
$lumina_theme = lumina_opt('theme_color', '');
$lumina_custom_css = lumina_opt('custom_css', '');
$lumina_dark = lumina_opt('dark_mode', '');
$lumina_footer_switch = lumina_opt('footer_copyright_switch', 'y');
$lumina_ajax_nav_enabled = (lumina_opt('enable_ajax_nav', 'n') === 'y');
$lumina_ajax_progress_enabled = (lumina_opt('ajax_progress_enable', 'y') === 'y');
$lumina_top_bgm_enabled = (lumina_opt('top_bgm_enable', 'n') === 'y');
$lumina_top_bgm_url = trim((string)lumina_opt('top_bgm_url', ''));
$lumina_top_bgm_url = $lumina_top_bgm_url !== '' ? lumina_resolve_url($lumina_top_bgm_url, '') : '';
$lumina_top_bgm_autoplay = (lumina_opt('top_bgm_autoplay', 'n') === 'y');
if ($lumina_top_bgm_url === '') {
    $lumina_top_bgm_enabled = false;
}
if ($lumina_footer_switch === '') {
    $lumina_footer_switch = 'y';
}
$lumina_list_pagination = lumina_opt('list_pagination', 'n') === 'y';
$lumina_is_author_page = isset($_GET['author']) && is_numeric($_GET['author']);
$lumina_pc_layout = lumina_opt('home_layout_pc', 'single');
if ($lumina_pc_layout === 'triple') {
    $lumina_pc_layout = 'double';
}
if (!in_array($lumina_pc_layout, ['single', 'double'], true)) {
    $lumina_pc_layout = 'single';
}
if (isset($lumina_page_identity) && in_array($lumina_page_identity, ['user', 'article'], true)) {
    $lumina_pc_layout = 'single';
}
$lumina_footer_hidden = ($lumina_footer_switch === 'n');
$site_title = isset($site_title) ? $site_title : '';
$site_key = isset($site_key) ? $site_key : '';
$site_description = isset($site_description) ? $site_description : '';
if (class_exists('Option')) {
    if ($site_title === '') {
        $site_title = (string)site_name();
    }
    if ($site_key === '') {
        $site_key = (string)settings('site_keywords', '');
        if ($site_key === '') {
            $site_key = $site_title;
        }
    }
    if ($site_description === '') {
        $site_description = (string)settings('site_description', '');
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title><?= $site_title ?></title>
    <meta name="keywords" content="<?= $site_key ?>">
    <meta name="description" content="<?= $site_description ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <link rel="icon" href="<?= $lumina_favicon ?>"<?= $lumina_favicon_type !== '' ? ' type="' . $lumina_favicon_type . '"' : '' ?>>
    <link rel="icon" sizes="32x32" href="<?= $lumina_favicon ?>"<?= $lumina_favicon_type !== '' ? ' type="' . $lumina_favicon_type . '"' : '' ?>>
    <link rel="shortcut icon" href="<?= $lumina_favicon ?>"<?= $lumina_favicon_type !== '' ? ' type="' . $lumina_favicon_type . '"' : '' ?>>
    <link rel="apple-touch-icon" href="<?= $lumina_favicon ?>">
    <meta name="lumina-page-script" content="<?= isset($lumina_page_js) ? htmlspecialchars((string)$lumina_page_js, ENT_QUOTES) : 'common' ?>">
    <meta name="lumina-page-identity" content="<?= isset($lumina_page_identity) ? htmlspecialchars((string)$lumina_page_identity, ENT_QUOTES) : 'common' ?>">

    <?php foreach ($lumina_vam_icon_styles as $lumina_vam_icon_style) : ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($lumina_vam_icon_style, ENT_QUOTES) ?>">
    <?php endforeach; ?>
    <?php if ($lumina_iconfont !== '') : ?>
        <link rel="stylesheet" href="<?= $lumina_iconfont ?>">
    <?php endif; ?>

    <link rel="stylesheet" type="text/css" href="<?= lumina_asset_url('vendor/dplayer/DPlayer.min.css') ?>">
    <link rel="stylesheet" type="text/css" href="<?= lumina_asset_url('css/style.css') ?>">
    <link rel="stylesheet" type="text/css" href="<?= lumina_asset_url('css/jquery.fancybox.min.css') ?>">

    <?php do_action('index_head'); ?>

    <?php if ($lumina_theme !== '') : ?>
        <style>:root{--theme:<?= $lumina_theme ?>;}</style>
    <?php endif; ?>

    <?php if ($lumina_bg !== '') : ?>
        <style>body{background-image:url('<?= $lumina_bg ?>');}</style>
    <?php endif; ?>

    <?php if ($lumina_custom_css !== '') : ?>
        <style><?= $lumina_custom_css ?></style>
    <?php endif; ?>

    <script>
        window.LUMINA_BASE = "<?= lumina_blog_base() ?>";
        window.LUMINA = window.LUMINA || {};
        window.LUMINA.isLogin = <?= (!is_logged_in()) ? 'false' : 'true' ?>;
        window.LUMINA.allowGuest = <?= (settings('comments_require_login', 'false') === 'true' ? 'n' : 'y') === 'n' ? 'true' : 'false' ?>;
        window.LUMINA.userName = <?= (!is_logged_in()) ? "''" : json_encode(lumina_get_user_name((int)(current_user()['id'] ?? 0), blog_author((int)(current_user()['id'] ?? 0)))) ?>;
        window.LUMINA.paginationEnabled = <?= $lumina_list_pagination ? 'true' : 'false' ?>;
        window.LUMINA.ajaxNavEnabled = <?= $lumina_ajax_nav_enabled ? 'true' : 'false' ?>;
        window.LUMINA.ajaxProgressEnabled = <?= ($lumina_ajax_nav_enabled && $lumina_ajax_progress_enabled) ? 'true' : 'false' ?>;
        window.LUMINA.templateUrl = <?= json_encode(lumina_tpl_base()) ?>;
        window.LUMINA.pageScript = <?= isset($lumina_page_js) ? json_encode((string)$lumina_page_js) : "'common'" ?>;
        window.LUMINA.pageIdentity = <?= isset($lumina_page_identity) ? json_encode((string)$lumina_page_identity) : "'common'" ?>;
        window.LUMINA.topBgmEnabled = <?= $lumina_top_bgm_enabled ? 'true' : 'false' ?>;
        window.LUMINA_API = {
            like: "<?= lumina_blog_base() ?>index.php?action=addlike",
            unlike: "<?= lumina_blog_base() ?>index.php?action=unlike",
            guestUnlike: <?= json_encode(lumina_user_url()) ?>,
            token: <?= json_encode(csrf_token()) ?>,
            likeEnabled: <?= class_exists('Like_Model') ? 'true' : 'false' ?>
        };
        window.LUMINA_AUTH = {
            blogUrl: "<?= lumina_blog_base() ?>",
            loginCode: <?= 'n' === 'y' ? 'true' : 'false' ?>,
            emailCode: <?= 'n' === 'y' ? 'true' : 'false' ?>,
            allowSignup: <?= (settings('site_allow_register', 'true') !== 'false' ? 'y' : 'n') === 'y' ? 'true' : 'false' ?>
        };
    </script>
</head>
<body class="<?= trim(($lumina_dark === 'dark' ? 'dark-theme' : '') . ' ' . ($lumina_footer_hidden ? 'lumina-footer-off' : '') . ' ' . ($lumina_is_author_page ? 'lumina-author-page' : '') . ' lumina-pc-layout-' . $lumina_pc_layout) ?>">
