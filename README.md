# pafish（纸鱼博客）

轻量、独立部署的 PHP 博客 CMS，适合个人博客和内容站。

[官网](https://www.pafish.cn) · [使用文档](https://docs.pafish.cn/docs) · [应用商店](https://www.pafish.cn/store) · [Gitee](https://gitee.com/shuyugit/pafish-php) · [GitHub](https://github.com/shuyu0131/pafish-php)

## 主要功能

- 内容管理：Markdown 编辑器、草稿、定时发布、密码文章、独立页面、微语、分类和标签；可通过插件接入富文本编辑器。
- 阅读与互动：评论及回复、点赞收藏、站内搜索、归档、RSS、Sitemap 和亮暗模式。
- 站点管理：媒体库、评论审核、导航菜单、友情链接、侧边栏组件、SMTP 邮件、用户和角色。
- 维护与扩展：数据备份、内容迁移、在线更新、开放 API、主题和插件商店；核心后台支持简体中文和 English。

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 8.1+ |
| PHP 扩展 | `pdo`、`pdo_mysql`、`gd`、`zip`、`mbstring`、`intl`、`curl`、`openssl`、`json`、`fileinfo` |
| MySQL | 5.7.6+，推荐 8.0 |
| Web 服务 | Nginx 或 Apache |

上传限制建议：`post_max_size >= 16M`、`upload_max_filesize >= 12M`。

## 安装

1. 从[官网下载页](https://www.pafish.cn/download)或仓库发行版下载完整发行包。
2. 解压到网站根目录，配置伪静态和目录权限。
3. 访问 `/install.php`，按提示填写数据库和管理员信息。
4. 安装完成后删除 `install.php`，访问 `/admin/` 登录后台。

完整发行包包含 Composer 运行依赖、默认主题、Sitemap 插件和 AI Foundation 插件，无需在服务器执行 Composer。仓库自动生成的源码 ZIP 不含运行依赖，不能直接用于部署。

详细步骤见[安装说明](https://docs.pafish.cn/docs/install)和[宝塔部署](https://docs.pafish.cn/docs/install-bt)；Docker 部署见 [DOCKER.md](DOCKER.md)。

## Nginx 伪静态

网站根目录指向项目根目录。安装完成后，将以下规则放入站点的 `server` 配置；已有 `location /` 时合并替换，PHP 拦截规则放在通用 PHP-FPM 规则之前。

```nginx
location ~* ^/(config\.php|composer\.(json|lock)|\.env)(/|$) {
    deny all;
}

location ^~ /runtime/ {
    deny all;
}

location ^~ /backups/ {
    deny all;
}

location ~ ^/(?!index\.php$|cron\.php$).*\.php(?:/|$) {
    deny all;
}

location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

执行 `nginx -t` 检查通过后重载配置。完整示例见 [docs/nginx-bt.conf.example](docs/nginx-bt.conf.example)；Apache 使用发行包自带的 `.htaccess`。

## 文档

- 使用：[开始使用](https://docs.pafish.cn/docs/welcome) · [配置说明](https://docs.pafish.cn/docs/config) · [常见问题](https://docs.pafish.cn/docs/faq)
- 开发：[主题](https://docs.pafish.cn/docs/theme) · [插件](https://docs.pafish.cn/docs/plugin) · [API](https://docs.pafish.cn/docs/api) · [扩展内核](https://docs.pafish.cn/docs/extension-kernel) · [事件钩子](https://docs.pafish.cn/docs/extension-hooks)

## 许可证

[MIT](LICENSE)
