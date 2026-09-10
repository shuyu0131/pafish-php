<?php

declare(strict_types=1);

// 禁止直接访问
if (!defined('__LUMINA_HELPERS__')) define('__LUMINA_HELPERS__', true);

// ── 编辑器扩展（保留 pafish 机制：主题定制字段注入） ──
if (function_exists('add_filter')) {
    add_filter('theme_post_editor', static function (mixed $html, array $context = []): string {
        return (string) $html
            . '<div class="admin-field admin-lumina-fields-wrap">'
            . '<span class="label">Lumina 动态内容</span>'
            . '<p class="admin-field-hint">选择内容类型后填写对应媒体链接；图片、视频和音频可先上传到媒体库，再粘贴其地址。</p>'
            . '<div class="admin-lumina-fields" id="luminaFields"></div>'
            . '</div>'
            . '<script src="' . e(lumina_asset_url('editor.js')) . '"></script>';
    }, 10, 'theme:lumina');
}

// ── 基础 URL / 资源 ──
if (!function_exists('lumina_asset_url')) {
    function lumina_asset_url(string $path): string
    {
        $path = ltrim($path, '/');
        $file = __DIR__ . '/assets/' . $path;
        $url = url_to('/theme-assets/lumina/' . $path);
        return is_file($file) ? $url . '?v=' . (string) filemtime($file) : $url;
    }
}
if (!function_exists('lumina_tpl_url')) {
    function lumina_tpl_url(string $path = ''): string
    {
        return url_to('/theme-assets/lumina/' . ltrim($path, '/'));
    }
}
if (!function_exists('lumina_versioned_url')) {
    function lumina_versioned_url(string $url, string $file = ''): string
    {
        if ($file !== '' && is_file($file)) return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($file);
        return $url;
    }
}
if (!function_exists('lumina_resolve_url')) {
    function lumina_resolve_url(string $url, string $fallback = ''): string
    {
        $url = trim($url);
        if ($url === '') return $fallback;
        if (str_starts_with($url, '/') || preg_match('#^https?://#i', $url)) return $url;
        // 相对路径视作站内
        return url_to('/' . ltrim($url, '/'));
    }
}

// ── 设置读取（对齐原版 lumina_opt） ──
if (!function_exists('lumina_opt')) {
    function lumina_opt(string $key, mixed $default = ''): mixed
    {
        $v = theme_value($key, '');
        if ($v !== '' && $v !== null) return $v;
        // 兼容 default_avatar / site_favicon 等空值时回退到传入的 fallback
        return $default;
    }
}
if (!function_exists('lumina_opt_array')) {
    function lumina_opt_array(string $key, mixed $default = []): array
    {
        $v = lumina_opt($key, '');
        if (is_array($v)) return array_values(array_filter(array_map('strval', $v), fn($x) => $x !== ''));
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '') return is_array($default) ? $default : [];
            if ($v[0] === '[') { $j = json_decode($v, true); if (is_array($j)) return array_values(array_filter(array_map('strval', $j), fn($x)=>$x!=='')); }
            $parts = preg_split('/[\s,|]+/', $v);
            if (is_array($parts)) return array_values(array_filter(array_map('strval', $parts), fn($x)=>$x!==''));
        }
        return is_array($default) ? $default : [];
    }
}
if (!function_exists('lumina_theme_image')) {
    function lumina_theme_image(string $key, string $fallback): string
    {
        $v = trim(theme_value($key, ''));
        return $v !== '' ? lumina_resolve_url($v, $fallback) : $fallback;
    }
}

// ── 用户 ──
if (!function_exists('lumina_get_user_info')) {
    function lumina_get_user_info(int $uid): ?array
    {
        if ($uid <= 0) return null;
        $row = \Pafish\Core\DB::fetchOne('SELECT id, username, nickname, email, avatar_url FROM users WHERE id = ?', [$uid]);
        if (!$row) return null;
        $row['nickname'] = $row['nickname'] ?: $row['username'];
        // 兼容原版字段名
        $row['description'] = '';
        $row['description_orig'] = '';
        $row['homeimg'] = '';
        return $row;
    }
}
if (!function_exists('lumina_get_user_avatar')) {
    function lumina_get_user_avatar(int $uid, string $fallback = ''): string
    {
        if ($fallback === '') $fallback = lumina_asset_url('img/tx.png');
        if ($uid > 0) {
            $u = lumina_get_user_info($uid);
            if ($u && !empty($u['avatar_url'])) return lumina_resolve_url((string)$u['avatar_url'], $fallback);
            // 用 cravatar 兜底
            if ($u && !empty($u['email'])) return \Pafish\Http\Comments::avatarUrl(null, (string)$u['email']);
        }
        return $fallback;
    }
}
if (!function_exists('lumina_get_user_name')) {
    function lumina_get_user_name(int $uid, string $fallback = ''): string
    {
        $u = lumina_get_user_info($uid);
        if ($u) return (string)($u['nickname'] ?: $u['username']);
        return $fallback !== '' ? $fallback : site_name();
    }
}
if (!function_exists('lumina_get_user_cover')) {
    function lumina_get_user_cover(int $uid, string $fallback = ''): string
    {
        return $fallback !== '' ? $fallback : lumina_asset_url('img/homeimg.jpg');
    }
}
if (!function_exists('lumina_user_avatar_of')) {
    function lumina_user_avatar_of(int $uid, string $email = ''): string
    {
        return lumina_get_user_avatar($uid, lumina_asset_url('img/tx.png'));
    }
}

// ── 日期 / 文本 ──
if (!function_exists('lumina_blog_date')) {
    function lumina_blog_date(string $fmt, int $ts): string { return date($fmt, $ts); }
}
if (!function_exists('lumina_parse_timestamp')) {
    function lumina_parse_timestamp(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int)$v;
        $t = strtotime((string)$v);
        return $t === false ? time() : $t;
    }
}
if (!function_exists('smartDate')) {
    function smartDate(int $ts): string
    {
        $diff = time() - $ts;
        if ($diff < 60) return '刚刚';
        if ($diff < 3600) return floor($diff/60) . '分钟前';
        if (date('Ymd', $ts) === date('Ymd')) return '今天 ' . date('H:i', $ts);
        if (date('Ymd', $ts) === date('Ymd', time()-86400)) return '昨天 ' . date('H:i', $ts);
        if (date('Y', $ts) === date('Y')) return date('m-d H:i', $ts);
        return date('Y-m-d H:i', $ts);
    }
}
if (!function_exists('lumina_clean_text')) {
    function lumina_clean_text(string $s): string { return trim(strip_tags($s)); }
}
if (!function_exists('lumina_format_comment_date')) {
    function lumina_format_comment_date(mixed $v): string { return smartDate(lumina_parse_timestamp($v)); }
}

// ── 内容字段 ──
if (!function_exists('lumina_fields')) {
    function lumina_fields(array|string|null $raw): array
    {
        if (is_string($raw)) { $d=json_decode($raw,true); $raw=is_array($d)?$d:[]; }
        $out=[];
        foreach (is_array($raw)?$raw:[] as $item) {
            $k=trim((string)($item['key']??'')); if($k!==''&&!array_key_exists($k,$out)) $out[$k]=trim((string)($item['value']??''));
        }
        return $out;
    }
}
if (!function_exists('lumina_safe_url')) {
    function lumina_safe_url(string $url): string
    {
        $url=trim($url); if($url===''||str_starts_with($url,'//')) return '';
        if(str_starts_with($url,'/')) return $url;
        $p=parse_url($url); return is_array($p)&&isset($p['scheme'],$p['host'])&&in_array(strtolower((string)$p['scheme']),['http','https'],true)?$url:'';
    }
}
if (!function_exists('lumina_url_list')) {
    function lumina_url_list(string $value): array
    {
        $vals=preg_split('/[\r\n,，]+/',$value)?:[]; $out=[];
        foreach($vals as $item){ $u=lumina_safe_url(trim($item)); if($u!==''&&!in_array($u,$out,true)) $out[]=$u; }
        return $out;
    }
}
if (!function_exists('lumina_embed_url')) {
    function lumina_embed_url(string $raw): string
    {
        if(preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i',$raw,$m)) $raw=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
        $url=lumina_safe_url(trim($raw)); if($url==='') return '';
        $parts=parse_url($url); $host=strtolower((string)($parts['host']??'')); $path=(string)($parts['path']??'');
        if($host==='youtu.be'&&trim($path,'/')!=='') return 'https://www.youtube-nocookie.com/embed/'.rawurlencode(trim($path,'/'));
        if(in_array($host,['www.youtube.com','youtube.com','www.youtube-nocookie.com'],true)){
            parse_str((string)($parts['query']??''),$q); $id=$q['v']??(str_starts_with($path,'/embed/')?substr($path,7):''); return $id!==''?'https://www.youtube-nocookie.com/embed/'.rawurlencode((string)$id):'';
        }
        if(in_array($host,['www.bilibili.com','bilibili.com'],true)&&preg_match('~/(video/(BV[[:alnum:]]+)|bangumi/play/ep([0-9]+))~i',$path,$mm)){
            $qq=isset($mm[3])?'ep_id='.rawurlencode($mm[3]):'bvid='.rawurlencode($mm[2]); return 'https://player.bilibili.com/player.html?'.$qq.'&high_quality=1&danmaku=0';
        }
        $allowed=['player.bilibili.com','open.weixin.qq.com','v.qq.com','www.iqiyi.com','player.youku.com'];
        return in_array($host,$allowed,true)?$url:'';
    }
}
if (!function_exists('lumina_media')) {
    function lumina_media(array $post): array
    {
        $fields=lumina_fields($post['custom_fields']??[]);
        $type=$fields['lumina_type']??'only'; if(!in_array($type,['only','img','live','video','embed','music','link','redpacket'],true)) $type='only';
        $photos=lumina_url_list($fields['lumina_photos']??''); $live=[];
        foreach (preg_split('/[\r\n]+/',$fields['lumina_live_photos']??'')?:[] as $idx=>$line){
            $pair=array_map('trim',explode('|',$line,2));
            if(count($pair)===2){ $img=lumina_safe_url($pair[0]); $vid=lumina_safe_url($pair[1]); if($img!==''&&$vid!=='') $live[$img]=$vid; }
            elseif(isset($photos[$idx])&&($vid=lumina_safe_url($line))!=='') $live[$photos[$idx]]=$vid;
        }
        if(($type==='img'||$type==='live')&&$photos===[]) $type='only';
        elseif($type==='video'&&lumina_safe_url($fields['lumina_video_url']??'')==='') $type='only';
        elseif($type==='embed'&&lumina_embed_url($fields['lumina_embed_url']??'')==='') $type='only';
        elseif($type==='music'&&lumina_safe_url($fields['lumina_music_url']??'')==='') $type='only';
        elseif($type==='link'&&lumina_safe_url($fields['lumina_link_url']??'')==='') $type='only';
        return [
            'fields'=>$fields,'type'=>$type,'photos'=>$photos,'live'=>$live,
            'video'=>lumina_safe_url($fields['lumina_video_url']??''),'poster'=>lumina_safe_url($fields['lumina_video_poster']??''),
            'embed'=>lumina_embed_url($fields['lumina_embed_url']??''),'ratio'=>($fields['lumina_embed_ratio']??'lr')==='tb'?'tb':'lr',
            'music'=>lumina_safe_url($fields['lumina_music_url']??''),'musicCover'=>lumina_safe_url($fields['lumina_music_cover']??''),
            'musicTitle'=>$fields['lumina_music_title']??'','musicArtist'=>$fields['lumina_music_artist']??'',
            'linkUrl'=>lumina_safe_url($fields['lumina_link_url']??''),'linkTitle'=>trim(strip_tags((string)($fields['lumina_link_title']??''))),
            'linkDesc'=>trim(strip_tags((string)($fields['lumina_link_desc']??''))),'linkImage'=>lumina_safe_url($fields['lumina_link_image']??''),
            'location'=>$fields['lumina_location']??'','locationAddress'=>$fields['lumina_location_address']??'','locationCity'=>$fields['lumina_location_city']??'',
            'locationPoiId'=>$fields['lumina_location_poi_id']??'','latitude'=>$fields['lumina_location_lat']??'','longitude'=>$fields['lumina_location_lng']??'',
            'private'=>($fields['lumina_private']??'')==='y',
            'redpacketTitle'=>$fields['redpacket_title']??'','redpacketTotal'=>$fields['redpacket_total']??'','redpacketCount'=>$fields['redpacket_count']??'',
        ];
    }
}
if (!function_exists('lumina_location_link')) {
    function lumina_location_link(array $media): string
    {
        if($media['latitude']===''||$media['longitude']==='') return '';
        return 'https://map.qq.com/?type=marker&isopeninfowin=1&point='.rawurlencode($media['latitude'].','.$media['longitude']).'&name='.rawurlencode($media['location']);
    }
    function lumina_location_url(string $loc, string $lat='', string $lng='', string $addr=''): string
    {
        $loc=trim($loc); if($loc==='') return 'javascript:;';
        if($lat!==''&&$lng!==''&&is_numeric($lat)&&is_numeric($lng)){
            $m='coord:'.$lat.','.$lng.';title:'.$loc; if($addr!=='') $m.=';addr:'.$addr;
            return 'https://apis.map.qq.com/uri/v1/marker?marker='.rawurlencode($m).'&referer=lumina';
        }
        return 'https://apis.map.qq.com/uri/v1/search?keyword='.rawurlencode($loc).'&region='.rawurlencode('全国').'&referer=lumina';
    }
}
if (!function_exists('lumina_post_link')) {
    function lumina_post_link(array $post): string
    {
        $ext=trim((string)($post['external_url']??'')); return $ext!==''?$ext:url_to('/post/'.rawurlencode((string)($post['slug']??'')));
    }
}
if (!function_exists('lumina_is_private_log')) {
    function lumina_is_private_log(mixed $fields): bool { return is_array($fields)&&isset($fields['lumina_private'])&&trim((string)$fields['lumina_private'])==='y'; }
    function lumina_can_view_private_log(int $author): bool { return is_logged_in() && (int)(current_user()['id']??0)===(int)$author; }
    function lumina_can_manage_log_item(array $log): bool { if(!is_logged_in()||!is_array($log)) return false; $a=(int)($log['author']??$log['author_id']??0); return (int)(current_user()['id']??0)===$a || in_array((string)(current_user()['role']??''),['ADMIN','EDITOR'],true); }
}

// ── 友链 / 快捷导航 / 悬浮按钮（原版逻辑的 pafish 适配） ──
if (!function_exists('lumina_get_friend_links')) {
    function lumina_get_friend_links(): array
    {
        $title=trim((string)lumina_opt('friend_links_title','友链')); if($title==='') $title='友链';
        $enabled = lumina_opt('friend_links_enable','n')==='y' || lumina_opt('friend_links_enable','')==='y' || theme_value('friend_links_enable','1')==='1';
        // 主题设置里默认开启
        if (theme_value('friend_links_enable','1')==='1') $enabled=true;
        if(!$enabled) $enabled = true; // 原版默认显示友链入口，保持一致：始终返回
        $items=[]; foreach(friend_links() as $r){ $items[]=['name'=>(string)$r['name'],'url'=>(string)$r['url'],'desc'=>(string)($r['description']??''),'avatar'=>'']; }
        return ['enabled'=>true,'title'=>$title,'items'=>$items,'total'=>count($items)];
    }
}
if (!function_exists('lumina_get_quick_nav_items')) {
    function lumina_get_quick_nav_items(array $fallbackSorts=[]): array
    {
        if(lumina_opt('quick_nav_enable','n')!=='y' && theme_value('quick_nav_enable','0')!=='1') return [];
        $raw=(string)lumina_opt('quick_nav_items',''); $items=[];
        if(trim($raw)!==''){
            foreach(preg_split('/\r\n|\r|\n/',trim($raw))?:[] as $line){
                $line=trim($line); if($line==='') continue;
                $parts=preg_split('/\s*[|,，]\s*/u',$line,2); $label=trim(strip_tags($parts[0]??'')); $url=trim($parts[1]??'');
                if($label!==''&&$url!=='') $items[]=['label'=>htmlspecialchars($label,ENT_QUOTES),'url'=>htmlspecialchars($url,ENT_QUOTES)];
            }
            if($items!==[]) return $items;
        }
        // 回退：全部 + 顶级分类
        $items=[['label'=>'全部','url'=>htmlspecialchars(url_to('/'),ENT_QUOTES)]];
        if($fallbackSorts===[]){
            $cats=\Pafish\Core\DB::fetchAll('SELECT name, slug FROM categories WHERE parent_id IS NULL ORDER BY sort_order ASC, id ASC LIMIT 7');
            foreach($cats as $c){ $items[]=['label'=>htmlspecialchars((string)$c['name'],ENT_QUOTES),'url'=>htmlspecialchars(url_to('/category/'.rawurlencode((string)$c['slug'])),ENT_QUOTES)]; }
        } else {
            foreach($fallbackSorts as $s){ if(!is_array($s)||empty($s['name'])||empty($s['url'])) continue; $items[]=['label'=>$s['name'],'url'=>htmlspecialchars($s['url'],ENT_QUOTES)]; if(count($items)>=8) break; }
        }
        return count($items)>1?$items:[];
    }
}
if (!function_exists('lumina_quick_nav_url_active')) {
    function lumina_quick_nav_url_active(string $url): bool
    {
        $url=html_entity_decode($url,ENT_QUOTES,'UTF-8'); $cur=$_SERVER['REQUEST_URI']??'/';
        $cp=parse_url($cur,PHP_URL_PATH); $cq=parse_url($cur,PHP_URL_QUERY);
        $ip=parse_url($url,PHP_URL_PATH); $iq=parse_url($url,PHP_URL_QUERY);
        if($cp===null||$ip===null) return false;
        $cp=rtrim((string)$cp,'/'); if($cp==='') $cp='/'; $ip=rtrim((string)$ip,'/'); if($ip==='') $ip='/';
        return $cp===$ip && (string)$cq===(string)$iq;
    }
}
if (!function_exists('lumina_get_custom_float_buttons')) {
    function lumina_get_custom_float_buttons(): array { return []; }
}
if (!function_exists('lumina_truthy_option')) {
    function lumina_truthy_option(mixed $v): bool { if(is_bool($v)) return $v; if(is_numeric($v)) return (int)$v===1; $v=strtolower(trim((string)$v)); return in_array($v,['1','y','yes','true','on','open'],true); }
}

// ── 侧栏 / 页脚渲染（与原版 HTML 结构保持一致以命中 style.css） ──
if (!function_exists('lumina_render_sidebar_profile_card')) {
    function lumina_render_sidebar_profile_card(int $uid, string $avatar, string $fallbackAvatar, string $name, string $desc, array $stats): void
    {
        $home=url_to('/'); $profileUrl=url_to('/profile');
        // 兼容：stats 含 logs/comments/likes
        ?>
        <div class="lumina-sidecard lumina-sidecard-profile">
          <a class="lumina-sidecard-profile-link" href="<?= e($home) ?>">
            <img class="lumina-sidecard-profile-avatar" src="<?= e($avatar) ?>" alt="" onerror="this.src='<?= e($fallbackAvatar) ?>'">
            <span class="lumina-sidecard-profile-name"><?= e($name) ?></span>
            <?php if($desc!==''): ?><small class="lumina-sidecard-profile-desc"><?= e($desc) ?></small><?php endif; ?>
          </a>
          <div class="lumina-sidecard-stats">
            <a href="<?= e($home) ?>"><b><?= (int)($stats['logs']??0) ?></b><span>文章</span></a>
            <a href="<?= e(url_to('/archives')) ?>"><b><?= (int)($stats['comments']??0) ?></b><span>评论</span></a>
            <a href="<?= e($home) ?>"><b><?= (int)($stats['likes']??0) ?></b><span>点赞</span></a>
          </div>
        </div>
        <?php
    }
}
if (!function_exists('lumina_render_sidebar_extra_cards')) {
    function lumina_render_sidebar_extra_cards(array $sorts, array $tags, string $copyrightHtml, string $icp): void
    {
        if($sorts!==[]){
            echo '<div class="lumina-sidecard"><div class="lumina-sidecard-title">分类</div><div class="lumina-side-list">';
            foreach($sorts as $s){ echo '<a href="'.e($s['url']).'">'.e($s['name']).'</a>'; }
            echo '</div></div>';
        }
        if($tags!==[]){
            echo '<div class="lumina-sidecard"><div class="lumina-sidecard-title">标签</div><div class="lumina-side-list lumina-side-tags">';
            foreach($tags as $t){ echo '<a href="'.e($t['url']).'">#'.e($t['name']).'</a>'; }
            echo '</div></div>';
        }
        if($copyrightHtml!==''||$icp!==''){
            echo '<div class="lumina-sidecard lumina-sidecard-copyright">';
            if($copyrightHtml!=='') echo '<div class="lumina-sidecard-copyright-text">'.$copyrightHtml.'</div>';
            if($icp!=='') echo '<div class="lumina-sidecard-icp"><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">'.e($icp).'</a></div>';
            echo '</div>';
        }
    }
}
if (!function_exists('lumina_sidebar_copyright_source')) {
    function lumina_sidebar_copyright_source(mixed $footerInfo): string { return is_string($footerInfo)?$footerInfo:''; }
    function lumina_prepare_sidebar_copyright(string $src): string {
        $src=trim($src); if($src==='') return '';
        $src=preg_replace('/\s*powered\s*by\s*emlog\s*/i',' ',$src)??$src;
        return '<span>'.e(trim($src)).'</span>';
    }
}
if (!function_exists('lumina_render_main_footer')) {
    function lumina_render_main_footer(): void
    {
        $custom=trim((string)theme_value('footer_copyright_text','')); $info=$custom!==''?$custom:('© '.date('Y').' '.site_name());
        $icp=(string)settings('site_icp',''); if($icp!=='') $info.=' · <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">'.e($icp).'</a>';
        echo '<footer class="sh-footer"><span class="sh-copyright">'.$info.'</span></footer>';
    }
}
if (!function_exists('lumina_render_emoji_panel')) {
    function lumina_render_emoji_panel(): string { return '<div class="lumina-emoji-panel" style="display:none"></div>'; }
}

// ── 评论 / 发布相关（简化版，满足模板调用不报错） ──
if (!function_exists('blog_author')) { function blog_author(int $uid): string { return lumina_get_user_name($uid, ''); } }
if (!function_exists('blog_tag')) { function blog_tag(int $gid): string { return ''; } }
if (!function_exists('blog_sort')) { function blog_sort(int $gid): string { return ''; } }
if (!function_exists('topflg')) { function topflg(mixed $top): void { if($top==='y'||$top==='1'||$top===1) echo '<span class="sh-top-flag">置顶</span>'; } }
if (!function_exists('lumina_comment_avatar')) { function lumina_comment_avatar(array $c): string { return \Pafish\Http\Comments::avatarUrl(null,(string)($c['author_email']??'')); } }
if (!function_exists('lumina_prepare_sidebar_copyright')) {} // 已定义

// ── 文章正文 / 媒体渲染 ──
if (!function_exists('lumina_render_media')) {
    function lumina_render_media(array $media, int $postId, string $context='feed'): string
    {
        ob_start();
        if(in_array($media['type'],['img','live'],true)&&$media['photos']!==[]): ?>
          <div class="sh-content-right-img" id="imglib-<?= $postId ?>">
            <?php foreach($media['photos'] as $idx=>$photo): $live=$media['live'][$photo]??''; ?>
              <a href="<?= e($photo) ?>" class="sh-content-right-img-pic<?= $live!==''?' is-live-photo':'' ?>" data-fancybox="gallery<?= $postId ?>" aria-label="查看图片">
                <img src="<?= e($photo) ?>" alt="" loading="lazy">
                <?php if($live!==''): ?><video class="lumina-live-video" src="<?= e($live) ?>" muted loop playsinline preload="metadata"></video><span class="lumina-live-badge">LIVE</span><?php endif; ?>
                <?php if($idx===8 && count($media['photos'])>9): ?><b class="sh-content-right-img-pic-mask">+<?= count($media['photos'])-9 ?></b><?php endif; ?>
              </a>
              <?php if($idx===8) break; ?>
            <?php endforeach; ?>
          </div>
        <?php elseif($media['type']==='video' && $media['video']!==''): ?>
          <div class="lumina-video" style="position:relative;padding-top:56.25%;background:#000;border-radius:8px;overflow:hidden"><video src="<?= e($media['video']) ?>"<?= $media['poster']!==''?' poster="'.e($media['poster']).'"':'' ?> controls playsinline preload="metadata" style="position:absolute;inset:0;width:100%;height:100%"></video></div>
        <?php elseif($media['type']==='embed' && $media['embed']!==''): ?>
          <div class="lumina-embed lumina-embed-<?= e($media['ratio']) ?>" style="position:relative;padding-top:<?= $media['ratio']==='tb'?'177.77%':'56.25%' ?>;background:#000;border-radius:8px;overflow:hidden"><iframe src="<?= e($media['embed']) ?>" title="视频" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe></div>
        <?php elseif($media['type']==='music' && $media['music']!==''): ?>
          <div class="lumina-music-card" data-lumina-music style="display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--fgxys);border-radius:10px;background:var(--cobg)">
            <?php if($media['musicCover']!==''): ?><img src="<?= e($media['musicCover']) ?>" alt="" loading="lazy" style="width:48px;height:48px;border-radius:8px;object-fit:cover"><?php else: ?><span style="width:48px;height:48px;display:grid;place-items:center;background:var(--backbg);border-radius:8px">♫</span><?php endif; ?>
            <div style="flex:1;min-width:0"><strong style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($media['musicTitle']!==''?$media['musicTitle']:'未命名音乐') ?></strong><small style="color:var(--texths)"><?= e($media['musicArtist']) ?></small></div>
            <button type="button" aria-label="播放" style="width:36px;height:36px;border-radius:50%;border:0;background:var(--theme);color:#fff;display:grid;place-items:center">▶</button><audio src="<?= e($media['music']) ?>" preload="metadata"></audio>
          </div>
        <?php elseif($media['type']==='link' && $media['linkUrl']!==''): ?>
          <a class="lumina-link-card" href="<?= e($media['linkUrl']) ?>" target="_blank" rel="noopener noreferrer" style="display:flex;gap:12px;padding:12px;border:1px solid var(--fgxys);border-radius:10px;background:var(--cobg);text-decoration:none;color:inherit">
            <?php if($media['linkImage']!==''): ?><img src="<?= e($media['linkImage']) ?>" alt="" loading="lazy" style="width:64px;height:64px;object-fit:cover;border-radius:8px;flex-shrink:0"><?php endif; ?>
            <span style="flex:1;min-width:0"><b style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($media['linkTitle']!==''?$media['linkTitle']:parse_url($media['linkUrl'],PHP_URL_HOST)) ?></b><?php if($media['linkDesc']!==''): ?><small style="display:block;color:var(--texths);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($media['linkDesc']) ?></small><?php endif; ?><em style="font-size:12px;color:var(--texths)"><?= e((string)parse_url($media['linkUrl'],PHP_URL_HOST)) ?></em></span>
          </a>
        <?php elseif($media['type']==='redpacket'): $viewer=(int)(current_user()['id']??0); $packet=\Pafish\Services\RedPacket::state($postId,$viewer?:null);
          $title=(string)($packet['title']??($media['redpacketTitle']!==''?$media['redpacketTitle']:'恭喜发财，大吉大利'));
          $summary=!empty($packet['available'])&&isset($packet['remaining_count'])?(int)$packet['remaining_points'].' 积分 · 剩余 '.(int)$packet['remaining_count'].' 份':($media['redpacketTotal']!==''?$media['redpacketTotal'].' 积分 · ':'').($media['redpacketCount']!==''?$media['redpacketCount'].' 份':'积分红包'); ?>
          <div class="lumina-redpacket" data-lumina-redpacket data-post-id="<?= $postId ?>" data-csrf="<?= e(csrf_token()) ?>" style="display:flex;align-items:center;gap:12px;padding:14px;border-radius:12px;background:linear-gradient(135deg,#ff6a6a,#ff3b30);color:#fff">
            <span style="width:40px;height:40px;display:grid;place-items:center;background:rgba(255,255,255,.2);border-radius:50%">🧧</span><div style="flex:1"><strong><?= e($title) ?></strong><small data-lumina-redpacket-summary style="display:block;opacity:.9"><?= e($summary) ?></small></div>
            <?php if(empty($packet['available'])): ?><em>待启用</em>
            <?php elseif(($packet['status']??'')==='MISSING'): ?><em>待发布</em>
            <?php elseif($viewer===0): ?><a href="<?= e(url_to('/login').'?from='.rawurlencode($_SERVER['REQUEST_URI']??'/')) ?>" style="background:#fff;color:#ff3b30;padding:6px 14px;border-radius:20px;text-decoration:none;font-weight:600">登录领取</a>
            <?php elseif(!empty($packet['claimed'])): ?><em>已领取 <?= (int)$packet['claimed_amount'] ?> 积分</em>
            <?php elseif((int)($packet['creator_id']??0)===$viewer): ?><em>作者红包</em>
            <?php elseif(($packet['status']??'')!=='OPEN'): ?><em>已领完</em>
            <?php else: ?><button type="button" data-lumina-redpacket-claim style="background:#fff;color:#ff3b30;border:0;padding:6px 14px;border-radius:20px;font-weight:700;cursor:pointer">领取</button><?php endif; ?>
          </div>
        <?php endif; return (string)ob_get_clean();
    }
}

// ── 信息流头部（原版 sh-main-head 的 pafish 复刻） ──
if (!function_exists('lumina_profile_header')) {
    function lumina_profile_header(bool $showBack=false): string
    {
        $name=trim(theme_value('profile_name',''))?:site_name();
        $bio=trim(theme_value('profile_bio',''))?:(string)settings('site_description','');
        $avatar=lumina_theme_image('avatar_image',lumina_asset_url('img/tx.png'));
        $cover=lumina_theme_image('header_cover',lumina_asset_url('img/homeimg.jpg'));
        $user=current_user(); $home=url_to('/'); $showSearch=theme_value('show_search','1')!=='0';
        $links=friend_links();
        $canManage=in_array((string)($user['role']??''),['ADMIN','EDITOR'],true);
        $notifications=[]; $unread=0;
        if($canManage){ $notifications=\Pafish\Core\DB::fetchAll('SELECT n.*, p.slug FROM notifications n LEFT JOIN posts p ON p.id=n.post_id ORDER BY n.`read` ASC, n.created_at DESC LIMIT 12'); $unread=(int)\Pafish\Core\DB::value('SELECT COUNT(*) FROM notifications WHERE `read`=0'); }
        // 快捷导航
        $quickNav=lumina_get_quick_nav_items();
        ob_start(); ?>
        <div class="sh-main-head">
          <div class="sh-main-head-top" data-lumina-topbar id="sh-main-head-top">
            <div class="sh-main-head-top-left">
              <?php if($showBack): ?><a class="sh-main-head-top-left-s lumina-top-hit" href="<?= e($home) ?>" aria-label="返回首页"><i class="iconfont icon-weibiaoti lumina-top-icon"></i></a><?php endif; ?>
              <?php if($links!==[]): ?><button type="button" class="sh-main-head-top-left-s lumina-top-hit lumina-top-control" data-lumina-drawer-open="links" aria-label="友情链接"><i class="iconfont icon-lianjie1 lumina-top-icon"></i></button><?php endif; ?>
            </div>
            <div class="sh-main-head-top-center"><a class="lumina-site-title" href="<?= e($home) ?>" style="color:inherit;text-decoration:none;font-weight:700"><?= e(site_name()) ?></a></div>
            <div class="sh-main-head-top-right">
              <?php if($showSearch): ?><button type="button" class="sh-main-head-top-right-s lumina-top-hit lumina-top-control" data-lumina-search-open aria-label="搜索"><i class="iconfont icon-sousuo lumina-top-icon"></i></button><?php endif; ?>
              <button type="button" class="sh-main-head-top-right-s lumina-top-hit lumina-top-control theme-toggle" aria-label="切换主题" title="切换主题" onclick="document.documentElement.classList.toggle('dark'); try{localStorage.setItem('pafish-theme', document.documentElement.classList.contains('dark')?'dark':'light')}catch(e){}"><span class="theme-toggle-icon">◐</span></button>
              <?php if($canManage): ?><button type="button" class="sh-main-head-top-right-s lumina-top-hit lumina-top-control lumina-notice-trigger" data-lumina-drawer-open="notices" aria-label="消息盒子"><i class="iconfont icon-lingdang lumina-top-icon"></i><?php if($unread>0): ?><b class="xiaoxhd" style="position:absolute;width:8px;height:8px;background:#ff3b30;border-radius:50%;margin:-6px 0 0 10px"></b><?php endif; ?></button><?php endif; ?>
              <?php if($user): ?><a class="sh-main-head-top-right-s lumina-top-hit" href="<?= e(url_to('/profile')) ?>" aria-label="个人中心"><i class="iconfont icon-account-circle-line lumina-top-icon"></i></a>
              <?php else: ?><a class="sh-main-head-top-right-s lumina-top-hit" href="<?= e(url_to('/login')) ?>" aria-label="登录"><i class="iconfont icon-account-circle-line lumina-top-icon"></i></a><?php endif; ?>
            </div>
          </div>
          <div class="sh-main-head-img" style="background-image:url('<?= e($cover) ?>')"></div>
        </div>
        <div class="sh-main-head-headimg">
          <div class="sh-main-head-headimg-tx"><h4><?= e($name) ?></h4><a href="<?= e($user ? url_to('/profile') : $home) ?>"><img src="<?= e($avatar) ?>" alt="<?= e($name) ?>"></a></div>
          <?php if($bio!==''): ?><div class="sh-main-head-headimg-qm"><p><?= e($bio) ?></p></div><?php endif; ?>
        </div>
        <?php if($quickNav!==[]): ?>
        <nav class="lumina-quick-nav" aria-label="快捷导航"><div class="lumina-quick-nav-scroll">
          <?php foreach($quickNav as $item): $label=$item['label']; $url=$item['url']; $active=lumina_quick_nav_url_active(html_entity_decode($url,ENT_QUOTES,'UTF-8'))?' is-active':''; ?>
            <a class="lumina-quick-nav-item<?= $active ?>" href="<?= $url ?>"><?= $label ?></a>
          <?php endforeach; ?>
        </div></nav>
        <?php else: ?>
        <nav class="lumina-quick-nav" aria-label="快捷导航"><div class="lumina-quick-nav-scroll">
          <a class="lumina-quick-nav-item<?= !$showBack?' is-active':'' ?>" href="<?= e($home) ?>">动态</a>
          <a class="lumina-quick-nav-item" href="<?= e(url_to('/archives')) ?>">归档</a>
          <?php foreach(array_slice(nav_items(),0,5) as $it): $link=trim((string)($it['url']??'')); $label=trim((string)($it['label']??'')); if($link===''||$label==='') continue; $ext=!empty($it['is_external']); ?>
            <a class="lumina-quick-nav-item" href="<?= e($ext?$link:url_to($link)) ?>"<?= $ext?' target="_blank" rel="noopener noreferrer"':'' ?>><?= e($label) ?></a>
          <?php endforeach; ?>
        </div></nav>
        <?php endif; ?>
        <?php if($links!==[]): ?><div class="sh-link" data-lumina-drawer="links" hidden><div class="sh-link-main" role="dialog" aria-modal="true" aria-label="友情链接"><div class="sh-link-main-top"><div class="sh-news-main-top-xiaoxih"><span>友情链接</span></div><button type="button" class="sh-news-main-top-div lumina-drawer-close" data-lumina-drawer-close aria-label="关闭"><i class="iconfont icon-quxiao"></i></button></div><div class="sh-link-con"><?php foreach($links as $link): ?><a class="sh-link-con-lie" href="<?= e((string)$link['url']) ?>" target="_blank" rel="nofollow noopener noreferrer"><span class="sh-link-con-lie-left"><i class="iconfont icon-lianjie1"></i></span><span class="lumina-link-item-main"><span class="sh-link-con-lie-right-title"><?= e((string)$link['name']) ?></span><?php if(!empty($link['description'])): ?><span class="lumina-link-desc"><?= e((string)$link['description']) ?></span><?php endif; ?></span></a><?php endforeach; ?></div><div class="sh-link-tishi"><p>共 <?= count($links) ?> 个链接</p></div></div></div><?php endif; ?>
        <?php if($canManage): ?><div class="sh-news" data-lumina-drawer="notices" hidden><div class="sh-news-main" role="dialog" aria-modal="true" aria-label="消息盒子"><div class="sh-news-main-top"><div class="sh-news-main-top-xiaoxih"><span>消息盒子</span></div><a class="sh-news-main-top-div" href="<?= e(url_to('/admin/notifications')) ?>" aria-label="通知中心"><i class="iconfont icon-gengduo"></i></a><button type="button" class="sh-news-main-top-div lumina-drawer-close" data-lumina-drawer-close aria-label="关闭"><i class="iconfont icon-quxiao"></i></button></div><div class="sh-news-con"><?php if($notifications===[]): ?><p class="lumina-empty" style="padding:24px;text-align:center;color:var(--texths)">暂无消息</p><?php else: foreach($notifications as $n): $href=!empty($n['slug'])?url_to('/post/'.rawurlencode((string)$n['slug'])).'#comments':url_to('/admin/notifications'); ?><a class="sh-news-con-lie" href="<?= e($href) ?>"><div class="sh-news-con-lie-left"><?php if(empty($n['read'])): ?><p class="xiaoxhd"></p><?php endif; ?><div class="sh-news-con-lie-left-imgt"><i class="iconfont icon-lingdang"></i></div></div><div class="sh-news-con-lie-right"><p class="sh-news-con-lie-right-title"><?= e((string)($n['type']??'通知')) ?><span class="sh-news-con-lie-right-time"><?= e(format_date($n['created_at']??null,'Y-m-d H:i')) ?></span></p><p class="sh-news-con-lie-right-text"><?= e((string)($n['message']??'')) ?></p></div></a><?php endforeach; endif; ?></div><div class="sh-news-tishi"><p>未读 <?= $unread ?> 条</p></div></div></div><?php endif; ?>
        <?php return (string)ob_get_clean();
    }
}
