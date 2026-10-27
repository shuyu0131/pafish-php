# Pafish 主题开发 API 速查

本文对应当前 PHP 运行时。主题文件直接以 PHP 执行，不需要 Node、React 或构建步骤。标准起点是 [`theme-starter/pafish-starter`](theme-starter/pafish-starter/)；另一种从空目录开发的[实战主题与验证记录](theme-exercise/)可供参考。模板变量详见[主题数据契约](theme-data-contract.json)，检查命令详见下方“本地检查”。

## 1. 从请求到模板

控制器调用 `render($name, $data)`。它会把当前页面上下文与 `$data` 合并（显式传入的数据优先），解析主题模板并 `include`，最后返回 HTML。`render_partial($name, $data)` 使用同一套解析规则，因此局部模板也能读到页面变量；传给局部模板的数据优先级更高。`get_header()` 和 `get_footer()` 分别渲染 `header.php` 与 `footer.php`。

常用页面模板（其中归档页可选）：

| 页面 | 模板 | 常见数据 |
| --- | --- | --- |
| 首页、分类、标签、搜索、归档、微语 | `index.php`、`category.php`、`tag.php`、`search.php`、`archives.php`、`micro.php` | `posts` / `items`、分页、标题和 SEO 字段 |
| 文章与独立页面 | `post.php`、`page.php` | `post` / `page`、`contentHtml`、评论数据和可选扩展字段 |
| 用户中心 | `user.php` | `user`、`userRoute`、由 `userData` 开启的数据 |
| 登录与错误页 | `auth.php`、`error.php` | 认证模式或安全错误信息 |

模板的完整变量名、类型、可空性与安全约束见[数据契约](theme-data-contract.json)。不要把页面变量当成数据库行或长期兼容 API。

### 页面与文章变体

- `page-{name}.php` 覆盖选中同名模板的页面；文件会自动发现。`theme.json` 的 `pageTemplates` 可为这些文件补充后台显示名，不负责启用 PHP 文件。
- `post-template-{name}.php` 覆盖使用同名文章模板的文章；文件会自动发现，首部 PHP 注释可用 `@name 图片文章` 提供显示名。
- `post-{category_slug}.php` 按文章分类 slug 提供布局覆盖，不会出现在文章模板选项中。
- 根目录 `post-{slug}.php` 保留给文章变体/分类布局；局部模板不要命名为 `post-card.php` 这类形式，否则同名分类 slug 会优先把局部模板当整篇文章模板。局部卡片使用 `entry-card.php` 等名称；检查器会提示这种冲突。
- 文章候选优先级为文章模板、分类布局、`post.php`；页面候选优先级为页面模板、`page.php`。
- `theme_template_candidates` 过滤器可按请求上下文增删或调整候选顺序。主题候选未命中后才进入系统模板回退。

`systemTemplateFallback` 是可选的回退策略，不是每个主题都要覆盖全部页面的要求。未声明该字段时，缺少的页面使用系统模板；这既兼容旧主题，也适合只覆盖少数页面的新主题。显式声明后，缺少的首页、文章、独立页面、分类、标签或搜索模板必须由主题提供或列入回退数组。样板使用空数组演示严格、自包含的主题；如果只想复用部分系统页面，则逐项列出允许回退的类型。归档页是可选的：缺少 `archives.php` 且未允许系统回退时，`/archives` 返回 404，导航与 Sitemap 会隐藏该入口。`micro.php` 缺失时始终保留系统模板兼容回退。没有 `user.php` 时 `/user` 默认关闭，除非主题注册了 `userIndex` provider；把 `user` 写进回退数组不会单独开放用户中心。

## 2. 模板内可用的常用函数

| 函数 | 用途 |
| --- | --- |
| `e($value)` | HTML 文本和属性转义。用户内容、设置、标题和 URL 属性都应转义。 |
| `url_to('/path')`、`absolute_url('/path')` | 生成适配站点子目录和伪静态设置的站内链接，或绝对链接。 |
| `asset_url('/css/site.css')` | 引用 `public/` 下的系统资源。 |
| `theme_asset_url(Theme::active(), 'assets/css/site.css')` | 引用当前主题资源；主题根目录资源也可省略 `assets/`。 |
| `theme_value('key', 'fallback')` | 读取当前主题 `theme.json` 声明的设置值；未声明的键不会读出。 |
| `settings('site_name')`、`site_name()` | 读取站点设置或获取最终站点名称。站点名优先用 `site_name()`。 |
| `current_user()`、`is_logged_in()` | 读取安全资料白名单或登录状态；不要从模板读取认证凭据。 |
| `format_date($date, 'yyyy-MM-dd')` | 格式化日期；空值返回空字符串。 |
| `content_html($source, $format)`、`md($markdown)` | 将正文转换为经过核心内容管线处理的 HTML。控制器提供 `$contentHtml` 时优先使用它。 |
| `nav_items()`、`widget_items($area)`、`friend_links()` | 读取公开导航、组件和友情链接。 |
| `add_action()`、`do_action()`、`add_filter()`、`apply_filters()` | 模板或扩展使用的钩子入口；主题自有流程放在主题的 `extension.php`，可复用且需独立管理的功能放在插件中。 |

仅核心产生的 `$contentHtml` 和扩展渲染管线返回的 `$pluginHtml` 可作为 HTML 输出。其余字符串按普通文本处理并用 `e()` 转义。

## 3. 主题入口、钩子和注入

纯展示主题可以没有 `extension.php`。需要钩子、注入、路由、生命周期或扩展数据时，入口返回一个注册闭包：

```php
<?php
return static function (\Pafish\Services\ExtensionContext $ctx): void {
    $ctx->filter('frontend_meta', static function (array $meta, array $request): array {
        $meta['title'] = '主题定制标题';
        return $meta;
    });

    $ctx->inject('footer', static function (array $view): string {
        return '<p class="theme-note">主题页脚内容</p>';
    });

    $ctx->on('after_post_published', static function (array $post) use ($ctx): void {
        $ctx->log('文章已发布：' . ($post['title'] ?? ''));
    });
};
```

API v2 主题应在 `theme.json` 设置 `"apiVersion": 2`，并把每个 `$ctx->inject()` 目标列入 `injects`。允许的目标是 `head`、`footer`、`sidebar`、`comment_form`、`login_form`、`register_form`、`post_editor` 和 `micro_editor`。头部输出会筛选为 `script`、`meta`、`link`、`style` 标签；其他点位输出扩展返回的 HTML。

核心默认视图和 starter 已接入页面头部、页脚、侧栏、评论表单、登录表单、注册表单及两个编辑器注入点。主题覆盖了这些模板时，应在自己的模板中调用对应的 `Theme::renderInjection($target, $context)`；插件注入仍通过现有 `do_action()` 或 `Plugin::renderInjection()` 调用，主题与插件两边都需要保留。

常用过滤器：

| 过滤器 | 输入与返回值 | 适用场景 |
| --- | --- | --- |
| `theme_template_candidates` | 候选模板名数组；返回候选名数组 | 根据页面或文章数据选择主题布局 |
| `frontend_meta` | meta 数组和请求上下文；返回 meta 数组 | 修改标题、描述、canonical、OG、Twitter 或 JSON-LD |
| `filter_post_content` | 正文字符串和内容上下文；必须返回字符串 | 在核心正文渲染前处理文章或页面内容 |
| `theme_setting_value` | 当前设置字符串、键名和默认值；返回标量 | 兼容或计算主题设置值 |
| `posts_per_page` | 列表页数量和分页上下文；返回整数 | 调整公开文章列表数量 |

Action 使用 `$ctx->on($name, $callback)` 监听。可从 `after_create_post`、`after_update_post`、`after_post_published`、`after_comment_submit`、`after_login` 等事件开始；回调收到的载荷随事件而异，保存后触发的事件应作为通知，不要假设它能回滚核心操作。

## 4. 页面模板、评论和用户中心

**页面模板**用 `page-{name}.php` 实现完整的主题页面结构。若要由插件提供动态正文，插件在 manifest 声明 `pageTemplates` 并实现 `renderPageTemplate`；`page.php` 仍由主题承载公共布局。主题与插件同名时，存在的主题 `page-{name}.php` 优先。

**评论**可在文章或页面模板中用 `render_partial('comment-section', $data)` 组合核心评论流程。若要自定义评论区布局或交互，复制 starter 的评论局部模板并保留 `data-comment-section`、`data-comment-form` 等前端交互标记和表单字段；不要自行改变 API/CSRF/验证码约定。额外表单字段用 `comment_form` 注入点，主题也可在这些核心约定内实现与自身体验配套的评论流程。

**用户中心**由 `user.php` 决定是否开放。`theme.json` 的 `userData` 可按需声明 `posts`、`stats`、`reactions`、`comments`、`points`；未声明的数据不会查询，并以空列表或零值提供。访客不会触发这些用户专属查询。主题可以通过 `extension.php` 和 `userIndex`、`userMutate`、`userCard` provider 承载与主题产品紧密相关的工作流。若这些能力要跨主题复用、独立启停/升级，或应在主题切换后继续提供，则更适合交给插件；供全站共用的基础实体和规则由核心维护。

## 5. ExtensionContext 和扩展边界

`ExtensionContext` 通过 `$ctx->on()`、`filter()`、`inject()`、`provider()`、`lifecycle()` 接入运行时，也提供 `getData()` / `setData()`、`getSettings()` / `setSettings()`、`log()`、`render()`、`getEntityMeta()` / `setEntityMeta()`、`getRecord()` / `putRecord()` / `listRecords()` / `deleteRecord()` 等能力。使用扩展自有路由时，API v2 在 manifest 声明路由并由同名 provider 提供处理器；`$ctx->route()` 保留给旧版 API v1 兼容。

主题设置页由 `theme.json` 的 schema 生成，包含分组、联动及复合字段（例如 repeater），适合管理主题选项和数量有限的配置集合；它不是用来管理持续增长的业务记录的 CRUD 后台。主题可以通过 `extension.php` 承载与自身体验紧密相关的路由、工作流和数据，不过 Pafish 目前只给插件提供通用后台菜单与管理路由，主题没有等价的通用后台页面接入。

这属于当前接口能力的差异，不代表主题在产品边界上只能展示：Emlog 的模板包本身就提供了可自动加载、可实现类似插件功能的 `plugins.php`，以及 `options.php`、`callback.php` 等入口（[Emlog 模板开发指南](https://www.emlog.net/docs/dev/template)）；Halo 则限制主题可附带的资源类型，自定义模型和权限等能力交由插件（[Halo 主题附带资源](https://docs.halo.run/developer-guide/theme/extension-resources)、[插件与主题集成](https://docs.halo.run/developer-guide/plugin/theme-integration)）。

选择时按产品需求判断：主题设置足够表达的选项直接放主题设置；与主题体验紧密绑定的功能和数据可以随主题交付；需要独立后台管理页面时，当前可用插件管理入口，或基于具体案例再评估是否补主题侧接入；需要跨主题复用、独立启停/升级的功能适合插件。核心负责所有扩展共用的基础实体和系统规则。扩展数据优先使用核心扩展记录 API；确有索引、关联或数据库约束需求时，再声明扩展自有数据表。模板不要直接建表或写业务 SQL；业务逻辑通过主题/插件的 `extension.php` 与 `ExtensionContext` 实现。

## 6. 本地检查与定位

```bash
php developer/check-theme.php themes/my-theme
php developer/check-theme.php themes/my-theme --strict-partials
php developer/check-theme.php themes/my-theme --trace
php developer/check-theme.php themes/my-theme --trace --json
```

检查器不会启动应用、连接数据库、执行主题入口或启用主题。`--trace` 展示默认页面上下文下的主体模板候选、主题/系统解析来源、页面/文章模板变体和静态资源引用；它不能模拟数据库中的具体分类/页面、已注册的 `theme_template_candidates` 过滤器或动态 provider。`--json` 会把页面/局部变量名、解析信息、资源引用和诊断结果放入 `info`。

排查顺序建议：先看 `templateResolution` 和 `partials` 是否来自预期目录，再确认变量在数据契约中存在且当前分支会传入，接着检查资源引用是否使用 `theme_asset_url()`/相对 CSS `url()` 并且目标文件实际存在。若只有后台显示和前台结果不一致，再核对主题是否已激活、设置键是否写入 schema，以及 extension.php 是否声明了相应的 `injects`。
