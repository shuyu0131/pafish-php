# pafish（PHP 版）

极简博客系统，Node 版（Next.js）的 PHP 复刻：功能、界面与数据层完全对齐 v1.1.0。
面向虚拟主机 / 宝塔等 PHP 环境，**零命令行**安装。

## 功能特性

- **前台**：文章列表/详情（Markdown、点赞、收藏、密码门、评论楼中楼、相关推荐）、分类（递归子分类）、标签、全文搜索（FULLTEXT ngram）、归档、独立页面（模板分发）、RSS / sitemap / robots、左侧边栏组件（导航/分类/标签/友链/公告/自定义 HTML）、亮暗主题切换
- **后台**：工作台统计（自绘 SVG 图表）、文章管理（筛选/排序/批量/回收站/置顶/定时发布）、Markdown 编辑器（工具栏/实时预览/拖拽上传/自动保存）、Markdown 批量导入、分类树、标签、媒体库（GD 压缩、云存储）、评论审核（楼中楼/拉黑）、通知、友链/导航/组件、外观（主题设置 8 类控件/导入导出）、站点设置（含 SMTP 测试、开放 API 面板）、应用商店（主题/插件安装更新回滚）、插件管理、用户管理、数据库备份（mysqldump / 纯 PHP 双模式）
- **开放 API v1**：posts / categories / tags / comments，X-API-Key 鉴权
- **扩展**：主题（`theme.json` + CSS 变量，与 Node 版主题包 1:1 兼容；可选 PHP 模板文件覆盖）、插件（11 个事件钩子、云存储管线、前台页面/页面模板）、内置应用商店
- **定时发布双通道**：`cron.php`（宝塔计划任务）+ 前台请求低频兜底，查询层 `published_at <= NOW()` 双保险

## 技术栈

- PHP 8.1+（pdo_mysql / gd / zip / mbstring / openssl / json / fileinfo）
- MySQL 5.7.6+（推荐 8.0，搜索需要 FULLTEXT ngram）
- Slim 4（微框架）+ PHP-DI + PDO
- 原生 PHP 模板（无模板引擎；主题可用 PHP 模板文件覆盖）

## 环境要求

| 项目 | 要求 |
|---|---|
| PHP | 8.1+（扩展见上） |
| MySQL | 5.7.6+（推荐 8.0） |
| 上传限制 | `post_max_size` ≥ 16M、`upload_max_filesize` ≥ 12M（主题/插件 zip 包上限 10MB） |

上传限制不满足时博客核心功能可正常使用，仅应用商店 / 主题插件 zip 安装会失败；
安装向导会以警告形式提示当前值（修改 `php.ini` 后需重启 Web 服务生效）。

## 安装

### 方式一：发布包（推荐，零命令行）

1. 下载 `pafish-php-vX.Y.Z.zip`（已预打包 `vendor/`，无需 Composer）
2. 上传到网站根目录（或子目录），解压后把 `pafish/` 内容移动到站点根目录
3. 浏览器访问 `http://你的域名/install.php`，按向导填写数据库信息与管理员账号
4. 安装完成，**删除 `install.php`**，打开首页即可

> 数据库不存在时会自动创建（数据库账号需有建库权限）。
> 不支持伪静态的主机：安装时取消勾选「启用伪静态」，链接自动使用 `index.php?p=xxx` 形式。

### 方式二：源码运行（开发调试）

```bash
composer install
php -S localhost:8000 router.php
```

### Web 服务器配置

- **Apache**：已内置 `.htaccess`（伪静态 + 静态资源重写 + 禁止直接访问 `config.php` / `runtime/` / `backups/`），默认即可
- **Nginx**：

```nginx
# 伪静态：所有前台路径交给 index.php
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

# 静态资源在 public/ 下，对外保持根路径（css/ js/ uploads/ vendor/）。
# v0.1.2+ 已内置 PHP 兜底：仅上面一条 try_files 即可让 /css/… 等资源正常加载
#（框架在会话/路由启动前按原内容直出，功能与视觉不受影响）。
# 以下 location 仅为性能优化（让 Nginx 直接读盘、绕过 PHP），可按需添加：
# location ~ ^/(css|js|uploads|vendor)/ {
#     root /www/wwwroot/你的站点/public;
#     try_files $uri =404;
# }

# 禁止直接访问敏感文件/目录
location ~ ^/(config\.php|runtime/|backups/) { deny all; }
```

> **两种部署模式**（安装向导可勾选）：
> - **启用伪静态**（默认）：按上表配置 Nginx，或使用 Apache（`.htaccess` 已内置），链接为 `/post/xxx` 形式
> - **关闭伪静态**：无需任何重写规则，链接自动使用 `/index.php?p=post/xxx` 形式（`config.php` 中 `pretty_urls` 设为 `false`）

## 定时发布（可选）

后台「高级选项 → 定时发布」排期的文章，由以下任一通道发布：

1. **宝塔计划任务**（推荐）：Shell 脚本，每 1 分钟执行一次
   `php /www/wwwroot/你的站点/cron.php`
2. **URL 访问**：计划任务选「访问 URL」，填 `https://你的域名/cron.php`
3. **兜底**：什么都没配置时，前台请求也会低频（60 秒限频）自动发布到期文章

即使全部通道都没跑，定时文章也不会提前泄露（前台只显示 PUBLISHED 且发布时间已到的文章）。

## 默认账号（安装向导种子数据）

| 角色 | 用户名 | 密码 |
|---|---|---|
| 管理员 | admin | Admin@12345 |
| 编辑 | editor | Editor@12345 |

> 安装完成后请立即修改管理员密码。

## 目录结构

```
├── index.php          # 入口
├── install.php        # Web 安装向导（安装后请删除）
├── cron.php           # 定时发布（计划任务可选）
├── config.php         # 安装生成（勿入库，.htaccess 已禁止访问）
├── app/               # 应用代码（Core/Services/Http/Views）
├── admin/             # 后台
├── themes/            # 主题（theme.json + theme.css + 可选 PHP 模板）
├── plugins/           # 插件
├── public/            # 静态资源（css/js/uploads/store）
├── backups/           # 数据库备份
├── runtime/           # 运行期缓存（验证码/限速/日志）
├── migrations/        # 增量迁移
├── scripts/           # 构建脚本（商店源 / 发布包）
└── docs/              # 详细文档（宝塔部署、常见问题）
```

## 主题

- `themes/{name}/theme.json`：manifest + 设置 schema（8 类型：text/textarea/checkbox/switcher/select/radio/color/image）
- `themes/{name}/theme.css`：语义 CSS 变量（`--bg/--fg/--accent/...`），与 Node 版主题包 1:1 兼容
- `themes/{name}/header.php` 等：**可选 PHP 模板文件**，覆盖系统模板（WordPress 式）
- Node 版主题包可直接在 PHP 版使用（CSS 变量兼容；PHP 模板文件仅 PHP 版生效）

## 插件与应用商店

- 插件 = `plugin.json` + `index.php`；API v2 支持 action/filter、发布事件、SEO/Markdown/上传过滤器，以及评论/认证/文章编辑器插槽（完整规范见 [`docs/plugins.md`](docs/plugins.md)）
- 云存储插件（如缤纷云 S4，S3 协议 + SigV4 签名）：媒体上传自动入云，失败回退本地
- 内置应用商店开箱即用（默认源内置在 `public/store/`），也可在站点设置配置自定义商店地址
- 内置插件新增 SEO 主动推送（IndexNow/百度）与通知中心（Bark/Telegram/钉钉/飞书/企微/Webhook）

## 开发与测试

```bash
# 启动开发服务器
php -S 127.0.0.1:8123 router.php

# 运行插件 API v2 测试（需本地 3307 端口的 pafish_php 测试库）
php scripts/test-plugin-api-v2.php
```

## 常见问题

- **应用商店安装包报错/上传失败**：检查 `post_max_size` / `upload_max_filesize`（见环境要求）
- **发布文章前台不显示**：多为服务器 MySQL 时区为 UTC，应用已自动按站点时区设置会话时区；若仍异常请检查站点设置的时区
- **搜索中文无结果**：MySQL 需 5.7.6+（FULLTEXT ngram）；老版本自动回退 LIKE 搜索
- **备份提示 mysqldump 不可用**：已自动回退纯 PHP 逐表导出

## 许可证

MIT
