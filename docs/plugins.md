# pafish PHP 插件开发

当前插件 API 版本为 **v2**。插件目录位于 `plugins/{name}/`，最小结构：

```text
plugins/example/
├── plugin.json
└── index.php
```

`plugin.json` 示例：

```json
{
  "name": "example",
  "title": "示例插件",
  "version": "1.0.0",
  "apiVersion": 2,
  "description": "插件说明",
  "author": "pafish",
  "settings": [],
  "injects": ["head", "footer"]
}
```

名称必须与目录一致，并符合 `^[a-z0-9_-]{1,50}$`。未声明 `apiVersion` 的旧插件按 v1 运行。

## index.php

插件入口返回函数数组：

```php
<?php

return [
    'registerHooks' => function (object $ctx): void {
        $ctx->on('after_post_published', function (array $post) use ($ctx): void {
            $ctx->log('文章发布：' . $post['title']);
        });
        $ctx->filter('frontend_meta', function (array $meta, array $context): array {
            $meta['robots'] = 'index,follow';
            return $meta;
        });
    },
    'onActivate' => function (object $ctx): void {},
    'onDeactivate' => function (object $ctx): void {},
    'onUninstall' => function (object $ctx): void {},
];
```

## PluginContext

- `$ctx->name`：插件名。
- `$ctx->apiVersion`：插件声明的 API 版本。
- `$ctx->on($hook, $fn, $priority = 10)`：注册 action。
- `$ctx->filter($hook, $fn, $priority = 10)`：注册 filter（v2）。
- `$ctx->getData()` / `$ctx->setData($array)`：插件 JSON 数据。
- `$ctx->getSettings()` / `$ctx->setSettings($partial)`：插件设置。
- `$ctx->log($message)`：保存最近 50 条日志。

action/filter 都会自动附带 `plugin:{name}` 标记，插件停用时统一注销。
执行 `onUninstall` 时插件设置和数据仍可读取，回调结束后系统才会删除持久化数据与插件目录。

## 设置字段

支持 `text`、`textarea`、`checkbox`、`switcher`、`select`、`radio`、`color`、`image`、`password`，以及 `group` 分组和 `show_if` 联动。

## 注入位置

API v2 插件必须在 `injects` 中声明使用的位置：

- `head`、`footer`、`sidebar`
- `comment_form`
- `login_form`、`register_form`
- `post_editor`

实现方式：

```php
'renderInjection' => function (string $target, object $ctx, array $context): string {
    if ($target !== 'comment_form') return '';
    return '<input type="hidden" name="plugins[example][token]" value="...">';
},
```

表单扩展字段必须使用 `plugins[插件名][字段名]`。登录、注册、评论和文章保存会把这些字段放进对应钩子的 `plugins` / `extensions` 数据中。

## 主要 action

- `after_create_post`、`after_update_post`、`after_delete_post`、`after_purge_post`
- `after_post_published`：首次发布、定时发布或批量发布后；`trigger` 为 `create|update|schedule|batch`
- `after_post_save`：包含 `post`、`created`、`action`、`extensions`
- `after_comment_submit`、`after_comment_status`、`after_comment_delete`、`after_comment_reply`
- `after_login`、`after_logout`、`after_register`

文章状态使用大写值：`DRAFT`、`PUBLISHED`、`SCHEDULED`。

## 主要 filter

- `before_login($decision, $context)`
- `before_register($decision, $context)`
- `before_comment_submit($decision, $context)`
- `frontend_meta($meta, $context)`
- `markdown_html($html, $context)`
- `upload_file($file)`

前置策略可把 `allowed` 设为 `false` 并返回 `error` 与 HTTP 状态。评论策略还可以把 `status` 改为 `PENDING`、`APPROVED` 或 `REJECTED`。

`frontend_meta` 支持 `title`、`description`、`canonical`、`robots`、`og`、`twitter` 和 `jsonLd`。

## 打包

ZIP 必须只有一个顶层目录，且顶层目录名等于插件名。内置商店运行：

```bash
php scripts/build-store.php
```

第三方插件是具有站点权限的 PHP 代码，只应从可信来源安装。
