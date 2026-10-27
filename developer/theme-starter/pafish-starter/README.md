# Pafish PHP 主题起点

这是一个可复制、可运行的完整主题样板。它提供首页、文章、页面、分类、标签、搜索、归档、微语、用户中心、错误页和登录/注册页，并通过 `systemTemplateFallback: []` 演示不混入系统页面布局的严格模式。复制后可以删去不需要的可选模板；若只覆盖少数页面，最简单的方式是省略 `systemTemplateFallback`，缺少的页面会继续使用系统模板；若只允许部分页面回退，则在数组里逐项列出。归档页是可选入口：删除 `archives.php` 且未允许系统回退时，`/archives` 返回 404，系统导航和 Sitemap 会隐藏该地址。没有 `user.php` 或 `userIndex` provider 时，用户中心也不会开放。

## 开始开发

1. 复制整个 `pafish-starter` 目录到项目的 `themes/` 下，并把目录名改成主题 ID，例如 `themes/my-journal/`。
2. 修改 `theme.json` 中的 `name`，使其与目录名完全一致，再填写标题、作者和设置项。
3. 直接编辑根目录中的 PHP 模板；模板文件就是渲染入口。完整样式放进 `assets/css/style.css`，语义色放进 `theme.css`。无需构建工具。
4. 在后台“主题与外观”启用主题，检查主题实现的页面；这个完整样板包含所有常见入口，精简主题可以只实现自己需要的页面。
5. 每次提交前从项目根目录运行：

   ```bash
   php developer/check-theme.php themes/my-journal --strict-partials
   ```

检查器只读文件，不会安装或启用主题。它会校验 manifest、共享模板数据契约和设置字段，按回退策略检查页面模板缺失与 PHP 语法，并列出静态模板调用实际会使用的局部文件。JSON 中的 `templatePolicy` 会区分严格模式下需要覆盖的页面、可选页面和兼容回退页面；`primaryTemplates` 仅表示文件存在情况。`--strict-partials` 会把仍落到 `app/Views/theme/` 的局部模板视为错误。

开发 API 速查见 [`../../theme-api-reference.md`](../../theme-api-reference.md)。需要确认默认页面模板来源、页面/文章变体或资源引用时运行 `php developer/check-theme.php themes/my-journal --trace`；这是静态检查，不会执行主题入口或模拟具体请求数据。

## 文件约定

主体文件：`index.php`、`post.php`、`page.php`、`category.php`、`tag.php`、`search.php`、`archives.php`、`micro.php`、`user.php`。主题自己的 `header.php` 和 `footer.php` 组成站点框架。`auth.php` 保留核心登录、注册和找回密码 API 的字段约定，外观由 `assets/css/auth.css` 控制。其他根目录 PHP 文件可作为局部模板，由 `render_partial('文件名')` 调用。

样板中包含可工作的 `entry-card.php` 列表卡片、分页、相关文章、微语卡片和评论模板。局部卡片使用 `entry-card.php`，避免与 `post-{category_slug}.php` 文章布局规则冲突。评论区沿用核心公开的表单数据属性和接口约定，但 markup、排版与 CSS 由主题自行负责。主题和插件注入点保留在 `header.php`、`footer.php`、评论表单及认证表单中。

## 模板数据

控制器通过 `render($name, $data)` 传入页面数据。模板可直接读取这些变量；`render_partial($name, $data)` 会继承当前页面上下文，局部传入的数据优先。完整页面和常用局部组件字段、空值及条件字段见 [`../../theme-data-contract.json`](../../theme-data-contract.json)，这是控制器与主题样板共享的数据契约，并由检查器验证其结构。

| 模板 | 主要变量 |
| --- | --- |
| `index` | `posts[]`（文章列表项）、`pageNum`、`totalPages`、`listBaseUrl`、`emptyText`、`title`、`description`、`og` |
| `post` | `post`（详情文章）、`contentHtml`、`locked`、可选 `passwordError`、互动状态、可选自定义字段、上下篇、相关文章、评论树与分页 |
| `page` | `page`、`contentHtml`、`pluginHtml`、评论开关与评论树分页 |
| `category` | `category`、`posts[]`、`total`、`pageNum`、`totalPages`、`listBaseUrl`、SEO 字段 |
| `tag` | `tag`、`posts[]`、`total`、`pageNum`、`totalPages`、`listBaseUrl`、SEO 字段 |
| `search` | `keyword`、`posts[]`、`total`、`pageNum`、`totalPages`、`listBaseUrl`、`robots` |
| `archives` | `groups[]`，每项含本地化 `month` 与 `{title,slug,published_at}[]`；`total` |
| `micro` | `items[]`（规范化驼峰字段）、`pageNum`、`totalPages`、`total`、`listBaseUrl` |
| `user` | `user`（安全资料白名单或空对象）、`userRoute`、`posts[]`、`stats`、`reactions[]`、`comments[]`、积分状态与流水 |
| `auth` | 独立认证页：`mode`、`siteName`、`subtitle`，以及当前模式适用的注册/验证/跳转/令牌字段 |
| `error` | 安全错误页：`status`、`message`、`backUrl` |

文章列表项和文章详情使用不同数据形状：列表只暴露 `is_password_protected` 标记，不传密码校验值；详情在未解锁时还会移除正文和 SEO 描述。`current_user()` 返回 `id/username/nickname/email/avatar_url/description/role/created_at` 白名单，不包含认证凭据。评论作者和微语作者也是核心整理过的展示数据，不是数据库行。

数据库字段会保留 PDO 的标量形式（数字列可能以整数或数字字符串出现）。条件字段会在密码门、登录状态、评论开关和主题 `userData` 声明下缺省或返回空列表；主题接管 `userIndex` provider 后则自行决定查询哪些数据。读取可空、可选字段时使用 `??` 和 `is_array()` 做防御，不要把不存在的值当作空字符串或布尔假值的稳定语义。安全细节和每个字段的说明都在契约 JSON 中。

用户内容和设置值用 `e()` 转义。只有核心生成的 `$contentHtml` 和核心/扩展渲染管线返回的 `$pluginHtml` 按可信 HTML 输出。URL 用 `url_to()`，主题文件资源用 `theme_asset_url(\Pafish\Services\Theme::active(), 'assets/...')`。

## 职责边界

主题可以是包含模板、资源、设置和紧密相关工作流的自包含产品。`theme.json` 的 `userData` 控制核心是否查询用户中心中的文章、统计、互动、评论和积分数据；未声明的部分不查询并返回空列表/零值。主题可以通过 provider 承载主题自己的投稿、资料维护等流程；需要钩子、路由、生命周期或扩展数据时，增加 `extension.php` 并使用主题上下文 API。主题设置页支持复合字段和 repeater，可管理主题选项与数量有限的配置集合；持续增长的业务记录则需要 CRUD 管理界面。目前通用后台菜单和管理路由只提供给插件。需要跨主题复用、独立启停/升级，或应在主题切换后继续提供的能力更适合做成插件；所有扩展共用的基础实体和系统规则由核心负责。详细选择方式见[主题 API 速查](../../theme-api-reference.md)。主题模板中不要直接创建或修改核心数据库表。
