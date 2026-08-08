# 变更日志

## v0.1.5（2026-08-08）

界面与权限修复（对齐 Node 版体验 + 参考 emlog 权限模型）：

### 修复

- **下拉框不显示内容**：`select.input` 用 `mask-image` 画箭头会把**整个元素**裁掉（文字、背景全被裁没），下拉框只剩一个小箭头。改为双渐变三角形绘制箭头，选项文字正常显示（明暗主题均可）
- **页脚移动端不显示**：原 `.app-footer` 仅桌面（≥1024px）显示，移动端整个页脚被隐藏。改为移动端/桌面均显示
- **备案号不显示**：页脚新增 `site_icp` 显示（对齐 Node 版 public-shell），后台「站点设置 → 站点信息」填了备案号即显示
- **移动端点击灰色高亮**：全局 `-webkit-tap-highlight-color: transparent`，去掉按钮/链接点击时的灰色高亮
- **编辑权限过大**（参考 emlog）：EDITOR 收窄为仅内容/互动（文章/页面/分类/标签/媒体/评论/通知/友链）；导航菜单、侧边栏组件、主题与外观、站点设置改为仅管理员（ADMIN）可管理（页面守卫 + 导航过滤同步收紧）
- **代码高亮 JS 路径回归**：主题 footer 的 `highlight.min.js` 改为 `asset_url()`（对齐 v0.1.4 静态资源 `/public/` 前缀，修复 404）

## v0.1.4（2026-08-07）

开箱即用与主题自包含：

### 修复

- **静态资源开箱即用**：全局资源（css/js/uploads/vendor）URL 改为 `/public/` 前缀直接指向真实文件，Web 服务器（Nginx/Apache）任何配置下都能直接读盘返回，无需 try_files / PHP 兜底 / .htaccess 静态重写。彻底解决「仅安装页有样式」（Nginx 未配静态规则时 `/css/` 直接 404、PHP 兜底触发不了）的问题——装上即有样式，零服务器配置
- **主题自包含**：前台布局样式 `style.css` 从全局 `public/css/` 移入主题目录 `themes/demo-nord/`，由 `Theme::layoutCss()` 内联注入（与 `theme.css` 变量一起）。主题切换换全套样式；第三方主题未提供 `style.css` 时回退默认主题布局，保证始终有样式
- 前台样式随 HTML 内联送达，不依赖 Web 服务器任何静态配置，真正开箱即用

## v0.1.3（2026-08-06）

体验与权限修复：

### 修复

- **收藏需登录**：文章收藏改为登录后才可使用，未登录返回 401 并自动跳转登录页（带回跳地址）；点赞保持匿名
- **退出登录 404**：前台移动端「退出登录」改为 POST 表单（带 CSRF），并新增 `GET /logout` 兼容路由（302 回首页），修复旧链接直接 404
- **版本常量未同步**：v0.1.2 发布包内 `Version.php` 仍为 0.1.1，导致已安装用户在线更新误报新版，本次同步为 0.1.3

## v0.1.2（2026-08-06）

部署修复：静态资源 PHP 兜底真正落地（v0.1.1 的声明实际未实现，Nginx 未配静态规则时 CSS/JS 仍 404）：

### 修复

- **静态资源 PHP 兜底落地**：框架入口（`index.php` → `bootstrap.php`）在会话/路由启动**之前**直出 `public/` 下的 `css/ js/ uploads/ vendor/`（正确 Content-Type / Content-Length / Cache-Control，子目录部署自动剥站点前缀），不再依赖 Web 服务器静态规则
  - Nginx 只需一条伪静态 `try_files $uri $uri/ /index.php?$query_string;`，配不配静态 `location` 样式都能加载（配了则由 Nginx 直接读盘，性能更佳）
  - Apache 不变（`.htaccess` 已内置静态重写，兜底仅作保底）
  - 静态请求零框架开销：不启动 session、不触发插件钩子
- 文档同步：README / docs/install-bt.md 的 Nginx 静态规则降为「可选性能优化」

## v0.1.1（2026-08-04）

线上部署修复与加固（针对 Nginx 场景 CSS/JS 加载失败、后台登录网络错误）：

### 新增

- **内置官方应用商店（store.waikanl.cn）**：商店地址硬编码官方域名，用户零配置；直接消费官网 `/api/catalog` 目录与 `/downloads/apps/{id}` 下载；目录拉取失败自动回退内置商店，商店永不自挂
- **系统在线更新**：后台「系统更新」页检查/升级，更新包与版本信息托管在 store.waikanl.cn；升级前自动整站备份（排除 runtime/backups/public/uploads 与 config.php），失败自动回滚；支持包内 `upgrade.php` 迁移脚本；导航角标提醒新版本（24h 缓存）
- **更新/删除的 Windows 兼容加固**：删除前先清除只读属性（git 对象等只读文件在 Windows 下 rename/unlink 会被拒绝）

### 修复

- **静态资源加载失败**：静态资源统一走 `public/` 直链（`css/ js/ uploads/`），不再依赖伪静态重写；新增 Slim 静态兜底路由（`StaticFileController`），Nginx 未配置静态规则时也由 PHP 正常返回资源
- **API 请求路径错误**（后台登录/操作提示「网络错误」）：前端 API 调用改为 `pafishApi()` 统一入口，自动适配伪静态/非伪静态两种 URL 形式
- **模板缺失 Config 类别名**（全站 500）：`helpers.php` 补充 `class_alias`，模板内 `Config::get()` 恢复正常
- **Windows 下卸载/删除插件目录失败**：`Plugin/Theme/Store` 删除逻辑升级为「换名后重删」兜底，规避 PHP 进程对 include 过文件的路径级句柄占用
- 文档补充 Nginx 静态资源规则与两种部署模式说明（README / docs/install-bt.md）

## v0.1.0（2026-08-04）

pafish 博客 CMS（PHP 版）首个发布版本。功能/界面/数据层与 Node 版 v1.1.0 对齐。

### 核心功能

- Web 安装向导（环境检查 → 建库建表 → 种子数据 → 生成配置，零命令行）
- 前台：文章/分类/标签/搜索/归档/页面/RSS/sitemap/robots、评论楼中楼、密码门、点赞收藏、相关推荐、侧边栏组件、亮暗主题
- 后台 18 页：工作台（SVG 图表）、文章（Markdown 编辑器/批量导入/回收站/定时发布）、分类树、标签、媒体库（GD 压缩）、评论审核、通知、友链/导航/组件、外观（主题设置/导入导出）、站点设置（SMTP 测试/开放 API）、商店、插件、用户、备份（双模式）、个人资料
- 认证：登录/注册/忘记密码（双通道）、邮箱验证、CSRF、会话 7 天

### 扩展

- 主题系统：theme.json + 语义 CSS 变量（与 Node 版主题包兼容）+ 可选 PHP 模板覆盖
- 插件系统：11 个事件钩子、云存储管线、前台页面/页面模板、应用商店安装更新回滚
- 内置应用商店（demo-nord / hello-pafish / demo-hooks / binfen-storage）

### 运维

- 定时发布双通道：cron.php（宝塔计划任务）+ 前台请求低频兜底
- 开放 API v1（X-API-Key 鉴权）
- 缤纷云 S4 云存储插件（SigV4 签名）
- 发布包预打包 vendor，上传解压即用
