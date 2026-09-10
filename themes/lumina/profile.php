<?php

// pafish: emlog guard removed
require_once __DIR__ . '/module.php';
$lumina_page_identity = 'user';

$routerPath = isset($routerPath) ? $routerPath : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = Input::postStrVar('lumina_action');
    LoginAuth::checkToken();

    if ($action === 'location_nearby') {
        if (!is_logged_in()) {
            Output::error('请先登录');
        }
        if (lumina_opt('enable_tencent_map', 'n') !== 'y') {
            Output::error('腾讯地图定位未开启');
        }
        $mapKey = trim((string)lumina_opt('tencent_map_key', ''));
        if ($mapKey === '') {
            Output::error('请先在模板设置中填写腾讯位置服务Key');
        }
        $lat = isset($_POST['lat']) ? trim((string)$_POST['lat']) : '';
        $lng = isset($_POST['lng']) ? trim((string)$_POST['lng']) : '';
        if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
            Output::error('定位坐标无效');
        }
        $lat = (float)$lat;
        $lng = (float)$lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            Output::error('定位坐标超出范围');
        }
        $keyword = trim(Input::postStrVar('keyword', ''));
        if ($keyword === '') {
            $query = http_build_query([
                'location' => $lat . ',' . $lng,
                'get_poi' => 1,
                'poi_options' => 'page_size=20;page_index=1',
                'key' => $mapKey,
            ]);
            $apiUrl = 'https://apis.map.qq.com/ws/geocoder/v1/?' . $query;
        } else {
            $query = http_build_query([
                'keyword' => $keyword,
                'location' => $lat . ',' . $lng,
                'region' => '全国',
                'get_subpois' => 0,
                'page_size' => 20,
                'page_index' => 1,
                'key' => $mapKey,
            ]);
            $apiUrl = 'https://apis.map.qq.com/ws/place/v1/suggestion?' . $query;
        }
        $raw = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            $raw = curl_exec($ch);
            curl_close($ch);
        } elseif (ini_get('allow_url_fopen')) {
            $raw = @file_get_contents($apiUrl);
        }
        if ($raw === '' || $raw === false) {
            Output::error('腾讯地图服务请求失败');
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['status']) || (int)$json['status'] !== 0) {
            $msg = is_array($json) && isset($json['message']) ? $json['message'] : '腾讯地图服务返回异常';
            Output::error($msg);
        }

        $items = [];
        if ($keyword === '' && !empty($json['result']) && is_array($json['result'])) {
            $result = $json['result'];
            $currentTitle = '';
            if (!empty($result['formatted_addresses']['recommend'])) {
                $currentTitle = (string)$result['formatted_addresses']['recommend'];
            } elseif (!empty($result['address'])) {
                $currentTitle = (string)$result['address'];
            }
            if ($currentTitle !== '') {
                $items[] = [
                    'title' => $currentTitle,
                    'address' => isset($result['address']) ? (string)$result['address'] : '',
                    'lat' => (string)$lat,
                    'lng' => (string)$lng,
                    'poi_id' => '',
                    'city' => isset($result['address_component']['city']) ? (string)$result['address_component']['city'] : '',
                ];
            }
            if (!empty($result['pois']) && is_array($result['pois'])) {
                foreach ($result['pois'] as $item) {
                    if (empty($item['title']) || empty($item['location']) || !is_array($item['location'])) {
                        continue;
                    }
                    $itemLat = isset($item['location']['lat']) ? (string)$item['location']['lat'] : '';
                    $itemLng = isset($item['location']['lng']) ? (string)$item['location']['lng'] : '';
                    if ($itemLat === '' || $itemLng === '') {
                        continue;
                    }
                    $items[] = [
                        'title' => (string)$item['title'],
                        'address' => isset($item['address']) ? (string)$item['address'] : '',
                        'lat' => $itemLat,
                        'lng' => $itemLng,
                        'poi_id' => isset($item['id']) ? (string)$item['id'] : '',
                        'city' => isset($result['address_component']['city']) ? (string)$result['address_component']['city'] : '',
                    ];
                }
            }
        } elseif (!empty($json['data']) && is_array($json['data'])) {
            foreach ($json['data'] as $item) {
                if (empty($item['title']) || empty($item['location']) || !is_array($item['location'])) {
                    continue;
                }
                $itemLat = isset($item['location']['lat']) ? (string)$item['location']['lat'] : '';
                $itemLng = isset($item['location']['lng']) ? (string)$item['location']['lng'] : '';
                if ($itemLat === '' || $itemLng === '') {
                    continue;
                }
                $items[] = [
                    'title' => (string)$item['title'],
                    'address' => isset($item['address']) ? (string)$item['address'] : '',
                    'lat' => $itemLat,
                    'lng' => $itemLng,
                    'poi_id' => isset($item['id']) ? (string)$item['id'] : '',
                    'city' => isset($item['city']) ? (string)$item['city'] : (isset($item['ad_info']['city']) ? (string)$item['ad_info']['city'] : ''),
                ];
            }
        }
        if (empty($items)) {
            $items[] = [
                'title' => '当前位置',
                'address' => '',
                'lat' => (string)$lat,
                'lng' => (string)$lng,
                'poi_id' => '',
                'city' => '',
            ];
        }
        Output::ok(['items' => $items]);
    }

    if ($action === 'guest_unlike') {
        $blogId = Input::postIntVar('gid', 0);
        if ($blogId <= 0) {
            Output::error('文章不存在');
        }
        if (!class_exists('Like_Model')) {
            Output::error('点赞功能不可用');
        }
        $ip = getIp();
        if ($ip === '') {
            Output::error('请求异常');
        }
        $Like_Model = new Like_Model();
        $r = $Like_Model->unLike(0, $blogId, $ip);
        if ($r === false) {
            Output::error('取消失败');
        }
        Output::ok();
    }

    if ($action === 'link_fetch') {
        if (!is_logged_in()) {
            Output::error('请先登录');
        }
        $url = trim(Input::postStrVar('url', ''));
        if ($url === '') {
            Output::error('请填写链接地址');
        }
        $preview = lumina_fetch_link_preview($url);
        if (empty($preview['ok'])) {
            Output::error(isset($preview['msg']) ? $preview['msg'] : '链接信息获取失败');
        }
        unset($preview['ok']);
        Output::ok($preview);
    }

    if ($action === 'media_upload') {
        if (!is_logged_in()) {
            Output::error('请先登录');
        }
        if (!in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true) && Option::get('forbid_user_upload') === 'y') {
            Output::error('系统关闭了资源上传');
        }
        $attach = isset($_FILES['file']) ? $_FILES['file'] : (isset($_FILES['image']) ? $_FILES['image'] : '');
        if (!$attach) {
            Output::error('请选择文件');
        }
        $uploadCheckResult = Media::checkUpload($attach);
        if ($uploadCheckResult !== true) {
            Output::error($uploadCheckResult);
        }
        $ret = '';
        addAction('upload_media', 'upload2local');
        doOnceAction('upload_media', $attach, $ret);
        if (empty($ret['success'])) {
            Output::error(isset($ret['message']) ? $ret['message'] : '上传失败');
        }
        $file_path = $ret['file_info']['file_path'] ?? '';
        $abs_file_path = lumina_resolve_media_url($file_path);
        if ($abs_file_path === '') {
            Output::error('文件上传出错');
        }
        $sid = Input::postIntVar('sid', 0);
        if (lumina_table_exists(DB_PREFIX . 'attachment')) {
            $Media_Model = new Media_Model();
            $Media_Model->addMedia($ret['file_info'], $sid);
        }
        Output::ok(['url' => $abs_file_path]);
    }

    if ($action === 'cover_upload') {
        if (!is_logged_in()) {
            Output::error('请先登录');
        }
        if (!in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true) && Option::get('forbid_user_upload') === 'y') {
            Output::error('系统关闭了资源上传');
        }
        $attach = isset($_FILES['file']) ? $_FILES['file'] : (isset($_FILES['image']) ? $_FILES['image'] : '');
        if (!$attach) {
            Output::error('请选择文件');
        }
        $uploadCheckResult = Media::checkUpload($attach);
        if ($uploadCheckResult !== true) {
            Output::error($uploadCheckResult);
        }
        $ret = '';
        addAction('upload_media', 'upload2local');
        doOnceAction('upload_media', $attach, $ret);
        if (empty($ret['success'])) {
            Output::error(isset($ret['message']) ? $ret['message'] : '上传失败');
        }
        $file_path = $ret['file_info']['file_path'] ?? '';
        $abs_file_path = lumina_resolve_media_url($file_path);
        if ($abs_file_path === '') {
            Output::error('文件上传出错');
        }
        lumina_set_user_cover((int)(current_user()['id'] ?? 0), $abs_file_path);
        Output::ok(['url' => $abs_file_path]);
    }


    if ($action === 'notice_delete') {
        if (!is_logged_in()) {
            Output::error('请先登录');
        }
        $keys = Input::postStrArray('keys');
        lumina_notice_mark_deleted((int)(current_user()['id'] ?? 0), $keys);
        Output::ok();
    }

    if ($action === 'redpacket_claim') {
        if (!is_logged_in()) {
            Output::error('请先登录');
        }
        $gid = Input::postIntVar('gid', 0);
        if ($gid <= 0) {
            Output::error('红包不存在');
        }
        $result = lumina_redpacket_claim($gid, (int)(current_user()['id'] ?? 0));
        if (empty($result['ok'])) {
            Output::error(isset($result['msg']) ? $result['msg'] : '领取失败');
        }
        Output::ok([
            'amount' => isset($result['amount']) ? (int)$result['amount'] : 0,
            'remain' => isset($result['remain']) ? (int)$result['remain'] : 0,
            'remain_count' => isset($result['remain_count']) ? (int)$result['remain_count'] : 0,
            'total_count' => isset($result['total_count']) ? (int)$result['total_count'] : 0,
        ]);
    }

    if ($action === 'post_toggle_top' || $action === 'post_toggle_private') {
        if (!is_logged_in()) {
            Output::error('请先登录');
        }
        $blogid = Input::postIntVar('blogid', 0);
        if ($blogid <= 0) {
            Output::error('文章不存在');
        }
        $Log_Model = new Log_Model();
        $log = null;
        if (method_exists($Log_Model, 'getOneLogForHome')) {
            $log = $Log_Model->getOneLogForHome($blogid, true, true);
        } elseif (method_exists($Log_Model, 'getOneLog')) {
            $log = $Log_Model->getOneLog($blogid);
        }
        if (!$log) {
            Output::error('文章不存在');
        }
        if (!lumina_can_manage_log_item($log)) {
            Output::error('无权限操作该文章');
        }

        if ($action === 'post_toggle_top') {
            if (!in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true) && !User::isAdmin()) {
                Output::error('无权限设置置顶');
            }
            $top = Input::postStrVar('top', 'n') === 'y' ? 'y' : 'n';
            if (!method_exists($Log_Model, 'updateLog')) {
                Output::error('系统暂不支持置顶');
            }
            $Log_Model->updateLog(['top' => $top], $blogid);
            if (isset($CACHE) && is_object($CACHE) && method_exists($CACHE, 'updateArticleCache')) {
                $CACHE->updateArticleCache();
            }
            Output::ok(['top' => $top]);
        }

        $private = Input::postStrVar('private', 'n') === 'y' ? 'y' : 'n';
        $fields = [];
        if (isset($log['fields']) && is_array($log['fields'])) {
            $fields = $log['fields'];
        } elseif (class_exists('Field') && method_exists('Field', 'getFields')) {
            $fields = Field::getFields($blogid);
        }
        $fields['lumina_private'] = $private;
        Field::updateField($blogid, array_keys($fields), array_values($fields));
        Output::ok(['private' => $private]);
    }

    if ($action === 'profile_update') {
        if (!is_logged_in()) {
            Output::error('请先登录');
        }
        if (!class_exists('Database')) {
            Output::error('系统暂不支持资料修改');
        }
        $hasNickname = array_key_exists('nickname', $_POST);
        $hasSignature = array_key_exists('signature', $_POST);
        $hasUrl = array_key_exists('url', $_POST);
        $hasEmail = array_key_exists('email', $_POST);

        $nickname = trim(Input::postStrVar('nickname', ''));
        $signature = trim(Input::postStrVar('signature', ''));
        $url = trim(Input::postStrVar('url', ''));
        $email = trim(Input::postStrVar('email', ''));

        $table = DB_PREFIX . 'user';
        $cols = lumina_table_columns($table);
        $idField = in_array('uid', $cols, true) ? 'uid' : (in_array('id', $cols, true) ? 'id' : '');
        if ($idField === '') {
            Output::error('用户表结构不支持修改');
        }

        $updates = [];
        if ($hasNickname) {
            if ($nickname !== '' && mb_strlen($nickname, 'UTF-8') > 10) {
                Output::error('昵称不可超过10个字符');
            }
            if ($nickname !== '' && strpos($nickname, '匿名用户') !== false) {
                Output::error('此昵称为禁用词');
            }
            if (in_array('nickname', $cols, true)) {
                $updates['nickname'] = $nickname;
            } elseif (in_array('name', $cols, true)) {
                $updates['name'] = $nickname;
            }
        }
        if ($hasSignature) {
            if ($signature !== '' && mb_strlen($signature, 'UTF-8') > 50) {
                Output::error('签名不可超过50个字符');
            }
            if (in_array('description', $cols, true)) {
                $updates['description'] = $signature;
            } elseif (in_array('sign', $cols, true)) {
                $updates['sign'] = $signature;
            }
        }
        if ($hasUrl && in_array('url', $cols, true)) {
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                Output::error('网址必须含有http或https');
            }
            $updates['url'] = $url;
        }
        if ($hasEmail && in_array('email', $cols, true)) {
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Output::error('邮箱格式不正确');
            }
            $updates['email'] = $email;
        }

        if (empty($updates)) {
            Output::error('未提交可更新内容');
        }

        $setParts = [];
        foreach ($updates as $col => $val) {
            $setParts[] = "`{$col}`='" . lumina_db_escape($val) . "'";
        }
        $uid = (int)(current_user()['id'] ?? 0);
        $sql = "UPDATE `$table` SET " . implode(',', $setParts) . " WHERE `$idField`={$uid} LIMIT 1";
        $db = \Pafish\Core\DB;
        $db->query($sql, true);
        Output::ok();
    }

    if ($action === 'avatar_upload') {
        if (!is_logged_in()) {
            Output::error('请先登录');
        }
        if (!in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true) && Option::get('forbid_user_upload') === 'y') {
            Output::error('系统关闭了资源上传');
        }
        $attach = isset($_FILES['file']) ? $_FILES['file'] : (isset($_FILES['image']) ? $_FILES['image'] : '');
        if (!$attach) {
            Output::error('请选择文件');
        }
        $uploadCheckResult = Media::checkUpload($attach);
        if ($uploadCheckResult !== true) {
            Output::error($uploadCheckResult);
        }
        $ret = '';
        addAction('upload_media', 'upload2local');
        doOnceAction('upload_media', $attach, $ret);
        if (empty($ret['success'])) {
            Output::error(isset($ret['message']) ? $ret['message'] : '上传失败');
        }
        $file_path = $ret['file_info']['file_path'] ?? '';
        $abs_file_path = lumina_resolve_media_url($file_path);
        if ($abs_file_path === '') {
            Output::error('文件上传出错');
        }
        if (!class_exists('Database')) {
            Output::error('系统暂不支持头像修改');
        }
        $table = DB_PREFIX . 'user';
        $cols = lumina_table_columns($table);
        $idField = in_array('uid', $cols, true) ? 'uid' : (in_array('id', $cols, true) ? 'id' : '');
        if ($idField === '') {
            Output::error('系统暂不支持头像修改');
        }
        $col = in_array('photo', $cols, true) ? 'photo' : (in_array('img', $cols, true) ? 'img' : '');
        if ($col === '') {
            Output::error('系统暂不支持头像修改');
        }
        $store = ($col === 'photo' && $file_path !== '') ? $file_path : $abs_file_path;
        $safe = lumina_db_escape($store);
        $uid = (int)(current_user()['id'] ?? 0);
        $db = \Pafish\Core\DB;
        $db->query("UPDATE `$table` SET `$col`='{$safe}' WHERE `$idField`={$uid} LIMIT 1", true);
        Output::ok(['url' => $abs_file_path]);
    }

    if ($action === 'password_update') {
        if (!is_logged_in()) {
            Output::error('请先登录');
        }
        if (!class_exists('Database')) {
            Output::error('系统暂不支持修改密码');
        }
        $oldPass = Input::postStrVar('old_password', '');
        $newPass = Input::postStrVar('new_password', '');
        $newPass2 = Input::postStrVar('new_password2', '');
        if ($newPass === '' || $newPass2 === '') {
            Output::error('请输入新密码');
        }
        if ($newPass !== $newPass2) {
            Output::error('两次密码不一致');
        }
        if (strlen($newPass) < 3 || strlen($newPass) > 32) {
            Output::error('密码长度需为3-32位');
        }
        if ($oldPass === $newPass) {
            Output::error('新密码不能与旧密码相同');
        }

        $table = DB_PREFIX . 'user';
        $cols = lumina_table_columns($table);
        $idField = in_array('uid', $cols, true) ? 'uid' : (in_array('id', $cols, true) ? 'id' : '');
        if ($idField === '' || !in_array('password', $cols, true)) {
            Output::error('系统暂不支持修改密码');
        }

        $db = \Pafish\Core\DB;
        $ret = $db->query("SELECT `password` FROM `$table` WHERE `$idField`={$uid} LIMIT 1");
        $stored = '';
        if ($ret && ($row = $db->fetch_array($ret))) {
            $stored = isset($row['password']) ? (string)$row['password'] : '';
        }
        if ($stored === '') {
            Output::error('系统暂不支持修改密码');
        }
        if (md5($oldPass) !== $stored) {
            Output::error('旧密码错误');
        }

        $hash = md5($newPass);
        $db->query("UPDATE `$table` SET `password`='" . lumina_db_escape($hash) . "' WHERE `$idField`={$uid} LIMIT 1", true);
        Output::ok();
    }

    if ($action === 'post_publish') {
        if (!is_logged_in()) {
            Output::error('请先登录');
        }
        $title = Input::postStrVar('title');
        $contentRaw = isset($_POST['content']) ? (string)$_POST['content'] : '';
        $content = trim($contentRaw);
        $sort = Input::postIntVar('sort_id', -1);
        $tagstring = strip_tags(Input::postStrVar('tags'));
        $cover = Input::postStrVar('cover');
        $allow_remark = Input::postStrVar('allow_remark', 'n');
        $draft = Input::postStrVar('draft', 'n');
        $field_keys = isset($_POST['field_keys']) && is_array($_POST['field_keys']) ? array_map('strval', $_POST['field_keys']) : [];
        $field_values = isset($_POST['field_values']) && is_array($_POST['field_values']) ? array_map('strval', $_POST['field_values']) : [];
        $fieldMap = [];
        if (!empty($field_keys)) {
            $fieldCount = count($field_keys);
            for ($i = 0; $i < $fieldCount; $i++) {
                $key = trim((string)$field_keys[$i]);
                if ($key === '') {
                    continue;
                }
                $fieldMap[$key] = isset($field_values[$i]) ? (string)$field_values[$i] : '';
            }
        }
        $photosRaw = isset($fieldMap['lumina_photos']) ? trim((string)$fieldMap['lumina_photos']) : '';
        $livePhotosRaw = isset($fieldMap['lumina_live_photos']) ? trim((string)$fieldMap['lumina_live_photos']) : '';
        $videoRaw = isset($fieldMap['lumina_video_url']) ? trim((string)$fieldMap['lumina_video_url']) : '';
        $embedRaw = isset($fieldMap['lumina_embed_url']) ? trim((string)$fieldMap['lumina_embed_url']) : '';
        $musicRaw = isset($fieldMap['lumina_music_url']) ? trim((string)$fieldMap['lumina_music_url']) : '';
        $linkRaw = isset($fieldMap['lumina_link_url']) ? trim((string)$fieldMap['lumina_link_url']) : '';
        $type = isset($fieldMap['lumina_type']) ? trim((string)$fieldMap['lumina_type']) : '';
        $redMode = isset($fieldMap['lumina_redpacket_mode']) ? trim((string)$fieldMap['lumina_redpacket_mode']) : '';
        $redTitle = isset($fieldMap['lumina_redpacket_title']) ? trim((string)$fieldMap['lumina_redpacket_title']) : '';
        $redTotal = isset($fieldMap['lumina_redpacket_total']) ? (int)$fieldMap['lumina_redpacket_total'] : 0;
        $redCount = isset($fieldMap['lumina_redpacket_count']) ? (int)$fieldMap['lumina_redpacket_count'] : 0;
        $isRedpacket = ($type === 'redpacket');
        $hasMedia = ($photosRaw !== '') || ($livePhotosRaw !== '') || ($videoRaw !== '') || ($embedRaw !== '') || ($musicRaw !== '') || ($linkRaw !== '') || $isRedpacket;

        if ($type === 'live' && ($photosRaw === '' || $livePhotosRaw === '')) {
            Output::error('实况图需要同时填写图片和实况视频');
        }
        if ($type === 'embed') {
            $embedState = lumina_prepare_embed_video_state($fieldMap);
            if ($embedRaw === '' || empty($embedState['src'])) {
                Output::error('请填写支持的平台视频链接或官方 iframe');
            }
            $fieldMap['lumina_embed_ratio'] = isset($fieldMap['lumina_embed_ratio']) && $fieldMap['lumina_embed_ratio'] === 'tb' ? 'tb' : 'lr';
        }
        if ($type === 'link' && $linkRaw === '') {
            Output::error('请填写链接地址');
        }

        if ($isRedpacket) {
            if (!class_exists('User_Model')) {
                Output::error('系统暂不支持积分红包');
            }
            if ($redTotal <= 0 || $redCount <= 0) {
                Output::error('请填写红包积分和数量');
            }
            if ($redTotal < $redCount) {
                Output::error('红包总积分不能小于数量');
            }
            $redMode = $redMode === 'equal' ? 'equal' : 'random';
            $fieldMap['lumina_redpacket_mode'] = $redMode;
            $fieldMap['lumina_redpacket_total'] = (string)$redTotal;
            $fieldMap['lumina_redpacket_count'] = (string)$redCount;
            $fieldMap['lumina_redpacket_title'] = $redTitle;
        }

        if (trim($content) === '' && !$hasMedia) {
            Output::error('内容不能为空');
        }

        if ($title === '') {
            $title = mb_substr(trim(strip_tags($content)), 0, 20);
            if ($title === '') {
                $title = '未命名';
            }
        }

        $excerpt = '';
        $origContent = trim($contentRaw);
        if ($origContent !== '') {
            $parseDown = new Parsedown();
            $excerpt = $parseDown->text($origContent);
            $excerpt = extractHtmlData($excerpt, 180);
            $excerpt = str_replace(["\r", "\n", "'", '"'], ' ', $excerpt);
            $excerpt = addslashes($excerpt);
        }

        if (empty($cover)) {
            if ($content) {
                $cover = getFirstImage($content);
            }
            if (empty($cover) && $photosRaw !== '') {
                $photoList = preg_split("/\\r\\n|\\n|\\r/", $photosRaw);
                if (!empty($photoList)) {
                    foreach ($photoList as $photoItem) {
                        $photoItem = trim((string)$photoItem);
                        if ($photoItem !== '') {
                            $cover = $photoItem;
                            break;
                        }
                    }
                }
            }
            if (empty($cover) && !empty($fieldMap['lumina_link_image'])) {
                $cover = trim((string)$fieldMap['lumina_link_image']);
            }
        }

        $ishide = $draft === 'y' ? 'y' : 'n';
        $checked = Option::get('ischkarticle') == 'y' && !in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true) ? 'n' : 'y';

        if (Article::hasReachedDailyPostLimit()) {
            Output::error('已达今日发布上限');
        }

        $Log_Model = new Log_Model();
        $Tag_Model = new Tag_Model();

        $userModel = null;
        if ($isRedpacket) {
            $userModel = new User_Model();
            $userInfo = $userModel->getOneUser((int)(current_user()['id'] ?? 0));
            $credits = isset($userInfo['credits']) ? (int)$userInfo['credits'] : 0;
            if ($credits < $redTotal) {
                Output::error('积分不足');
            }
            if (!lumina_redpacket_ensure_table() || !lumina_redpacket_claim_ensure_table()) {
                Output::error('红包功能暂不可用');
            }
            if (!method_exists($userModel, 'reduceCredits')) {
                Output::error('系统暂不支持积分扣减');
            }
            $reduced = $userModel->reduceCredits((int)(current_user()['id'] ?? 0), $redTotal);
            if ($reduced === false) {
                Output::error('积分扣减失败');
            }
        }

        $canTop = in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true) || User::isAdmin();
        $top = $canTop && Input::postStrVar('top', 'n') === 'y' ? 'y' : 'n';
        $logData = [
            'title'        => $title,
            'alias'        => '',
            'content'      => $content,
            'excerpt'      => $excerpt,
            'cover'        => $cover,
            'author'       => (int)(current_user()['id'] ?? 0),
            'sortid'       => $sort,
            'date'         => time(),
            'top'          => $top,
            'sortop'       => 'n',
            'allow_remark' => $allow_remark === 'y' ? 'y' : 'n',
            'hide'         => $ishide,
            'checked'      => $checked,
            'password'     => '',
            'link'         => '',
            'template'     => '',
        ];

        doMultiAction('pre_save_log', $logData, $logData);

        $blogid = $Log_Model->addlog($logData);
        if (!$blogid) {
            if ($isRedpacket && $userModel && method_exists($userModel, 'addCredits')) {
                $userModel->addCredits((int)(current_user()['id'] ?? 0), $redTotal);
            }
            Output::error('发布失败');
        }
        $Tag_Model->addTag($tagstring, $blogid);
        $CACHE->updateArticleCache();
        if (!empty($fieldMap)) {
            $field_keys = array_keys($fieldMap);
            $field_values = array_values($fieldMap);
        }
        Field::updateField($blogid, $field_keys, $field_values);
        if ($isRedpacket) {
            lumina_redpacket_create($blogid, (int)(current_user()['id'] ?? 0), $redTotal, $redCount, $redMode);
        }
        do_action('save_log', $blogid, $ishide === 'n', $logData);

        Output::ok(['article_id' => $blogid]);
    }

    if ($action === 'post_update') {
        if (!is_logged_in()) {
            Output::error('请先登录');
        }
        $blogid = Input::postIntVar('blogid', 0);
        if ($blogid <= 0) {
            Output::error('文章不存在');
        }
        $Log_Model = new Log_Model();
        $log = null;
        if (method_exists($Log_Model, 'getOneLogForHome')) {
            $log = $Log_Model->getOneLogForHome($blogid, true, true);
        } elseif (method_exists($Log_Model, 'getOneLog')) {
            $log = $Log_Model->getOneLog($blogid);
        }
        if (!$log) {
            Output::error('文章不存在');
        }
        $canEdit = (isset($log['author']) && (int)$log['author'] === (int)(current_user()['id'] ?? 0)) || (class_exists('User') && in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true));
        if (!$canEdit) {
            Output::error('无权限编辑该文章');
        }

        $title = Input::postStrVar('title');
        $contentRaw = isset($_POST['content']) ? (string)$_POST['content'] : '';
        $content = trim($contentRaw);
        $sort = Input::postIntVar('sort_id', -1);
        $tagstring = strip_tags(Input::postStrVar('tags'));
        $cover = Input::postStrVar('cover');
        $allow_remark = Input::postStrVar('allow_remark', 'n');
        $draft = Input::postStrVar('draft', 'n');
        $field_keys = isset($_POST['field_keys']) && is_array($_POST['field_keys']) ? array_map('strval', $_POST['field_keys']) : [];
        $field_values = isset($_POST['field_values']) && is_array($_POST['field_values']) ? array_map('strval', $_POST['field_values']) : [];
        $fieldMap = [];
        if (!empty($field_keys)) {
            $fieldCount = count($field_keys);
            for ($i = 0; $i < $fieldCount; $i++) {
                $key = trim((string)$field_keys[$i]);
                if ($key === '') {
                    continue;
                }
                $fieldMap[$key] = isset($field_values[$i]) ? (string)$field_values[$i] : '';
            }
        }
        $photosRaw = isset($fieldMap['lumina_photos']) ? trim((string)$fieldMap['lumina_photos']) : '';
        $livePhotosRaw = isset($fieldMap['lumina_live_photos']) ? trim((string)$fieldMap['lumina_live_photos']) : '';
        $videoRaw = isset($fieldMap['lumina_video_url']) ? trim((string)$fieldMap['lumina_video_url']) : '';
        $embedRaw = isset($fieldMap['lumina_embed_url']) ? trim((string)$fieldMap['lumina_embed_url']) : '';
        $musicRaw = isset($fieldMap['lumina_music_url']) ? trim((string)$fieldMap['lumina_music_url']) : '';
        $linkRaw = isset($fieldMap['lumina_link_url']) ? trim((string)$fieldMap['lumina_link_url']) : '';
        $type = isset($fieldMap['lumina_type']) ? trim((string)$fieldMap['lumina_type']) : '';
        $redMode = isset($fieldMap['lumina_redpacket_mode']) ? trim((string)$fieldMap['lumina_redpacket_mode']) : '';
        $redTitle = isset($fieldMap['lumina_redpacket_title']) ? trim((string)$fieldMap['lumina_redpacket_title']) : '';
        $redTotal = isset($fieldMap['lumina_redpacket_total']) ? (int)$fieldMap['lumina_redpacket_total'] : 0;
        $redCount = isset($fieldMap['lumina_redpacket_count']) ? (int)$fieldMap['lumina_redpacket_count'] : 0;

        $originType = '';
        if (isset($log['fields']) && is_array($log['fields']) && isset($log['fields']['lumina_type'])) {
            $originType = trim((string)$log['fields']['lumina_type']);
        } elseif (class_exists('Field') && method_exists('Field', 'getFieldValue')) {
            $originType = trim((string)Field::getFieldValue($blogid, 'lumina_type'));
        }
        if (!isset($fieldMap['lumina_private']) && isset($log['fields']) && is_array($log['fields']) && isset($log['fields']['lumina_private'])) {
            $fieldMap['lumina_private'] = trim((string)$log['fields']['lumina_private']) === 'y' ? 'y' : 'n';
        }

        $isRedpacket = ($type === 'redpacket') || ($originType === 'redpacket');
        if ($type === 'live' && ($photosRaw === '' || $livePhotosRaw === '')) {
            Output::error('实况图需要同时填写图片和实况视频');
        }
        if ($type === 'embed') {
            $embedState = lumina_prepare_embed_video_state($fieldMap);
            if ($embedRaw === '' || empty($embedState['src'])) {
                Output::error('请填写支持的平台视频链接或官方 iframe');
            }
            $fieldMap['lumina_embed_ratio'] = isset($fieldMap['lumina_embed_ratio']) && $fieldMap['lumina_embed_ratio'] === 'tb' ? 'tb' : 'lr';
            $originEmbedUrl = '';
            if (isset($log['fields']) && is_array($log['fields']) && isset($log['fields']['lumina_embed_url'])) {
                $originEmbedUrl = trim((string)$log['fields']['lumina_embed_url']);
            }
            if ($originEmbedUrl !== '' && $originEmbedUrl !== $embedRaw) {
                $fieldMap['lumina_embed_cover'] = '';
            }
        }
        if ($type === 'link' && $linkRaw === '') {
            Output::error('请填写链接地址');
        }
        if ($originType !== 'redpacket' && $type === 'redpacket') {
            Output::error('已发布内容不能改为红包');
        }
        if ($originType === 'redpacket') {
            $type = 'redpacket';
            $fieldMap['lumina_type'] = 'redpacket';
            $packet = lumina_redpacket_get($blogid);
            if (!empty($packet)) {
                $redMode = $packet['mode'] === 'equal' ? 'equal' : 'random';
                $redTotal = (int)$packet['total_credits'];
                $redCount = (int)$packet['total_count'];
                $fieldMap['lumina_redpacket_mode'] = $redMode;
                $fieldMap['lumina_redpacket_total'] = (string)$redTotal;
                $fieldMap['lumina_redpacket_count'] = (string)$redCount;
            }
            $fieldMap['lumina_redpacket_title'] = $redTitle;
        }
        $hasMedia = ($photosRaw !== '') || ($livePhotosRaw !== '') || ($videoRaw !== '') || ($embedRaw !== '') || ($musicRaw !== '') || ($linkRaw !== '') || $isRedpacket;

        if (trim($content) === '' && !$hasMedia) {
            Output::error('内容不能为空');
        }

        if ($title === '') {
            $title = mb_substr(trim(strip_tags($content)), 0, 20);
            if ($title === '') {
                $title = '未命名';
            }
        }

        $excerpt = '';
        $origContent = trim($contentRaw);
        if ($origContent !== '') {
            $parseDown = new Parsedown();
            $excerpt = $parseDown->text($origContent);
            $excerpt = extractHtmlData($excerpt, 180);
            $excerpt = str_replace(["\r", "\n", "'", '"'], ' ', $excerpt);
            $excerpt = addslashes($excerpt);
        }

        if (empty($cover)) {
            if ($content) {
                $cover = getFirstImage($content);
            }
            if (empty($cover) && $photosRaw !== '') {
                $photoList = preg_split("/\\r\\n|\\n|\\r/", $photosRaw);
                if (!empty($photoList)) {
                    foreach ($photoList as $photoItem) {
                        $photoItem = trim((string)$photoItem);
                        if ($photoItem !== '') {
                            $cover = $photoItem;
                            break;
                        }
                    }
                }
            }
            if (empty($cover) && !empty($fieldMap['lumina_link_image'])) {
                $cover = trim((string)$fieldMap['lumina_link_image']);
            }
        }

        $ishide = $draft === 'y' ? 'y' : 'n';
        $checked = Option::get('ischkarticle') == 'y' && !in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true) ? 'n' : 'y';

        if (!method_exists($Log_Model, 'updateLog')) {
            Output::error('系统暂不支持前台编辑');
        }

        $logAlias = isset($log['alias']) ? $log['alias'] : (isset($log['log_alias']) ? $log['log_alias'] : '');
        $logDate = isset($log['date']) ? $log['date'] : (isset($log['log_date']) ? $log['log_date'] : time());
        $logTop = isset($log['top']) ? $log['top'] : (isset($log['log_top']) ? $log['log_top'] : 'n');
        $logSortTop = isset($log['sortop']) ? $log['sortop'] : (isset($log['log_sortop']) ? $log['log_sortop'] : 'n');
        $logPassword = isset($log['password']) ? $log['password'] : (isset($log['log_password']) ? $log['log_password'] : '');
        $logLink = isset($log['link']) ? $log['link'] : (isset($log['log_link']) ? $log['log_link'] : '');
        $logTemplate = isset($log['template']) ? $log['template'] : (isset($log['log_template']) ? $log['log_template'] : '');

        $canTop = in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true) || User::isAdmin();
        $top = $canTop && Input::postStrVar('top', $logTop) === 'y' ? 'y' : $logTop;
        $logData = [
            'title'        => $title,
            'alias'        => $logAlias,
            'content'      => $content,
            'excerpt'      => $excerpt,
            'cover'        => $cover,
            'author'       => isset($log['author']) ? $log['author'] : (int)(current_user()['id'] ?? 0),
            'sortid'       => $sort,
            'date'         => $logDate,
            'top'          => $top,
            'sortop'       => $logSortTop,
            'allow_remark' => $allow_remark === 'y' ? 'y' : 'n',
            'hide'         => $ishide,
            'checked'      => $checked,
            'password'     => $logPassword,
            'link'         => $logLink,
            'template'     => $logTemplate,
        ];

        doMultiAction('pre_save_log', $logData, $logData);

        $Log_Model->updateLog($logData, $blogid);
        $Tag_Model = new Tag_Model();
        if (method_exists($Tag_Model, 'updateTag')) {
            $Tag_Model->updateTag($tagstring, $blogid);
        } else {
            $Tag_Model->addTag($tagstring, $blogid);
        }
        $CACHE->updateArticleCache();
        if (!empty($fieldMap)) {
            $field_keys = array_keys($fieldMap);
            $field_values = array_values($fieldMap);
        }
        Field::updateField($blogid, $field_keys, $field_values);
        do_action('save_log', $blogid, $ishide === 'n', $logData);

        Output::ok(['article_id' => $blogid]);
    }

    Output::error('非法请求');
}

if ($routerPath === 'card') {
    if (lumina_opt('profile_card_enable', 'n') !== 'y') {
        http_response_code(404); echo render("error", ["status" => 404, "message" => "内容不存在", "backUrl" => url_to("/")]); exit;
    }

    $card_uid = isset($params[3]) && is_numeric($params[3]) ? (int)$params[3] : 1;
    if ($card_uid <= 0) {
        $card_uid = 1;
    }
    if (empty(lumina_get_user_info($card_uid))) {
        http_response_code(404); echo render("error", ["status" => 404, "message" => "内容不存在", "backUrl" => url_to("/")]); exit;
    }
    $lumina_avatar = lumina_opt('default_avatar', lumina_tpl_base() . 'assets/img/tx.png');
    if ($lumina_avatar === '') {
        $lumina_avatar = lumina_tpl_base() . 'assets/img/tx.png';
    }
    $lumina_avatar = lumina_resolve_url($lumina_avatar, lumina_tpl_base() . 'assets/img/tx.png');
    $card_avatar = lumina_get_user_avatar($card_uid, $lumina_avatar);
    $card_name = lumina_get_user_name($card_uid, blog_author($card_uid));
    if ($card_name === '') {
        $card_name = class_exists('Option') ? (string)site_name() : '';
    }
    $card_is_owner = ($card_uid === 1);
    $card_gender = 'hidden';
    $card_wechat = '';
    $card_region = '';
    $card_message_url = '';
    if ($card_is_owner) {
        $card_gender = lumina_opt('profile_card_gender', 'hidden');
        if (!in_array($card_gender, ['hidden', 'male', 'female'], true)) {
            $card_gender = 'hidden';
        }
        $card_wechat = trim((string)lumina_opt('profile_card_wechat', ''));
        $card_region = trim((string)lumina_opt('profile_card_region', ''));
        $card_message_url = trim((string)lumina_opt('profile_card_message_url', ''));
        if ($card_message_url !== '') {
            $card_message_url = lumina_resolve_url($card_message_url, $card_message_url);
        }
    }
    $card_moments_url = class_exists('Url') ? url_to('/author/' . rawurlencode((string)($card_uid))) : lumina_blog_base();
    $card_thumbs = lumina_get_profile_moment_thumbs($card_uid, 4, 32);

    // 个性签名：owner 优先模板选项，否则回退用户自身签名设置
    $card_sign = '';
    if ($card_is_owner) {
        $card_sign = trim((string)lumina_opt('profile_card_signature', ''));
    }
    if ($card_sign === '') {
        $card_user = lumina_get_user_info($card_uid);
        if (!empty($card_user['sign'])) {
            $card_sign = trim((string)$card_user['sign']);
        } elseif (!empty($card_user['description'])) {
            $card_sign = trim((string)$card_user['description']);
        } elseif (!empty($card_user['description_orig'])) {
            $card_sign = trim((string)$card_user['description_orig']);
        }
    }

    // pafish: header already loaded by get_header()
    ?>
    <div class="centent lumina-layout lumina-layout-single lumina-page-user lumina-profile-page" data-lumina-pjax-container>
        <div class="setup-main lumina-profile-shell">
            <div class="sh-main-head setup-main-head">
                <div class="sh-main-head-top setup-main-top" id="sh-main-head-top">
                    <div class="sh-main-head-top-left setup-main-top-left">
                        <button type="button" class="sh-main-head-top-left-s setup-main-backbtn" data-lumina-back-url="<?= htmlspecialchars(lumina_blog_base(), ENT_QUOTES) ?>" onclick="return window.luminaNavigateBack ? window.luminaNavigateBack(this,event) : (location.href='<?= lumina_blog_base() ?>', false)" aria-label="返回">
                            <i class="iconfont icon-weibiaoti al-sxbh" id="top-left-1"></i>
                        </button>
                    </div>
                </div>
            </div>
        <section class="lumina-profile-card" aria-label="个人资料卡">
            <div class="lumina-profile-hero">
                <span class="lumina-profile-avatar">
                    <img src="<?= htmlspecialchars($card_avatar, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($card_name, ENT_QUOTES) ?>" data-fancybox="lumina-profile-avatar" data-caption="<?= htmlspecialchars($card_name, ENT_QUOTES) ?>" onerror="this.onerror=null;this.src='<?= htmlspecialchars($lumina_avatar, ENT_QUOTES) ?>'">
                </span>
                <div class="lumina-profile-meta">
                    <h1 class="lumina-profile-name">
                        <span><?= htmlspecialchars($card_name, ENT_QUOTES) ?></span>
                        <?php if ($card_gender === 'male'): ?>
                            <span class="lumina-profile-gender is-male" aria-label="男">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14m-5 0a5 5 0 1 0 10 0a5 5 0 1 0 -10 0"/><path d="M19 5l-5.4 5.4"/><path d="M19 5h-5"/><path d="M19 5v5"/></svg>
                            </span>
                        <?php elseif ($card_gender === 'female'): ?>
                            <span class="lumina-profile-gender is-female" aria-label="女">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9m-5 0a5 5 0 1 0 10 0a5 5 0 1 0 -10 0"/><path d="M12 14v7"/><path d="M9 18h6"/></svg>
                            </span>
                        <?php endif; ?>
                    </h1>
                    <?php if ($card_wechat !== ''): ?>
                        <p class="lumina-profile-line">微信号：<span class="lumina-profile-wechat-text" data-copy="<?= htmlspecialchars($card_wechat, ENT_QUOTES) ?>" title="点击复制"><?= htmlspecialchars($card_wechat, ENT_QUOTES) ?></span></p>
                    <?php endif; ?>
                    <?php if ($card_region !== ''): ?>
                        <p class="lumina-profile-line">地区：<?= htmlspecialchars($card_region, ENT_QUOTES) ?></p>
                    <?php endif; ?>
                    <?php if ($card_sign !== ''): ?>
                        <p class="lumina-profile-line">个性签名：<?= htmlspecialchars($card_sign, ENT_QUOTES) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <a class="lumina-profile-row lumina-profile-moments-row" href="<?= htmlspecialchars($card_moments_url, ENT_QUOTES) ?>" aria-label="查看朋友圈">
                <span class="lumina-profile-row-label">朋友圈</span>
                <span class="lumina-profile-row-media">
                    <?php foreach ($card_thumbs as $thumb): ?>
                        <img src="<?= htmlspecialchars($thumb, ENT_QUOTES) ?>" alt="" loading="lazy" decoding="async" draggable="false" data-fancybox="lumina-profile-moments">
                    <?php endforeach; ?>
                </span>
                <span class="lumina-profile-row-arrow" aria-hidden="true"></span>
            </a>

            <div class="lumina-profile-actions">
                <a class="lumina-profile-message-btn" href="<?= $card_message_url !== '' ? htmlspecialchars($card_message_url, ENT_QUOTES) : lumina_blog_base() ?>">
                    <i class="iconfont icon-pinglun2 lumina-profile-message-icon"></i>
                    <span>发消息</span>
                </a>
            </div>
        </section>
        <?php lumina_render_main_footer(); ?>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/footer.php';
    return;
}

if (!is_logged_in()) {
    // pafish: header already loaded by get_header()
    ?>
    <div class="centent lumina-layout lumina-layout-single lumina-page-user lumina-page-guest">
        <div class="lumina-layout-wrap">
            <div class="sh-main lumina-guest-main">
                <div class="lumina-guest-topbar">
                    <button type="button" class="lumina-guest-back" onclick="if (history.length > 1) { history.back(); } else { location.href='<?= lumina_blog_base() ?>'; }" aria-label="返回">
                        <i class="iconfont icon-weibiaoti al-sxbh"></i>
                    </button>
                    <span>用户中心</span>
                </div>
                <div class="lumina-guest-state">
                    <div class="lumina-guest-mark">
                        <i class="iconfont icon-account-circle-line"></i>
                    </div>
                    <h2>请先登录</h2>
                    <p>登录后可以发布动态、编辑资料和管理自己的内容。</p>
                    <a class="lumina-guest-login" href="<?= lumina_blog_base() ?>admin/account.php?action=signin">前往登录</a>
                </div>
                <?php lumina_render_main_footer(); ?>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/footer.php';
    return;
}

$isProfilePage = ($routerPath === 'profile');

if (!in_array($routerPath, ['', 'post', 'profile'], true)) {
    http_response_code(404); echo render("error", ["status" => 404, "message" => "内容不存在", "backUrl" => url_to("/")]); exit;
}

if ($isProfilePage) {
    $profile_uid = (int)(current_user()['id'] ?? 0);
    $profile_user = lumina_get_user_info($profile_uid);
    $profile_name = lumina_get_user_name($profile_uid, blog_author($profile_uid));
    $profile_sign = '';
    if (!empty($profile_user)) {
        if (!empty($profile_user['sign'])) {
            $profile_sign = $profile_user['sign'];
        } elseif (!empty($profile_user['description'])) {
            $profile_sign = $profile_user['description'];
        } elseif (!empty($profile_user['description_orig'])) {
            $profile_sign = $profile_user['description_orig'];
        }
    }
    $profile_url = isset($profile_user['url']) ? trim((string)$profile_user['url']) : '';
    $profile_email = isset($profile_user['email']) ? trim((string)$profile_user['email']) : '';
    $profile_username = isset($profile_user['username']) ? trim((string)$profile_user['username']) : '';
    $lumina_avatar = lumina_opt('default_avatar', lumina_tpl_base() . 'assets/img/tx.png');
    if ($lumina_avatar === '') {
        $lumina_avatar = lumina_tpl_base() . 'assets/img/tx.png';
    }
    $lumina_avatar = lumina_resolve_url($lumina_avatar, lumina_tpl_base() . 'assets/img/tx.png');
    $profile_avatar = lumina_get_user_avatar($profile_uid, $lumina_avatar);
    $profile_cover = lumina_get_user_cover($profile_uid, '');
    if ($profile_cover === '' || $profile_cover === '-1') {
        $profile_cover = lumina_opt('header_cover', lumina_tpl_base() . 'assets/img/homeimg.jpg');
    }
    $profile_cover = lumina_resolve_url($profile_cover, lumina_tpl_base() . 'assets/img/homeimg.jpg');
    $useBloggerApi = defined('dirname(__DIR__, 2)') && file_exists(dirname(__DIR__, 2) . '/admin/blogger.php');
    $profileAction = $useBloggerApi ? (lumina_blog_base() . 'admin/blogger.php?action=update') : lumina_user_url('profile');
    $passwordAction = $useBloggerApi ? (lumina_blog_base() . 'admin/blogger.php?action=change_password') : lumina_user_url('profile');

    $pc_layout = 'single';

    // pafish: header already loaded by get_header()
    ?>
    <div class="centent lumina-layout lumina-layout-single lumina-layout-pc-<?= $pc_layout ?> lumina-page-user">
        <div class="lumina-layout-wrap">
            <div class="sh-main setup-main">
                <div class="sh-main-head setup-main-head">
                    <div class="sh-main-head-top setup-main-top" id="sh-main-head-top">
                        <div class="sh-main-head-top-left setup-main-top-left">
                            <button type="button" class="sh-main-head-top-left-s setup-main-backbtn" onclick="if (history.length > 1) { history.back(); } else { location.href='<?= lumina_blog_base() ?>'; }" aria-label="返回">
                                <i class="iconfont icon-weibiaoti al-sxbh" id="top-left-1"></i>
                            </button>
                            <span class="setup-main-title">设置</span>
                        </div>
                    </div>
                </div>

                <div class="sh-cont-nr">
                    <div class="lumina-setting-wrap">
                        <div class="lumina-setting-section-title">账号资料</div>
                        <div class="lumina-setting-group">
                            <div class="lumina-setting-item lumina-setting-upload lumina-setting-toggle" data-target="lumina-avatar-panel">
                                <div class="lumina-setting-label">头像</div>
                                <div class="lumina-setting-value">
                                    <div class="lumina-setting-thumb">
                                        <?php $avatarStyle = $profile_avatar ? 'display:block' : 'display:none'; ?>
                                        <span class="lumina-setting-plus" data-preview="avatar" style="<?= $profile_avatar ? 'display:none' : '' ?>">+</span>
                                        <img id="lumina-avatar-preview" data-preview="avatar" src="<?= $profile_avatar ?>" alt="avatar" class="lumina-setting-avatar" style="<?= $avatarStyle ?>">
                                    </div>
                                </div>
                                <span class="lumina-setting-arrow">></span>
                                <input type="file" id="lumina-avatar-input" class="lumina-setting-file" accept="image/*" data-preview="avatar" data-mode="<?= $useBloggerApi ? 'blogger' : 'local' ?>" data-blogger-url="<?= lumina_blog_base() ?>admin/blogger.php?action=update_avatar" data-upload-url="<?= lumina_user_url('profile') ?>" data-blog-url="<?= lumina_blog_base() ?>" data-token="<?= csrf_token() ?>">
                            </div>
                            <div class="lumina-setting-panel lumina-setting-upload-panel" id="lumina-avatar-panel">
                                <div class="lumina-setting-upload-preview" data-upload-target="lumina-avatar-input">
                                    <span class="lumina-setting-plus" data-preview="avatar" style="<?= $profile_avatar ? 'display:none' : '' ?>">+</span>
                                    <img data-preview="avatar" src="<?= $profile_avatar ?>" alt="avatar" style="<?= $avatarStyle ?>">
                                </div>
                                <button type="button" class="lumina-setting-upload-btn" data-upload-target="lumina-avatar-input">上传</button>
                            </div>
                            <div class="lumina-setting-item lumina-setting-upload lumina-setting-toggle" data-target="lumina-cover-panel">
                                <div class="lumina-setting-label">封面</div>
                                <div class="lumina-setting-value">
                                    <div class="lumina-setting-thumb lumina-setting-thumb-cover">
                                        <?php $coverStyle = $profile_cover ? 'display:block' : 'display:none'; ?>
                                        <span class="lumina-setting-plus" data-preview="cover" style="<?= $profile_cover ? 'display:none' : '' ?>">+</span>
                                        <img id="lumina-cover-preview" data-preview="cover" src="<?= $profile_cover ?>" alt="cover" class="lumina-setting-cover" style="<?= $coverStyle ?>">
                                    </div>
                                </div>
                                <span class="lumina-setting-arrow">></span>
                                <input type="file" id="lumina-cover-input" class="lumina-setting-file" accept="image/*" data-preview="cover" data-action="cover_upload" data-upload-url="<?= lumina_user_url('profile') ?>" data-blog-url="<?= lumina_blog_base() ?>" data-token="<?= csrf_token() ?>">
                            </div>
                            <div class="lumina-setting-panel lumina-setting-upload-panel" id="lumina-cover-panel">
                                <div class="lumina-setting-upload-preview lumina-setting-upload-preview-cover" data-upload-target="lumina-cover-input">
                                    <span class="lumina-setting-plus" data-preview="cover" style="<?= $profile_cover ? 'display:none' : '' ?>">+</span>
                                    <img data-preview="cover" src="<?= $profile_cover ?>" alt="cover" style="<?= $coverStyle ?>">
                                </div>
                                <button type="button" class="lumina-setting-upload-btn" data-upload-target="lumina-cover-input">上传</button>
                            </div>

                            <form id="lumina-profile-form" method="post" action="<?= $profileAction ?>">
                                <?php if ($useBloggerApi): ?>
                                    <input type="hidden" name="token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="username" value="<?= htmlspecialchars($profile_username, ENT_QUOTES) ?>">
                                    <div class="lumina-setting-item lumina-setting-toggle" data-target="lumina-profile-name-panel">
                                        <div class="lumina-setting-label">昵称</div>
                                        <div class="lumina-setting-value">
                                            <span class="lumina-setting-text"><?= htmlspecialchars($profile_name, ENT_QUOTES) ?></span>
                                        </div>
                                        <span class="lumina-setting-arrow">></span>
                                    </div>
                                    <div class="lumina-setting-panel" id="lumina-profile-name-panel">
                                        <div class="lumina-setting-field">
                                            <input type="text" name="name" maxlength="10" value="<?= htmlspecialchars($profile_name, ENT_QUOTES) ?>" placeholder="请输入昵称" class="lumina-setting-input-block">
                                        </div>
                                    </div>
                                    <div class="lumina-setting-item lumina-setting-toggle" data-target="lumina-profile-sign-panel">
                                        <div class="lumina-setting-label">签名</div>
                                        <div class="lumina-setting-value">
                                            <span class="lumina-setting-text"><?= htmlspecialchars($profile_sign, ENT_QUOTES) ?></span>
                                        </div>
                                        <span class="lumina-setting-arrow">></span>
                                    </div>
                                    <div class="lumina-setting-panel" id="lumina-profile-sign-panel">
                                        <div class="lumina-setting-field">
                                            <input type="text" name="description" maxlength="50" value="<?= htmlspecialchars($profile_sign, ENT_QUOTES) ?>" placeholder="写点个性签名吧" class="lumina-setting-input-block">
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <input type="hidden" name="lumina_action" value="profile_update">
                                    <input type="hidden" name="token" value="<?= csrf_token() ?>">
                                    <div class="lumina-setting-item lumina-setting-toggle" data-target="lumina-profile-name-panel">
                                        <div class="lumina-setting-label">昵称</div>
                                        <div class="lumina-setting-value">
                                            <span class="lumina-setting-text"><?= htmlspecialchars($profile_name, ENT_QUOTES) ?></span>
                                        </div>
                                        <span class="lumina-setting-arrow">></span>
                                    </div>
                                    <div class="lumina-setting-panel" id="lumina-profile-name-panel">
                                        <div class="lumina-setting-field">
                                            <input type="text" name="nickname" maxlength="10" value="<?= htmlspecialchars($profile_name, ENT_QUOTES) ?>" placeholder="请输入昵称" class="lumina-setting-input-block">
                                        </div>
                                    </div>
                                    <div class="lumina-setting-item lumina-setting-toggle" data-target="lumina-profile-sign-panel">
                                        <div class="lumina-setting-label">签名</div>
                                        <div class="lumina-setting-value">
                                            <span class="lumina-setting-text"><?= htmlspecialchars($profile_sign, ENT_QUOTES) ?></span>
                                        </div>
                                        <span class="lumina-setting-arrow">></span>
                                    </div>
                                    <div class="lumina-setting-panel" id="lumina-profile-sign-panel">
                                        <div class="lumina-setting-field">
                                            <input type="text" name="signature" maxlength="50" value="<?= htmlspecialchars($profile_sign, ENT_QUOTES) ?>" placeholder="写点个性签名吧" class="lumina-setting-input-block">
                                        </div>
                                    </div>
                                    <div class="lumina-setting-item lumina-setting-toggle" data-target="lumina-profile-url-panel">
                                        <div class="lumina-setting-label">网址</div>
                                        <div class="lumina-setting-value">
                                            <span class="lumina-setting-text"><?= htmlspecialchars($profile_url, ENT_QUOTES) ?></span>
                                        </div>
                                        <span class="lumina-setting-arrow">></span>
                                    </div>
                                    <div class="lumina-setting-panel" id="lumina-profile-url-panel">
                                        <div class="lumina-setting-field">
                                            <input type="text" name="url" value="<?= htmlspecialchars($profile_url, ENT_QUOTES) ?>" placeholder="https://" class="lumina-setting-input-block">
                                        </div>
                                    </div>
                                    <div class="lumina-setting-item lumina-setting-toggle" data-target="lumina-profile-email-panel">
                                        <div class="lumina-setting-label">邮箱</div>
                                        <div class="lumina-setting-value">
                                            <span class="lumina-setting-text"><?= htmlspecialchars($profile_email, ENT_QUOTES) ?></span>
                                        </div>
                                        <span class="lumina-setting-arrow">></span>
                                    </div>
                                    <div class="lumina-setting-panel" id="lumina-profile-email-panel">
                                        <div class="lumina-setting-field">
                                            <input type="email" name="email" value="<?= htmlspecialchars($profile_email, ENT_QUOTES) ?>" placeholder="邮箱地址" class="lumina-setting-input-block">
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>

                        <div class="lumina-setting-section-title">安全与密码</div>
                        <div class="lumina-setting-group">
                            <div class="lumina-setting-item lumina-setting-toggle" data-target="lumina-security-panel">
                                <div class="lumina-setting-label">安全中心</div>
                                <div class="lumina-setting-value"></div>
                                <span class="lumina-setting-arrow">></span>
                            </div>
                            <form id="lumina-password-form" method="post" action="<?= $passwordAction ?>">
                                <?php if ($useBloggerApi): ?>
                                    <input type="hidden" name="token" value="<?= csrf_token() ?>">
                                <?php else: ?>
                                    <input type="hidden" name="lumina_action" value="password_update">
                                    <input type="hidden" name="token" value="<?= csrf_token() ?>">
                                <?php endif; ?>
                                <div class="lumina-setting-panel" id="lumina-security-panel">
                                    <?php if ($profile_username !== ''): ?>
                                        <div class="lumina-setting-field">
                                            <div class="lumina-setting-field-label">账号</div>
                                            <input type="text" value="<?= htmlspecialchars($profile_username, ENT_QUOTES) ?>" readonly class="lumina-setting-input-block">
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($useBloggerApi): ?>
                                        <div class="lumina-setting-field">
                                            <div class="lumina-setting-field-label">新密码</div>
                                            <input type="password" name="new_passwd" placeholder="请输入新密码" class="lumina-setting-input-block">
                                        </div>
                                        <div class="lumina-setting-field">
                                            <div class="lumina-setting-field-label">确认新密码</div>
                                            <input type="password" name="new_passwd2" placeholder="请再次输入新密码" class="lumina-setting-input-block">
                                        </div>
                                    <?php else: ?>
                                        <div class="lumina-setting-field">
                                            <div class="lumina-setting-field-label">旧密码</div>
                                            <input type="password" name="old_password" placeholder="输入旧密码" class="lumina-setting-input-block">
                                        </div>
                                        <div class="lumina-setting-field">
                                            <div class="lumina-setting-field-label">新密码</div>
                                            <input type="password" name="new_password" placeholder="输入新密码" class="lumina-setting-input-block">
                                        </div>
                                        <div class="lumina-setting-field">
                                            <div class="lumina-setting-field-label">确认新密码</div>
                                            <input type="password" name="new_password2" placeholder="请再次输入新密码" class="lumina-setting-input-block">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                        <div class="lumina-setting-section-title">操作</div>
                        <div class="lumina-setting-group lumina-setting-group-actions">
                            <button type="button" class="setup-main-lieb-gxan lumina-setting-save" id="lumina-profile-save-all"><span>保存更改</span></button>
                            <a class="setup-main-lieb-gxan lumina-setting-save lumina-setting-logout" href="<?= lumina_blog_base() ?>admin/account.php?action=logout"><span>退出登录</span></a>
                        </div>
                    </div>
                </div>
        <?php lumina_render_main_footer(); ?>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/footer.php';
    return;
}

$editId = Input::getIntVar('edit', 0);
$isEdit = false;
$editLog = null;
$editFields = [];
$editTags = '';
$editSort = -1;
$editTitle = '';
$editContent = '';
$editCover = '';
$editAllowRemark = 'y';
$editDraft = 'n';
$editTop = 'n';
$editLocation = '';
$editType = 'img';
$editPhotos = '';
$editVideo = '';
$editVideoPoster = '';
$editMusic = '';
$editMusicTitle = '';
$editMusicArtist = '';
$editMusicCover = '';
$editRedpacketMode = '';
$editRedpacketTotal = '';
$editRedpacketCount = '';
$editRedpacketTitle = '';
$redpacketLocked = false;

if ($editId > 0) {
    $Log_Model = new Log_Model();
    if (method_exists($Log_Model, 'getOneLogForHome')) {
        $editLog = $Log_Model->getOneLogForHome($editId, true, true);
    } elseif (method_exists($Log_Model, 'getOneLog')) {
        $editLog = $Log_Model->getOneLog($editId);
    }
    if (empty($editLog)) {
        http_response_code(404); echo render("error", ["status" => 404, "message" => "内容不存在", "backUrl" => url_to("/")]); exit;
    }
    $canEdit = (isset($editLog['author']) && (int)$editLog['author'] === (int)(current_user()['id'] ?? 0)) || (class_exists('User') && in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true));
    if (!$canEdit) {
        Output::error('无权限编辑该文章');
    }
    $isEdit = true;
    $editTitle = isset($editLog['log_title']) ? $editLog['log_title'] : (isset($editLog['title']) ? $editLog['title'] : '');
    $editContent = isset($editLog['log_content']) ? $editLog['log_content'] : (isset($editLog['content']) ? $editLog['content'] : '');
    $editCover = isset($editLog['log_cover']) ? $editLog['log_cover'] : (isset($editLog['cover']) ? $editLog['cover'] : '');
    $editAllowRemark = isset($editLog['allow_remark']) ? $editLog['allow_remark'] : 'y';
    $editDraft = (isset($editLog['hide']) && $editLog['hide'] === 'y') ? 'y' : 'n';
    $editSort = isset($editLog['sortid']) ? (int)$editLog['sortid'] : -1;
    $editTop = isset($editLog['top']) ? $editLog['top'] : (isset($editLog['log_top']) ? $editLog['log_top'] : 'n');

    $editFields = isset($editLog['fields']) && is_array($editLog['fields']) ? $editLog['fields'] : [];
    if (empty($editFields) && class_exists('Field') && method_exists('Field', 'getFieldValue')) {
        $fieldKeys = [
            'lumina_type',
            'lumina_photos',
            'lumina_video_url',
            'lumina_video_poster',
            'lumina_embed_url',
            'lumina_embed_ratio',
            'lumina_embed_cover',
            'lumina_live_photos',
            'lumina_music_url',
            'lumina_music_title',
            'lumina_music_artist',
            'lumina_music_cover',
            'lumina_link_url',
            'lumina_link_title',
            'lumina_link_desc',
            'lumina_link_image',
            'lumina_location',
            'lumina_location_address',
            'lumina_location_lat',
            'lumina_location_lng',
            'lumina_location_poi_id',
            'lumina_location_city',
            'lumina_redpacket_mode',
            'lumina_redpacket_total',
            'lumina_redpacket_count',
            'lumina_redpacket_title'
        ];
        foreach ($fieldKeys as $fieldKey) {
            $fieldVal = Field::getFieldValue($editId, $fieldKey);
            if ($fieldVal !== '' && $fieldVal !== null) {
                $editFields[$fieldKey] = $fieldVal;
            }
        }
    }

    $editType = isset($editFields['lumina_type']) && $editFields['lumina_type'] !== '' ? $editFields['lumina_type'] : 'img';
    $editPhotos = isset($editFields['lumina_photos']) ? $editFields['lumina_photos'] : '';
    $editVideo = isset($editFields['lumina_video_url']) ? $editFields['lumina_video_url'] : '';
    $editVideoPoster = isset($editFields['lumina_video_poster']) ? $editFields['lumina_video_poster'] : '';
    $editEmbed = isset($editFields['lumina_embed_url']) ? $editFields['lumina_embed_url'] : '';
    $editEmbedRatio = isset($editFields['lumina_embed_ratio']) ? $editFields['lumina_embed_ratio'] : 'lr';
    $editEmbedCover = isset($editFields['lumina_embed_cover']) ? $editFields['lumina_embed_cover'] : '';
    $editLivePhotos = isset($editFields['lumina_live_photos']) ? $editFields['lumina_live_photos'] : '';
    $editMusic = isset($editFields['lumina_music_url']) ? $editFields['lumina_music_url'] : '';
    $editMusicTitle = isset($editFields['lumina_music_title']) ? $editFields['lumina_music_title'] : '';
    $editMusicArtist = isset($editFields['lumina_music_artist']) ? $editFields['lumina_music_artist'] : '';
    $editMusicCover = isset($editFields['lumina_music_cover']) ? $editFields['lumina_music_cover'] : '';
    $editLinkUrl = isset($editFields['lumina_link_url']) ? $editFields['lumina_link_url'] : '';
    $editLinkTitle = isset($editFields['lumina_link_title']) ? $editFields['lumina_link_title'] : '';
    $editLinkDesc = isset($editFields['lumina_link_desc']) ? $editFields['lumina_link_desc'] : '';
    $editLinkImage = isset($editFields['lumina_link_image']) ? $editFields['lumina_link_image'] : '';
    $editLocation = isset($editFields['lumina_location']) ? $editFields['lumina_location'] : '';
    $editLocationAddress = isset($editFields['lumina_location_address']) ? $editFields['lumina_location_address'] : '';
    $editLocationLat = isset($editFields['lumina_location_lat']) ? $editFields['lumina_location_lat'] : '';
    $editLocationLng = isset($editFields['lumina_location_lng']) ? $editFields['lumina_location_lng'] : '';
    $editLocationPoiId = isset($editFields['lumina_location_poi_id']) ? $editFields['lumina_location_poi_id'] : '';
    $editLocationCity = isset($editFields['lumina_location_city']) ? $editFields['lumina_location_city'] : '';
    $editRedpacketMode = isset($editFields['lumina_redpacket_mode']) ? $editFields['lumina_redpacket_mode'] : '';
    $editRedpacketTotal = isset($editFields['lumina_redpacket_total']) ? $editFields['lumina_redpacket_total'] : '';
    $editRedpacketCount = isset($editFields['lumina_redpacket_count']) ? $editFields['lumina_redpacket_count'] : '';
    $editRedpacketTitle = isset($editFields['lumina_redpacket_title']) ? $editFields['lumina_redpacket_title'] : '';

    if ($editType === 'redpacket') {
        $packet = lumina_redpacket_get($editId);
        if (!empty($packet)) {
            $redpacketLocked = true;
            $editRedpacketMode = $packet['mode'] === 'equal' ? 'equal' : 'random';
            $editRedpacketTotal = (string)(int)$packet['total_credits'];
            $editRedpacketCount = (string)(int)$packet['total_count'];
        }
    }

    if ($editContent !== '') {
        $hasHtml = preg_match('/<\\/?[a-z][^>]*>/i', $editContent) || strpos($editContent, '&lt;') !== false;
        if ($hasHtml) {
            $editContent = lumina_html_to_text($editContent, '<img><a>');
        }
    }

    if (method_exists($Log_Model, 'getTag')) {
        $tagList = $Log_Model->getTag($editId);
        if (is_array($tagList)) {
            $tagNames = [];
            foreach ($tagList as $tag) {
                if (is_array($tag) && isset($tag['tagname'])) {
                    $tagNames[] = $tag['tagname'];
                } elseif (is_string($tag)) {
                    $tagNames[] = $tag;
                }
            }
            $editTags = implode(',', $tagNames);
        }
    }
}

$Sort_Model = new Sort_Model();
$sorts = $Sort_Model->getSorts(true);
$isAdmin = is_logged_in() && User::isAdmin();
$canTop = is_logged_in() && (in_array((string)(current_user()['role'] ?? ''), ['ADMIN', 'EDITOR'], true) || $isAdmin);
$hasMediaLib = $isAdmin && (lumina_table_exists(DB_PREFIX . 'attachment') || lumina_table_exists(DB_PREFIX . 'media'));
$mediaItems = $hasMediaLib ? lumina_get_media_library(80) : [];
$tencentMapEnabled = lumina_opt('enable_tencent_map', 'n') === 'y' && trim((string)lumina_opt('tencent_map_key', '')) !== '';

$pc_layout = 'single';
$show_sidebar = ($pc_layout !== 'single');
$profile_uid = (int)(current_user()['id'] ?? 0);
$profile_user = lumina_get_user_info($profile_uid);
$profile_name = lumina_get_user_name($profile_uid, blog_author($profile_uid));
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
$lumina_avatar = lumina_opt('default_avatar', lumina_tpl_base() . 'assets/img/tx.png');
if ($lumina_avatar === '') {
    $lumina_avatar = lumina_tpl_base() . 'assets/img/tx.png';
}
$lumina_avatar = lumina_resolve_url($lumina_avatar, lumina_tpl_base() . 'assets/img/tx.png');
$profile_avatar = lumina_get_user_avatar($profile_uid, $lumina_avatar);

$sidebar_contact = trim((string)lumina_opt('contact_info', ''));
if ($sidebar_contact === '') {
    $sidebar_contact = $profile_desc;
}

// pafish: header already loaded by get_header()
?>
<div id="editor-md-dialog" class="lumina-editor-dialog-root"></div>
<div class="centent lumina-layout lumina-layout-single lumina-layout-pc-<?= $pc_layout ?> lumina-page-user">
    <div class="lumina-layout-wrap">
    <div class="sh-main setup-main edit-main">
        <div class="sh-main-head setup-main-head lumina-publish-head">
            <div class="sh-main-head-top setup-main-top lumina-publish-bar" id="sh-main-head-top">
                <div class="lumina-publish-bar-left">
                    <button type="button" class="lumina-publish-backbtn" onclick="location.href='<?= lumina_blog_base() ?>'" aria-label="返回">
                        <i class="iconfont icon-weibiaoti al-sxbh" id="top-left-1"></i>
                    </button>
                </div>
                <div class="lumina-publish-bar-right">
                    <button
                        type="submit"
                        form="lumina-post-form"
                        class="lumina-publish-submit-btn"
                        id="lumina-publish-submit"
                        aria-disabled="true"
                        disabled
                    ><?= $isEdit ? '更新' : '发表' ?></button>
                </div>
            </div>
        </div>

        <div class="sh-cont-nr">
            <form id="lumina-post-form" class="lumina-post-form" action="<?= lumina_user_url() ?>" method="post" data-blog-url="<?= lumina_blog_base() ?>">
                <input type="hidden" name="lumina_action" value="<?= $isEdit ? 'post_update' : 'post_publish' ?>">
                <input type="hidden" name="token" value="<?= csrf_token() ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="blogid" id="lumina-blogid" value="<?= $editId ?>">
                <?php endif; ?>
                <div class="sh-cont-nr">
                    <div class="sh-cont-nr-tzh">
                        <textarea name="content" id="lumina-content" data-default-placeholder="说点什么.." placeholder="说点什么.."><?= htmlspecialchars($isEdit ? $editContent : '') ?></textarea>
                    </div>
                </div>

                <div class="sh-cont-nr-kg lumina-short-post-tools" data-lumina-short-only>
                    <div class="sh-cont-nr-kg-bqkg" onclick="shkgbqkg()" title="表情" id="bqkg">
                        <i class="iconfont icon-biaoqing ri-sxfbbq" id="bqkgimg"></i>
                    </div>
                    <div class="sh-cont-nr-kg-lxqh" onclick="luminaSetType('img')" title="图片" id="lumina-type-img" data-label="图片">
                        <i class="iconfont icon-tupian ri-sxfbbqls" id="lumina-type-img-icon"></i>
                    </div>
                    <div class="sh-cont-nr-kg-lxqh" onclick="luminaSetType('live')" title="实况图" id="lumina-type-live" data-label="实况">
                        <i class="iconfont icon-xiangji1 ri-sxfbbq" id="lumina-type-live-icon"></i>
                    </div>
                    <div class="sh-cont-nr-kg-lxqh" onclick="luminaSetType('video')" title="本地视频" id="lumina-type-video" data-label="本地视频">
                        <i class="iconfont icon-shipinbofang ri-sxfbbq" id="lumina-type-video-icon"></i>
                    </div>
                    <div class="sh-cont-nr-kg-lxqh" onclick="luminaSetType('embed')" title="平台视频" id="lumina-type-embed" data-label="平台视频">
                        <i class="iconfont icon-24gf-playCircle ri-sxfbbq" id="lumina-type-embed-icon"></i>
                    </div>
                    <div class="sh-cont-nr-kg-lxqh" onclick="luminaSetType('music')" title="音乐" id="lumina-type-music" data-label="音乐">
                        <i class="iconfont icon-yinle_2 ri-sxfbbq" id="lumina-type-music-icon"></i>
                    </div>
                    <div class="sh-cont-nr-kg-lxqh" onclick="luminaSetType('link')" title="链接卡片" id="lumina-type-link" data-label="链接">
                        <i class="iconfont icon-lianjie1 ri-sxfbbq" id="lumina-type-link-icon"></i>
                    </div>
                    <div class="sh-cont-nr-kg-lxqh" onclick="luminaSetType('redpacket')" title="红包" id="lumina-type-redpacket" data-label="红包">
                        <i class="iconfont icon-hongbao ri-sxfbbq" id="lumina-type-redpacket-icon" aria-hidden="true"></i>
                    </div>
                    <div class="sh-cont-nr-kg-twl" onclick="tpscfs()" title="切换外链图片" id="tpscfs" lang="1">
                        <i class="iconfont icon-qiehuan ri-sxfbbqls" id="twlimg"></i>
                    </div>
                    <div class="sh-cont-nr-kg-ggkg" onclick="luminaToggleMore()" title="更多设置" id="lumina-more-btn">
                        <i class="iconfont icon-gengduo ri-sxfbbq" id="lumina-more-icon"></i>
                    </div>
                </div>
                <div class="lumina-emoji-panel sh-pinglun-biao" id="lumina-emoji-panel" data-lumina-short-only>
                            <?php
                            $emojiDir = defined('__LUMINA_DIR__') ? __LUMINA_DIR__ . 'assets/owo/paopao/' : '';
                            $emojiList = $emojiDir ? glob($emojiDir . '*.png') : [];
                            if (!empty($emojiList)) {
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
                                    $token = '::(' . $alt . ')';
                                    $emojiUrl = lumina_tpl_base() . 'assets/owo/paopao/' . $emojiName;
                                    echo '<img data-emoji-src="' . $emojiUrl . '" data-emoji-token="' . htmlspecialchars($token) . '" src="' . $emojiUrl . '" alt="' . htmlspecialchars($token) . '">';
                                }
                            }
                            ?>
                        </div>
                <div class="lumina-pub-location<?= $tencentMapEnabled ? ' lumina-pub-location-map' : '' ?>" data-map-enabled="<?= $tencentMapEnabled ? 'y' : 'n' ?>" data-lumina-short-only>
                    <i class="iconfont icon-dingwei1"></i>
                    <input type="text" id="lumina-location" data-field-key="lumina_location" placeholder="当前位置" value="<?= htmlspecialchars($isEdit ? $editLocation : '') ?>"<?= $tencentMapEnabled ? ' readonly' : '' ?>>
                    <button type="button" class="lumina-location-pick" id="lumina-location-pick"><?= $tencentMapEnabled ? '选择' : '文本' ?></button>
                </div>
                <input type="hidden" id="lumina-location-address" data-field-key="lumina_location_address" value="<?= htmlspecialchars($isEdit ? $editLocationAddress : '') ?>">
                <input type="hidden" id="lumina-location-lat" data-field-key="lumina_location_lat" value="<?= htmlspecialchars($isEdit ? $editLocationLat : '') ?>">
                <input type="hidden" id="lumina-location-lng" data-field-key="lumina_location_lng" value="<?= htmlspecialchars($isEdit ? $editLocationLng : '') ?>">
                <input type="hidden" id="lumina-location-poi-id" data-field-key="lumina_location_poi_id" value="<?= htmlspecialchars($isEdit ? $editLocationPoiId : '') ?>">
                <input type="hidden" id="lumina-location-city" data-field-key="lumina_location_city" value="<?= htmlspecialchars($isEdit ? $editLocationCity : '') ?>">

                <div class="sh-cont-img" id="sh-cont-img">
                    <div class="lumina-pub-media">
                        <div class="lumina-img-add" id="lumina-img-add">+</div>
                        <div class="lumina-upload-actions">
                            <button type="button" class="lumina-upload-btn" id="lumina-upload-img-btn">上传图片</button>
                            <?php if ($hasMediaLib): ?>
                                <button type="button" class="lumina-media-btn" data-type="img">媒体库</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <input type="file" id="lumina-upload-img" accept="image/*" multiple style="display:none">
                    <div class="sh-cont-imgul" id="sh-cont-imgul">
                        <textarea id="lumina-photos" data-field-key="lumina_photos" class="form-controllul" placeholder="图片外链(一行一条,最多15条)"><?= htmlspecialchars($isEdit ? $editPhotos : '') ?></textarea>
                    </div>
                </div>

                <div class="sh-cont-live" id="sh-cont-live">
                    <div class="lumina-live-config">
                        <div class="lumina-live-config-section">
                            <div class="lumina-live-config-head">
                                <span>实况封面图片</span>
                                <small>一行一张，作为朋友圈中显示的静态画面</small>
                            </div>
                            <textarea id="lumina-live-photos-cover" class="form-controllul lumina-live-photo-input" placeholder="图片外链(一行一条,最多15条)"><?= htmlspecialchars($isEdit ? $editPhotos : '') ?></textarea>
                            <div class="lumina-upload-actions">
                                <button type="button" class="lumina-upload-btn" id="lumina-upload-live-img-btn">上传图片</button>
                                <?php if ($hasMediaLib): ?>
                                    <button type="button" class="lumina-media-btn" data-type="live_img">媒体库</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="lumina-live-config-section">
                            <div class="lumina-live-config-head">
                                <span>实况短视频</span>
                                <small>一行一个，按图片顺序配对</small>
                            </div>
                            <textarea id="lumina-live-photos" data-field-key="lumina_live_photos" class="form-controllul lumina-live-photo-input" placeholder="实况视频URL(一行一条)，或 图片URL|视频URL 精确配对"><?= htmlspecialchars($isEdit ? $editLivePhotos : '') ?></textarea>
                            <div class="lumina-upload-actions">
                                <button type="button" class="lumina-upload-btn" id="lumina-upload-live-video-btn">上传实况视频</button>
                                <?php if ($hasMediaLib): ?>
                                    <button type="button" class="lumina-media-btn" data-type="live_video">媒体库</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <input type="file" id="lumina-upload-live-img" accept="image/*" multiple style="display:none">
                    <input type="file" id="lumina-upload-live-video" accept="video/*" multiple style="display:none">
                </div>

                <div class="sh-cont-sp" id="sh-cont-sp">
                    <textarea id="lumina-video" data-field-key="lumina_video_url" class="form-controllul lumina-media-textarea" placeholder="视频地址(一行一条)"><?= htmlspecialchars($isEdit ? $editVideo : '') ?></textarea>
                    <div class="lumina-upload-actions">
                        <button type="button" class="lumina-upload-btn" id="lumina-upload-video-btn">上传视频</button>
                        <?php if ($hasMediaLib): ?>
                            <button type="button" class="lumina-media-btn" data-type="video">媒体库</button>
                        <?php endif; ?>
                    </div>
                    <input type="file" id="lumina-upload-video" accept="video/*" multiple style="display:none">
                </div>
                <div class="sh-cont-sp" id="sh-cont-spfm" style="display: none;margin-top: 2px;">
                    <input type="text" id="lumina-video-poster" data-field-key="lumina_video_poster" placeholder="视频封面（上传视频后自动生成，可手动替换）" value="<?= htmlspecialchars($isEdit ? $editVideoPoster : '') ?>">
                </div>

                <div class="sh-cont-embed" id="sh-cont-embed">
                    <textarea id="lumina-embed" data-field-key="lumina_embed_url" class="form-controllul lumina-media-textarea" placeholder="Bilibili/抖音/腾讯/优酷/YouTube 链接，或官方 iframe 代码"><?= htmlspecialchars($isEdit ? $editEmbed : '') ?></textarea>
                    <select id="lumina-embed-ratio" data-field-key="lumina_embed_ratio">
                        <option value="lr"<?= ($isEdit ? $editEmbedRatio : 'lr') !== 'tb' ? ' selected' : '' ?>>横屏视频</option>
                        <option value="tb"<?= ($isEdit ? $editEmbedRatio : '') === 'tb' ? ' selected' : '' ?>>竖屏视频</option>
                    </select>
                    <div class="lumina-embed-tip">支持 Bilibili 视频/直播、抖音、腾讯视频、优酷、YouTube，也可粘贴平台提供的 iframe。</div>
                    <input type="hidden" id="lumina-embed-cover" data-field-key="lumina_embed_cover" value="<?= htmlspecialchars($isEdit ? $editEmbedCover : '') ?>">
                </div>

                <div class="sh-cont-yy" id="sh-cont-yy">
                    <textarea id="lumina-music" data-field-key="lumina_music_url" class="form-controllul lumina-media-textarea" placeholder="音乐地址(一行一条，支持 URL|标题|作者|封面)"><?= htmlspecialchars($isEdit ? $editMusic : '') ?></textarea>
                    <span><input type="text" id="lumina-music-title" data-field-key="lumina_music_title" placeholder="默认音乐标题(可选)" value="<?= htmlspecialchars($isEdit ? $editMusicTitle : '') ?>"></span>
                    <span><input type="text" id="lumina-music-artist" data-field-key="lumina_music_artist" placeholder="音乐作者/歌手(可选)" value="<?= htmlspecialchars($isEdit ? $editMusicArtist : '') ?>"></span>
                    <span><input type="text" id="lumina-music-cover" data-field-key="lumina_music_cover" placeholder="音乐封面(可选) https://...jpg" value="<?= htmlspecialchars($isEdit ? $editMusicCover : '') ?>"></span>
                    <div class="lumina-upload-actions">
                        <button type="button" class="lumina-upload-btn" id="lumina-upload-music-cover-btn">上传封面</button>
                        <?php if ($hasMediaLib): ?>
                            <button type="button" class="lumina-media-btn" data-type="music_cover">媒体库</button>
                        <?php endif; ?>
                    </div>
                    <input type="file" id="lumina-upload-music-cover" accept="image/*" style="display:none">
                    <div class="lumina-upload-actions">
                        <button type="button" class="lumina-upload-btn" id="lumina-upload-music-btn">上传音乐</button>
                        <?php if ($hasMediaLib): ?>
                            <button type="button" class="lumina-media-btn" data-type="music">媒体库</button>
                        <?php endif; ?>
                    </div>
                    <input type="file" id="lumina-upload-music" accept="audio/*" multiple style="display:none">
                </div>

                <div class="sh-cont-link" id="sh-cont-link">
                    <span class="lumina-link-url-row">
                        <input type="text" id="lumina-link-url" data-field-key="lumina_link_url" placeholder="链接地址 https://..." value="<?= htmlspecialchars($isEdit ? $editLinkUrl : '') ?>">
                        <button type="button" class="lumina-link-fetch-btn" id="lumina-link-fetch-btn">获取</button>
                    </span>
                    <span><input type="text" id="lumina-link-title" data-field-key="lumina_link_title" placeholder="链接标题(可选)" value="<?= htmlspecialchars($isEdit ? $editLinkTitle : '') ?>"></span>
                    <span><input type="text" id="lumina-link-desc" data-field-key="lumina_link_desc" placeholder="链接摘要/来源(可选)" value="<?= htmlspecialchars($isEdit ? $editLinkDesc : '') ?>"></span>
                    <span><input type="text" id="lumina-link-image" data-field-key="lumina_link_image" placeholder="缩略图(可选) https://...jpg" value="<?= htmlspecialchars($isEdit ? $editLinkImage : '') ?>"></span>
                    <div class="lumina-upload-actions">
                        <button type="button" class="lumina-upload-btn" id="lumina-upload-link-image-btn">上传缩略图</button>
                        <?php if ($hasMediaLib): ?>
                            <button type="button" class="lumina-media-btn" data-type="link_image">媒体库</button>
                        <?php endif; ?>
                    </div>
                    <input type="file" id="lumina-upload-link-image" accept="image/*" style="display:none">
                </div>

                <div class="sh-cont-hb" id="sh-cont-hb">
                    <div class="lumina-redpacket-fields">
                        <input type="text" id="lumina-redpacket-title" data-field-key="lumina_redpacket_title" placeholder="红包标题(可选)" value="<?= htmlspecialchars($isEdit ? $editRedpacketTitle : '') ?>">
                        <div class="lumina-redpacket-row">
                            <input type="number" id="lumina-redpacket-total" data-field-key="lumina_redpacket_total" placeholder="总积分" min="1" value="<?= htmlspecialchars($isEdit ? $editRedpacketTotal : '') ?>"<?= $redpacketLocked ? ' readonly' : '' ?>>
                            <input type="number" id="lumina-redpacket-count" data-field-key="lumina_redpacket_count" placeholder="红包数量" min="1" value="<?= htmlspecialchars($isEdit ? $editRedpacketCount : '') ?>"<?= $redpacketLocked ? ' readonly' : '' ?>>
                        </div>
                        <select id="lumina-redpacket-mode" data-field-key="lumina_redpacket_mode"<?= $redpacketLocked ? ' disabled' : '' ?>>
                            <option value="random"<?= ($isEdit ? $editRedpacketMode : 'random') !== 'equal' ? ' selected' : '' ?>>随机</option>
                            <option value="equal"<?= ($isEdit ? $editRedpacketMode : '') === 'equal' ? ' selected' : '' ?>>等额</option>
                        </select>
                        <div class="lumina-redpacket-tip">创建时一次性扣除积分，红包领取后积分直接到账。</div>
                    </div>
                </div>

                <input type="hidden" name="title" id="lumina-title" value="<?= htmlspecialchars($isEdit ? $editTitle : '') ?>">
                <input type="hidden" id="lumina-type" data-field-key="lumina_type" value="<?= htmlspecialchars($isEdit ? $editType : 'img') ?>">

                <div class="lumina-advanced show lumina-publish-panel" id="lumina-advanced">
                    <div class="lumina-publish-panel-title">基础设置</div>
                    <div class="setup-main-lieb-content">
                        <span>分类</span>
                        <select name="sort_id" class="setup-main-select">
                            <option value="-1"<?= $isEdit && $editSort <= 0 ? ' selected' : '' ?>>未分类</option>
                            <?php lumina_render_sort_options($sorts, 0, $isEdit ? $editSort : null); ?>
                        </select>
                    </div>

                    <div class="setup-main-lieb-content">
                        <span>标签</span>
                        <input type="text" name="tags" placeholder="使用英文逗号分隔" value="<?= htmlspecialchars($isEdit ? $editTags : '') ?>">
                    </div>
                </div>

                <div class="lumina-more-panel lumina-publish-panel" id="lumina-more-panel">
                    <div class="lumina-publish-panel-title">更多选项</div>
                    <div class="setup-main-lieb-content-kg">
                        <span>允许评论</span>
                        <div class="setup-main-lieb-content-kg-an">
                            <div class="setup-main-lieb-content-kg-an-xin lumina-toggle" id="lumina-allow-remark-toggle" data-target="lumina-allow-remark-input" data-on="y" data-off="n" style="<?= ($isEdit ? ($editAllowRemark === 'y') : true) ? 'background: var(--theme); justify-content:flex-end;' : '' ?>"><p></p></div>
                        </div>
                    </div>
                    <input type="hidden" name="allow_remark" id="lumina-allow-remark-input" value="<?= $isEdit ? $editAllowRemark : 'y' ?>">
                    <div class="setup-main-lieb-content-kg">
                        <span>保存草稿</span>
                        <div class="setup-main-lieb-content-kg-an">
                            <div class="setup-main-lieb-content-kg-an-xin lumina-toggle" id="lumina-draft-toggle" data-target="lumina-draft-input" data-on="y" data-off="n" style="<?= $isEdit && $editDraft === 'y' ? 'background: var(--theme); justify-content:flex-end;' : '' ?>"><p></p></div>
                        </div>
                    </div>
                    <input type="hidden" name="draft" id="lumina-draft-input" value="<?= $isEdit ? $editDraft : 'n' ?>">
                    <?php if ($canTop): ?>
                        <div class="setup-main-lieb-content-kg">
                            <span>置顶</span>
                            <div class="setup-main-lieb-content-kg-an">
                                <div class="setup-main-lieb-content-kg-an-xin lumina-toggle" id="lumina-top-toggle" data-target="lumina-top-input" data-on="y" data-off="n" style="<?= $isEdit && $editTop === 'y' ? 'background: var(--theme); justify-content:flex-end;' : '' ?>"><p></p></div>
                            </div>
                        </div>
                        <input type="hidden" name="top" id="lumina-top-input" value="<?= $isEdit ? $editTop : 'n' ?>">
                    <?php endif; ?>
                </div>
                </form>
                <div id="lumina-post-msg" class="lumina-post-msg"></div>
            </div>
    <?php lumina_render_main_footer(); ?>
    </div>

    <?php if ($show_sidebar): ?>
        <aside class="lumina-aside lumina-aside-right">
            <div class="lumina-sidecard lumina-sidecard-preview">
                <div class="lumina-sidecard-title">发布助手</div>
                <div class="lumina-preview-card" id="lumina-preview-card">
                    <div class="lumina-preview-author">
                        <img src="<?= $profile_avatar ?>" alt="avatar" onerror="this.onerror=null;this.src='<?= $lumina_avatar ?>'">
                        <div class="lumina-preview-author-meta">
                            <span><?= $profile_name ?></span>
                            <small>内容发布状态</small>
                        </div>
                    </div>
                    <div class="lumina-helper-summary" id="lumina-preview-summary">还没有输入内容</div>
                    <div class="lumina-sidecard-pills" id="lumina-preview-pills"></div>
                    <div class="lumina-side-list" id="lumina-preview-list"></div>
                    <div class="lumina-helper-tip" id="lumina-preview-tip">这里会帮你确认当前的发布类型、媒体状态和关键设置。</div>
                </div>
            </div>
        </aside>
    <?php endif; ?>
    </div>
</div>
<?php if ($hasMediaLib): ?>
    <div class="lumina-media-modal" id="lumina-media-modal" style="display:none;" onclick="luminaCloseMedia()">
        <div class="lumina-media-dialog" onclick="event.stopPropagation()">
            <div class="lumina-media-head">
                <span>媒体库</span>
                <span class="lumina-media-close" onclick="luminaCloseMedia()">×</span>
            </div>
            <div class="lumina-media-grid" id="lumina-media-grid"></div>
        </div>
    </div>
    <script>
        window.LUMINA_MEDIA = <?= json_encode($mediaItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
