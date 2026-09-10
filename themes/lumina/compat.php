<?php
// pafish: 由 scripts/port-lumina.php 生成，基于原版 module.php 1:1 移植，HTML/CSS/JS 一字不改
if (!defined('__LUMINA_DIR__')) define('__LUMINA_DIR__', __DIR__);
if (!function_exists('lumina_tpl_base')) { function lumina_tpl_base(): string { return url_to('/theme-assets/lumina/'); } }
if (!function_exists('lumina_blog_base')) { function lumina_blog_base(): string { return rtrim(url_to('/'), '/') . '/'; } }
if (!function_exists('lumina_user_avatar_of')) { function lumina_user_avatar_of(int $uid, string $email = ''): string { return \Pafish\Http\Comments::avatarUrl(null, $email); } }
if (!function_exists('lumina_opt')) {
function lumina_opt($key, $default = '') {
    if (function_exists('_vam')) {
        $val = theme_value($key, null);
        if ($val !== '' && $val !== null) {
            return $val;
        }
    }
    if (function_exists('_g')) {
        $val = theme_value($key);
        if ($val !== '' && $val !== null) {
            return $val;
        }
    }
    return $default;
}
}

if (!function_exists('lumina_opt_array')) {
function lumina_opt_array($key, $default = []) {
    $value = lumina_opt($key, $default);
    if (is_array($value)) {
        return array_values(array_filter(array_map('strval', $value), function ($item) {
            return $item !== '';
        }));
    }
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return is_array($default) ? $default : [];
        }
        if ($value[0] === '[') {
            $json = json_decode($value, true);
            if (is_array($json)) {
                return array_values(array_filter(array_map('strval', $json), function ($item) {
                    return $item !== '';
                }));
            }
        }
        $parts = preg_split('/[\s,|]+/', $value);
        if (is_array($parts)) {
            return array_values(array_filter(array_map('strval', $parts), function ($item) {
                return $item !== '';
            }));
        }
    }
    return is_array($default) ? $default : [];
}
}

if (!function_exists('lumina_tpl_url')) {
function lumina_tpl_url($path = '') {
    if (function_exists('lumina_tpl_base')) {
        $base = lumina_tpl_base();
    } else {
        $base = rtrim(lumina_blog_base(), '/') . '/content/templates/' . 'lumina' . '/';
    }
    return $base . ltrim($path, '/');
}
}

if (!function_exists('lumina_asset_url')) {
function lumina_asset_url($path) {
    $path = ltrim((string)$path, '/');
    $url = lumina_tpl_url($path);
    if (defined('__LUMINA_DIR__')) {
        $file = __LUMINA_DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($file)) {
            $glue = strpos($url, '?') === false ? '?' : '&';
            return $url . $glue . 'v=' . filemtime($file);
        }
    }
    return $url;
}
}

if (!function_exists('lumina_user_url')) {
function lumina_user_url($path = '', $query = []) {
    $url = rtrim(lumina_blog_base(), '/') . '/index.php/user';
    $path = trim((string)$path, '/');
    if ($path !== '') {
        $url .= '/' . $path;
    }
    if (is_array($query) && !empty($query)) {
        $query = http_build_query($query);
    }
    if (is_string($query)) {
        $query = ltrim($query, '?&');
        if ($query !== '') {
            $url .= '?' . $query;
        }
    }
    return $url;
}
}

if (!function_exists('lumina_profile_link')) {
function lumina_profile_link($uid) {
    $uid = (int)$uid;
    if ($uid > 0 && lumina_opt('profile_card_enable', 'n') === 'y') {
        return lumina_user_url('card/' . $uid);
    }
    if (class_exists('Url') && method_exists('Url', 'author')) {
        return url_to('/author/' . rawurlencode((string)($uid)));
    }
    return rtrim(lumina_blog_base(), '/') . '/';
}
}

if (!function_exists('lumina_location_url')) {
function lumina_location_url($location, $lat = '', $lng = '', $address = '') {
    $location = trim((string)$location);
    if ($location === '') {
        return 'javascript:;';
    }
    $lat = trim((string)$lat);
    $lng = trim((string)$lng);
    $address = trim((string)$address);
    if ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)) {
        $marker = 'coord:' . $lat . ',' . $lng . ';title:' . $location;
        if ($address !== '') {
            $marker .= ';addr:' . $address;
        }
        return 'https://apis.map.qq.com/uri/v1/marker?marker=' . rawurlencode($marker) . '&referer=lumina';
    }
    $region = '全国';
    if (preg_match('/([\x{4e00}-\x{9fa5}]{2,12}?(?:市|区|县|州|盟))/u', $location, $matches)) {
        $region = $matches[1];
    }
    return 'https://apis.map.qq.com/uri/v1/search?keyword=' . rawurlencode($location) . '&region=' . rawurlencode($region) . '&referer=lumina';
}
}

if (!function_exists('lumina_is_private_log')) {
function lumina_is_private_log($fields) {
    if (!is_array($fields)) {
        return false;
    }
    return isset($fields['lumina_private']) && trim((string)$fields['lumina_private']) === 'y';
}
}

if (!function_exists('lumina_can_view_private_log')) {
function lumina_can_view_private_log($author) {
    return is_logged_in() && (int)(current_user()['id'] ?? 0) === (int)$author;
}
}

if (!function_exists('lumina_can_manage_log_item')) {
function lumina_can_manage_log_item($log) {
    if (!true || !is_logged_in() || !is_array($log)) {
        return false;
    }
    $author = isset($log['author']) ? (int)$log['author'] : 0;
    return ((int)(current_user()['id'] ?? 0) === $author) || (class_exists('User') && in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true));
}
}

if (!function_exists('lumina_log_status_badges_html')) {
function lumina_log_status_badges_html($isTop = false, $isPrivate = false) {
    $html = '';
    if ($isTop) {
        $html .= '<span class="lumina-log-badge lumina-log-badge-top" title="置顶" aria-label="置顶">置顶</span>';
    }
    if ($isPrivate) {
        $html .= '<span class="lumina-log-badge lumina-log-badge-private" title="仅自己可看" aria-label="仅自己可看">私密</span>';
    }
    return $html;
}
}

if (!function_exists('lumina_first_scalar')) {
function lumina_first_scalar($value, $default = '') {
    if (is_array($value)) {
        foreach ($value as $item) {
            if (is_scalar($item) && (string)$item !== '') {
                return (string)$item;
            }
        }
        return $default;
    }
    return is_scalar($value) ? (string)$value : $default;
}
}

if (!function_exists('lumina_truthy_option')) {
function lumina_truthy_option($value) {
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int)$value === 1;
    }
    $value = strtolower(trim(lumina_first_scalar($value, '')));
    return in_array($value, array('1', 'y', 'yes', 'true', 'on', 'open'), true);
}
}

if (!function_exists('lumina_normalize_icon_class')) {
function lumina_normalize_icon_class($icon, $fallback = 'icon-lianjie1') {
    $icon = lumina_first_scalar($icon, '');
    $icon = trim(strip_tags((string)$icon));
    $icon = preg_replace('/[^a-zA-Z0-9_\\-\\s:]/', '', $icon);
    $icon = preg_replace('/\\s+/', ' ', $icon);
    if ($icon === '') {
        $icon = $fallback;
    }
    if (strpos($icon, ' ') !== false) {
        return $icon;
    }
    if (strpos($icon, 'icon-') === 0) {
        return 'iconfont ' . $icon;
    }
    if (strpos($icon, 'ri-') === 0 || strpos($icon, 'fa-') === 0 || strpos($icon, 'lucide-') === 0) {
        return $icon;
    }
    return 'iconfont ' . $icon;
}
}

if (!function_exists('lumina_safe_custom_url')) {
function lumina_safe_custom_url($url) {
    $url = lumina_first_scalar($url, '');
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    if (preg_match('/^\\s*(javascript|data|vbscript):/i', $url)) {
        return '';
    }
    if (strpos($url, '#') === 0 || strpos($url, '?') === 0 || strpos($url, '/') === 0) {
        return $url;
    }
    if (preg_match('#^(https?:)?//#i', $url) || preg_match('/^(mailto|tel):/i', $url)) {
        return $url;
    }
    return defined('lumina_blog_base()') ? rtrim(lumina_blog_base(), '/') . '/' . ltrim($url, '/') : $url;
}
}

if (!function_exists('lumina_parse_quick_nav_rows')) {
function lumina_parse_quick_nav_rows($raw) {
    $items = [];
    if (is_array($raw)) {
        $raw = implode("\n", array_map('strval', $raw));
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return $items;
    }
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/\s*[|,，]\s*/u', $line, 2);
        $label = isset($parts[0]) ? trim(strip_tags($parts[0])) : '';
        $url = isset($parts[1]) ? lumina_safe_custom_url($parts[1]) : '';
        if ($label === '' || $url === '') {
            continue;
        }
        $items[] = [
            'label' => htmlspecialchars($label, ENT_QUOTES),
            'url' => htmlspecialchars($url, ENT_QUOTES),
        ];
    }
    return $items;
}
}

if (!function_exists('lumina_get_quick_nav_items')) {
function lumina_get_quick_nav_items($fallbackSorts = []) {
    if (!lumina_truthy_option(lumina_opt('quick_nav_enable', 'n'))) {
        return [];
    }
    $customItems = lumina_parse_quick_nav_rows(lumina_opt('quick_nav_items', ''));
    if (!empty($customItems)) {
        return $customItems;
    }
    $items = [
        [
            'label' => '全部',
            'url' => htmlspecialchars(defined('lumina_blog_base()') ? lumina_blog_base() : '/', ENT_QUOTES),
        ],
    ];
    foreach ($fallbackSorts as $sort) {
        if (!is_array($sort) || empty($sort['name']) || empty($sort['url'])) {
            continue;
        }
        $items[] = [
            'label' => $sort['name'],
            'url' => htmlspecialchars($sort['url'], ENT_QUOTES),
        ];
        if (count($items) >= 8) {
            break;
        }
    }
    return count($items) > 1 ? $items : [];
}
}

if (!function_exists('lumina_quick_nav_current_url')) {
function lumina_quick_nav_current_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $request = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if ($host !== '') {
        return $scheme . '://' . $host . $request;
    }
    return $request;
}
}

if (!function_exists('lumina_quick_nav_url_active')) {
function lumina_quick_nav_url_active($url) {
    $url = html_entity_decode((string)$url, ENT_QUOTES, 'UTF-8');
    $currentRaw = lumina_quick_nav_current_url();
    if ($url === '' || $currentRaw === '') {
        return false;
    }
    $currentPath = parse_url($currentRaw, PHP_URL_PATH);
    $currentQuery = parse_url($currentRaw, PHP_URL_QUERY);
    $itemPath = parse_url($url, PHP_URL_PATH);
    $itemQuery = parse_url($url, PHP_URL_QUERY);
    if ($currentPath === null || $itemPath === null) {
        return false;
    }
    $currentPath = rtrim($currentPath, '/');
    $itemPath = rtrim($itemPath, '/');
    if ($currentPath === '') {
        $currentPath = '/';
    }
    if ($itemPath === '') {
        $itemPath = '/';
    }
    return $currentPath === $itemPath && (string)$currentQuery === (string)$itemQuery;
}
}

if (!function_exists('lumina_render_quick_nav')) {
function lumina_render_quick_nav($items, $isHomeList = false) {
    if (empty($items)) {
        return;
    }
    ?>
    <nav class="lumina-quick-nav" data-lumina-quick-nav="1" aria-label="快捷入口">
        <div class="lumina-quick-nav-scroll">
            <?php foreach ($items as $idx => $item): ?>
                <?php
                $label = isset($item['label']) ? $item['label'] : '';
                $url = isset($item['url']) ? $item['url'] : '';
                if ($label === '' || $url === '') {
                    continue;
                }
                $active = lumina_quick_nav_url_active($url) ? ' is-active' : '';
                ?>
                <a class="lumina-quick-nav-item<?= $active ?>" href="<?= $url ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </nav>
    <?php
}
}

if (!function_exists('lumina_parse_custom_float_button_rows')) {
function lumina_parse_custom_float_button_rows($raw) {
    $rows = [];
    if (is_array($raw)) {
        if (isset($raw['title']) || isset($raw['content'])) {
            $titles = isset($raw['title']) && is_array($raw['title']) ? array_values($raw['title']) : [];
            $contents = isset($raw['content']) && is_array($raw['content']) ? array_values($raw['content']) : [];
            $count = max(count($titles), count($contents));
            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'icon' => isset($titles[$i]) ? $titles[$i] : '',
                    'url' => isset($contents[$i]) ? $contents[$i] : '',
                ];
            }
        } else {
            foreach ($raw as $item) {
                if (is_array($item)) {
                    $rows[] = [
                        'icon' => isset($item['icon']) ? $item['icon'] : (isset($item['title']) ? $item['title'] : ''),
                        'url' => isset($item['link']) ? $item['link'] : (isset($item['url']) ? $item['url'] : (isset($item['content']) ? $item['content'] : '')),
                    ];
                }
            }
        }
    } elseif (is_string($raw) && trim($raw) !== '') {
        $lines = preg_split('/\\r\\n|\\r|\\n/', trim($raw));
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            $rows[] = [
                'icon' => isset($parts[0]) ? $parts[0] : '',
                'url' => isset($parts[1]) ? $parts[1] : '',
            ];
        }
    }
    return $rows;
}
}

if (!function_exists('lumina_get_custom_float_buttons')) {
function lumina_get_custom_float_buttons() {
    $buttons = [];
    if (lumina_truthy_option(lumina_opt('custom_float_enable', 'n'))) {
        $target = lumina_opt('custom_float_target', '_self');
        $target = $target === '_blank' ? '_blank' : '_self';
        $vam_items = function_exists('_vam') ? theme_value('custom_float_items', null) : null;
        $rows = is_array($vam_items) ? lumina_parse_custom_float_button_rows($vam_items) : [];
        if (empty($rows)) {
            $rows = lumina_parse_custom_float_button_rows(lumina_opt('custom_float_buttons', []));
        }
        foreach ($rows as $idx => $row) {
            $url = lumina_safe_custom_url(isset($row['url']) ? $row['url'] : '');
            if ($url === '') {
                continue;
            }
            $icon = isset($row['icon']) ? $row['icon'] : '';
            $buttons[] = [
                'url' => $url,
                'title' => '自定义悬浮按钮' . (count($buttons) + 1),
                'icon' => lumina_normalize_icon_class($icon, 'icon-lianjie1'),
                'target' => $target,
            ];
        }
        return $buttons;
    }

    for ($i = 1; $i <= 3; $i++) {
        if (lumina_opt('custom_float_' . $i . '_enable', 'n') !== 'y') {
            continue;
        }
        $url = lumina_safe_custom_url(lumina_opt('custom_float_' . $i . '_url', ''));
        if ($url === '') {
            continue;
        }
        $title = '自定义悬浮按钮' . $i;
        $target = lumina_opt('custom_float_' . $i . '_target', '_self');
        $target = $target === '_blank' ? '_blank' : '_self';
        $icon = lumina_opt('custom_float_' . $i . '_icon_custom', '');
        if (trim(lumina_first_scalar($icon, '')) === '') {
            $icon = lumina_opt('custom_float_' . $i . '_icon', 'icon-lianjie1');
        }
        $buttons[] = [
            'url' => $url,
            'title' => $title,
            'icon' => lumina_normalize_icon_class($icon),
            'target' => $target,
        ];
    }
    return $buttons;
}
}

if (!function_exists('lumina_get_friend_links')) {
function lumina_get_friend_links() {
    $title = trim(lumina_first_scalar(lumina_opt('friend_links_title', '友链'), '友链'));
    if ($title === '') {
        $title = '友链';
    }
    $out = [
        'enabled' => lumina_opt('friend_links_enable', 'n') === 'y',
        'title' => $title,
        'items' => [],
        'total' => 0,
    ];
    if (!$out['enabled']) {
        return $out;
    }

    $linkCache = [];
    global $CACHE;
    if (isset($CACHE) && is_object($CACHE) && method_exists($CACHE, 'readCache')) {
        $cached = $CACHE->readCache('link');
        if (is_array($cached)) {
            $linkCache = $cached;
        }
    } elseif (class_exists('Cache')) {
        $cache = method_exists('Cache', 'getInstance') ? null : new Cache();
        if ($cache && method_exists($cache, 'readCache')) {
            $cached = $cache->readCache('link');
            if (is_array($cached)) {
                $linkCache = $cached;
            }
        }
    }
    if (empty($linkCache)) {
        return $out;
    }

    foreach ($linkCache as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (isset($item['hide']) && (string)$item['hide'] === 'y') {
            continue;
        }
        $name = trim(lumina_first_scalar([
            isset($item['link']) ? $item['link'] : '',
            isset($item['sitename']) ? $item['sitename'] : '',
            isset($item['name']) ? $item['name'] : '',
            isset($item['title']) ? $item['title'] : '',
        ], ''));
        $url = lumina_first_scalar([
            isset($item['url']) ? $item['url'] : '',
            isset($item['siteurl']) ? $item['siteurl'] : '',
            isset($item['href']) ? $item['href'] : '',
        ], '');
        $url = lumina_safe_custom_url($url);
        if ($name === '' || $url === '') {
            continue;
        }
        $avatar = trim(lumina_first_scalar([
            isset($item['icon']) ? $item['icon'] : '',
            isset($item['logo']) ? $item['logo'] : '',
            isset($item['image']) ? $item['image'] : '',
            isset($item['img']) ? $item['img'] : '',
        ], ''));
        if ($avatar !== '') {
            $avatar = lumina_resolve_url($avatar, '');
        }
        $out['items'][] = [
            'name' => $name,
            'url' => $url,
            'avatar' => $avatar,
            'desc' => trim(lumina_first_scalar([
                isset($item['des']) ? $item['des'] : '',
                isset($item['description']) ? $item['description'] : '',
                isset($item['desc']) ? $item['desc'] : '',
            ], '')),
        ];
        $out['total']++;
    }
    return $out;
}
}

if (!function_exists('lumina_blog_date')) {
function lumina_blog_date($format, $timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    $timestamp = (int)$timestamp;
    if ($timestamp <= 0) {
        return '';
    }
    $timezone = class_exists('Option') ? Option::get('timezone') : null;
    if (is_numeric($timezone)) {
        return gmdate($format, $timestamp + ((int)$timezone * 3600));
    }
    return date($format, $timestamp);
}
}

if (!function_exists('lumina_parse_timestamp')) {
function lumina_parse_timestamp($value) {
    if ($value === null || $value === '') {
        return 0;
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    $time = strtotime($value);
    return $time === false ? 0 : (int)$time;
}
}

if (!function_exists('lumina_log_timestamp')) {
function lumina_log_timestamp($log) {
    if (!is_array($log)) {
        return lumina_parse_timestamp($log);
    }
    if (isset($log['timestamp']) && $log['timestamp'] !== '') {
        $timestamp = lumina_parse_timestamp($log['timestamp']);
        if ($timestamp > 0) {
            return $timestamp;
        }
    }
    return isset($log['date']) ? lumina_parse_timestamp($log['date']) : 0;
}
}

if (!function_exists('lumina_versioned_url')) {
function lumina_versioned_url($url, $localFile = '') {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    $localFile = trim((string)$localFile);
    if ($localFile !== '' && is_file($localFile)) {
        $glue = strpos($url, '?') === false ? '?' : '&';
        return $url . $glue . 'v=' . filemtime($localFile);
    }
    return $url;
}
}

if (!function_exists('lumina_resolve_url')) {
function lumina_resolve_url($url, $fallback = '') {
    $url = trim((string)$url);
    if ($url === '') {
        return $fallback;
    }
    if (strpos($url, 'images/') === 0) {
        return lumina_tpl_base() . 'assets/img/' . substr($url, strlen('images/'));
    }
    if (strpos($url, '/images/') !== false && strpos($url, '/assets/img/') === false) {
        return str_replace('/images/', '/assets/img/', $url);
    }
    if (preg_match('#^https?://#i', $url) || strpos($url, '//') === 0) {
        return $url;
    }
    if (strpos($url, '/') === 0) {
        return $url;
    }
    if (defined('__LUMINA_DIR__') && file_exists(__LUMINA_DIR__ . 'assets/img/' . $url)) {
        return lumina_tpl_base() . 'assets/img/' . $url;
    }
    if (strpos($url, 'content/') === 0 || strpos($url, 'assets/') === 0 || strpos($url, 'uploadfile/') === 0) {
        return lumina_blog_base() . $url;
    }
    return $url;
}
}

if (!function_exists('lumina_resolve_avatar')) {
function lumina_resolve_avatar($url, $fallback = '') {
    $url = trim((string)$url);
    if ($url === '' || $url === 'null') {
        return $fallback;
    }
    return lumina_resolve_url($url, $fallback);
}
}

if (!function_exists('lumina_clean_text')) {
function lumina_clean_text($text) {
    $text = lumina_strip_media_html($text);
    $text = preg_replace('/<(script|style|noscript|svg|canvas|form|button|object|embed)\b[^>]*>.*?<\/\1>/is', ' ', $text);
    $text = preg_replace('/<(input|select|textarea|iframe)\b[^>]*>/is', ' ', $text);
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}
}

if (!function_exists('lumina_format_comment_date')) {
function lumina_format_comment_date($raw) {
    if ($raw === null || $raw === '') {
        return '';
    }
    if (is_numeric($raw)) {
        return date('Y-m-d H:i', (int)$raw);
    }
    return htmlspecialchars((string)$raw);
}
}

if (!function_exists('lumina_strip_media_html')) {
function lumina_strip_media_html($html) {
    if (!$html) {
        return '';
    }
    $html = preg_replace_callback('/<img[^>]*>/i', function ($match) {
        $tag = $match[0];
        if (preg_match('/src\\s*=\\s*([\'"])(.*?)\\1/i', $tag, $m)) {
            $src = htmlspecialchars_decode($m[2], ENT_QUOTES);
            $srcLower = strtolower($src);
            if ($src !== '' && (strpos($srcLower, '/assets/owo/') !== false || strpos($srcLower, '/owo/paopao/') !== false)) {
                return $tag;
            }
        }
        return '';
    }, $html);
    $html = preg_replace('/<video[^>]*>.*?<\\/video>/is', '', $html);
    $html = preg_replace('/<audio[^>]*>.*?<\\/audio>/is', '', $html);
    $html = preg_replace('/<source[^>]*>/i', '', $html);
    $html = preg_replace('/<iframe[^>]*>.*?<\\/iframe>/is', '', $html);
    return $html;
}
}

if (!function_exists('lumina_sanitize_content_html')) {
function lumina_sanitize_content_html($html) {
    $html = (string)$html;
    if ($html === '') {
        return '';
    }
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = preg_replace('/<(script|style|noscript|svg|canvas|form|button|object|embed)\b[^>]*>.*?<\/\1>/is', '', $html);
    $html = preg_replace('/<(input|select|textarea|meta|link)\b[^>]*>/is', '', $html);
    $html = preg_replace('/\son\w+\s*=\s*(["\']).*?\1/is', '', $html);
    $html = preg_replace('/\sstyle\s*=\s*(["\']).*?\1/is', '', $html);
    $html = preg_replace('/\s(srcdoc|formaction)\s*=\s*(["\']).*?\2/is', '', $html);
    $html = preg_replace('/(href|src)\s*=\s*(["\'])\s*(javascript|vbscript):.*?\2/is', '$1="#"', $html);
    $html = strip_tags($html, '<p><br><a><img><video><audio><source><iframe><blockquote><pre><code><strong><b><em><i><u><s><ul><ol><li><h1><h2><h3><h4><h5><h6><table><thead><tbody><tr><th><td><hr><span><div>');
    return trim($html);
}
}

if (!function_exists('lumina_prepare_article_text_html')) {
function lumina_prepare_article_text_html($content, $allowHtml = true, $stripMedia = true) {
    if ($stripMedia) {
        $content = lumina_strip_media_html($content);
    }
    return lumina_render_markdown($content, $allowHtml);
}
}

if (!function_exists('lumina_prepare_sidebar_copyright')) {
function lumina_prepare_sidebar_copyright($raw) {
    $html = trim((string)$raw);
    if ($html === '') {
        return '';
    }
    $html = preg_replace('/\s*powered\s*by\s*emlog\s*/i', ' ', $html);
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $html = preg_replace('/<\/(p|div|li|h[1-6])\s*>/i', "\n", $html);
    $html = strip_tags($html, '<a><br><img><span><strong><em>');
    $html = preg_replace('/\son\w+\s*=\s*(["\']).*?\1/is', '', $html);
    $html = preg_replace('/\sstyle\s*=\s*(["\']).*?\1/is', '', $html);
    $html = preg_replace('/href\s*=\s*(["\'])\s*(javascript|vbscript):.*?\1/is', 'href="#"', $html);
    $html = preg_replace('/src\s*=\s*(["\'])\s*(javascript|vbscript):.*?\1/is', 'src=""', $html);
    $lines = preg_split('/\n+/', $html);
    $clean = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (trim(strip_tags($line)) === '' && stripos($line, '<img') === false) {
            continue;
        }
        $clean[] = $line;
    }
    return implode('<br>', $clean);
}
}

if (!function_exists('lumina_sidebar_copyright_source')) {
function lumina_sidebar_copyright_source($footerInfo = '') {
    $sidebarCopyright = trim((string)lumina_opt('sidebar_copyright', ''));
    if ($sidebarCopyright !== '') {
        return $sidebarCopyright;
    }
    return (string)$footerInfo;
}
}

if (!function_exists('lumina_render_emoji_panel')) {
function lumina_render_emoji_panel() {
    $emojiDir = defined('__LUMINA_DIR__') ? __LUMINA_DIR__ . 'assets/owo/paopao/' : '';
    if (!$emojiDir || !is_dir($emojiDir)) {
        return;
    }
    $emojiList = glob($emojiDir . '*.png');
    if (empty($emojiList)) {
        return;
    }
    foreach ($emojiList as $emojiFile) {
        $emojiName = basename($emojiFile);
        $base = preg_replace('/_2x\\.png$/i', '', $emojiName);
        $alt = $base;
        if (preg_match('/^[0-9A-Fa-f]+$/', $base)) {
            $hex = strtoupper($base);
            $bytes = [];
            for ($i = 0; $i < strlen($hex); $i += 2) {
                $bytes[] = '%' . substr($hex, $i, 2);
            }
            $alt = urldecode(implode('', $bytes));
        }
        $alt = '::(' . $alt . ')';
        $emojiUrl = lumina_tpl_base() . 'assets/owo/paopao/' . $emojiName;
        echo '<img data-emoji-src="' . $emojiUrl . '" src="' . $emojiUrl . '" alt="' . htmlspecialchars($alt) . '" onclick="biaoqzj()">';
    }
}
}

if (!function_exists('lumina_parse_emoji')) {
function lumina_parse_emoji($content) {
    if ($content === '' || $content === null) {
        return $content;
    }
    static $emojiMap = null;
    if ($emojiMap === null) {
        $emojiMap = [];
        $emojiDir = defined('__LUMINA_DIR__') ? __LUMINA_DIR__ . 'assets/owo/paopao/' : '';
        if ($emojiDir && is_dir($emojiDir)) {
            $emojiList = glob($emojiDir . '*.png');
            if (!empty($emojiList)) {
                foreach ($emojiList as $emojiFile) {
                    $emojiName = basename($emojiFile);
                    $base = preg_replace('/_2x\\.png$/i', '', $emojiName);
                    if ($base === '') {
                        continue;
                    }
                    $alt = $base;
                    if (preg_match('/^[0-9A-Fa-f]+$/', $base)) {
                        $hex = strtoupper($base);
                        $bytes = [];
                        for ($i = 0; $i < strlen($hex); $i += 2) {
                            $bytes[] = '%' . substr($hex, $i, 2);
                        }
                        $alt = urldecode(implode('', $bytes));
                    }
                    $token = '::(' . $alt . ')';
                    $emojiUrl = lumina_tpl_base() . 'assets/owo/paopao/' . $emojiName;
                    $emojiMap[$token] = '<img class="lumina-emoji" src="' . $emojiUrl . '" alt="' . htmlspecialchars($alt) . '">';
                }
            }
        }
    }
    if (empty($emojiMap)) {
        return $content;
    }
    return str_replace(array_keys($emojiMap), array_values($emojiMap), $content);
}
}

if (!function_exists('lumina_prepare_redpacket_state')) {
    function lumina_prepare_redpacket_state($gid, $fields = []) {
        $fields = is_array($fields) ? $fields : [];
        $state = [
            'title' => isset($fields['lumina_redpacket_title']) ? trim((string)$fields['lumina_redpacket_title']) : '',
            'mode' => isset($fields['lumina_redpacket_mode']) ? trim((string)$fields['lumina_redpacket_mode']) : '',
            'total' => isset($fields['lumina_redpacket_total']) ? (int)$fields['lumina_redpacket_total'] : 0,
            'count' => isset($fields['lumina_redpacket_count']) ? (int)$fields['lumina_redpacket_count'] : 0,
            'remain' => 0,
            'remain_count' => 0,
            'status' => 0,
            'claimed' => [],
            'claimed_amount' => 0,
            'raw' => [],
        ];

        $redpacket = lumina_redpacket_get((int)$gid);
        if (!empty($redpacket)) {
            $state['raw'] = $redpacket;
            $state['mode'] = $redpacket['mode'] === 'equal' ? 'equal' : 'random';
            $state['total'] = (int)$redpacket['total_credits'];
            $state['remain'] = (int)$redpacket['remain_credits'];
            $state['count'] = (int)$redpacket['total_count'];
            $state['remain_count'] = (int)$redpacket['remain_count'];
            $state['status'] = (int)$redpacket['status'];
            if (is_logged_in()) {
                $claim = lumina_redpacket_get_claim((int)$redpacket['id'], (int)(current_user()['id'] ?? 0));
                if (!empty($claim) && isset($claim['credits'])) {
                    $state['claimed'] = $claim;
                    $state['claimed_amount'] = (int)$claim['credits'];
                }
            }
        }

        return $state;
    }
}

if (!function_exists('lumina_prepare_link_card_state')) {
    function lumina_prepare_link_card_state($fields = []) {
        $fields = is_array($fields) ? $fields : [];
        $url = isset($fields['lumina_link_url']) ? trim((string)$fields['lumina_link_url']) : '';
        $url = lumina_normalize_external_link_url($url);
        $url = lumina_safe_custom_url($url);
        if ($url === '') {
            return [
                'url' => '',
                'title' => '',
                'desc' => '',
                'image' => '',
                'host' => '',
            ];
        }

        $hostUrl = $url;
        if (strpos($hostUrl, '//') === 0) {
            $hostUrl = 'https:' . $hostUrl;
        } elseif (strpos($hostUrl, '/') === 0 && defined('lumina_blog_base()')) {
            $hostUrl = rtrim(lumina_blog_base(), '/') . $hostUrl;
        }
        $host = parse_url($hostUrl, PHP_URL_HOST);
        if (!$host && defined('lumina_blog_base()')) {
            $host = parse_url(lumina_blog_base(), PHP_URL_HOST);
        }
        $host = $host ? preg_replace('/^www\\./i', '', (string)$host) : '';

        $title = isset($fields['lumina_link_title']) ? trim(strip_tags((string)$fields['lumina_link_title'])) : '';
        $desc = isset($fields['lumina_link_desc']) ? trim(strip_tags((string)$fields['lumina_link_desc'])) : '';
        $image = isset($fields['lumina_link_image']) ? trim((string)$fields['lumina_link_image']) : '';
        if ($image !== '' && preg_match('/^\\s*(javascript|vbscript):/i', $image)) {
            $image = '';
        }
        $image = $image !== '' ? lumina_resolve_url($image, '') : '';
        if ($title === '') {
            $title = $host !== '' ? $host : '链接';
        }
        if ($desc === '') {
            $desc = $host;
        }

        return [
            'url' => $url,
            'title' => $title,
            'desc' => $desc,
            'image' => $image,
            'host' => $host,
        ];
    }
}

if (!function_exists('lumina_normalize_external_link_url')) {
    function lumina_normalize_external_link_url($url) {
        $url = trim((string)$url);
        if ($url !== '' && !preg_match('#^(https?:)?//#i', $url) && !preg_match('/^(mailto|tel):/i', $url) && strpos($url, '/') !== 0 && strpos($url, '#') !== 0 && strpos($url, '?') !== 0 && preg_match('/^[a-z0-9][a-z0-9.-]*\\.[a-z]{2,}(\\/.*)?$/i', $url)) {
            $url = 'https://' . $url;
        }
        return $url;
    }
}

if (!function_exists('lumina_link_preview_ip_allowed')) {
    function lumina_link_preview_ip_allowed($ip) {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        return false;
    }
}

if (!function_exists('lumina_link_preview_url_allowed')) {
    function lumina_link_preview_url_allowed($url) {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $host = trim((string)$parts['host']);
        $hostLower = strtolower($host);
        if ($hostLower === 'localhost' || $hostLower === '127.0.0.1' || $hostLower === '::1' || substr($hostLower, -6) === '.local') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return lumina_link_preview_ip_allowed($host);
        }
        if (function_exists('gethostbynamel')) {
            $ips = @gethostbynamel($host);
            if (empty($ips) || !is_array($ips)) {
                return false;
            }
            foreach ($ips as $ip) {
                if (!lumina_link_preview_ip_allowed($ip)) {
                    return false;
                }
            }
        }
        return true;
    }
}

if (!function_exists('lumina_link_preview_absolute_url')) {
    function lumina_link_preview_absolute_url($url, $baseUrl) {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (strpos($url, '//') === 0) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
            return ($scheme ? $scheme : 'https') . ':' . $url;
        }
        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            return $url;
        }
        $root = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (strpos($url, '/') === 0) {
            return $root . $url;
        }
        $path = isset($base['path']) ? $base['path'] : '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path);
        return $root . $dir . $url;
    }
}

if (!function_exists('lumina_link_preview_fetch_html')) {
    function lumina_link_preview_fetch_html($url, $redirects = 0) {
        if ($redirects > 2 || !lumina_link_preview_url_allowed($url)) {
            return false;
        }
        $limit = 524288;
        $body = '';
        $status = 0;
        $location = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_USERAGENT, 'LuminaLinkPreview/1.0');
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body, $limit) {
                $body .= $chunk;
                return strlen($body) > $limit ? 0 : strlen($chunk);
            });
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$location) {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return strlen($header);
            });
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } elseif (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 6,
                    'ignore_errors' => true,
                    'max_redirects' => 0,
                    'header' => "User-Agent: LuminaLinkPreview/1.0\r\nRange: bytes=0-" . ($limit - 1) . "\r\n",
                ],
            ]);
            $raw = @file_get_contents($url, false, $context, 0, $limit);
            $body = $raw === false ? '' : (string)$raw;
            $respHeaders = http_get_last_response_headers(); if (is_array($respHeaders)) {
                foreach ($respHeaders as $header) {
                    if (preg_match('/^HTTP\\/\\S+\\s+(\\d+)/i', $header, $m)) {
                        $status = (int)$m[1];
                    } elseif (stripos($header, 'Location:') === 0) {
                        $location = trim(substr($header, 9));
                    }
                }
            }
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            $nextUrl = lumina_link_preview_absolute_url($location, $url);
            return lumina_link_preview_fetch_html($nextUrl, $redirects + 1);
        }
        if ($status >= 400 || trim($body) === '') {
            return false;
        }
        return $body;
    }
}

if (!function_exists('lumina_link_preview_meta_value')) {
    function lumina_link_preview_meta_value($html, $names) {
        foreach ((array)$names as $name) {
            $quoted = preg_quote($name, '/');
            $value = '';
            if (preg_match('/<meta\\s+[^>]*(?:property|name)\\s*=\\s*([\'"])' . $quoted . '\\1[^>]*content\\s*=\\s*([\'"])(.*?)\\2[^>]*>/is', $html, $m)) {
                $value = isset($m[3]) ? $m[3] : '';
            } elseif (preg_match('/<meta\\s+[^>]*content\\s*=\\s*([\'"])(.*?)\\1[^>]*(?:property|name)\\s*=\\s*([\'"])' . $quoted . '\\3[^>]*>/is', $html, $m)) {
                $value = isset($m[2]) ? $m[2] : '';
            }
            if ($value !== '') {
                $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8'));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }
}

if (!function_exists('lumina_fetch_link_preview')) {
    function lumina_fetch_link_preview($rawUrl) {
        $url = lumina_normalize_external_link_url($rawUrl);
        if (!preg_match('#^https?://#i', $url) || !lumina_link_preview_url_allowed($url)) {
            return ['ok' => false, 'msg' => '链接地址不可抓取'];
        }
        $html = lumina_link_preview_fetch_html($url);
        if ($html === false) {
            return ['ok' => false, 'msg' => '链接信息获取失败'];
        }
        $title = lumina_link_preview_meta_value($html, ['og:title', 'twitter:title']);
        if ($title === '' && preg_match('/<title[^>]*>(.*?)<\\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        }
        $desc = lumina_link_preview_meta_value($html, ['og:description', 'twitter:description', 'description']);
        $image = lumina_link_preview_meta_value($html, ['og:image', 'og:image:url', 'twitter:image', 'twitter:image:src']);
        $image = $image !== '' ? lumina_link_preview_absolute_url($image, $url) : '';
        $host = parse_url($url, PHP_URL_HOST);
        $host = $host ? preg_replace('/^www\\./i', '', (string)$host) : '';
        if ($title === '') {
            $title = $host !== '' ? $host : '链接';
        }
        if ($desc === '') {
            $desc = $host;
        }
        if (function_exists('mb_substr')) {
            $title = mb_substr($title, 0, 80);
            $desc = mb_substr($desc, 0, 140);
        } else {
            $title = substr($title, 0, 80);
            $desc = substr($desc, 0, 140);
        }
        return [
            'ok' => true,
            'url' => $url,
            'title' => $title,
            'desc' => $desc,
            'image' => $image,
            'host' => $host,
        ];
    }
}

if (!function_exists('lumina_render_link_card')) {
    function lumina_render_link_card($state, $compact = false, $wholeLink = true, $showAction = true) {
        $state = is_array($state) ? $state : [];
        $url = isset($state['url']) ? trim((string)$state['url']) : '';
        if ($url === '') {
            return '';
        }
        $title = isset($state['title']) && $state['title'] !== '' ? $state['title'] : '链接';
        $desc = isset($state['desc']) ? trim((string)$state['desc']) : '';
        $image = isset($state['image']) ? trim((string)$state['image']) : '';
        $host = isset($state['host']) ? trim((string)$state['host']) : '';
        $meta = $desc !== '' ? $desc : $host;
        $class = 'lumina-link-card' . ($compact ? ' lumina-link-card-compact' : '') . ($wholeLink ? '' : ' lumina-link-card-static');

        ob_start();
        ?>
        <<?= $wholeLink ? 'a' : 'span' ?> class="<?= $class ?>"<?= $wholeLink ? ' href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation();"' : '' ?>>
            <span class="lumina-link-card-main">
                <span class="lumina-link-card-title"><?= htmlspecialchars($title) ?></span>
                <?php if ($meta !== ''): ?>
                    <span class="lumina-link-card-desc"><?= htmlspecialchars($meta) ?></span>
                <?php endif; ?>
            </span>
            <span class="lumina-link-card-thumb">
                <?php if ($image !== ''): ?>
                    <img src="<?= lumina_tpl_base() ?>assets/img/thumbnail.svg" data-src="<?= htmlspecialchars($image, ENT_QUOTES) ?>" alt="">
                <?php else: ?>
                    <i class="iconfont icon-lianjie1" aria-hidden="true"></i>
                <?php endif; ?>
            </span>
            <?php if (!$wholeLink && $showAction): ?>
                <a class="lumina-link-card-action" href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation();" aria-label="打开链接">
                    <i class="iconfont icon-lianjie1" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </<?= $wholeLink ? 'a' : 'span' ?>>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('lumina_embed_allowed_host')) {
    function lumina_embed_allowed_host($host) {
        $host = strtolower(trim((string)$host));
        if ($host === '') {
            return false;
        }
        $allowed = [
            'player.bilibili.com',
            'www.bilibili.com',
            'live.bilibili.com',
            'bilibili.com',
            'open.douyin.com',
            'v.qq.com',
            'player.youku.com',
            'www.youtube.com',
            'youtube.com',
            'www.youtube-nocookie.com',
        ];
        return in_array($host, $allowed, true);
    }
}

if (!function_exists('lumina_normalize_embed_src')) {
    function lumina_normalize_embed_src($src) {
        $src = trim(html_entity_decode((string)$src, ENT_QUOTES, 'UTF-8'));
        if ($src === '' || preg_match('/^\\s*(javascript|data|vbscript):/i', $src)) {
            return '';
        }
        if (strpos($src, '//') === 0) {
            $src = 'https:' . $src;
        }
        if (!preg_match('#^https?://#i', $src)) {
            return '';
        }
        $host = parse_url($src, PHP_URL_HOST);
        if (!lumina_embed_allowed_host($host)) {
            return '';
        }
        return $src;
    }
}

if (!function_exists('lumina_normalize_bilibili_player_src')) {
    function lumina_normalize_bilibili_player_src($src) {
        $src = trim((string)$src);
        $parts = parse_url($src);
        if (!is_array($parts)) {
            return $src;
        }
        $host = isset($parts['host']) ? strtolower(preg_replace('/^www\./i', '', (string)$parts['host'])) : '';
        $path = isset($parts['path']) ? strtolower((string)$parts['path']) : '';
        // 统一收敛到 html5mobileplayer 端点：实测该端点在第三方站点可正常页内播放
        // （点击封面播放、控制栏完整、不跳转详情页），player.html 端点在非白名单站点会降级跳转
        $isBiliPlayer = ($host === 'player.bilibili.com' && substr($path, -12) === '/player.html') ||
            ($host === 'bilibili.com' && strpos($path, '/blackboard/html5mobileplayer.html') !== false);
        if (!$isBiliPlayer) {
            return $src;
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        // 注意：html5mobileplayer 端点的 autoplay 参数是「存在即强制静音自动播放」（0/1 均无效），
        // 因此这里必须完全省略 autoplay 参数，而不是设 autoplay=0 —— 省略时端点才会停驻封面不自动播放
        $playerQuery = [
            'high_quality' => 1,
            'danmaku' => 0,
        ];
        foreach (['bvid', 'aid', 'cid', 'page', 'p', 't'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $playerQuery[$key] = $query[$key];
            }
        }
        if (isset($playerQuery['p']) && !isset($playerQuery['page'])) {
            $playerQuery['page'] = $playerQuery['p'];
        }
        unset($playerQuery['p']);
        return 'https://www.bilibili.com/blackboard/html5mobileplayer.html?' . http_build_query($playerQuery, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('lumina_expand_embed_short_url')) {
    function lumina_expand_embed_short_url($url) {
        $original = trim((string)$url);
        $current = $original;
        for ($i = 0; $i < 3; $i++) {
            $parts = parse_url($current);
            $host = is_array($parts) && !empty($parts['host']) ? strtolower((string)$parts['host']) : '';
            if (!in_array($host, ['v.douyin.com', 'v.ixigua.com'], true)) {
                return $current;
            }

            $location = '';
            if (function_exists('curl_init')) {
                $ch = curl_init($current);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_HEADER, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($ch, CURLOPT_TIMEOUT, 6);
                curl_setopt($ch, CURLOPT_USERAGENT, 'LuminaEmbed/1.0');
                $headers = curl_exec($ch);
                curl_close($ch);
                if (is_string($headers) && preg_match('/^Location:\s*(.+)$/im', $headers, $m)) {
                    $location = trim($m[1]);
                }
            } elseif (ini_get('allow_url_fopen')) {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'HEAD',
                        'timeout' => 6,
                        'ignore_errors' => true,
                        'max_redirects' => 0,
                        'header' => "User-Agent: LuminaEmbed/1.0\r\n",
                    ],
                ]);
                @file_get_contents($current, false, $context);
                $respHeaders = http_get_last_response_headers(); if (is_array($respHeaders)) {
                    foreach ($respHeaders as $header) {
                        if (stripos($header, 'Location:') === 0) {
                            $location = trim(substr($header, 9));
                            break;
                        }
                    }
                }
            }
            if ($location === '') {
                return $original;
            }

            if (strpos($location, '//') === 0) {
                $location = 'https:' . $location;
            } elseif (!preg_match('#^https?://#i', $location)) {
                $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
                $location = $scheme . '://' . $host . (strpos($location, '/') === 0 ? '' : '/') . $location;
            }
            $current = $location;
        }
        return $current;
    }
}

if (!function_exists('lumina_embed_cover_normalize')) {
    /**
     * 规范化封面 URL：http 升级为 https，避免在 https 站点触发混合内容拦截导致封面加载失败。
     * B站/腾讯/优酷/抖音的 CDN 均支持 https。
     */
    function lumina_embed_cover_normalize($url) {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        if (stripos($url, 'http://') === 0) {
            $url = 'https://' . substr($url, 7);
        }
        return $url;
    }
}

if (!function_exists('lumina_prepare_embed_video_state')) {
    function lumina_prepare_embed_video_state($fields = []) {
        $fields = is_array($fields) ? $fields : [];
        $raw = isset($fields['lumina_embed_url']) ? trim((string)$fields['lumina_embed_url']) : '';
        $cover = isset($fields['lumina_embed_cover']) ? trim((string)$fields['lumina_embed_cover']) : '';
        if ($cover !== '') {
            $cover = lumina_resolve_url($cover, '');
            // 渲染兜底：手动填写的封面可能是 http://，在 https 站点会触发混合内容拦截，
            // 统一升级为 https（各平台 CDN 均支持 https）。
            $cover = lumina_embed_cover_normalize($cover);
        }
        if ($raw === '') {
            return [
                'url' => '',
                'src' => '',
                'platform' => '',
                'label' => '',
                'ratio' => 'lr',
                'cover' => $cover,
            ];
        }

        $ratio = isset($fields['lumina_embed_ratio']) ? trim((string)$fields['lumina_embed_ratio']) : 'lr';
        $ratio = $ratio === 'tb' ? 'tb' : 'lr';
        $platform = '';
        $label = '';
        $src = '';
        $url = trim(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'));
        if (stripos($url, '<iframe') === false) {
            $url = lumina_expand_embed_short_url($url);
        }

        if (preg_match('/<iframe[^>]+src=["\\\']([^"\\\']+)["\\\']/i', $url, $m)) {
            $src = lumina_normalize_embed_src($m[1]);
            if ($src !== '') {
                $host = parse_url($src, PHP_URL_HOST);
                $platform = $host ? preg_replace('/^www\\./i', '', strtolower((string)$host)) : 'iframe';
                if ($platform === 'open.douyin.com') {
                    $platform = 'douyin';
                }
                if ($platform === 'player.bilibili.com' || $platform === 'bilibili.com') {
                    $src = lumina_normalize_bilibili_player_src($src);
                }
                $label = $platform === 'player.bilibili.com' || $platform === 'bilibili.com' ? 'Bilibili' : '视频';
                return [
                    'url' => $url,
                    'src' => $src,
                    'platform' => $platform,
                    'label' => $label,
                    'ratio' => $platform === 'douyin' ? 'tb' : $ratio,
                    'cover' => $cover,
                ];
            }
        }

        $directSrc = lumina_normalize_embed_src($url);
        if ($directSrc !== '') {
            $host = parse_url($directSrc, PHP_URL_HOST);
            $host = $host ? preg_replace('/^www\\./i', '', strtolower((string)$host)) : '';
            $path = parse_url($directSrc, PHP_URL_PATH);
            $path = strtolower((string)$path);
            if (
                $host === 'player.bilibili.com'
                || ($host === 'bilibili.com' && strpos($path, '/blackboard/') === 0)
                || $host === 'open.douyin.com'
                || $host === 'v.qq.com'
                || $host === 'player.youku.com'
                || $host === 'youtube-nocookie.com'
                || ($host === 'youtube.com' && strpos($path, '/embed/') === 0)
            ) {
                $label = strpos($host, 'bilibili') !== false ? 'Bilibili' : '视频';
                if ($host === 'player.bilibili.com' || $host === 'bilibili.com') {
                    $directSrc = lumina_normalize_bilibili_player_src($directSrc);
                }
                if ($host === 'open.douyin.com') {
                    $host = 'douyin';
                }
                return [
                    'url' => $url,
                    'src' => $directSrc,
                    'platform' => $host,
                    'label' => $label,
                    'ratio' => $host === 'douyin' ? 'tb' : $ratio,
                    'cover' => $cover,
                ];
            }
        }

        $patterns = [
            'bilibili' => [
                'label' => 'Bilibili',
                'patterns' => [
                    '#https?://(?:www\\.)?bilibili\\.com/video/(BV[0-9A-Za-z]+)#i',
                    '#https?://(?:www\\.)?bilibili\\.com/(?:video/)?av(\\d+)#i',
                ],
                'src' => function ($id) {
                    // html5mobileplayer 端点在第三方站点可正常页内播放（实测：
                    // 点击封面播放、控制栏完整、不跳转详情页、不自动播放）。
                    // 注意不能带 autoplay 参数：该端点参数存在即强制静音自动播放
                    if (preg_match('/^BV/i', $id)) {
                        return 'https://www.bilibili.com/blackboard/html5mobileplayer.html?bvid=' . rawurlencode($id) . '&page=1&high_quality=1&danmaku=0';
                    }
                    return 'https://www.bilibili.com/blackboard/html5mobileplayer.html?aid=' . rawurlencode($id) . '&page=1&high_quality=1&danmaku=0';
                },
            ],
            'bilibili_live' => [
                'label' => 'Bilibili 直播',
                'patterns' => ['#https?://live\\.bilibili\\.com/(\\d+)#i'],
                'src' => function ($id) {
                    return 'https://www.bilibili.com/blackboard/live/live-activity-player.html?cid=' . rawurlencode($id) . '&quality=0';
                },
            ],
            'douyin' => [
                'label' => '抖音',
                'patterns' => [
                    '#https?://(?:www\\.)?douyin\\.com/video/(\\d+)#i',
                    '#https?://(?:www\\.)?douyin\\.com/note/(\\d+)#i',
                    '#https?://(?:www\\.)?ixigua\\.com/(\\d+)#i',
                    '#https?://(?:www\\.)?iesdouyin\\.com/share/video/(\\d+)#i',
                ],
                'src' => function ($id) {
                    return 'https://open.douyin.com/player/video?vid=' . rawurlencode($id) . '&autoplay=0';
                },
            ],
            'qq' => [
                'label' => '腾讯视频',
                'patterns' => [
                    '#https?://v\\.qq\\.com/x/cover/[^/]+/([0-9A-Za-z]+)\\.html#i',
                    '#https?://v\\.qq\\.com/x/page/([0-9A-Za-z]+)\\.html#i',
                ],
                'src' => function ($id) {
                    return 'https://v.qq.com/txp/iframe/player.html?vid=' . rawurlencode($id);
                },
            ],
            'youku' => [
                'label' => '优酷',
                'patterns' => ['#https?://v\\.youku\\.com/v_show/id_([0-9A-Za-z=]+)\\.html#i'],
                'src' => function ($id) {
                    return 'https://player.youku.com/embed/' . rawurlencode($id);
                },
            ],
            'youtube' => [
                'label' => 'YouTube',
                'patterns' => [
                    '#https?://(?:www\\.)?youtube\\.com/watch\\?[^\\s<>]*v=([0-9A-Za-z_-]{6,})#i',
                    '#https?://youtu\\.be/([0-9A-Za-z_-]{6,})#i',
                ],
                'src' => function ($id) {
                    return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id);
                },
            ],
        ];

        foreach ($patterns as $key => $config) {
            foreach ($config['patterns'] as $pattern) {
                if (preg_match($pattern, $url, $m)) {
                    $builder = $config['src'];
                    $candidate = $builder($m[1]);
                    if ($key === 'bilibili' && preg_match('/[?&]p=(\d+)/i', $url, $pageMatch) && (int)$pageMatch[1] > 1) {
                        $page = (int)$pageMatch[1];
                        $candidate = preg_replace_callback('/([?&]page=)1(?=&|$)/', function ($match) use ($page) {
                            return $match[1] . $page;
                        }, $candidate, 1);
                    }
                    $candidate = lumina_normalize_embed_src($candidate);
                    if ($candidate !== '') {
                        $platform = $key;
                        $label = $config['label'];
                        $src = $candidate;
                        break 2;
                    }
                }
            }
        }

        return [
            'url' => $url,
            'src' => $src,
            'platform' => $platform,
            'label' => $label,
            'ratio' => $platform === 'douyin' ? 'tb' : $ratio,
            'cover' => $cover,
        ];
    }
}

if (!function_exists('lumina_embed_platform_key')) {
    /**
     * 将多形态的 platform 值归一化为单一 key。
     * 输入可能是 bilibili / bilibili_live / player.bilibili.com / bilibili.com / douyin /
     * v.qq.com / qq / youku / player.youku.com / youtube / youtube.com / youtube-nocookie.com 等。
     * 返回 bilibili / douyin / qq / youku / youtube / video 之一。
     */
    function lumina_embed_platform_key($platform) {
        $p = strtolower(trim((string)$platform));
        if ($p === '') {
            return 'video';
        }
        if (strpos($p, 'bilibili') !== false) {
            return 'bilibili';
        }
        if (strpos($p, 'douyin') !== false) {
            return 'douyin';
        }
        if (strpos($p, 'qq') !== false) {
            return 'qq';
        }
        if (strpos($p, 'youku') !== false) {
            return 'youku';
        }
        if (strpos($p, 'youtube') !== false) {
            return 'youtube';
        }
        return 'video';
    }
}

if (!function_exists('lumina_embed_platform_logo')) {
    /**
     * 返回平台品牌 logo 的内联 SVG（白色填充，viewBox 0 0 24 24）。
     * 用于 embed 视频卡片展示，零额外 HTTP 请求。
     */
    function lumina_embed_platform_logo($key) {
        $key = (string)$key;
        $paths = array(
            // 抖音音符（来自 morpho 主题）
            'douyin' => 'M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.18 8.18 0 0 0 4.78 1.52V6.75a4.85 4.85 0 0 1-1.01-.06z',
            // B站小电视
            'bilibili' => 'M17.813 4.653h.854c1.51.054 2.769.578 3.773 1.574 1.004.995 1.524 2.249 1.56 3.76v7.36c-.036 1.51-.556 2.769-1.56 3.773s-2.262 1.524-3.773 1.56H5.333c-1.51-.036-2.769-.556-3.773-1.56S.036 18.858 0 17.347v-7.36c.036-1.511.556-2.765 1.56-3.76 1.004-.996 2.262-1.52 3.773-1.574h.774l-1.174-1.12a1.234 1.234 0 0 1-.373-.906c0-.356.124-.658.373-.907l.027-.027c.267-.249.573-.373.92-.373.347 0 .653.124.92.373L9.653 4.44c.071.071.134.142.187.213h4.267a.836.836 0 0 1 .16-.213l2.853-2.747c.267-.249.573-.373.92-.373.347 0 .662.151.929.4.267.249.391.551.391.907 0 .355-.124.657-.373.906zM5.333 7.24c-.746.018-1.373.276-1.88.773-.506.498-.769 1.13-.789 1.894v7.52c.018.764.282 1.395.789 1.893.507.498 1.134.756 1.88.773h13.334c.746-.017 1.373-.275 1.88-.773.506-.498.769-1.129.789-1.893v-7.52c-.018-.765-.282-1.396-.789-1.894-.507-.497-1.134-.755-1.88-.773zM8 11.107c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.249-.56.373-.933.373s-.684-.124-.933-.373c-.25-.249-.383-.569-.4-.96V12.44c0-.373.129-.689.387-.947.258-.257.574-.386.946-.386zm8 0c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.249-.56.373-.933.373s-.684-.124-.933-.373c-.25-.249-.383-.569-.4-.96V12.44c.017-.391.15-.711.4-.96.249-.249.56-.373.933-.373z',
            // 腾讯视频 TV 屏幕内嵌播放三角
            'qq' => 'M3 5h18a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm6.5 3v6l5-3-5-3zM5 20h14v1.5H5z',
            // 优酷双三角播放
            'youku' => 'M4 5l8 7-8 7V5zm10 0l8 7-8 7V5z',
            // YouTube 圆角矩形+三角
            'youtube' => 'M21.582 6.186a2.506 2.506 0 0 0-1.768-1.768C18.254 4 12 4 12 4s-6.254 0-7.814.418a2.506 2.506 0 0 0-1.768 1.768C2 7.746 2 12 2 12s0 4.254.418 5.814a2.506 2.506 0 0 0 1.768 1.768C5.746 20 12 20 12 20s6.254 0 7.814-.418a2.506 2.506 0 0 0 1.768-1.768C22 16.254 22 12 22 12s0-4.254-.418-5.814zM10 15.464V8.536L16 12l-6 3.464z',
            // 通用视频播放图标（兜底）
            'video' => 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm5 4.5v7l6-3.5-6-3.5z',
        );
        $d = isset($paths[$key]) ? $paths[$key] : $paths['video'];
        return '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor" aria-hidden="true"><path d="' . $d . '"/></svg>';
    }
}

if (!function_exists('lumina_render_embed_video')) {
    function lumina_render_embed_video($state, $compact = false, $listPreview = false) {
        $state = is_array($state) ? $state : [];
        $src = isset($state['src']) ? trim((string)$state['src']) : '';
        if ($src === '') {
            return '';
        }
        $platform = isset($state['platform']) ? preg_replace('/[^a-zA-Z0-9_\\-.]/', '', (string)$state['platform']) : '';
        $platformKey = lumina_embed_platform_key($platform);
        $label = isset($state['label']) && $state['label'] !== '' ? $state['label'] : '平台视频';
        $ratio = isset($state['ratio']) && $state['ratio'] === 'tb' ? 'tb' : 'lr';
        $logo = lumina_embed_platform_logo($platformKey);
        // 列表预览(80×80 缩略)始终以平台 logo 呈现，仅作跳转入口，不携带弹窗语义
        // 竖屏(抖音等)用点击卡片 → 弹窗播放；横屏(B站等)直接嵌入播放器 iframe（无遮罩），
        // 端点参数已规范化（html5mobileplayer 省略 autoplay → 停驻封面、点击页内播放）
        $isPortrait = $ratio === 'tb';
        $useCard = !$listPreview;
        $class = 'lumina-embed-video lumina-embed-video-local lumina-embed-video-' . $platformKey . ($compact ? ' lumina-embed-video-compact' : '') . ($listPreview ? ' lumina-embed-video-list-preview' : '') . ($isPortrait ? ' lumina-embed-video-portrait' : '') . ($useCard && $isPortrait ? ' is-modal' : '') . ($useCard && !$isPortrait ? ' is-embedded' : '');

        ob_start();
        ?>
        <div class="<?= $class ?>" data-platform="<?= htmlspecialchars($platformKey, ENT_QUOTES) ?>" data-ratio="<?= $ratio ?>"<?php if ($useCard && $isPortrait) : ?> data-src="<?= htmlspecialchars($src, ENT_QUOTES) ?>" data-label="<?= htmlspecialchars($label, ENT_QUOTES) ?>"<?php endif; ?>>
            <?php if ($listPreview) : ?>
            <button type="button" class="lumina-embed-video-start" aria-label="播放<?= htmlspecialchars($label, ENT_QUOTES) ?>"><span class="lumina-embed-video-list-logo" aria-hidden="true"><?= $logo ?></span></button>
            <?php elseif ($isPortrait) : ?>
            <button type="button" class="lumina-embed-video-start lumina-embed-video-card" aria-label="播放<?= htmlspecialchars($label, ENT_QUOTES) ?>">
                <span class="lumina-embed-video-card-logo" aria-hidden="true"><?= $logo ?></span>
                <span class="lumina-embed-video-card-body">
                    <span class="lumina-embed-video-card-title"><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
                    <span class="lumina-embed-video-card-meta">点击播放</span>
                </span>
            </button>
            <?php else : ?>
            <?php // 横屏(如B站)：直接嵌入播放器 iframe，无遮罩层（对标 morpho）。
            // html5mobileplayer 端点省略 autoplay 参数时停驻封面不自动播放；
            // 用户点击封面即页内播放(有声)、控制栏完整、不跳转源站（已在解析层规范化） ?>
            <iframe class="lumina-embed-video-frame" src="<?= htmlspecialchars($src, ENT_QUOTES) ?>" title="<?= htmlspecialchars($label, ENT_QUOTES) ?>" allow="encrypted-media; fullscreen; picture-in-picture" allowfullscreen scrolling="no"></iframe>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('lumina_render_main_footer')) {
    /**
     * 渲染底部页脚(.sh-footer)，输出于主体卡片(.sh-main)内部，
     * 使页脚与主体共享 var(--cobg) 表面成为「一个整体」。
     * 数据计算从 footer.php 提取，保持一致；PJAX 替换 .centent 时随主体重新渲染。
     */
    function lumina_render_main_footer() {
        $lumina_footer_switch = lumina_opt('footer_copyright_switch', 'y');
        if ($lumina_footer_switch === '') {
            $lumina_footer_switch = 'y';
        }
        $lumina_footer_custom = trim((string)lumina_opt('footer_copyright_text', ''));
        $lumina_footer_info = $lumina_footer_custom !== '' ? $lumina_footer_custom : (isset($GLOBALS['footer_info']) ? $GLOBALS['footer_info'] : '');
        if ($lumina_footer_info !== '') {
            $lumina_footer_info = preg_replace('/\\s*powered\\s*by\\s*emlog\\s*/i', ' ', $lumina_footer_info);
            $lumina_footer_info = trim($lumina_footer_info);
        }
        if ($lumina_footer_info === '') {
            $siteName = isset($GLOBALS['blogname']) ? trim((string)$GLOBALS['blogname']) : '';
            if ($siteName === '' && class_exists('Option')) {
                $siteName = trim((string)site_name());
            }
            if ($siteName !== '') {
                $lumina_footer_info = '© ' . date('Y') . ' ' . $siteName;
            } else {
                $lumina_footer_info = '© ' . date('Y');
            }
        }
        $icp_value = isset($GLOBALS['icp']) ? $GLOBALS['icp'] : '';
        if ($icp_value === '' && class_exists('Option')) {
            $icp_value = (string)settings('site_icp', '');
        }
        ?>
    <div class="sh-footer">
        <?php if ($lumina_footer_switch !== 'n' && $lumina_footer_info !== ''): ?>
            <div class="sh-copyright">
                <span><?= $lumina_footer_info ?></span>
            </div>
        <?php endif; ?>
        <?php if ($lumina_footer_switch !== 'n' && $icp_value) : ?>
            <div class="sh-icp"><a href="https://beian.miit.gov.cn" target="_blank" rel="noopener noreferrer"><?= $icp_value ?></a></div>
        <?php endif; ?>
    </div>
        <?php
    }
}

if (!function_exists('lumina_render_redpacket_card')) {
    function lumina_render_redpacket_card($gid, $authorName, $state) {
        $state = is_array($state) ? $state : [];
        $title = isset($state['title']) && $state['title'] !== '' ? $state['title'] : '恭喜发财';
        $count = isset($state['count']) ? (int)$state['count'] : 0;
        $remainCount = isset($state['remain_count']) ? (int)$state['remain_count'] : 0;
        $status = isset($state['status']) ? (int)$state['status'] : 0;
        $claimed = !empty($state['claimed']);
        $claimedAmount = isset($state['claimed_amount']) ? (int)$state['claimed_amount'] : 0;
        $btnText = '领';
        $disabled = '';
        if ($status === 1) {
            $btnText = '已抢完';
            $disabled = ' is-disabled';
        } elseif ($claimed) {
            $btnText = '已领取';
            $disabled = ' is-disabled';
        }
        $remainText = $count > 0 ? ('剩余 ' . $remainCount . '/' . $count) : '';

        ob_start();
        ?>
        <div class="lumina-redpacket-card<?= $disabled ?>" data-gid="<?= (int)$gid ?>" data-login="<?= (is_logged_in()) ? '1' : '0' ?>" data-claimed="<?= $claimed ? '1' : '0' ?>" data-status="<?= $status === 1 ? '1' : '0' ?>" data-token="<?= (is_logged_in()) ? csrf_token() : '' ?>" data-blog-url="<?= lumina_blog_base() ?>" data-claim-url="<?= htmlspecialchars(function_exists('lumina_user_url') ? lumina_user_url() : (rtrim(lumina_blog_base(), '/') . '/index.php/user'), ENT_QUOTES) ?>">
            <div class="lumina-redpacket-head">
                <i class="iconfont icon-hongbao lumina-redpacket-mark"></i>
                <div class="lumina-redpacket-title"><?= htmlspecialchars($title) ?></div>
                <div class="lumina-redpacket-sub"><?= htmlspecialchars((string)$authorName) ?> 的红包</div>
            </div>
            <button type="button" class="lumina-redpacket-btn<?= $disabled ?>">
                <span class="lumina-redpacket-btn-text"><?= $btnText ?></span>
                <span class="lumina-redpacket-btn-icon" aria-hidden="true"><i class="iconfont icon-hongbao"></i></span>
            </button>
            <div class="lumina-redpacket-meta">
                <span class="lumina-redpacket-count"><?= $remainText ?></span>
                <span class="lumina-redpacket-amount"><?= $claimedAmount > 0 ? ('已领取 ' . $claimedAmount . ' 积分') : '' ?></span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('lumina_render_sidebar_profile_card')) {
    function lumina_render_sidebar_profile_card($profileUid, $profileAvatar, $fallbackAvatar, $profileName, $sidebarContact, $sidebarStats) {
        $sidebarStats = is_array($sidebarStats) ? $sidebarStats : [];
        ?>
        <div class="lumina-sidecard lumina-sidecard-profile">
            <div class="lumina-sidecard-profile-head">
                <a class="lumina-sidecard-avatar" href="<?= url_to('/author/' . rawurlencode((string)($profileUid))) ?>">
                    <img src="<?= $profileAvatar ?>" alt="avatar" onerror="this.onerror=null;this.src='<?= $fallbackAvatar ?>'">
                </a>
                <div class="lumina-sidecard-profile-info">
                    <div class="lumina-sidecard-name"><?= $profileName ?></div>
                    <?php if ($sidebarContact !== ''): ?>
                        <div class="lumina-sidecard-desc"><?= $sidebarContact ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="lumina-sidecard-stats">
                <div class="lumina-sidecard-stat">
                    <span class="lumina-sidecard-stat-num"><?= isset($sidebarStats['logs']) ? (int)$sidebarStats['logs'] : 0 ?></span>
                    <span class="lumina-sidecard-stat-label">动态</span>
                </div>
                <div class="lumina-sidecard-stat">
                    <span class="lumina-sidecard-stat-num"><?= isset($sidebarStats['comments']) ? (int)$sidebarStats['comments'] : 0 ?></span>
                    <span class="lumina-sidecard-stat-label">评论</span>
                </div>
                <div class="lumina-sidecard-stat">
                    <span class="lumina-sidecard-stat-num"><?= isset($sidebarStats['likes']) ? (int)$sidebarStats['likes'] : 0 ?></span>
                    <span class="lumina-sidecard-stat-label">获赞</span>
                </div>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('lumina_render_sidebar_extra_cards')) {
    function lumina_render_sidebar_extra_cards($sidebarSorts, $sidebarTags, $sidebarCopyrightHtml, $sidebarIcp) {
        if (!empty($sidebarSorts)) {
            ?>
            <div class="lumina-sidecard">
                <div class="lumina-sidecard-title">文章分类</div>
                <div class="lumina-sidecard-pills">
                    <?php foreach ($sidebarSorts as $sort): ?>
                        <a class="lumina-pill" href="<?= $sort['url'] ?>">
                            <?= $sort['name'] ?>
                            <?php if ($sort['count'] > 0): ?><span class="lumina-pill-count"><?= $sort['count'] ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
        }

        if (!empty($sidebarTags)) {
            ?>
            <div class="lumina-sidecard">
                <div class="lumina-sidecard-title">标签</div>
                <div class="lumina-sidecard-pills">
                    <?php foreach ($sidebarTags as $tag): ?>
                        <a class="lumina-pill" href="<?= $tag['url'] ?>"># <?= $tag['name'] ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
        }

        if ($sidebarCopyrightHtml !== '' || !empty($sidebarIcp)) {
            ?>
            <div class="lumina-side-footer">
                <?php if ($sidebarCopyrightHtml !== ''): ?>
                    <div class="lumina-side-footer-text"><?= $sidebarCopyrightHtml ?></div>
                <?php endif; ?>
                <?php if (!empty($sidebarIcp)): ?>
                    <a class="lumina-side-footer-icp" href="https://beian.miit.gov.cn" target="_blank" rel="noopener noreferrer"><?= $sidebarIcp ?></a>
                <?php endif; ?>
            </div>
            <?php
        }
    }
}

if (!function_exists('smartDate')) {
    function smartDate($timestamp) {
        $timestamp = lumina_parse_timestamp($timestamp);
        if ($timestamp <= 0) {
            return '';
        }
        $now = time();
        $diff = $now - $timestamp;
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 60) {
            return '刚刚';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . '分钟前';
        }
        $postDay = lumina_blog_date('Ymd', $timestamp);
        $today = lumina_blog_date('Ymd', $now);
        $yesterday = lumina_blog_date('Ymd', $now - 86400);
        if ($postDay === $today) {
            return '今天 ' . lumina_blog_date('H:i', $timestamp);
        }
        if ($postDay === $yesterday) {
            return '昨天 ' . lumina_blog_date('H:i', $timestamp);
        }
        if (lumina_blog_date('Y', $timestamp) === lumina_blog_date('Y', $now)) {
            return lumina_blog_date('m-d H:i', $timestamp);
        }
        return lumina_blog_date('Y-m-d H:i', $timestamp);
    }
}

if (!function_exists('lumina_render_markdown')) {
    function lumina_render_markdown($content, $allowHtml = true) {
        $content = trim((string)$content);
        if ($content === '') {
            return '';
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (function_exists('parseUBB')) {
            $content = parseUBB($content);
        }

        $hasHtml = preg_match('/<\\/?[a-z][^>]*>/i', $content) === 1;
        if ($allowHtml && $hasHtml) {
            return lumina_parse_emoji(lumina_sanitize_content_html($content));
        }

        $safe = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        $lines = explode("\n", $safe);
        $html = [];
        $inUl = false;
        $inOl = false;
        $inQuote = false;
        $inCode = false;

        $closeLists = function () use (&$html, &$inUl, &$inOl, &$inQuote) {
            if ($inUl) {
                $html[] = '</ul>';
                $inUl = false;
            }
            if ($inOl) {
                $html[] = '</ol>';
                $inOl = false;
            }
            if ($inQuote) {
                $html[] = '</blockquote>';
                $inQuote = false;
            }
        };

        foreach ($lines as $line) {
            $trim = trim($line);

            if (preg_match('/^```/', $trim)) {
                $closeLists();
                if (!$inCode) {
                    $html[] = '<pre><code>';
                    $inCode = true;
                } else {
                    $html[] = '</code></pre>';
                    $inCode = false;
                }
                continue;
            }

            if ($inCode) {
                $html[] = $line;
                continue;
            }

            if ($trim === '') {
                $closeLists();
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $trim, $m)) {
                if (!$inQuote) {
                    $closeLists();
                    $html[] = '<blockquote>';
                    $inQuote = true;
                }
                $html[] = '<p>' . lumina_markdown_inline($m[1]) . '</p>';
                continue;
            } elseif ($inQuote) {
                $html[] = '</blockquote>';
                $inQuote = false;
            }

            if (preg_match('/^[-*+]\s+(.*)$/', $trim, $m)) {
                if (!$inUl) {
                    if ($inOl) {
                        $html[] = '</ol>';
                        $inOl = false;
                    }
                    $html[] = '<ul>';
                    $inUl = true;
                }
                $html[] = '<li>' . lumina_markdown_inline($m[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\d+\.\s+(.*)$/', $trim, $m)) {
                if (!$inOl) {
                    if ($inUl) {
                        $html[] = '</ul>';
                        $inUl = false;
                    }
                    $html[] = '<ol>';
                    $inOl = true;
                }
                $html[] = '<li>' . lumina_markdown_inline($m[1]) . '</li>';
                continue;
            }

            if ($inUl) {
                $html[] = '</ul>';
                $inUl = false;
            }
            if ($inOl) {
                $html[] = '</ol>';
                $inOl = false;
            }

            if (preg_match('/^(#{1,6})\s*(.+)$/', $trim, $m)) {
                $level = min(6, strlen($m[1]));
                $html[] = '<h' . $level . '>' . lumina_markdown_inline($m[2]) . '</h' . $level . '>';
                continue;
            }

            $html[] = '<p>' . lumina_markdown_inline($trim) . '</p>';
        }

        $closeLists();
        if ($inCode) {
            $html[] = '</code></pre>';
        }

        return lumina_parse_emoji(implode("\n", $html));
    }
}

if (!function_exists('lumina_markdown_inline')) {
    function lumina_markdown_inline($text) {
        $text = preg_replace('/!\\[([^\\]]*)\\]\\(([^\\)]+)\\)/', '<img src="$2" alt="$1">', $text);
        $text = preg_replace('/\\[([^\\]]+)\\]\\(([^\\)]+)\\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text);
        $text = preg_replace('/\\*\\*(.+?)\\*\\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\\*)\\*(?!\\*)(.+?)(?<!\\*)\\*(?!\\*)/s', '<em>$1</em>', $text);
        $text = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/s', '<em>$1</em>', $text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        return nl2br($text);
    }
}

if (!function_exists('lumina_html_to_text')) {
    function lumina_html_to_text($html, $allowTags = '') {
        $html = (string)$html;
        if ($html === '') {
            return '';
        }
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = preg_replace_callback('/<img[^>]+src\\s*=\\s*(["\\\'])(.*?)\\1[^>]*>/i', function ($m) {
            $src = htmlspecialchars_decode($m[2], ENT_QUOTES);
            return "\n[img]" . trim($src) . "[/img]\n";
        }, $html);
        $html = preg_replace_callback('/<a[^>]+href\\s*=\\s*(["\\\'])(.*?)\\1[^>]*>(.*?)<\\/a>/is', function ($m) {
            $href = htmlspecialchars_decode($m[2], ENT_QUOTES);
            $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));
            if ($text === '' || $text === $href) {
                return $href;
            }
            return $text . ' ' . $href;
        }, $html);
        $html = preg_replace('/<(br|\\/p|\\/div|\\/li|\\/blockquote|\\/h[1-6])\\b[^>]*>/i', "\n", $html);
        $html = strip_tags($html, $allowTags);
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $html = preg_replace("/\n{3,}/", "\n\n", $html);
        return trim($html);
    }
}

if (!function_exists('lumina_get_user_info')) {
function lumina_get_user_info($uid) {
    static $cache = [];
    $uid = (int)$uid;
    if ($uid <= 0) {
        return [];
    }
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }
    if (!class_exists('User_Model')) {
        $cache[$uid] = [];
        return $cache[$uid];
    }
    $userModel = new User_Model();
    $user = $userModel->getOneUser($uid);
    $cache[$uid] = is_array($user) ? $user : [];
    return $cache[$uid];
}
}

if (!function_exists('lumina_get_user_avatar')) {
function lumina_get_user_avatar($uid, $fallback = '') {
    $user = lumina_get_user_info($uid);
    if (!empty($user['photo'])) {
        if (class_exists('User') && method_exists('User', 'getAvatar')) {
            return lumina_resolve_avatar(lumina_user_avatar_of($user['photo']), $fallback);
        }
        return lumina_resolve_avatar($user['photo'], $fallback);
    }
    return lumina_resolve_avatar($fallback, $fallback);
}
}

if (!function_exists('lumina_get_user_name')) {
function lumina_get_user_name($uid, $fallback = '') {
    $user = lumina_get_user_info($uid);
    if (!empty($user['name_orig'])) {
        return $user['name_orig'];
    }
    if (!empty($user['nickname'])) {
        return $user['nickname'];
    }
    return $fallback;
}
}


if (!function_exists('lumina_get_profile_moment_thumbs')) {
function lumina_get_profile_moment_thumbs($uid, $limit = 4, $scan = 32) {
    $uid = (int)$uid;
    $limit = (int)$limit;
    $scan = (int)$scan;
    if ($limit <= 0) {
        $limit = 4;
    }
    if ($scan < $limit) {
        $scan = $limit;
    }
    if (!class_exists('Log_Model')) {
        return [];
    }
    $logModel = new Log_Model();
    $condition = $uid > 0 ? "and author={$uid} order by date desc" : "order by date desc";
    $logs = $logModel->getLogsForHome($condition, 1, $scan);
    if (!is_array($logs)) {
        return [];
    }
    $thumbs = [];
    foreach ($logs as $log) {
        if (!is_array($log)) {
            continue;
        }
        $candidates = [];
        $fields = isset($log['fields']) && is_array($log['fields']) ? $log['fields'] : [];
        $type = isset($fields['lumina_type']) ? trim((string)$fields['lumina_type']) : '';
        // 链接卡片(OG图)与红包不是真实照片，跳过，避免混入朋友圈预览
        if ($type === 'link' || $type === 'redpacket') {
            continue;
        }
        if (!empty($fields['lumina_photos'])) {
            $candidates = lumina_parse_list($fields['lumina_photos']);
        }
        if (empty($candidates) && !empty($log['log_cover'])) {
            $candidates[] = $log['log_cover'];
        }
        if (empty($candidates) && !empty($log['content'])) {
            $candidates = lumina_extract_images($log['content']);
        }
        foreach ($candidates as $src) {
            $src = trim((string)$src);
            if ($src === '') {
                continue;
            }
            $src = lumina_resolve_url($src, '');
            if ($src === '' || in_array($src, $thumbs, true)) {
                continue;
            }
            $thumbs[] = $src;
            if (count($thumbs) >= $limit) {
                return $thumbs;
            }
        }
    }
    return $thumbs;
}
}


if (!function_exists('lumina_get_notice_feed')) {
function lumina_get_notice_feed($uid, $days = 30, $limit = 20) {
    $uid = (int)$uid;
    if ($uid <= 0 || !class_exists('Database')) {
        return [];
    }
    $limit = (int)$limit;
    if ($limit <= 0) {
        $limit = 20;
    }
    $days = (int)$days;
    if ($days < 0) {
        $days = 0;
    }

    $db = \Pafish\Core\DB;
    $blogTable = DB_PREFIX . 'blog';
    $commentTable = DB_PREFIX . 'comment';
    $likeTable = DB_PREFIX . 'like';

    $since = $days > 0 ? (time() - $days * 86400) : 0;
    $timeWhereComment = $since > 0 ? " AND c.date >= $since" : '';
    $timeWhereLike = $since > 0 ? " AND l.date >= $since" : '';

    $items = [];

    $commentCols = lumina_table_columns($commentTable);
    $commentSelect = ['c.cid', 'c.gid', 'c.poster', 'c.comment', 'c.date', 'b.title'];
    if (in_array('mail', $commentCols, true)) {
        $commentSelect[] = 'c.mail';
    }
    if (in_array('uid', $commentCols, true)) {
        $commentSelect[] = 'c.uid';
    }
    $sql = "SELECT " . implode(',', $commentSelect) . " FROM $commentTable c JOIN $blogTable b ON c.gid=b.gid WHERE b.author=$uid AND c.hide='n' $timeWhereComment ORDER BY c.date DESC LIMIT $limit";
    $ret = $db->query($sql);
    while ($row = $db->fetch_array($ret)) {
        $items[] = [
            'type' => 'comment',
            'cid' => (int)$row['cid'],
            'gid' => (int)$row['gid'],
            'poster' => $row['poster'],
            'comment' => $row['comment'],
            'date' => (int)$row['date'],
            'mail' => isset($row['mail']) ? $row['mail'] : '',
            'uid' => isset($row['uid']) ? (int)$row['uid'] : 0,
            'title' => $row['title'],
        ];
    }

    $likeCols = lumina_table_columns($likeTable);
    if (in_array('id', $likeCols, true) && in_array('gid', $likeCols, true) && in_array('date', $likeCols, true)) {
        $likeSelect = ['l.id', 'l.gid', 'l.poster', 'l.date', 'b.title'];
        if (in_array('uid', $likeCols, true)) {
            $likeSelect[] = 'l.uid';
        }
        if (in_array('avatar', $likeCols, true)) {
            $likeSelect[] = 'l.avatar';
        }
        if (in_array('mail', $likeCols, true)) {
            $likeSelect[] = 'l.mail';
        }
        $voteWhere = in_array('vote_type', $likeCols, true) ? " AND l.vote_type='like'" : '';
        $sql = "SELECT " . implode(',', $likeSelect) . " FROM $likeTable l JOIN $blogTable b ON l.gid=b.gid WHERE b.author=$uid $voteWhere $timeWhereLike ORDER BY l.date DESC LIMIT $limit";
        $ret = $db->query($sql);
        while ($row = $db->fetch_array($ret)) {
            $items[] = [
                'type' => 'like',
                'id' => (int)$row['id'],
                'gid' => (int)$row['gid'],
                'poster' => $row['poster'],
                'date' => (int)$row['date'],
                'title' => $row['title'],
                'uid' => isset($row['uid']) ? (int)$row['uid'] : 0,
                'avatar' => isset($row['avatar']) ? $row['avatar'] : '',
                'mail' => isset($row['mail']) ? $row['mail'] : '',
            ];
        }
    }

    usort($items, function ($a, $b) {
        $ad = isset($a['date']) ? (int)$a['date'] : 0;
        $bd = isset($b['date']) ? (int)$b['date'] : 0;
        if ($ad === $bd) {
            return 0;
        }
        return $ad > $bd ? -1 : 1;
    });

    if (count($items) > $limit) {
        $items = array_slice($items, 0, $limit);
    }

    return $items;
}
}

if (!function_exists('lumina_table_columns')) {
function lumina_table_columns($table) {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    if (!lumina_table_exists($table)) {
        $cache[$table] = [];
        return $cache[$table];
    }
    $cols = [];
    if (!class_exists('Database')) {
        $cache[$table] = $cols;
        return $cols;
    }
    $db = \Pafish\Core\DB;
    $ret = $db->query("SHOW COLUMNS FROM $table");
    if ($ret) {
        while ($row = $db->fetch_array($ret)) {
            if (isset($row['Field'])) {
                $cols[] = $row['Field'];
            }
        }
    }
    $cache[$table] = $cols;
    return $cols;
}
}

if (!function_exists('lumina_table_exists')) {
function lumina_table_exists($table, $force = false) {
    static $cache = [];
    if (!$force && isset($cache[$table])) {
        return $cache[$table];
    }
    if (!class_exists('Database')) {
        $cache[$table] = false;
        return $cache[$table];
    }
    $db = \Pafish\Core\DB;
    $safe = addslashes($table);
    $safe = str_replace(['_', '%'], ['\\_', '\\%'], $safe);
    $ret = $db->query("SHOW TABLES LIKE '{$safe}'");
    $exists = false;
    if ($ret && $db->num_rows($ret) > 0) {
        $exists = true;
    }
    $cache[$table] = $exists;
    return $exists;
}
}

if (!function_exists('lumina_db_escape')) {
function lumina_db_escape($value) {
    if (!class_exists('Database')) {
        return addslashes((string)$value);
    }
    $db = \Pafish\Core\DB;
    if (method_exists($db, 'escape_string')) {
        return $db->escape_string((string)$value);
    }
    return addslashes((string)$value);
}
}

if (!function_exists('lumina_notice_table')) {
function lumina_notice_table() {
    return DB_PREFIX . 'lumina_notice';
}
}

if (!function_exists('lumina_notice_ensure_table')) {
function lumina_notice_ensure_table() {
    if (!class_exists('Database')) {
        return false;
    }
    $table = lumina_notice_table();
    if (lumina_table_exists($table, true)) {
        return true;
    }
    $db = \Pafish\Core\DB;
    $sql = "CREATE TABLE IF NOT EXISTS `$table` (
        `id` int(11) unsigned NOT NULL auto_increment,
        `uid` int(11) unsigned NOT NULL DEFAULT 0,
        `notice_key` varchar(64) NOT NULL DEFAULT '',
        `deleted_at` bigint(20) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uid_key` (`uid`,`notice_key`),
        KEY `uid` (`uid`),
        KEY `deleted_at` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->query($sql, true);
    return lumina_table_exists($table, true);
}
}

if (!function_exists('lumina_notice_mark_deleted')) {
function lumina_notice_mark_deleted($uid, $keys) {
    $uid = (int)$uid;
    if ($uid <= 0) {
        return false;
    }
    if (!lumina_notice_ensure_table()) {
        return false;
    }
    if (!is_array($keys)) {
        $keys = [$keys];
    }
    $keys = array_unique(array_filter(array_map('trim', $keys)));
    if (empty($keys)) {
        return true;
    }
    $db = \Pafish\Core\DB;
    $table = lumina_notice_table();
    $now = time();
    foreach ($keys as $key) {
        if ($key === '') {
            continue;
        }
        $key = substr($key, 0, 64);
        $ekey = lumina_db_escape($key);
        $sql = "INSERT INTO `$table` (uid, notice_key, deleted_at) VALUES ($uid, '$ekey', $now)
                ON DUPLICATE KEY UPDATE deleted_at = $now";
        $db->query($sql, true);
    }
    return true;
}
}

if (!function_exists('lumina_user_cover_table')) {
function lumina_user_cover_table() {
    return DB_PREFIX . 'lumina_user_cover';
}
}

if (!function_exists('lumina_user_cover_ensure_table')) {
function lumina_user_cover_ensure_table() {
    if (!class_exists('Database')) {
        return false;
    }
    $table = lumina_user_cover_table();
    if (lumina_table_exists($table, true)) {
        return true;
    }
    $db = \Pafish\Core\DB;
    $sql = "CREATE TABLE IF NOT EXISTS `$table` (
        `uid` int(11) unsigned NOT NULL,
        `cover_url` varchar(255) NOT NULL DEFAULT '',
        `updated_at` bigint(20) NOT NULL DEFAULT 0,
        PRIMARY KEY (`uid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->query($sql, true);
    return lumina_table_exists($table, true);
}
}

if (!function_exists('lumina_get_user_cover')) {
function lumina_get_user_cover($uid, $fallback = '') {
    $uid = (int)$uid;
    if ($uid <= 0) {
        return $fallback;
    }
    if (!lumina_user_cover_ensure_table()) {
        return $fallback;
    }
    $db = \Pafish\Core\DB;
    $table = lumina_user_cover_table();
    $ret = $db->query("SELECT cover_url FROM $table WHERE uid={$uid} LIMIT 1");
    if ($ret && ($row = $db->fetch_array($ret))) {
        $cover = isset($row['cover_url']) ? trim((string)$row['cover_url']) : '';
        if ($cover !== '') {
            return $cover;
        }
    }
    return $fallback;
}
}

if (!function_exists('lumina_set_user_cover')) {
function lumina_set_user_cover($uid, $url) {
    $uid = (int)$uid;
    if ($uid <= 0) {
        return false;
    }
    if (!lumina_user_cover_ensure_table()) {
        return false;
    }
    $url = trim((string)$url);
    $table = lumina_user_cover_table();
    $db = \Pafish\Core\DB;
    if ($url === '') {
        $db->query("DELETE FROM $table WHERE uid={$uid}", true);
        return true;
    }
    $safe = lumina_db_escape($url);
    $now = time();
    $sql = "REPLACE INTO $table (uid, cover_url, updated_at) VALUES ({$uid}, '{$safe}', {$now})";
    $db->query($sql, true);
    return true;
}
}

if (!function_exists('lumina_redpacket_table')) {
function lumina_redpacket_table() {
    return DB_PREFIX . 'lumina_redpacket';
}
}

if (!function_exists('lumina_redpacket_claim_table')) {
function lumina_redpacket_claim_table() {
    return DB_PREFIX . 'lumina_redpacket_claim';
}
}

if (!function_exists('lumina_redpacket_claim_ensure_index')) {
function lumina_redpacket_claim_ensure_index($db, $table) {
    if (!$db || $table === '') {
        return false;
    }
    $ret = $db->query("SHOW INDEX FROM `$table` WHERE Key_name='packet_uid'", true);
    $exists = false;
    if ($ret) {
        if ($row = $db->fetch_array($ret)) {
            $exists = true;
        }
    }
    if (!$exists) {
        $db->query("ALTER TABLE `$table` ADD UNIQUE KEY `packet_uid` (`packet_id`,`uid`)", true);
    }
    return true;
}
}

if (!function_exists('lumina_db_rows_affected')) {
function lumina_db_rows_affected($db, $result) {
    if ($result && is_object($result) && method_exists($result, 'rowCount')) {
        return (int)$result->rowCount();
    }
    if ($db && method_exists($db, 'affected_rows')) {
        return (int)$db->affected_rows();
    }
    return 0;
}
}

if (!function_exists('lumina_redpacket_ensure_table')) {
function lumina_redpacket_ensure_table() {
    if (!class_exists('Database')) {
        return false;
    }
    $table = lumina_redpacket_table();
    if (lumina_table_exists($table)) {
        return true;
    }
    $db = \Pafish\Core\DB;
    $sql = "CREATE TABLE IF NOT EXISTS `$table` (
        `id` int(11) unsigned NOT NULL auto_increment,
        `gid` int(11) unsigned NOT NULL DEFAULT 0,
        `uid` int(11) unsigned NOT NULL DEFAULT 0,
        `total_credits` int(11) unsigned NOT NULL DEFAULT 0,
        `remain_credits` int(11) unsigned NOT NULL DEFAULT 0,
        `total_count` int(11) unsigned NOT NULL DEFAULT 0,
        `remain_count` int(11) unsigned NOT NULL DEFAULT 0,
        `mode` varchar(10) NOT NULL DEFAULT 'random',
        `status` tinyint(1) NOT NULL DEFAULT 0,
        `created_at` bigint(20) NOT NULL DEFAULT 0,
        `updated_at` bigint(20) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `gid` (`gid`),
        KEY `uid` (`uid`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->query($sql, true);
    return lumina_table_exists($table);
}
}

if (!function_exists('lumina_redpacket_claim_ensure_table')) {
function lumina_redpacket_claim_ensure_table() {
    if (!class_exists('Database')) {
        return false;
    }
    $table = lumina_redpacket_claim_table();
    $db = \Pafish\Core\DB;
    if (!lumina_table_exists($table)) {
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            `id` int(11) unsigned NOT NULL auto_increment,
            `packet_id` int(11) unsigned NOT NULL DEFAULT 0,
            `uid` int(11) unsigned NOT NULL DEFAULT 0,
            `credits` int(11) unsigned NOT NULL DEFAULT 0,
            `created_at` bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `packet_uid` (`packet_id`,`uid`),
            KEY `uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $db->query($sql, true);
    }
    if (!lumina_table_exists($table)) {
        return false;
    }
    lumina_redpacket_claim_ensure_index($db, $table);
    return true;
}
}

if (!function_exists('lumina_redpacket_get')) {
function lumina_redpacket_get($gid) {
    $gid = (int)$gid;
    if ($gid <= 0 || !lumina_redpacket_ensure_table()) {
        return [];
    }
    $db = \Pafish\Core\DB;
    $table = lumina_redpacket_table();
    $ret = $db->query("SELECT * FROM `$table` WHERE gid={$gid} LIMIT 1");
    if ($ret && ($row = $db->fetch_array($ret))) {
        return $row;
    }
    return [];
}
}

if (!function_exists('lumina_redpacket_get_claim')) {
function lumina_redpacket_get_claim($packetId, $uid) {
    $packetId = (int)$packetId;
    $uid = (int)$uid;
    if ($packetId <= 0 || $uid <= 0 || !lumina_redpacket_claim_ensure_table()) {
        return [];
    }
    $db = \Pafish\Core\DB;
    $table = lumina_redpacket_claim_table();
    $ret = $db->query("SELECT credits, created_at FROM `$table` WHERE packet_id={$packetId} AND uid={$uid} LIMIT 1");
    if ($ret && ($row = $db->fetch_array($ret))) {
        return $row;
    }
    return [];
}
}

if (!function_exists('lumina_redpacket_create')) {
function lumina_redpacket_create($gid, $uid, $total, $count, $mode) {
    $gid = (int)$gid;
    $uid = (int)$uid;
    $total = (int)$total;
    $count = (int)$count;
    if ($gid <= 0 || $uid <= 0 || $total <= 0 || $count <= 0) {
        return false;
    }
    if (!lumina_redpacket_ensure_table()) {
        return false;
    }
    $mode = ($mode === 'equal') ? 'equal' : 'random';
    $db = \Pafish\Core\DB;
    $table = lumina_redpacket_table();
    $ret = $db->query("SELECT id FROM `$table` WHERE gid={$gid} LIMIT 1");
    if ($ret && $db->fetch_array($ret)) {
        return true;
    }
    $now = time();
    $sql = "INSERT INTO `$table` (gid, uid, total_credits, remain_credits, total_count, remain_count, mode, status, created_at, updated_at)
            VALUES ({$gid}, {$uid}, {$total}, {$total}, {$count}, {$count}, '{$mode}', 0, {$now}, {$now})";
    $db->query($sql, true);
    return true;
}
}

if (!function_exists('lumina_redpacket_calc_amount')) {
function lumina_redpacket_calc_amount($packet) {
    $remain = isset($packet['remain_credits']) ? (int)$packet['remain_credits'] : 0;
    $remainCount = isset($packet['remain_count']) ? (int)$packet['remain_count'] : 0;
    if ($remain <= 0 || $remainCount <= 0) {
        return 0;
    }
    if ($remainCount === 1) {
        return $remain;
    }
    $mode = isset($packet['mode']) && $packet['mode'] === 'equal' ? 'equal' : 'random';
    if ($mode === 'equal') {
        $total = isset($packet['total_credits']) ? (int)$packet['total_credits'] : $remain;
        $totalCount = isset($packet['total_count']) ? (int)$packet['total_count'] : $remainCount;
        if ($totalCount <= 0) {
            $totalCount = $remainCount;
        }
        $base = (int)floor($total / $totalCount);
        $extra = $total % $totalCount;
        $claimed = $totalCount - $remainCount;
        $amount = $base + ($claimed < $extra ? 1 : 0);
        return $amount > 0 ? $amount : 1;
    }
    $min = 1;
    $max = $remain - ($remainCount - 1);
    if ($max < 1) {
        $max = 1;
    }
    if (function_exists('random_int')) {
        return random_int($min, $max);
    }
    return mt_rand($min, $max);
}
}

if (!function_exists('lumina_redpacket_claim')) {
function lumina_redpacket_claim($gid, $uid) {
    $gid = (int)$gid;
    $uid = (int)$uid;
    if ($gid <= 0 || $uid <= 0) {
        return ['ok' => false, 'msg' => '红包不存在'];
    }
    if (!lumina_redpacket_ensure_table() || !lumina_redpacket_claim_ensure_table()) {
        return ['ok' => false, 'msg' => '红包暂不可用'];
    }
    $db = \Pafish\Core\DB;
    $table = lumina_redpacket_table();
    $claimTable = lumina_redpacket_claim_table();
    $db->query('START TRANSACTION', true);
    $packet = $db->once_fetch_array("SELECT * FROM `$table` WHERE gid={$gid} LIMIT 1 FOR UPDATE");
    if (empty($packet)) {
        $db->query('ROLLBACK', true);
        return ['ok' => false, 'msg' => '红包不存在'];
    }
    $remain = isset($packet['remain_credits']) ? (int)$packet['remain_credits'] : 0;
    $remainCount = isset($packet['remain_count']) ? (int)$packet['remain_count'] : 0;
    if ($remain <= 0 || $remainCount <= 0 || (int)$packet['status'] === 1) {
        $db->query('ROLLBACK', true);
        return ['ok' => false, 'msg' => '红包已抢完'];
    }
    $claimed = $db->once_fetch_array("SELECT id FROM `$claimTable` WHERE packet_id=" . (int)$packet['id'] . " AND uid={$uid} LIMIT 1");
    if (!empty($claimed)) {
        $db->query('ROLLBACK', true);
        return ['ok' => false, 'msg' => '已领取'];
    }

    $amount = lumina_redpacket_calc_amount($packet);
    if ($amount <= 0) {
        $db->query('ROLLBACK', true);
        return ['ok' => false, 'msg' => '红包已抢完'];
    }
    $newRemain = $remain - $amount;
    $newCount = $remainCount - 1;
    if ($newRemain < 0 || $newCount < 0) {
        $db->query('ROLLBACK', true);
        return ['ok' => false, 'msg' => '红包已抢完'];
    }

    $now = time();
    $insertRes = $db->query("INSERT INTO `$claimTable` (packet_id, uid, credits, created_at) VALUES (" . (int)$packet['id'] . ", {$uid}, {$amount}, {$now})", true);
    if (!$insertRes) {
        $db->query('ROLLBACK', true);
        return ['ok' => false, 'msg' => '已领取'];
    }
    $status = ($newRemain <= 0 || $newCount <= 0) ? 1 : 0;
    $updateRes = $db->query("UPDATE `$table` SET remain_credits={$newRemain}, remain_count={$newCount}, status={$status}, updated_at={$now} WHERE id=" . (int)$packet['id'] . " AND remain_credits>={$amount} AND remain_count>0 AND status=0", true);
    if (lumina_db_rows_affected($db, $updateRes) <= 0) {
        $db->query("DELETE FROM `$claimTable` WHERE packet_id=" . (int)$packet['id'] . " AND uid={$uid} AND created_at={$now}", true);
        $db->query('ROLLBACK', true);
        return ['ok' => false, 'msg' => '红包已抢完'];
    }
    $db->query('COMMIT', true);

    if (class_exists('User_Model')) {
        $userModel = new User_Model();
        if (method_exists($userModel, 'addCredits')) {
            $userModel->addCredits($uid, $amount);
        }
    }

    return [
        'ok' => true,
        'amount' => $amount,
        'remain' => $newRemain,
        'remain_count' => $newCount,
        'total_count' => isset($packet['total_count']) ? (int)$packet['total_count'] : 0,
    ];
}
}

if (!function_exists('lumina_notice_get_deleted_keys')) {
function lumina_notice_get_deleted_keys($uid, $limit = 500) {
    $uid = (int)$uid;
    if ($uid <= 0) {
        return [];
    }
    if (!lumina_notice_ensure_table()) {
        return [];
    }
    $limit = (int)$limit;
    if ($limit <= 0) {
        $limit = 500;
    }
    $db = \Pafish\Core\DB;
    $table = lumina_notice_table();
    $sql = "SELECT notice_key FROM `$table` WHERE uid=$uid ORDER BY id DESC LIMIT $limit";
    $ret = $db->query($sql, true);
    $out = [];
    if ($ret) {
        while ($row = $db->fetch_array($ret)) {
            if (!empty($row['notice_key'])) {
                $out[$row['notice_key']] = 1;
            }
        }
    }
    return $out;
}
}

if (!function_exists('getEmUserAvatar')) {
function getEmUserAvatar($uid, $mail) {
    $uid = (int)$uid;
    if ($uid > 0) {
        return lumina_get_user_avatar($uid, lumina_opt('default_avatar', lumina_tpl_base() . 'assets/img/tx.png'));
    }
    if (!empty($mail) && function_exists('getGravatar')) {
        $avatar = getGravatar($mail);
        if (!empty($avatar)) {
            return lumina_resolve_avatar($avatar, lumina_opt('default_avatar', lumina_tpl_base() . 'assets/img/tx.png'));
        }
    }
    return lumina_resolve_avatar(lumina_blog_base() . 'admin/views/images/avatar.svg', lumina_opt('default_avatar', lumina_tpl_base() . 'assets/img/tx.png'));
}
}

if (!function_exists('lumina_parse_list')) {
function lumina_parse_list($raw) {
    if (!$raw) {
        return [];
    }
    $parts = preg_split('/[\r\n,]+/', $raw);
    $parts = array_map('trim', $parts);
    $parts = array_filter($parts, function ($v) { return $v !== ''; });
    return array_values($parts);
}
}

if (!function_exists('lumina_parse_media_list')) {
function lumina_parse_media_list($raw) {
    return lumina_parse_list($raw);
}
}

if (!function_exists('lumina_parse_live_photo_map')) {
function lumina_parse_live_photo_map($raw, $photos = []) {
    $map = [];
    $ordered = [];
    $lines = lumina_parse_list($raw);
    foreach ($lines as $line) {
        $parts = explode('|', $line, 2);
        if (count($parts) === 2) {
            $photo = trim((string)$parts[0]);
            $video = trim((string)$parts[1]);
            if ($photo !== '' && $video !== '') {
                $map[$photo] = $video;
            }
        } else {
            $video = trim((string)$line);
            if ($video !== '') {
                $ordered[] = $video;
            }
        }
    }
    if (!empty($ordered) && !empty($photos)) {
        foreach (array_values($photos) as $idx => $photo) {
            $photo = trim((string)$photo);
            if ($photo !== '' && isset($ordered[$idx]) && !isset($map[$photo])) {
                $map[$photo] = $ordered[$idx];
            }
        }
    }
    return $map;
}
}

if (!function_exists('lumina_live_photo_video')) {
function lumina_live_photo_video($photo, $liveMap) {
    $photo = trim((string)$photo);
    if ($photo === '' || empty($liveMap) || !is_array($liveMap)) {
        return '';
    }
    if (isset($liveMap[$photo])) {
        return $liveMap[$photo];
    }
    $photoClean = strtok($photo, '?');
    foreach ($liveMap as $key => $video) {
        if ($key === $photo || strtok($key, '?') === $photoClean) {
            return $video;
        }
    }
    return '';
}
}

if (!function_exists('lumina_parse_music_list')) {
function lumina_parse_music_list($raw, $fallbackTitle = '') {
    $list = [];
    $lines = lumina_parse_list($raw);
    foreach ($lines as $idx => $line) {
        $parts = explode('|', $line);
        $url = isset($parts[0]) ? trim($parts[0]) : '';
        if ($url === '') {
            continue;
        }
        $title = isset($parts[1]) ? trim($parts[1]) : '';
        $artist = isset($parts[2]) ? trim($parts[2]) : '';
        $cover = isset($parts[3]) ? trim($parts[3]) : '';
        if ($title === '' && $idx === 0 && $fallbackTitle !== '') {
            $title = $fallbackTitle;
        }
        $list[] = [
            'url' => $url,
            'title' => $title,
            'artist' => $artist,
            'cover' => $cover,
        ];
    }
    if (empty($list) && trim($raw) !== '') {
        $list[] = [
            'url' => trim($raw),
            'title' => $fallbackTitle,
            'artist' => '',
            'cover' => '',
        ];
    }
    return $list;
}
}

if (!function_exists('lumina_resolve_media_url')) {
function lumina_resolve_media_url($file_path) {
    $file_path = trim((string)$file_path);
    if ($file_path === '') {
        return '';
    }
    if (stripos($file_path, 'content/uploadfile/') !== false) {
        $pos = stripos($file_path, 'content/uploadfile/');
        return lumina_blog_base() . substr($file_path, $pos);
    }
    return lumina_resolve_url($file_path, $file_path);
}
}

if (!function_exists('lumina_get_media_library')) {
function lumina_get_media_library($limit = 60) {
    if (!class_exists('Database')) {
        return [];
    }
    $limit = (int)$limit;
    if ($limit <= 0) {
        $limit = 60;
    }
    $items = [];
    $attachmentTable = DB_PREFIX . 'attachment';
    if (class_exists('Media_Model') && lumina_table_exists($attachmentTable)) {
        $Media_Model = new Media_Model();
        $medias = $Media_Model->getMedias(1, $limit, 0, 0);
        foreach ($medias as $media) {
            if (!is_array($media)) {
                continue;
            }
            $url = isset($media['file_url']) ? $media['file_url'] : '';
            if ($url === '') {
                $filepath = isset($media['filepath']) ? $media['filepath'] : '';
                $url = lumina_resolve_media_url($filepath);
            }
            if ($url === '') {
                continue;
            }
            $thumb = isset($media['thumbnail_url']) && $media['thumbnail_url'] !== '' ? $media['thumbnail_url'] : $url;
            $items[] = [
                'id' => isset($media['aid']) ? (int)$media['aid'] : 0,
                'url' => $url,
                'thumb' => $thumb,
                'mime' => isset($media['mimetype']) ? $media['mimetype'] : '',
                'name' => isset($media['filename']) ? $media['filename'] : basename($url),
            ];
        }
        return $items;
    }

    $table = DB_PREFIX . 'media';
    if (!lumina_table_exists($table)) {
        return $items;
    }
    $cols = lumina_table_columns($table);
    if (empty($cols)) {
        return $items;
    }
    $idCol = in_array('aid', $cols, true) ? 'aid' : (in_array('id', $cols, true) ? 'id' : '');
    $pathCol = in_array('filepath', $cols, true) ? 'filepath' : (in_array('file_path', $cols, true) ? 'file_path' : (in_array('path', $cols, true) ? 'path' : ''));
    $thumbCol = in_array('thum_file', $cols, true) ? 'thum_file' : (in_array('thumb', $cols, true) ? 'thumb' : (in_array('thumb_path', $cols, true) ? 'thumb_path' : ''));
    $mimeCol = in_array('mime_type', $cols, true) ? 'mime_type' : (in_array('mime', $cols, true) ? 'mime' : '');
    $nameCol = in_array('file_name', $cols, true) ? 'file_name' : (in_array('filename', $cols, true) ? 'filename' : (in_array('name', $cols, true) ? 'name' : ''));
    $dateCol = in_array('addtime', $cols, true) ? 'addtime' : (in_array('date', $cols, true) ? 'date' : '');
    if ($pathCol === '') {
        return $items;
    }
    $selectCols = array_filter([$idCol, $pathCol, $thumbCol, $mimeCol, $nameCol, $dateCol]);
    $orderCol = $dateCol ?: ($idCol ?: $pathCol);
    $sql = "SELECT " . implode(',', $selectCols) . " FROM $table ORDER BY $orderCol DESC LIMIT $limit";
    $db = \Pafish\Core\DB;
    $ret = $db->query($sql);
    if ($ret) {
        while ($row = $db->fetch_array($ret)) {
            $path = $row[$pathCol] ?? '';
            $url = lumina_resolve_media_url($path);
            if ($url === '') {
                continue;
            }
            $thumb = '';
            if ($thumbCol && !empty($row[$thumbCol])) {
                $thumb = lumina_resolve_media_url($row[$thumbCol]);
            }
            $mime = $mimeCol && isset($row[$mimeCol]) ? $row[$mimeCol] : '';
            $name = $nameCol && isset($row[$nameCol]) ? $row[$nameCol] : basename($url);
            $items[] = [
                'id' => $idCol && isset($row[$idCol]) ? (int)$row[$idCol] : 0,
                'url' => $url,
                'thumb' => $thumb ?: $url,
                'mime' => $mime,
                'name' => $name,
            ];
        }
    }
    return $items;
}
}

if (!function_exists('lumina_comment_avatar')) {
function lumina_comment_avatar($uid, $mail, $fallback) {
    $uid = (int)$uid;
    if ($uid > 0) {
        return lumina_get_user_avatar($uid, $fallback);
    }
    if (!empty($mail) && function_exists('getGravatar')) {
        $avatar = getGravatar($mail);
        if (!empty($avatar)) {
            return lumina_resolve_avatar($avatar, $fallback);
        }
    }
    return lumina_resolve_avatar($fallback, $fallback);
}
}

if (!function_exists('blog_author')) {
    function blog_author($uid) {
        $uid = (int)$uid;
        if ($uid <= 0) {
            return '';
        }
        $userModel = new User_Model();
        $user = $userModel->getOneUser($uid);
        if (!$user) {
            return '';
        }
        if (!empty($user['name_orig'])) {
            return $user['name_orig'];
        }
        return isset($user['nickname']) ? $user['nickname'] : '';
    }
}

if (!function_exists('blog_tag')) {
    function blog_tag($logid) {
        $logid = (int)$logid;
        if ($logid <= 0) {
            return '';
        }
        if (!class_exists('Log_Model')) {
            return '';
        }
        $Log_Model = new Log_Model();
        if (!method_exists($Log_Model, 'getTag')) {
            return '';
        }
        $tags = $Log_Model->getTag($logid);
        if (empty($tags)) {
            return '';
        }
        $out = [];
        foreach ($tags as $tag) {
            if (is_array($tag) && isset($tag['tagname'])) {
                $name = $tag['tagname'];
                $url = isset($tag['tagurl']) ? $tag['tagurl'] : '';
            } else {
                $name = is_string($tag) ? $tag : '';
                $url = '';
            }
            $name = htmlspecialchars($name);
            if ($name === '') {
                continue;
            }
            if ($url !== '') {
                $out[] = '<a href="' . htmlspecialchars($url) . '">#' . $name . '</a>';
            } else {
                $out[] = '<span>#' . $name . '</span>';
            }
        }
        return implode(' ', $out);
    }
}

if (!function_exists('lumina_render_sort_options')) {
    function lumina_render_sort_options($sorts, $level = 0, $selected = null) {
        if (empty($sorts) || !is_array($sorts)) {
            return;
        }
        foreach ($sorts as $sort) {
            if (!is_array($sort)) {
                continue;
            }
            $sid = isset($sort['sid']) ? (int)$sort['sid'] : (isset($sort['id']) ? (int)$sort['id'] : 0);
            $name = isset($sort['sortname']) ? $sort['sortname'] : (isset($sort['name']) ? $sort['name'] : '');
            if ($sid <= 0 || $name === '') {
                // skip invalid entries
            } else {
                $prefix = $level > 0 ? str_repeat('—', $level) . ' ' : '';
                $sel = ((int)$selected === $sid) ? ' selected' : '';
                echo '<option value="' . $sid . '"' . $sel . '>' . $prefix . htmlspecialchars($name) . '</option>';
            }
            if (!empty($sort['children']) && is_array($sort['children'])) {
                lumina_render_sort_options($sort['children'], $level + 1, $selected);
            }
        }
    }
}

if (!function_exists('blog_sort')) {
    function blog_sort($sortid) {
        $sortid = (int)$sortid;
        if ($sortid <= 0 || !class_exists('Sort_Model')) {
            return '';
        }

        $Sort_Model = new Sort_Model();
        $sort = [];

        if (method_exists($Sort_Model, 'getOneSortById')) {
            $sort = $Sort_Model->getOneSortById($sortid);
        } elseif (method_exists($Sort_Model, 'getOneSort')) {
            $sort = $Sort_Model->getOneSort($sortid);
        }

        if (!$sort || empty($sort['sortname'])) {
            return '';
        }

        $name = htmlspecialchars((string)$sort['sortname'], ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars((string)url_to('/category/' . rawurlencode((string)($sortid))), ENT_QUOTES, 'UTF-8');
        return '<a href="' . $url . '">#' . $name . '</a>';
    }
}

if (!function_exists('blog_comments')) {
    function blog_comments($data, $comnum = 0, $logid = null) {
        if (empty($data) || !is_array($data)) {
            return;
        }
        $comments = $data;
        $stack = [];
        if (isset($data['comments']) && is_array($data['comments'])) {
            $comments = $data['comments'];
            $stack = isset($data['commentStacks']) ? $data['commentStacks'] : [];
        } elseif (isset($data['commentStacks'])) {
            $stack = $data['commentStacks'];
        } else {
            $stack = array_keys($comments);
        }
        if (empty($stack)) {
            return;
        }
        $fallback_avatar = lumina_opt('default_avatar', lumina_tpl_base() . 'assets/img/tx.png');
        if ($fallback_avatar === '') {
            $fallback_avatar = lumina_tpl_base() . 'assets/img/tx.png';
        }
        $list_id = $logid ? ' id="sh-zanp-pl-' . (int)$logid . '"' : '';
        echo '<div class="sh-zanp sh-zanp-pl-ku">';
        echo '<ul class="sh-zanp-pl sh-zanp-pl3"' . $list_id . '>';
        foreach ($stack as $cid) {
            if (!isset($comments[$cid])) {
                continue;
            }
            $comment = $comments[$cid];
            $poster = isset($comment['poster']) ? htmlspecialchars($comment['poster']) : '访客';
            $content = isset($comment['content']) ? $comment['content'] : '';
            $date = isset($comment['date']) ? lumina_format_comment_date($comment['date']) : '';
            $uid = isset($comment['uid']) ? (int)$comment['uid'] : 0;
            $url = isset($comment['url']) ? trim($comment['url']) : '';
            if ($uid > 0) {
                $posterHtml = '<a href="' . htmlspecialchars(url_to('/author/' . rawurlencode((string)($uid)))) . '">' . $poster . '</a>';
            } elseif ($url !== '') {
                $posterHtml = '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">' . $poster . '</a>';
            } else {
                $posterHtml = $poster;
            }
            $avatar = lumina_comment_avatar($uid, isset($comment['mail']) ? $comment['mail'] : '', $fallback_avatar);
            echo '<li>';
            echo '<div class="sh-zanp-pl-tx"><img src="' . htmlspecialchars($avatar) . '" alt="avatar"></div>';
            echo '<div class="sh-zanp-pl-n sh-zanp-pl-n2">';
            echo '<div class="sh-zanp-pl-n-mz">' . $posterHtml . '<span>· ' . $date . '</span></div>';
            echo '<span class="sh-zanp-pl-n-nr">' . $content . '</span>';
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }
}

if (!function_exists('blog_comments_post')) {
    function blog_comments_post($logid, $ckname = '', $ckmail = '', $ckurl = '', $verifyCode = '', $allow_remark = 'y') {
        if ($allow_remark !== 'y') {
            return;
        }
        $isLoginComment = (settings('comments_require_login', 'false') === 'true' ? 'n' : 'y');
        $logid = (int)$logid;
        echo '<div class="sh-pinglunkuang">';
        if ((!is_logged_in()) && $isLoginComment === 'y') {
            echo '<div class="sh-pinglun">';
            echo '<div class="sh-pinglun-s"><textarea rows="2" placeholder="请先登录再评论" disabled></textarea></div>';
            echo '<div class="sh-pinglun-fs"><div class="sh-pinglun-fs-right"><div class="sh-pinglun-fs-right-fs"><span>登录后评论</span></div></div></div>';
            echo '</div>';
            echo '</div>';
            return;
        }
        echo '<form class="sh-pinglun" method="post" action="' . lumina_blog_base() . '?action=addcom">';
        echo '<input type="hidden" name="gid" value="' . $logid . '">';
        echo '<input type="hidden" name="pid" value="0">';
        if ((!is_logged_in()) && $isLoginComment === 'n') {
            echo '<div class="sh-plk-yk" style="display:flex;">';
            echo '<div class="sh-plk-yk-z"><input type="text" name="comname" placeholder="昵称" value="' . htmlspecialchars((string)$ckname) . '" required></div>';
            echo '<div class="sh-plk-yk-zz"><input type="email" name="commail" placeholder="邮箱" value="' . htmlspecialchars((string)$ckmail) . '"></div>';
            echo '<div class="sh-plk-yk-z"><input type="text" name="comurl" placeholder="网站" value="' . htmlspecialchars((string)$ckurl) . '"></div>';
            echo '</div>';
        }
        echo '<div class="sh-pinglun-s"><textarea name="comment" rows="3" placeholder="写下你的评论..."></textarea></div>';
        if (!empty($verifyCode)) {
            echo '<div class="sh-comment-verify">' . $verifyCode . '</div>';
        }
        echo '<div class="sh-pinglun-fs"><div class="sh-pinglun-fs-right">';
        echo '<button type="submit" class="sh-pinglun-fs-right-fs"><span>发送</span></button>';
        echo '</div></div>';
        echo '</form>';
        echo '</div>';
    }
}

if (!function_exists('lumina_render_comment_item_simple')) {
function lumina_render_comment_item_simple($comment, $comments, $logid, $allow_remark, $parent = null, &$count = 0, $limit = 0) {
    if ($limit > 0 && $count >= $limit) {
        return;
    }
    $poster = isset($comment['poster']) ? $comment['poster'] : '';
    $parentName = '';
    if ($parent && isset($parent['poster'])) {
        $parentName = $parent['poster'];
    } elseif (!empty($comment['pid']) && isset($comments[$comment['pid']]['poster'])) {
        $parentName = $comments[$comment['pid']]['poster'];
    }
    $url = isset($comment['url']) ? $comment['url'] : '';
    $mail = isset($comment['mail']) ? $comment['mail'] : '';
    $onclick = $allow_remark === 'y' ? 'onclick="plhuifu()"' : '';
    $linkAttr = $url ? 'href="' . htmlspecialchars($url) . '" style="pointer-events: all;"' : '';
    $posterEsc = htmlspecialchars($poster);
    $parentEsc = htmlspecialchars($parentName);
    $mailEsc = htmlspecialchars($mail);
    $content = isset($comment['content']) ? $comment['content'] : '';
    $content = lumina_parse_emoji($content);
    echo '<li lang="' . $posterEsc . '" ' . $onclick . ' id="' . $logid . '" value="' . $mailEsc . '" data-comkzt="0" data-cid="' . (int)$comment['cid'] . '" data-gid="' . $logid . '" data-name="' . $posterEsc . '" data-email="' . $mailEsc . '">';
    echo '<div class="sh-zanp-pl-n">';
    if ($parentName === '') {
        echo '<a ' . $linkAttr . ' class="sh-zanp-pl-n-nc" onclick="hfljurl()" target="_blank">' . $posterEsc . '</a>：';
        echo '<span class="sh-zanp-pl-n-nr">' . $content . '</span>';
    } else {
        echo '<a ' . $linkAttr . ' class="sh-zanp-pl-n-nc" onclick="hfljurl()" target="_blank">' . $posterEsc . '</a>';
        echo '<span class="sh-zanp-pl-n-reply"> 回复 </span>';
        echo '<span class="sh-zanp-pl-n-nc">' . $parentEsc . '</span>：';
        echo '<span class="sh-zanp-pl-n-nr">' . $content . '</span>';
    }
    echo '</div></li>';
    $count++;
    if ($limit > 0 && $count >= $limit) {
        return;
    }

    if (!empty($comment['children'])) {
        foreach ($comment['children'] as $child) {
            if (isset($comments[$child])) {
                lumina_render_comment_item_simple($comments[$child], $comments, $logid, $allow_remark, $comment, $count, $limit);
                if ($limit > 0 && $count >= $limit) {
                    return;
                }
            }
        }
    }
}
}

if (!function_exists('lumina_render_comments_simple')) {
function lumina_render_comments_simple($comments, $logid, $allow_remark, $limit = 0) {
    $commentStacks = isset($comments['commentStacks']) ? $comments['commentStacks'] : [];
    $commentData = $comments;
    if (isset($comments['comments']) && is_array($comments['comments'])) {
        $commentData = $comments['comments'];
    }
    if (empty($commentStacks)) {
        echo '<ul class="sh-zanp-pl" id="sh-zanp-pl-' . $logid . '" style="display:none;"></ul>';
        return;
    }
    echo '<ul class="sh-zanp-pl" id="sh-zanp-pl-' . $logid . '">';
    $count = 0;
    foreach ($commentStacks as $cid) {
        if ($limit > 0 && $count >= $limit) {
            break;
        }
        if (isset($commentData[$cid])) {
            lumina_render_comment_item_simple($commentData[$cid], $commentData, $logid, $allow_remark, null, $count, $limit);
        }
    }
    echo '</ul>';
}
}

if (!function_exists('lumina_render_comment_item_detail')) {
function lumina_render_comment_item_detail($comment, $comments, $logid, $allow_remark, $parent = null) {
    $poster = isset($comment['poster']) ? $comment['poster'] : '';
    $parentName = '';
    if ($parent && isset($parent['poster'])) {
        $parentName = $parent['poster'];
    } elseif (!empty($comment['pid']) && isset($comments[$comment['pid']]['poster'])) {
        $parentName = $comments[$comment['pid']]['poster'];
    }
    $url = isset($comment['url']) ? $comment['url'] : '';
    $mail = isset($comment['mail']) ? $comment['mail'] : '';
    $onclick = $allow_remark === 'y' ? 'onclick="plhuifu()"' : '';
    $linkAttr = $url ? 'href="' . htmlspecialchars($url) . '" style="pointer-events: all;"' : '';
    $posterEsc = htmlspecialchars($poster);
    $parentEsc = htmlspecialchars($parentName);
    $mailEsc = htmlspecialchars($mail);
    $content = isset($comment['content']) ? $comment['content'] : '';
    $content = lumina_parse_emoji($content);
    $avatar = getEmUserAvatar(isset($comment['uid']) ? $comment['uid'] : 0, $mail);
    $date = isset($comment['date']) ? lumina_format_comment_date($comment['date']) : '';
    echo '<li lang="' . $posterEsc . '" ' . $onclick . ' id="' . $logid . '" value="' . $mailEsc . '" data-comkzt="0" data-cid="' . (int)$comment['cid'] . '" data-gid="' . $logid . '" data-name="' . $posterEsc . '" data-email="' . $mailEsc . '">';
    echo '<div class="sh-zanp-pl-tx"><img src="' . htmlspecialchars($avatar) . '" alt="avatar"></div>';
    echo '<div class="sh-zanp-pl-n sh-zanp-pl-n2">';
    echo '<div class="sh-zanp-pl-n-mz"><a ' . $linkAttr . ' class="sh-zanp-pl-n-nc" onclick="hfljurl()" target="_blank">' . $posterEsc . '</a> <span>· ' . $date . '</span></div>';
    if ($parentName === '') {
        echo '<span class="sh-zanp-pl-n-nr">' . $content . '</span>';
    } else {
        echo '<span class="sh-zanp-pl-n-reply"> 回复 </span>';
        echo '<span class="sh-zanp-pl-n-nc">' . $parentEsc . '</span>：';
        echo '<span class="sh-zanp-pl-n-nr">' . $content . '</span>';
    }
    echo '</div></li>';

    if (!empty($comment['children'])) {
        foreach ($comment['children'] as $child) {
            if (isset($comments[$child])) {
                lumina_render_comment_item_detail($comments[$child], $comments, $logid, $allow_remark, $comment);
            }
        }
    }
}
}

if (!function_exists('lumina_render_comments_detail')) {
function lumina_render_comments_detail($comments, $logid, $allow_remark) {
    $commentStacks = isset($comments['commentStacks']) ? $comments['commentStacks'] : [];
    $commentData = $comments;
    if (isset($comments['comments']) && is_array($comments['comments'])) {
        $commentData = $comments['comments'];
    }
    if (empty($commentStacks)) {
        echo '<div class="sh-dz-z" style="display:none;" id="sh-dz-z-' . $logid . '">';
        echo '<div class="sh-zanp-zan-left sh-zanp-zan-left2"><i class="iconfont icon-pinglun2 ri-sxdzcommls"></i></div>';
        echo '<ul class="sh-zanp-pl sh-zanp-pl2 sh-zanp-pl3" id="sh-zanp-pl-' . $logid . '" style="display:none;"></ul>';
        echo '</div>';
        return;
    }
    echo '<div class="sh-dz-z" id="sh-dz-z-' . $logid . '">';
    echo '<div class="sh-zanp-zan-left sh-zanp-zan-left2"><i class="iconfont icon-pinglun2 ri-sxdzcommls"></i></div>';
    echo '<ul class="sh-zanp-pl sh-zanp-pl2 sh-zanp-pl3" id="sh-zanp-pl-' . $logid . '">';
    foreach ($commentStacks as $cid) {
        if (isset($commentData[$cid])) {
            lumina_render_comment_item_detail($commentData[$cid], $commentData, $logid, $allow_remark);
        }
    }
    echo '</ul></div>';
}
}

if (!function_exists('lumina_extract_images')) {
function lumina_extract_images($html) {
    if (!$html) {
        return [];
    }
    $imgs = [];
    // src
    if (preg_match_all('/<img[^>]+src=["\\\']?([^"\\\'>\\s]+)/i', $html, $matches)) {
        foreach ($matches[1] as $src) {
            $src = htmlspecialchars_decode($src, ENT_QUOTES);
            if (stripos($src, 'data:') === 0) {
                continue;
            }
            $srcLower = strtolower($src);
            if (strpos($srcLower, '/assets/owo/') !== false || strpos($srcLower, '/owo/paopao/') !== false) {
                continue;
            }
            $imgs[] = $src;
        }
    }
    // common lazy attributes
    if (preg_match_all('/<img[^>]+(?:data-src|data-original|data-lazy|data-file)=["\\\']?([^"\\\'>\\s]+)/i', $html, $lazyMatches)) {
        foreach ($lazyMatches[1] as $src) {
            $src = htmlspecialchars_decode($src, ENT_QUOTES);
            if (stripos($src, 'data:') === 0) {
                continue;
            }
            $srcLower = strtolower($src);
            if (strpos($srcLower, '/assets/owo/') !== false || strpos($srcLower, '/owo/paopao/') !== false) {
                continue;
            }
            $imgs[] = $src;
        }
    }
    // bbcode style [img]url[/img]
    if (preg_match_all('/\\[img\\]([^\\[]+)\\[\\/img\\]/i', $html, $bbMatches)) {
        foreach ($bbMatches[1] as $src) {
            $src = trim($src);
            if ($src === '' || stripos($src, 'data:') === 0) {
                continue;
            }
            $srcLower = strtolower($src);
            if (strpos($srcLower, '/assets/owo/') !== false || strpos($srcLower, '/owo/paopao/') !== false) {
                continue;
            }
            $imgs[] = $src;
        }
    }
    // raw image urls
    if (preg_match_all('/(?:https?:\\/\\/|\\/)[^"\\\'\\s<>]+\\.(?:png|jpe?g|gif|webp|bmp|svg)(?:\\?[^"\\\'\\s<>]*)?/i', $html, $urlMatches)) {
        foreach ($urlMatches[0] as $src) {
            $src = trim($src);
            if ($src === '' || stripos($src, 'data:') === 0) {
                continue;
            }
            $srcLower = strtolower($src);
            if (strpos($srcLower, '/assets/owo/') !== false || strpos($srcLower, '/owo/paopao/') !== false) {
                continue;
            }
            $imgs[] = $src;
        }
    }
    if (preg_match_all('/!\\[[^\\]]*\\]\\(([^\\)]+)\\)/', $html, $mdMatches)) {
        foreach ($mdMatches[1] as $src) {
            $src = trim($src);
            if ($src === '' || stripos($src, 'data:') === 0) {
                continue;
            }
            $srcLower = strtolower($src);
            if (strpos($srcLower, '/assets/owo/') !== false || strpos($srcLower, '/owo/paopao/') !== false) {
                continue;
            }
            $imgs[] = $src;
        }
    }
    return array_values($imgs);
}
}

if (!function_exists('lumina_extract_videos')) {
function lumina_extract_videos($html) {
    if (!$html) {
        return [];
    }
    $videos = [];
    if (preg_match_all('/<video[^>]+src=["\\\']?([^"\\\'>\\s]+)["\\\']?/i', $html, $matches)) {
        foreach ($matches[1] as $src) {
            $src = htmlspecialchars_decode($src, ENT_QUOTES);
            $videos[] = $src;
        }
    }
    if (preg_match_all('/<source[^>]+src=["\\\']?([^"\\\'>\\s]+)["\\\']?/i', $html, $matches2)) {
        foreach ($matches2[1] as $src) {
            $src = htmlspecialchars_decode($src, ENT_QUOTES);
            $videos[] = $src;
        }
    }
    if (preg_match_all('/\\(([^\\)]+\\.(?:mp4|webm|ogg)(?:\\?[^\\)]*)?)\\)/i', $html, $matches3)) {
        foreach ($matches3[1] as $src) {
            $src = trim($src);
            $videos[] = $src;
        }
    }
    if (preg_match_all('/(?:https?:\\/\\/|\\/)[^"\'\\s<>]+\\.(?:mp4|webm|ogg)(?:\\?[^"\'\\s<>]*)?/i', $html, $matches4)) {
        foreach ($matches4[0] as $src) {
            $videos[] = $src;
        }
    }
    $videos = array_values(array_unique(array_filter($videos)));
    return $videos;
}
}

if (!function_exists('topflg')) {
function topflg($top) {
    if ($top === 'y' || $top === '1' || $top === 1) {
        echo '<span class="sh-top-flag">置顶</span>';
    }
}
}
?>
