# pafish

轻量、独立部署的 PHP 博客 CMS，适合个人博客、内容站和小型团队站点。

pafish 使用 PHP 与 MySQL，提供浏览器安装向导、完整的内容后台、主题和插件扩展能力，可直接运行在宝塔和常见共享主机环境中。运行时只需要 PHP 与数据库服务。

[官网](https://www.pafish.cn) · [使用文档](https://www.pafish.cn/docs) · [应用商店](https://www.pafish.cn/store) · [Gitee](https://gitee.com/shuyugit/pafish-php) · [GitHub](https://github.com/shuyu0131/pafish-php)

发行包包含默认主题、Sitemap 插件与内置 AI Foundation 插件，其他主题与插件通过应用商店或独立安装包提供。各版本更新内容请查看仓库发行版说明。

## 快速开始

1. 从[官网下载页](https://www.pafish.cn/download)获取最新发行包，或在本仓库的 **发行版 / Releases** 里下载 `pafish-php-vX.Y.Z.zip`。
2. 将压缩包上传到网站目录并解压，把 `pafish/` 目录内的文件放到站点根目录。
3. 访问 `https://你的域名/install.php`，按向导填写数据库和管理员信息。
4. 安装完成后删除 `install.php`，登录后台开始创建内容。

> 仓库页上的「克隆/下载 → 下载 ZIP」得到的是源码快照，`vendor/`（Composer 依赖）不在版本控制里，装完首页会 500。请勿用源码 ZIP 部署，认准发行版里的同名 zip 并用附带的 `.sha256` 校验。

发行包已包含运行所需的 PHP 依赖，生产部署无需执行 Composer。
安装器将数据库配置写入 `runtime/config.php`，基础安装只需为 PHP 运行账户开放 `runtime/`、`public/uploads/` 与 `backups/` 的写入权限；使用大文件分片上传还需要 `storage/`。若要从后台安装或更新主题/插件，还需开放对应的 `themes/`、`plugins/` 目录；在线升级需要程序文件可写，建议仅在执行升级时按需授权。

已有站点升级前请备份数据库与上传文件。升级后在媒体库执行一次“重建媒体引用”，完成前系统会保护已有附件并禁用删除。邮件验证码需重新发送，并在发送验证码的同一浏览器完成注册或密码重置。

## 核心能力

- 内容发布：Markdown 编辑、草稿、定时发布、置顶、回收站、文章密码、独立页面、微语、分类和标签。
- 读者体验：评论与楼中楼回复、点赞收藏、全文搜索、归档、RSS、sitemap、robots 和亮暗模式。
- 媒体与运营：图片上传、媒体库、评论审核、导航、友链、侧栏组件和 SMTP 邮件设置。
- 站点管理：用户和角色、数据备份、开放 API、内容迁移、纯 PHP 备份与在线更新，兼容禁用 `exec()` 的共享主机。
- 移动与集成：手机管理 App 的设备令牌、文章/评论/媒体接口，以及只读开放 API v1。
- 扩展生态：主题设置、模板覆盖、插件事件钩子和官方应用商店；主题和插件均可声明前台路由、后台菜单与自有数据表。

## 应用商店

主题可以把模板、设置、路由和与主题体验紧密相关的功能做成一个自包含产品；插件适合需要跨主题复用、独立启停或独立升级的功能。核心提供所有主题和插件共用的系统能力与基础实体。管理员可以在后台的“应用商店”中查看、安装、更新或回滚官方应用，不需要为应用单独寻找下载地址。

应用包使用清单文件描述名称、版本、兼容环境和设置项。安装前会校验包结构与 PHP 版本，更新前会创建备份，失败时自动恢复原版本。

## 主题开发

主题采用 PHP 模板优先的方式。把 `header.php`、`footer.php`、`index.php`、`post.php` 等需要覆盖的模板放入 `themes/<主题名>/` 即可；未提供的模板继续使用系统默认模板，但可选展示项只在主题清单明确声明后开启。主题可以额外提供 `user.php`，通过 `/user/{route}` 自定义用户中心；没有该模板时该入口返回 404。系统默认账户中心统一使用 `/admin`，核心不提供固定的前台个人中心。`theme.json`、设置 schema、页面模板、组件区域和扩展路由按主题需要声明。主题封面使用根目录的 `preview.jpg`；需要钩子、注入、业务路由或主题自有业务流程时再提供 `extension.php`。与主题体验紧密耦合的功能可以随主题交付；需要跨主题复用、独立启停或不随主题切换的能力，再考虑拆成插件。

从零创建主题时，可按需提供首页、文章、页面、分类、标签、搜索、归档、微语和用户中心模板；未提供的模板继续使用系统兼容回退规则。主题开发指南、模板数据说明和示例见[在线文档](https://www.pafish.cn/docs)。

插件使用 `extension.php` 入口；主题也可以在需要钩子、注入、路由、生命周期或主题自有业务流程时提供该入口。入口返回注册闭包，闭包接收 `ExtensionContext`，可调用 `$ctx->on()`、`$ctx->filter()`、`$ctx->route()`、`$ctx->settings()`、`$ctx->inject()` 和 `$ctx->lifecycle()`，并通过 `$ctx->getData()`、`$ctx->setData()`、`$ctx->getSettings()`、`$ctx->setSettings()` 管理自己的数据。路由、设置、前台注入和生命周期会自动接入现有管线。主题目录中的 `index.php`、`theme.php`、`helpers.php` 等文件仅作为模板或由 `extension.php` 显式组织的业务实现，不再作为独立扩展入口自动加载。功能归属按产品需要选择：主题可完整承载与自身体验紧密相关的流程；需要跨主题复用、独立启停/升级，或不应随主题切换而变化的功能更适合做成插件。

插件和主题的普通业务记录可通过 `$ctx->putRecord()`、`$ctx->getRecord()`、`$ctx->listRecords()`、`$ctx->deleteRecord()` 使用核心共用存储，数据按扩展身份隔离，单条 JSON 对象最多 256 KiB，并支持游标分页和版本冲突检测。需要字段过滤/排序索引、复杂关联或数据库强约束时，再声明扩展自有表。数据归属跟随功能归属：主题紧密关联的设置和记录可以归主题；跨主题共用的能力可以归插件；所有功能共用的基础实体由核心管理。

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 8.1+，启用 `pdo`、`pdo_mysql`、`gd`、`zip`、`mbstring`、`intl`、`openssl`、`json`、`fileinfo` |
| MySQL | 5.7.6+，推荐 MySQL 8.0 |
| Web 服务 | Nginx 或 Apache |
| 上传限制 | 建议 `post_max_size >= 16M`、`upload_max_filesize >= 12M` |

上传限制不足不会影响写作和阅读，但主题、插件或应用包的安装可能受限，安装向导会提示当前环境状态。

## 部署提示

Apache 可直接使用发行包中的 `.htaccess`。Nginx 站点根目录应指向 pafish 项目根目录。完成安装并删除 `install.php` 后，在站点配置中添加以下规则；PHP 拦截规则需放在通用 PHP-FPM 规则之前：

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

保存后按面板提示应用配置。若主机不支持伪静态，可在安装向导中关闭，或在 `runtime/config.php` 中设置 `pretty_urls` 为 `false`，系统会使用 `index.php?p=...` 形式的链接。

## 定时发布

定时发布推荐通过宝塔计划任务每分钟执行一次：

```bash
php /www/wwwroot/你的站点/cron.php
```

未配置计划任务时，前台请求会以低频方式兜底执行；文章在设定的发布时间到达前不会出现在前台。

## 在线更新

后台“系统更新”固定从 Gitee Release 检查并下载版本。更新包会进行 HTTPS、哈希和结构校验，备份与恢复使用纯 PHP 实现，不依赖 `exec()`、`mysqldump` 或 `mysql` 命令。数据库结构由版本化迁移在启动时幂等补齐，升级过程不需要手动导入 SQL。

## 开发文档

安装部署、使用说明、接口与扩展开发文档由独立文档站维护：<https://docs.pafish.cn/docs>。

## 常见问题

- 中文搜索无结果：建议使用 MySQL 5.7.6+；不支持全文索引时系统会回退到普通查询。
- 应用安装失败：确认上传限制满足要求，并检查站点目录的写入权限。
- 装完首页 500，日志提示 `Failed opening required .../vendor/autoload.php`：用的是源码包（仓库里 `vendor/` 不纳入版本控制），不是发行包。请改用官网下载页的发行包，或在站点根目录执行 `composer install --no-dev` 后重新访问。
- 计划文章未发布：确认已配置 `cron.php` 计划任务。
- 找不到更新：确认服务器可以访问 Gitee。

## 许可证

MIT
