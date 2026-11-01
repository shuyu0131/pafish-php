# pafish（纸鱼博客）

轻量、独立部署的 PHP 博客 CMS，适合个人博客、内容站和小型团队站点。只需要 PHP 和 MySQL。

[官网](https://www.pafish.cn) · [使用文档](https://docs.pafish.cn/docs) · [应用商店](https://www.pafish.cn/store) · [Gitee](https://gitee.com/shuyugit/pafish-php) · [GitHub](https://github.com/shuyu0131/pafish-php)

发行包自带默认主题、Sitemap 插件和 AI Foundation 插件；其他主题与插件在应用商店安装。

## 快速开始

1. 从[官网下载页](https://www.pafish.cn/download)下载发行包 `pafish-php-vX.Y.Z.zip`。
2. 解压到网站根目录，确保根目录能看到 `index.php`、`app/`、`public/`。
3. 访问 `https://你的域名/install.php`，填写数据库和管理员信息。
4. 安装完成后删除 `install.php`，访问 `https://你的域名/admin/` 登录后台。

> 不要用仓库页的「下载 ZIP」部署。源码包不含 `vendor/`，装完首页会 500。请认准发行版里的同名 zip，并用附带的 `.sha256` 校验。

详细步骤、伪静态和目录权限见[安装说明](https://docs.pafish.cn/docs/install)；宝塔用户见[宝塔面板部署](https://docs.pafish.cn/docs/install-bt)。

## 能做什么

- 写作：默认 Markdown 编辑器，也支持通过编辑器插件切换富文本格式；另有草稿、定时发布、置顶、密码文章、独立页面、微语、分类和标签。
- 阅读：评论和楼中楼、点赞收藏、搜索、归档、RSS、sitemap、亮暗模式。
- 运营：媒体库、评论审核、导航菜单、友情链接、侧边栏组件、SMTP 邮件。
- 管理：用户和角色、数据备份、内容迁移、在线更新、开放 API、手机管理 App。
- 扩展：主题、插件和应用商店。

### 正文格式与编辑器

默认编辑器保存 Markdown，渲染器使用仓库内置的 `ParsedownExtra`，不依赖后台临时下载第三方编辑器。Markdown 默认关闭原始 HTML；管理员可在「站点设置 → 内容与评论 → 文章与内容」中开启 `md_allow_raw_html`，开启后只应允许可信作者写作。

富文本编辑器以插件形式接入。插件声明 `format: html` 后，核心会保存正文格式并在保存时用 `HTMLPurifier` 白名单净化 HTML；停用或更换插件不会改变已经保存的正文。格式不匹配的历史正文会回退到核心源码编辑器，不会自动把 Markdown 和 HTML 互相转换。插件开发契约见[编辑器插件说明](https://docs.pafish.cn/docs/plugin#编辑器插件)。

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 8.1+，启用 `pdo`、`pdo_mysql`、`gd`、`zip`、`mbstring`、`intl`、`curl`、`openssl`、`json`、`fileinfo` |
| MySQL | 5.7.6+，推荐 8.0 |
| Web 服务 | Nginx 或 Apache |

安装前请确认 `post_max_size >= 16M`、`upload_max_filesize >= 12M`。发行包已包含 Composer 依赖，生产环境不需要执行 Composer。

## 部署

- **普通主机 / 宝塔**：按[安装说明](https://docs.pafish.cn/docs/install)上传发行包，访问 `install.php` 安装。
- **Docker**：见 [DOCKER.md](DOCKER.md)。GHCR 镜像可直接运行；首次发布前或需要从当前源码运行时，用 `docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build` 本地构建。
- **伪静态**：Nginx 和 Apache 规则见[安装说明的伪静态部分](https://docs.pafish.cn/docs/install#6-伪静态配置)。
- **定时发布**：建议每分钟执行 `php /路径/cron.php`；不配置也能用，前台访问会低频补发。
- **在线更新**：后台「系统更新」从 Gitee 检查新版本，备份和恢复使用纯 PHP，不依赖 `exec()`、`mysqldump` 或 `mysql` 命令。

## 文档

- 使用：[开始使用](https://docs.pafish.cn/docs/welcome)、[安装说明](https://docs.pafish.cn/docs/install)、[常见问题](https://docs.pafish.cn/docs/faq)、[配置说明](https://docs.pafish.cn/docs/config)
- 开发：[主题开发](https://docs.pafish.cn/docs/theme)、[插件开发](https://docs.pafish.cn/docs/plugin)、[API](https://docs.pafish.cn/docs/api)、[App 接口](https://docs.pafish.cn/docs/app-api)、[扩展内核](https://docs.pafish.cn/docs/extension-kernel)、[事件钩子](https://docs.pafish.cn/docs/extension-hooks)、[通用函数](https://docs.pafish.cn/docs/develop-func)、[数据库迁移](https://docs.pafish.cn/docs/migrations)

普通使用不需要看开发文档。

## 常见问题

- 首页 500，提示 `vendor/autoload.php` 缺失：用了源码包，换发行包。
- 文章地址 404：伪静态没生效，见安装说明。
- 图片上传失败：检查上传限制和 `public/uploads/` 权限。
- 中文搜索没有结果：确认 MySQL 5.7.6+ 和全文索引。

更多见[常见问题](https://docs.pafish.cn/docs/faq)。

## 许可证

MIT
