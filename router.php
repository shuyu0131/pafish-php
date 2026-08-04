<?php
/**
 * PHP 内置服务器路由器（开发/无伪静态环境）：
 *   php -S 0.0.0.0:8000 router.php
 * - 静态资源（css/ js/ uploads/）映射到 public/
 * - 真实文件（index.php / install.php / cron.php）正常执行
 * - 其余路径统一交给 index.php（伪静态路由）
 * 生产环境请用 Apache（.htaccess）或 Nginx，忽略本文件。
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$rel = ltrim($path, '/');

// 静态资源 → public/
foreach (['css', 'js', 'uploads'] as $dir) {
    if (str_starts_with($rel, $dir . '/')) {
        $file = __DIR__ . '/public/' . $rel;
        if (is_file($file)) {
            // 资源不在 docroot 下，return false 会让内置服务器 404，需手动输出
            $types = [
                'css' => 'text/css; charset=utf-8',
                'js' => 'application/javascript; charset=utf-8',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                'ico' => 'image/x-icon',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
            ];
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
            header('Content-Length: ' . (string) filesize($file));
            readfile($file);
            return true;
        }
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 Not Found';
        return true;
    }
}

// 真实文件正常执行
if ($rel !== '' && is_file(__DIR__ . '/' . $rel)) {
    return false;
}

// 其余统一走框架入口（伪静态路径）
require __DIR__ . '/index.php';
return true;
