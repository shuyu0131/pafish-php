<?php
$lumina_custom_js = lumina_opt('custom_js', '');
$lumina_footer_switch = lumina_opt('footer_copyright_switch', 'y');
if ($lumina_footer_switch === '') {
    $lumina_footer_switch = 'y';
}
$lumina_search_enabled = (lumina_opt('enable_search', 'y') === 'y');
$lumina_back_to_top_enabled = (lumina_opt('enable_back_to_top', 'y') === 'y');
$lumina_theme_toggle_enabled = (lumina_opt('enable_theme_toggle', 'n') === 'y');
$lumina_footer_ajax_nav_enabled = (lumina_opt('enable_ajax_nav', 'n') === 'y');
$lumina_footer_swup_enabled = false;
$lumina_custom_float_buttons = lumina_get_custom_float_buttons();
$lumina_float_hide_pages = lumina_opt_array('floating_hide_pages', []);
$lumina_current_page_identity = isset($lumina_page_identity) ? (string)$lumina_page_identity : 'common';
$lumina_float_hidden_here = in_array($lumina_current_page_identity, $lumina_float_hide_pages, true);
$lumina_theme_is_dark = (lumina_opt('dark_mode', '') === 'dark');
$lumina_footer_custom = trim((string)lumina_opt('footer_copyright_text', ''));
$lumina_footer_info = $lumina_footer_custom !== '' ? $lumina_footer_custom : (isset($footer_info) ? $footer_info : '');
if ($lumina_footer_info !== '') {
    $lumina_footer_info = preg_replace('/\\s*powered\\s*by\\s*emlog\\s*/i', ' ', $lumina_footer_info);
    $lumina_footer_info = trim($lumina_footer_info);
}
if ($lumina_footer_info === '') {
    $siteName = isset($blogname) ? trim((string)$blogname) : '';
    if ($siteName === '' && class_exists('Option')) {
        $siteName = trim((string)site_name());
    }
    if ($siteName !== '') {
        $lumina_footer_info = '© ' . date('Y') . ' ' . $siteName;
    } else {
        $lumina_footer_info = '© ' . date('Y');
    }
}
$icp_value = isset($icp) ? $icp : '';
if ($icp_value === '' && class_exists('Option')) {
    $icp_value = (string)settings('site_icp', '');
}
// .sh-footer 已由 lumina_render_main_footer() 在各模板的 .sh-main 内部输出，
// 此处不再重复渲染（避免重复与脱离主体卡片）
?>
    <?php if ($lumina_search_enabled): ?>
        <div class="so" id="lumina-search" aria-hidden="true">
            <div class="so-shell">
                <form class="sobd" id="lumina-search-form" method="get" action="<?= lumina_blog_base() ?>" role="search">
                    <div class="sobd-field">
                        <i class="iconfont icon-sousuoxiao sobd-field-icon" aria-hidden="true"></i>
                        <input class="sobd-in" id="lumina-search-input" type="search" name="keyword" placeholder="搜索文章、标签或关键词" value="<?= isset($_GET['keyword']) ? htmlspecialchars((string)$_GET['keyword'], ENT_QUOTES) : '' ?>" autocomplete="off" enterkeyhint="search" spellcheck="false">
                    </div>
                    <button class="sobd-bu" type="submit" aria-label="提交搜索">搜索</button>
                    <button class="sobd-close" type="button" data-action="search-close" aria-label="取消搜索" onclick="if(window.luminaCloseSearch){window.luminaCloseSearch();} return false;">
                        <span class="sobd-close-text">取消</span>
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <?php if (!$lumina_float_hidden_here && ($lumina_search_enabled || $lumina_back_to_top_enabled || $lumina_theme_toggle_enabled || !empty($lumina_custom_float_buttons))): ?>
        <div class="sh-menu is-visible" id="sh-menu" data-force="1">
            <?php foreach ($lumina_custom_float_buttons as $button): ?>
                <a class="sh-menu-k lumina-custom-float-btn" href="<?= htmlspecialchars($button['url'], ENT_QUOTES) ?>" target="<?= htmlspecialchars($button['target'], ENT_QUOTES) ?>"<?= $button['target'] === '_blank' ? ' rel="noopener noreferrer"' : '' ?> aria-label="<?= htmlspecialchars($button['title'], ENT_QUOTES) ?>">
                    <i class="<?= htmlspecialchars($button['icon'], ENT_QUOTES) ?>" aria-hidden="true"></i>
                </a>
            <?php endforeach; ?>
            <?php if ($lumina_search_enabled): ?>
                <button type="button" class="sh-menu-k lumina-menu-search" data-action="search" aria-label="搜索" onclick="if(window.luminaOpenSearch){window.luminaOpenSearch();} return false;">
                    <i class="iconfont icon-sousuoxiao" aria-hidden="true"></i>
                </button>
            <?php endif; ?>
            <?php if ($lumina_theme_toggle_enabled): ?>
                <button type="button" class="sh-menu-k" id="day" data-bind="theme" aria-label="日夜切换" onclick="if(window.luminaToggleTheme){window.luminaToggleTheme();} return false;">
                    <i id="day-i" class="iconfont <?= $lumina_theme_is_dark ? 'icon-yueliang' : 'icon-ai250' ?>" aria-hidden="true"></i>
                </button>
            <?php endif; ?>
            <?php if ($lumina_back_to_top_enabled): ?>
                <button type="button" class="sh-menu-k lumina-backtop-btn" data-action="backtop" aria-label="返回顶部">
                    <i class="iconfont icon-fanhuidingbu lumina-backtop-icon" aria-hidden="true"></i>
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="lumina-music-mini" id="lumina-music-mini" aria-hidden="true">
        <div class="lumina-music-mini-cover">
            <img id="lumina-music-mini-cover" src="<?= lumina_asset_url('img/musicba.jpg') ?>" data-default-src="<?= lumina_asset_url('img/musicba.jpg') ?>" alt="">
            <div class="lumina-music-mini-overlay">
                <button type="button" class="lumina-music-mini-btn" id="lumina-music-mini-toggle" aria-label="暂停播放">
                    <i class="iconfont icon-iconstop lumina-music-mini-icon-pause" aria-hidden="true"></i>
                    <i class="iconfont icon-sa4f56 lumina-music-mini-icon-play" aria-hidden="true"></i>
                </button>
                <button type="button" class="lumina-music-mini-btn" id="lumina-music-mini-close" aria-label="关闭音乐卡片">
                    <i class="iconfont icon-quxiao" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>

    <script src="<?= lumina_asset_url('js/jquery.min.js') ?>"></script>
    <script src="<?= lumina_asset_url('js/jquery.fancybox.min.js') ?>"></script>
    <script src="<?= lumina_asset_url('vendor/dplayer/DPlayer.min.js') ?>"></script>
    <?php if (isset($lumina_page_js) && $lumina_page_js === 'index') : ?>
        <script src="<?= lumina_asset_url('js/index.js') ?>"></script>
    <?php elseif (isset($lumina_page_js) && $lumina_page_js === 'home') : ?>
        <script src="<?= lumina_asset_url('js/index.js') ?>"></script>
        <script src="<?= lumina_asset_url('js/home.js') ?>"></script>
    <?php elseif (isset($lumina_page_js) && $lumina_page_js === 'view') : ?>
        <script src="<?= lumina_asset_url('js/index.js') ?>"></script>
        <script src="<?= lumina_asset_url('js/view.js') ?>"></script>
    <?php endif; ?>
    <?php if ($lumina_footer_ajax_nav_enabled && $lumina_footer_swup_enabled) : ?>
        <script src="<?= lumina_asset_url('js/swup.umd.js') ?>"></script>
    <?php endif; ?>
    <script src="<?= lumina_asset_url('js/theme.js') ?>"></script>

    <?php if ($lumina_custom_js !== '') : ?>
        <script><?= $lumina_custom_js ?></script>
    <?php endif; ?>

    <?php do_action('index_footer'); ?>
</body>
</html>
