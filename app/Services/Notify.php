<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;

/**
 * 站内通知 + 邮件通知
 * - createNotification：新评论/新回复写入 notifications 表（后台铃铛）
 * - sendCommentEmail：站长邮件提醒（notify_email_enabled + notify_email 开启时生效；失败静默）
 * - sendReplyEmail：被回复者邮件通知（父评论者勾选 notifyReply；失败静默）
 * - sendEmailCode / sendResetLinkEmail：验证码 / 重置链接（失败抛错给调用方）
 */
final class Notify
{
    /** 站内通知：新评论/新回复 */
    public static function createNotification(string $type, string $message, ?int $postId = null, ?int $commentId = null): void
    {
        DB::execute(
            'INSERT INTO notifications (type, message, post_id, comment_id) VALUES (?, ?, ?, ?)',
            [$type, $message, $postId, $commentId]
        );
    }

    /** 邮箱验证码（注册/忘记密码统一入口）；失败抛错给调用方 */
    public static function sendEmailCode(string $email, string $code, string $purpose): void
    {
        $siteName = site_name();
        $isRegister = $purpose === 'register';
        Mail::send(
            $email,
            $isRegister ? '注册验证码' : '重置密码验证码',
            implode("\n", [
                '你好：',
                '',
                $isRegister
                    ? "你正在注册「{$siteName}」账号，验证码为："
                    : "你正在找回「{$siteName}」账号密码，验证码为：",
                '',
                "  {$code}  ",
                '',
                '验证码 10 分钟内有效。如果不是你本人操作，请忽略此邮件。',
            ])
        );
    }

    /** 站长邮件提醒：站点设置开启 + SMTP 配置后生效；发信失败静默，不影响主流程 */
    public static function sendCommentEmail(array $ctx): void
    {
        $enabled = (string) Settings::get('notify_email_enabled', '') === 'true';
        $to = (string) Settings::get('notify_email', '');
        if (!$enabled || $to === '') {
            return;
        }
        try {
            $postUrl = \absolute_url('/post/' . rawurlencode((string) $ctx['postSlug']));
            $adminUrl = \absolute_url('/admin/comments?status=PENDING');
            Mail::send(
                $to,
                '收到新' . ($ctx['isReply'] ? '回复' : '评论') . '：《' . $ctx['postTitle'] . '》',
                implode("\n", [
                    $ctx['commenter'] . ' 在文章《' . $ctx['postTitle'] . '》下发表了' . ($ctx['isReply'] ? '回复' : '评论') . '：',
                    '',
                    (string) $ctx['content'],
                    '',
                    '查看文章：' . $postUrl,
                    '审核评论：' . $adminUrl,
                ])
            );
        } catch (\Throwable) {
            // 邮件发送失败不阻塞评论流程
        }
    }

    /** 被回复者邮件通知：评论者勾选"有新回复邮件通知我"后，有人回复时发信 */
    public static function sendReplyEmail(array $input): void
    {
        try {
            $threadUrl = \absolute_url(
                '/post/' . rawurlencode((string) $input['postSlug']) . '#comment-' . $input['commentId']
            );
            Mail::send(
                $input['toEmail'],
                $input['replier'] . ' 回复了你在《' . $input['postTitle'] . '》下的评论',
                implode("\n", [
                    $input['replier'] . ' 回复了你在《' . $input['postTitle'] . '》下的评论：',
                    '',
                    (string) $input['replyContent'],
                    '',
                    '查看回复：' . $threadUrl,
                ])
            );
        } catch (\Throwable) {
            // 邮件发送失败不阻塞评论流程
        }
    }

    /** 重置链接邮件（forgot 流程）；失败抛错 */
    public static function sendResetLinkEmail(string $to, string $username, string $resetUrl): void
    {
        $siteName = site_name();
        Mail::send(
            $to,
            '重置密码',
            implode("\n", [
                "你好 {$username}：",
                '',
                '请在 30 分钟内点击以下链接重置密码：',
                $resetUrl,
                '',
                '如果不是你本人操作，请忽略此邮件。',
            ])
        );
    }
}
