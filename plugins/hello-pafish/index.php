<?php

declare(strict_types=1);

/**
 * Hello, pafish —— 应用商店安装演示插件（PHP 版）
 * 约定：index.php 返回函数数组；每个约定函数接收 ctx（插件上下文）：
 *   - registerHooks(ctx)：注册事件钩子（ctx.on，返回注销函数）
 *   - renderPageTemplate(template, page, ctx)：渲染页面模板（只处理自己声明的模板名，其余返回 null）
 *   - renderPluginPage(path, ctx)：渲染 /plugin/<name>/<path> 前台页面
 *   - onActivate / onDeactivate / onUninstall：生命周期回调
 * ctx API：on / getData / setData / getSettings / setSettings / log / refreshInjections
 */

function pafish_hello_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

return [
    'registerHooks' => function (object $ctx): void {
        $ctx->on('after_login', function (array $u) use ($ctx): void {
            $ctx->log('商店插件：用户 ' . ($u['username'] ?? '') . ' 登录');
        });
    },

    'onActivate' => function (object $ctx): void {
        // 激活时记录一次打招呼（PHP 无 Node 版 5s 节流，boot() 每请求执行，不能放在 registerHooks）
        $ctx->log('Hello, pafish! 我已通过应用商店安装并启用。');
        error_log('[hello-pafish] 已激活');
    },

    'onDeactivate' => function (object $ctx): void {
        error_log('[hello-pafish] 已停用');
    },

    'onUninstall' => function (object $ctx): void {
        error_log('[hello-pafish] 已卸载');
    },

    // 页面模板渲染：只处理自己声明的模板名，其余返回 null（交给后续插件/默认渲染）
    'renderPageTemplate' => function (string $template, array $page, object $ctx): ?string {
        if ($template !== 'card') {
            return null;
        }
        return '<div style="border:1px solid var(--border);border-radius:12px;padding:24px 28px;background:var(--card)">'
            . '<h2 style="margin-top:0">卡片模板：' . pafish_hello_escape((string) ($page['title'] ?? '')) . '</h2>'
            . '<p style="color:var(--muted);font-size:13px">此页面由插件 hello-pafish 的 renderPageTemplate 渲染。</p>'
            . '<div>' . pafish_hello_escape((string) ($page['content'] ?? '')) . '</div>'
            . '</div>';
    },

    // 插件前台页面：渲染 /plugin/hello-pafish/hello（含 ctx API 访问示例）
    'renderPluginPage' => function (string $path, object $ctx): ?string {
        if ($path !== 'hello') {
            return null;
        }
        $data = $ctx->getData();
        $logs = is_array($data['logs'] ?? null) ? array_slice($data['logs'], -5) : [];
        $items = '';
        foreach ($logs as $l) {
            $items .= '<li>' . pafish_hello_escape((string) ($l['time'] ?? '')) . '：' . pafish_hello_escape((string) ($l['message'] ?? '')) . '</li>';
        }
        if ($items === '') {
            $items = '<li>暂无日志</li>';
        }
        return '<h1>Hello, pafish!</h1>'
            . '<p>这是插件前台页面（/plugin/hello-pafish/hello），由 hello-pafish 的 <code>renderPluginPage</code> 渲染，'
            . '并套用站点的导航 / 主题 / 页脚注入。</p>'
            . '<h3>最近插件日志</h3>'
            . '<ul>' . $items . '</ul>';
    },
];
