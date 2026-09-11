# pafish

轻量、独立部署的 PHP 博客 CMS，适合个人博客、内容站和小型团队站点。

pafish 使用 PHP 与 MySQL，提供浏览器安装向导、完整的内容后台、主题和插件扩展能力，可直接运行在宝塔和常见共享主机环境中。运行时只需要 PHP 与数据库服务。

[官网](https://www.pafish.cn) · [使用文档](https://www.pafish.cn/docs) · [应用商店](https://www.pafish.cn/store) · [Gitee](https://gitee.com/shuyugit/pafish-php) · [GitHub](https://github.com/shuyu0131/pafish-php)

## 快速开始

1. 从[官网下载页](https://www.pafish.cn/download)获取最新发行包。
2. 将压缩包上传到网站目录并解压，把 `pafish/` 目录内的文件放到站点根目录。
3. 访问 `https://你的域名/install.php`，按向导填写数据库和管理员信息。
4. 安装完成后删除 `install.php`，登录后台开始创建内容。

发行包已包含运行所需的 PHP 依赖，生产部署无需执行 Composer。

## 核心能力

- 内容发布：Markdown 编辑、草稿、定时发布、置顶、回收站、文章密码、独立页面、分类和标签。
- 读者体验：评论与楼中楼回复、点赞收藏、全文搜索、归档、RSS、sitemap、robots 和亮暗模式。
- 媒体与运营：图片上传、媒体库、评论审核、导航、友链、侧栏组件、通知和 SMTP 邮件设置。
- 站点管理：用户和角色、数据备份、开放 API、内容迁移、纯 PHP 备份与在线更新，兼容禁用 `exec()` 的共享主机。
- 扩展生态：主题设置、模板覆盖、插件事件钩子和官方应用商店。

## 应用商店

主题负责呈现，插件负责功能。管理员可以在后台的“应用商店”中查看、安装、更新或回滚官方应用，不需要为应用单独寻找下载地址。

应用包使用清单文件描述名称、版本、兼容环境和设置项。安装前会校验包结构与 PHP 版本，更新前会创建备份，失败时自动恢复原版本。

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 8.1+，启用 `pdo_mysql`、`gd`、`zip`、`mbstring`、`openssl`、`json`、`fileinfo` |
| MySQL | 5.7.6+，推荐 MySQL 8.0 |
| Web 服务 | Nginx 或 Apache |
| 上传限制 | 建议 `post_max_size >= 16M`、`upload_max_filesize >= 12M` |

上传限制不足不会影响写作和阅读，但主题、插件或应用包的安装可能受限，安装向导会提示当前环境状态。

## 部署提示

Apache 可直接使用发行包中的 `.htaccess`。Nginx 站点根目录应指向 pafish 项目根目录，并将不存在的路径交给 `index.php`：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ ^/(config\.php|runtime/|backups/) {
    deny all;
}

location ~* ^/themes/.*\.php$ {
    deny all;
}
```

修改配置后请执行 `nginx -t` 并重载 Nginx。若主机不支持伪静态，可在配置中关闭伪静态，系统会使用 `index.php?p=...` 形式的链接。

## 定时发布

定时发布推荐通过宝塔计划任务每分钟执行一次：

```bash
php /www/wwwroot/你的站点/cron.php
```

未配置计划任务时，前台请求会以低频方式兜底执行；文章在设定的发布时间到达前不会出现在前台。

## 在线更新

后台“系统更新”固定从 Gitee Release 检查并下载版本。更新包会进行 HTTPS、哈希和结构校验，备份与恢复使用纯 PHP 实现，不依赖 `exec()`、`mysqldump` 或 `mysql` 命令。

## 常见问题

- 中文搜索无结果：建议使用 MySQL 5.7.6+；不支持全文索引时系统会回退到普通查询。
- 应用安装失败：确认上传限制满足要求，并检查站点目录的写入权限。
- 计划文章未发布：确认已配置 `cron.php` 计划任务。
- 找不到更新：确认服务器可以访问 Gitee，也可以在后台上传更新包。

## 许可证

MIT
