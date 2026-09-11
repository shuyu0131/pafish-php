# pafish![输入图片说明](docs/logo.png)

> 轻量、独立部署的 PHP 博客 CMS。

pafish 面向个人博客、内容站和小型团队站点。它使用 PHP 与 MySQL，提供浏览器安装向导、完整的内容后台、主题和插件扩展能力，并可直接运行在宝塔和常见共享主机环境中。

[官网](https://www.pafish.cn) · [使用文档](https://www.pafish.cn/docs) · [应用商店](https://www.pafish.cn/store) · [Gitee](https://gitee.com/shuyugit/pafish-php) · [GitHub](https://github.com/shuyu0131/pafish-php)

## pafish 是什么？

pafish 是一个以博客写作和内容发布为核心的独立 CMS。系统不依赖 Node.js 构建环境，也不要求 Docker 或命令行：上传发行包、访问安装页、填写数据库信息后即可使用。

它把日常内容管理、站点外观和应用扩展放在同一套后台中。主题和插件通过清晰的清单文件与钩子机制扩展，官方应用统一从系统内置应用商店安装和更新。

## 快速开始

1. 从[官网下载页](https://www.pafish.cn/download)获取最新发行包。
2. 上传 zip 到网站根目录并解压，将 `pafish/` 目录内的文件放到站点根目录。
3. 访问 `https://你的域名/install.php`，按向导填写数据库和管理员信息。
4. 安装结束后删除 `install.php`，登录后台开始创建内容。

发行包已包含 `vendor/`，生产部署无需执行 Composer。

> 数据库账号具有建库权限时，安装向导可以自动创建数据库。若主机不支持伪静态，可在安装时关闭它，系统会使用 `index.php?p=...` 形式的链接。

## 核心能力

- **内容发布**：Markdown 编辑、草稿、定时发布、置顶、回收站、文章密码、独立页面、分类和标签。
- **读者体验**：评论与楼中楼回复、点赞收藏、全文搜索、归档、RSS、sitemap、robots 和亮暗模式。
- **媒体与运营**：图片上传与压缩、媒体库、评论审核、导航、友链、侧栏组件、通知与 SMTP 邮件设置。
- **站点管理**：用户和角色、数据备份、开放 API、迁移、纯 PHP 备份与在线更新，兼容禁用 `exec()` 的共享主机。
- **扩展生态**：主题设置、可覆盖模板、插件事件钩子、云存储管线和官方应用商店。

## 应用生态

主题负责呈现，插件负责功能。管理员可以在后台的“应用商店”中查看、安装、更新或回滚官方应用；不需要为应用单独寻找下载地址。

- **主题**：使用 `theme.json` 描述信息和设置 schema，支持 CSS 变量以及可选 PHP 模板覆盖。
- **插件**：使用 `plugin.json` 和 `index.php`，可接入内容、SEO、上传、评论、认证和编辑器等扩展点。
- **开发文档**：[插件开发](docs/plugins.md)；主题的 manifest 与编辑器字段规范位于主题目录的 `theme.json` 中。

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 8.1+，启用 `pdo_mysql`、`gd`、`zip`、`mbstring`、`openssl`、`json`、`fileinfo` |
| MySQL | 5.7.6+，推荐 MySQL 8.0 |
| Web 服务 | Nginx 或 Apache |
| 上传限制 | 建议 `post_max_size >= 16M`、`upload_max_filesize >= 12M` |

上传限制不足不会影响写作和阅读，但主题、插件或应用 zip 的安装会受限。安装向导会提示当前环境状态。

## 部署与运维

### Nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ ^/(config\.php|runtime/|backups/) {
    deny all;
}
```

Apache 可直接使用发行包中的 `.htaccess`。更完整的宝塔配置见 [宝塔部署说明](docs/install-bt.md) 和 [Nginx 配置示例](docs/nginx-bt.conf.example)。

### 定时发布

定时发布推荐通过宝塔计划任务每分钟执行一次：

```bash
php /www/wwwroot/你的站点/cron.php
```

未配置计划任务时，前台请求会以低频方式兜底执行；文章在 `published_at` 到达之前不会出现在前台。

### 在线更新

后台“系统更新”默认按 Gitee Release、GitHub Release、官网镜像的顺序检查新版本。更新包会进行 HTTPS、哈希和包结构校验，并在替换前创建站点与数据库备份；备份不依赖 `exec()`、`mysqldump` 或 `mysql` 命令。

## 开发

源码开发需要 PHP 8.1+ 与 Composer：

```bash
composer install
php -S 127.0.0.1:8123 router.php
```

常用入口：

```text
app/          应用、服务、控制器和后台视图
themes/       主题
plugins/      插件
migrations/   数据库增量迁移
public/       静态资源和内置商店目录
docs/         部署、迁移与插件文档
scripts/      发行包和商店包构建脚本
```

构建发行包：

```bash
php scripts/build-release.php --tag=vX.Y.Z
```

## 常见问题

- **中文搜索无结果**：建议使用 MySQL 5.7.6+ 的 FULLTEXT ngram；旧版本会回退到 LIKE 查询。
- **应用安装失败**：确认 `post_max_size` 和 `upload_max_filesize` 满足要求，并检查站点目录的写入权限。
- **计划文章未发布**：确认 `cron.php` 可由计划任务执行；即使任务未执行，未到发布时间的文章也不会提前显示。
- **备份提示命令不可用**：pafish 默认使用纯 PHP 导出，无需开启 `exec()` 或安装 `mysqldump`。

## 许可证

MIT
