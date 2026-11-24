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
| PHP 扩展 | `pdo`、`pdo_mysql`、`gd`、`zip`、`mbstring`、`intl`、`curl`、`openssl`、`json` |
| MySQL | 5.7.6+，推荐 8.0 |
| Web 服务 | Nginx 或 Apache |

上传限制建议：`post_max_size >= 16M`、`upload_max_filesize >= 12M`。

`fileinfo` 为可选扩展；未启用时安装向导会显示提示，但不阻止安装。

## 安装

1. 从[官网下载页](https://www.pafish.cn/download)或仓库发行版下载完整发行包。
2. 解压到网站根目录，配置伪静态和目录权限。
3. 访问 `/install.php`，按提示填写数据库和管理员信息。
4. 安装完成后删除 `install.php`，访问 `/admin/` 登录后台。

完整发行包包含 Composer 运行依赖、默认主题、Sitemap 插件和 AI Foundation 插件，无需在服务器执行 Composer。仓库自动生成的源码 ZIP 不含运行依赖，不能直接用于部署。

详细步骤见[安装说明](https://docs.pafish.cn/docs/install)和[宝塔部署](https://docs.pafish.cn/docs/install-bt)；Docker 部署见 [DOCKER.md](DOCKER.md)。

## Nginx 伪静态

宝塔面板：**站点设置 → 伪静态 → 填写以下内容 → 保存**。先将站点目录设为项目根目录，并选择 PHP 8.1 或以上版本。

```nginx
if ($uri ~* "^/(?:config\.php|composer\.(?:json|lock))(?:[./~]|$)|^/(?:app|vendor|migrations|tests|docs|docker|runtime|backups|storage|scripts|tools|dist|developer)(?:/|$)") { return 403; }
if ($uri ~ "^(?!/\.well-known/acme-challenge/[A-Za-z0-9_-]+$).*/\.") { return 403; }
if ($uri ~* "^/(?!index\.php(?:/|$)|install\.php$|cron\.php$).*\.(?:php[0-9]?|phtml|phar)(?:/|$)") { return 403; }
rewrite ^/uploads/(.*)$ /public/uploads/$1 last;
location ^~ /public/uploads/ {
    try_files $uri =404;
    add_header X-Content-Type-Options nosniff always;
    add_header Content-Security-Policy "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'" always;
}
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

这段可直接填进宝塔的“伪静态”文本框，无需填写 PHP-FPM socket 或重复添加 PHP handler。安装后删除 install.php。已有自定义 location / 或 /public/uploads/ 时先合并，避免重复；证书验证沿用面板原有配置。

适用于域名根路径部署，子目录部署需调整路径。保存后打开首页、文章和后台检查；完整操作见 [宝塔部署](docs/install-bt.md)，可复制配置文件见 [Nginx 伪静态](docs/nginx-bt.conf.example)。Apache 使用发行包的 .htaccess。

## 文档

[本地文档目录](docs/README.md)按安装使用、主题与插件开发、API 和核心维护组织。线上文档与本地版本可能不同步。

- 入门使用：[开始使用](docs/getting-started.md) · [配置说明](docs/config.md) · [常见问题](docs/faq.md) · [媒体管理](docs/media.md) · [备份搬迁](docs/backup-migration.md) · [更新维护](docs/maintenance.md)
- 开发入门：[开发准备](docs/development.md) · [主题开发](docs/themes.md) · [插件开发](docs/plugins.md)
- 真实示例：[默认主题子主题](docs/examples/default-child/README.md) · [最小 demo](docs/examples/demo/README.md) · [问候语插件](docs/examples/hello_pafish/README.md)
- 接口参考：[设置表单](docs/extension-settings.md) · [模板](docs/theme-templates.md) · [路由](docs/extension-routes.md) · [数据存储](docs/extension-data.md) · [钩子](docs/extension-hooks.md)
- 更新与接口：[扩展打包](docs/extension-packaging.md) · [开放 API](docs/api.md) · [App 接口](docs/app-api.md)

## 许可证

[MIT](LICENSE)
