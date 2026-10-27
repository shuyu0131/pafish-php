# 主题开发实战演练

`field-notes/` 是本轮从空目录搭建的 PHP 主题，故意与 Starter 的结构和视觉样式不同。它包含自定义首页、文章详情（含密码门）、通用页面、“专注阅读”页面模板、分组设置、主题 CSS 和一个由 `extension.php` 注册的页脚注入。

## 验证记录

- `php developer/check-theme.php developer/theme-exercise/field-notes --trace`：通过，0 错误；6 条提醒来自声明的系统页面回退、关闭的用户中心和复用的系统局部模板。
- 临时安装到本地站点并访问首页、普通文章、加密文章与 `template=reading` 页面：均返回 HTTP 200，布局与注入标记匹配；之后恢复原激活主题和页面模板，并移除临时安装目录。
- `php developer/check-theme.php developer/theme-starter/pafish-starter --strict-partials --trace`：通过，0 提示。
- Starter 主题专项回归：36 项通过；页面模板、文章模板和扩展核心专项回归也通过。

## 实际发现

`post-card.php` 作为列表局部模板时，同时符合 `post-{category_slug}.php` 的分类文章模板命名规则。若站点新增 slug 为 `card` 的分类，解析器会把列表卡片当成文章详情模板。演练样例和 Starter 均改用 `entry-card.php`，检查器现会对仍有该命名交叉的主题给出提示。已安装的旧主题仍需按提示逐个评估；本轮不改动这些主题包。

除这个命名交叉外，演练没有发现需要新增运行时 API 的阻塞。依靠模板数据契约、trace、现有设置 schema 和注入点即可走完这条最小开发路径。
