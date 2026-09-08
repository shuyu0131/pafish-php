<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\Config;
use Pafish\Core\DB;
use Pafish\Core\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController extends AdminController
{
    public function index(Request $request, Response $response): Response
    {
        $this->guardCapability('health.view');
        $checks = [];
        $checks['PHP'] = [version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION];
        foreach (['pdo_mysql' => '数据库驱动', 'mbstring' => '多字节字符串', 'fileinfo' => '文件类型检测', 'openssl' => '加密通信', 'gd' => '图片处理', 'zip' => '压缩包处理'] as $ext => $label) {
            $checks[$label] = [extension_loaded($ext), extension_loaded($ext) ? '已安装' : '缺失'];
        }
        try {
            DB::value('SELECT 1');
            $checks['数据库连接'] = [true, '正常'];
        } catch (\Throwable $e) {
            $checks['数据库连接'] = [false, '连接失败'];
        }
        foreach ([PAFISH_ROOT . '/runtime' => 'runtime 目录', PAFISH_ROOT . '/public/uploads' => 'uploads 目录'] as $dir => $label) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $checks[$label] = [is_writable($dir), is_writable($dir) ? '可写' : '不可写'];
        }
        $checks['上传限制'] = [true, ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size')];
        $checks['伪静态'] = [(bool) Config::get('pretty_urls', true), Config::get('pretty_urls', true) ? '已启用' : '查询串模式'];
        $response->getBody()->write($this->render('health', ['checks' => $checks], '系统健康'));
        return $response;
    }
}
