<?php

declare(strict_types=1);

/**
 * 演示插件：事件钩子（PHP 版，覆盖全部 11 个钩子点）
 * 通过 ctx.on() 注册系统事件钩子，ctx.log() 将日志写入插件数据（settings plugin_data:demo-hooks），
 * 可在插件管理页「数据」中查看。
 */

return [
    'registerHooks' => function (object $ctx): void {
        $ctx->on('after_create_post', function (array $post) use ($ctx): void {
            $ctx->log('文章发布：' . ($post['title'] ?? '') . '（id=' . ($post['id'] ?? '') . '）');
        });
        $ctx->on('after_update_post', function (array $post) use ($ctx): void {
            $ctx->log('文章更新：' . ($post['title'] ?? '') . '（id=' . ($post['id'] ?? '') . '）');
        });
        $ctx->on('after_delete_post', function (array $post) use ($ctx): void {
            $ctx->log('文章删除：' . ($post['title'] ?? '') . '（id=' . ($post['id'] ?? '') . '）');
        });
        $ctx->on('after_purge_post', function (array $post) use ($ctx): void {
            $ctx->log('文章清除：' . ($post['title'] ?? '') . '（id=' . ($post['id'] ?? '') . '）');
        });
        $ctx->on('after_comment_submit', function (array $c) use ($ctx): void {
            $ctx->log('收到评论：' . ($c['author'] ?? '') . ' 于文章 ' . ($c['postId'] ?? '') . '（' . ($c['status'] ?? '') . '）');
        });
        $ctx->on('after_comment_status', function (array $c) use ($ctx): void {
            $ctx->log('评论 ' . ($c['id'] ?? '') . ' 状态：' . ($c['from'] ?? '') . ' → ' . ($c['to'] ?? ''));
        });
        $ctx->on('after_comment_delete', function (array $c) use ($ctx): void {
            $ctx->log('评论删除：' . ($c['authorName'] ?? '') . '（id=' . ($c['id'] ?? '') . '）');
        });
        $ctx->on('after_comment_reply', function (array $c) use ($ctx): void {
            $ctx->log('管理员回复评论 ' . ($c['parentId'] ?? '') . '：' . ($c['content'] ?? ''));
        });
        $ctx->on('after_login', function (array $u) use ($ctx): void {
            $ctx->log('用户登录：' . ($u['username'] ?? ''));
        });
        $ctx->on('after_logout', function (array $u) use ($ctx): void {
            $ctx->log('用户登出：id=' . ($u['id'] ?? ''));
        });
        $ctx->on('after_register', function (array $u) use ($ctx): void {
            $ctx->log('用户注册：' . ($u['username'] ?? ''));
        });
    },

    'onActivate' => function (object $ctx): void {
        error_log('[demo-hooks] 已激活');
    },

    'onDeactivate' => function (object $ctx): void {
        error_log('[demo-hooks] 已停用');
    },
];
