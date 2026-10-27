# Field Notes 实战主题演练

这是从空目录完成的一份轻量主题，用来走通 pafish PHP 主题的真实开发路径。它不在 `themes/` 安装列表中，临时复制过去后才能本地启用。

主题实现了自定义首页、加密文章与普通文章详情、通用页面、“专注阅读”页面模板、分组设置、语义色覆盖、主题静态资源，以及通过 `extension.php` 注册的页脚注入。

## 检查

在仓库根目录运行：

```bash
php developer/check-theme.php developer/theme-exercise/field-notes --trace
```

分类、标签、搜索、归档和微语声明为系统模板回退；这是本主题有意缩小覆盖范围的选择。评论表单与分页也复用系统局部模板，所以 `--strict-partials` 会标出这些复用点。

## 页面模板

在后台编辑页面时将模板选为“专注阅读”。运行时会先尝试 `page-reading.php`；该模板设置布局变体后复用 `page.php` 的页面骨架。文件名决定分发，`pageTemplates` 提供后台显示名称。

## 扩展注入

`theme.json` 声明 API v2 与 `footer` 注入白名单。`extension.php` 从 `$ctx->getSettings()` 读取“主题扩展标记”，模板通过 `Theme::renderInjection('footer', $context)` 输出它。
