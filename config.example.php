<?php
/**
 * pafish 博客 CMS配置文件模板
 * 安装向导会自动生成 runtime/config.php。
 * 手动配置时请复制本文件为 runtime/config.php 并填写；根目录 config.php 仅为旧站兼容保留。
 */
return [
    // ---- 数据库连接 ----
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'pafish',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    // ---- 会话签名密钥：请更换为随机长字符串（安装向导自动生成） ----
    'auth_secret' => 'CHANGE-ME-TO-A-LONG-RANDOM-STRING',

    // ---- 站点绝对 URL（RSS / sitemap / OG 基准），结尾不要带斜杠 ----
    'site_url' => 'http://localhost',

    // ---- 是否启用伪静态（.htaccess / nginx try_files 已配置时保持 true） ----
    // 不支持伪静态的虚拟主机改为 false，链接自动退化为 index.php?p=xxx 形式
    'pretty_urls' => true,

    // ---- 邮件 SMTP（可选：后台"站点设置 → 邮件服务"优先，此处仅兜底） ----
    // 'smtp' => [
    //     'host' => 'smtp.qq.com',
    //     'port' => 465,
    //     'user' => 'your-account@qq.com',
    //     'pass' => 'your-smtp-auth-code',
    //     'from' => '纸鱼博客 <your-account@qq.com>',
    // ],

    // ---- 调试模式（生产环境请保持 false） ----
    'debug' => false,

    // ---- 时区 ----
    'timezone' => 'Asia/Shanghai',
];
