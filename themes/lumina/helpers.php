<?php

declare(strict_types=1);

if (!function_exists('lumina_asset_url')) {
    function lumina_asset_url(string $path): string
    {
        $path = ltrim($path, '/');
        $file = __DIR__ . '/assets/' . $path;
        $url = url_to('/theme-assets/lumina/' . $path);
        return is_file($file) ? $url . '?v=' . (string) filemtime($file) : $url;
    }
}

if (!function_exists('lumina_theme_image')) {
    function lumina_theme_image(string $key, string $fallback): string
    {
        $value = trim(theme_value($key));
        return $value !== '' ? $value : $fallback;
    }
}

if (!function_exists('lumina_post_link')) {
    function lumina_post_link(array $post): string
    {
        $external = trim((string) ($post['external_url'] ?? ''));
        return $external !== '' ? $external : url_to('/post/' . rawurlencode((string) ($post['slug'] ?? '')));
    }
}

if (!function_exists('lumina_fields')) {
    /** 将 pafish 的 [{key,value}] 自定义字段转为便于主题消费的键值表。 */
    function lumina_fields(array|string|null $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $fields = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            $key = trim((string) ($item['key'] ?? ''));
            if ($key !== '' && !array_key_exists($key, $fields)) {
                $fields[$key] = trim((string) ($item['value'] ?? ''));
            }
        }
        return $fields;
    }
}

if (!function_exists('lumina_safe_url')) {
    /** 仅允许站内绝对路径或 HTTP(S) 地址，阻止 javascript/data 等注入。 */
    function lumina_safe_url(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }
        if (str_starts_with($url, '/')) {
            return $url;
        }
        $parts = parse_url($url);
        return is_array($parts) && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            ? $url
            : '';
    }
}

if (!function_exists('lumina_url_list')) {
    function lumina_url_list(string $value): array
    {
        $values = preg_split('/[\r\n,，]+/', $value) ?: [];
        $urls = [];
        foreach ($values as $item) {
            $url = lumina_safe_url($item);
            if ($url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }
        return $urls;
    }
}

if (!function_exists('lumina_embed_url')) {
    /** 将 Lumina 支持的平台链接转为安全播放器地址。 */
    function lumina_embed_url(string $raw): string
    {
        if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $raw, $match)) {
            $raw = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        }
        $url = lumina_safe_url($raw);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host === 'youtu.be' && trim($path, '/') !== '') {
            return 'https://www.youtube-nocookie.com/embed/' . rawurlencode(trim($path, '/'));
        }
        if (in_array($host, ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com'], true)) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $id = $query['v'] ?? (str_starts_with($path, '/embed/') ? substr($path, 7) : '');
            return $id !== '' ? 'https://www.youtube-nocookie.com/embed/' . rawurlencode((string) $id) : '';
        }
        if (in_array($host, ['www.bilibili.com', 'bilibili.com'], true)
            && preg_match('~/(video/(BV[[:alnum:]]+)|bangumi/play/ep([0-9]+))~i', $path, $match)) {
            $query = isset($match[3]) ? 'ep_id=' . rawurlencode($match[3]) : 'bvid=' . rawurlencode($match[2]);
            return 'https://player.bilibili.com/player.html?' . $query . '&high_quality=1&danmaku=0';
        }
        $allowed = ['player.bilibili.com', 'open.weixin.qq.com', 'v.qq.com', 'www.iqiyi.com', 'player.youku.com'];
        return in_array($host, $allowed, true) ? $url : '';
    }
}

if (!function_exists('lumina_media')) {
    /** 返回模板所需的统一媒体模型，未知值安全降级为普通文章。 */
    function lumina_media(array $post): array
    {
        $fields = lumina_fields($post['custom_fields'] ?? []);
        $type = $fields['lumina_type'] ?? 'only';
        $supported = ['only', 'img', 'live', 'video', 'embed', 'music', 'redpacket'];
        if (!in_array($type, $supported, true)) {
            $type = 'only';
        }
        $photos = lumina_url_list($fields['lumina_photos'] ?? '');
        $live = [];
        foreach (preg_split('/[\r\n]+/', $fields['lumina_live_photos'] ?? '') ?: [] as $index => $line) {
            $pair = array_map('trim', explode('|', $line, 2));
            if (count($pair) === 2) {
                $image = lumina_safe_url($pair[0]);
                $video = lumina_safe_url($pair[1]);
                if ($image !== '' && $video !== '') {
                    $live[$image] = $video;
                }
            } elseif (isset($photos[$index]) && ($video = lumina_safe_url($line)) !== '') {
                $live[$photos[$index]] = $video;
            }
        }
        // 字段不完整时沿用文章封面，避免编辑器只填了类型就让旧内容消失。
        if (($type === 'img' || $type === 'live') && $photos === []) {
            $type = 'only';
        } elseif ($type === 'video' && lumina_safe_url($fields['lumina_video_url'] ?? '') === '') {
            $type = 'only';
        } elseif ($type === 'embed' && lumina_embed_url($fields['lumina_embed_url'] ?? '') === '') {
            $type = 'only';
        } elseif ($type === 'music' && lumina_safe_url($fields['lumina_music_url'] ?? '') === '') {
            $type = 'only';
        }
        return [
            'fields' => $fields,
            'type' => $type,
            'photos' => $photos,
            'live' => $live,
            'video' => lumina_safe_url($fields['lumina_video_url'] ?? ''),
            'poster' => lumina_safe_url($fields['lumina_video_poster'] ?? ''),
            'embed' => lumina_embed_url($fields['lumina_embed_url'] ?? ''),
            'ratio' => ($fields['lumina_embed_ratio'] ?? 'lr') === 'tb' ? 'tb' : 'lr',
            'music' => lumina_safe_url($fields['lumina_music_url'] ?? ''),
            'musicCover' => lumina_safe_url($fields['lumina_music_cover'] ?? ''),
            'musicTitle' => $fields['lumina_music_title'] ?? '',
            'musicArtist' => $fields['lumina_music_artist'] ?? '',
            'location' => $fields['lumina_location'] ?? '',
            'locationAddress' => $fields['lumina_location_address'] ?? '',
            'locationCity' => $fields['lumina_location_city'] ?? '',
            'locationPoiId' => $fields['lumina_location_poi_id'] ?? '',
            'latitude' => $fields['lumina_location_lat'] ?? '',
            'longitude' => $fields['lumina_location_lng'] ?? '',
            'private' => ($fields['lumina_private'] ?? '') === 'y',
            'redpacketTitle' => $fields['redpacket_title'] ?? '',
            'redpacketTotal' => $fields['redpacket_total'] ?? '',
            'redpacketCount' => $fields['redpacket_count'] ?? '',
        ];
    }
}

if (!function_exists('lumina_location_link')) {
    function lumina_location_link(array $media): string
    {
        if ($media['latitude'] === '' || $media['longitude'] === '') {
            return '';
        }
        return 'https://map.qq.com/?type=marker&isopeninfowin=1&point='
            . rawurlencode($media['latitude'] . ',' . $media['longitude'])
            . '&name=' . rawurlencode($media['location']);
    }
}

if (!function_exists('lumina_render_media')) {
    function lumina_render_media(array $media, int $postId, string $context = 'feed'): string
    {
        ob_start();
        if (in_array($media['type'], ['img', 'live'], true) && $media['photos'] !== []): ?>
          <div class="lumina-gallery lumina-gallery-<?= min(9, count($media['photos'])) ?>" data-lumina-gallery>
            <?php foreach ($media['photos'] as $index => $photo): $liveVideo = $media['live'][$photo] ?? ''; ?>
              <button type="button" class="lumina-gallery-item<?= $liveVideo !== '' ? ' is-live' : '' ?>" data-lumina-image="<?= e($photo) ?>" aria-label="查看第 <?= $index + 1 ?> 张图片">
                <img src="<?= e($photo) ?>" alt="" loading="lazy">
                <?php if ($liveVideo !== ''): ?><video src="<?= e($liveVideo) ?>" muted loop playsinline preload="metadata"></video><span>LIVE</span><?php endif; ?>
                <?php if ($index === 8 && count($media['photos']) > 9): ?><b>+<?= count($media['photos']) - 9 ?></b><?php endif; ?>
              </button>
              <?php if ($index === 8) { break; } ?>
            <?php endforeach; ?>
          </div>
        <?php elseif ($media['type'] === 'video' && $media['video'] !== ''): ?>
          <div class="lumina-video"><video src="<?= e($media['video']) ?>"<?= $media['poster'] !== '' ? ' poster="' . e($media['poster']) . '"' : '' ?> controls playsinline preload="metadata"></video></div>
        <?php elseif ($media['type'] === 'embed' && $media['embed'] !== ''): ?>
          <div class="lumina-embed lumina-embed-<?= e($media['ratio']) ?>"><iframe src="<?= e($media['embed']) ?>" title="嵌入视频" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>
        <?php elseif ($media['type'] === 'music' && $media['music'] !== ''): ?>
          <div class="lumina-music-card" data-lumina-music>
            <?php if ($media['musicCover'] !== ''): ?><img src="<?= e($media['musicCover']) ?>" alt="" loading="lazy"><?php else: ?><span class="lumina-music-placeholder"><?= admin_icon('file-audio', 22) ?></span><?php endif; ?>
            <div><strong><?= e($media['musicTitle'] !== '' ? $media['musicTitle'] : '未命名音乐') ?></strong><small><?= e($media['musicArtist']) ?></small></div>
            <button type="button" aria-label="播放或暂停"><?= admin_icon('play', 18) ?></button><audio src="<?= e($media['music']) ?>" preload="metadata"></audio>
          </div>
        <?php elseif ($media['type'] === 'redpacket'): ?>
          <?php
          $viewerId = (int) (current_user()['id'] ?? 0);
          $packet = \Pafish\Services\RedPacket::state($postId, $viewerId ?: null);
          $title = (string) ($packet['title'] ?? ($media['redpacketTitle'] !== '' ? $media['redpacketTitle'] : '恭喜发财，大吉大利'));
          $summary = !empty($packet['available']) && isset($packet['remaining_count'])
              ? (int) $packet['remaining_points'] . ' 积分 · 剩余 ' . (int) $packet['remaining_count'] . ' 份'
              : ($media['redpacketTotal'] !== '' ? $media['redpacketTotal'] . ' 积分 · ' : '') . ($media['redpacketCount'] !== '' ? $media['redpacketCount'] . ' 份' : '积分红包');
          ?>
          <div class="lumina-redpacket" data-lumina-redpacket data-post-id="<?= $postId ?>" data-csrf="<?= e(csrf_token()) ?>">
            <span><?= admin_icon('heart', 22) ?></span><div><strong><?= e($title) ?></strong><small data-lumina-redpacket-summary><?= e($summary) ?></small></div>
            <?php if (empty($packet['available'])): ?><em>待启用</em>
            <?php elseif (($packet['status'] ?? '') === 'MISSING'): ?><em>待发布</em>
            <?php elseif ($viewerId === 0): ?><a href="<?= e(url_to('/login') . (\Pafish\Core\Config::get('pretty_urls', true) ? '?' : '&') . 'from=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/')) ?>">登录领取</a>
            <?php elseif (!empty($packet['claimed'])): ?><em>已领取 <?= (int) $packet['claimed_amount'] ?> 积分</em>
            <?php elseif ((int) ($packet['creator_id'] ?? 0) === $viewerId): ?><em>作者红包</em>
            <?php elseif (($packet['status'] ?? '') !== 'OPEN'): ?><em>已领完</em>
            <?php else: ?><button type="button" data-lumina-redpacket-claim>领取</button><?php endif; ?>
          </div>
        <?php endif;
        return (string) ob_get_clean();
    }
}
