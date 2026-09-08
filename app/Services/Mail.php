<?php

declare(strict_types=1);

namespace Pafish\Services;

/**
 * 邮件发送（纯 PHP 零依赖）：
 * - 465 SSL / 587 STARTTLS 直连 SMTP（stream_socket_client）
 * - 配置优先级：settings 表（后台可配）> config.php smtp > PHP mail() 兜底
 * - 失败抛 RuntimeException，由调用方决定提示
 */
final class Mail
{
    public const TIMEOUT = 10;

    /** 发送一封纯文本邮件；失败抛异常 */
    public static function send(string $to, string $subject, string $text): void
    {
        $cfg = self::config();
        if ($cfg !== null) {
            self::smtpSend($cfg, $to, $subject, $text);
            return;
        }
        // mail() 兜底：虚拟主机无 SMTP 配置时的最后手段
        $headers = "Content-Type: text/plain; charset=UTF-8\r\nFrom: " . self::fallbackFrom();
        $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers);
        if (!$sent) {
            throw new \RuntimeException('邮件服务未配置（请在后台设置 SMTP）');
        }
    }

    /** 测试发信：使用表单当前值（未保存也能测） */
    public static function sendWith(
        array $cfg,
        string $to,
        string $subject,
        string $text
    ): void {
        self::smtpSend($cfg, $to, $subject, $text);
    }

    /** 读取 SMTP 配置（settings 表优先，config.php 兜底）；未配置返回 null */
    public static function config(): ?array
    {
        $host = (string) Settings::get('smtp_host', '');
        $port = (int) Settings::get('smtp_port', '465');
        $user = (string) Settings::get('smtp_user', '');
        $pass = (string) Settings::get('smtp_pass', '');
        $from = (string) Settings::get('smtp_from', '');
        if ($host === '' || $user === '' || $pass === '') {
            return null;
        }
        return ['host' => $host, 'port' => $port ?: 465, 'user' => $user, 'pass' => $pass, 'from' => $from];
    }

    // ---------- SMTP 实现 ----------

    private static function smtpSend(array $cfg, string $to, string $subject, string $text): void
    {
        $host = $cfg['host'];
        $port = (int) ($cfg['port'] ?? 465);
        $user = $cfg['user'];
        $pass = $cfg['pass'];
        $from = trim((string) ($cfg['from'] ?? '')) ?: '"' . $user . '" <' . $user . '>';
        $secure = $port === 465;

        $transport = $secure ? 'ssl://' . $host : 'tcp://' . $host;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            $transport . ':' . $port,
            $errno,
            $errstr,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT
        );
        if (!$fp) {
            throw new \RuntimeException('SMTP 连接失败：' . $errstr);
        }
        stream_set_timeout($fp, self::TIMEOUT);
        try {
            self::expect($fp, '220');
            self::cmd($fp, "EHLO " . (gethostname() ?: 'localhost'));
            self::expect($fp, '250');
            if (!$secure) {
                // STARTTLS：若服务器支持则升级为 TLS，否则继续明文
                self::cmd($fp, 'STARTTLS');
                $code = self::expect($fp, '220', false);
                if ($code === '220') {
                    $ok = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    if ($ok) {
                        self::cmd($fp, "EHLO " . (gethostname() ?: 'localhost'));
                        self::expect($fp, '250');
                    }
                }
            }
            self::cmd($fp, 'AUTH LOGIN');
            self::expect($fp, '334');
            self::cmd($fp, base64_encode($user));
            self::expect($fp, '334');
            self::cmd($fp, base64_encode($pass));
            self::expect($fp, '235');
            self::cmd($fp, 'MAIL FROM:<' . self::addr($from) . '>');
            self::expect($fp, '250');
            self::cmd($fp, 'RCPT TO:<' . $to . '>');
            self::expect($fp, '250');
            self::cmd($fp, 'DATA');
            self::expect($fp, '354');
            $body = "From: {$from}\r\n" .
                "To: <{$to}>\r\n" .
                "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" .
                "MIME-Version: 1.0\r\n" .
                "Content-Type: text/plain; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: base64\r\n" .
                "\r\n" .
                chunk_split(base64_encode($text));
            self::cmd($fp, str_replace("\r\n.\r\n", "\r\n..\r\n", $body) . "\r\n.");
            self::expect($fp, '250');
            self::cmd($fp, 'QUIT');
        } finally {
            fclose($fp);
        }
    }

    private static function cmd($fp, string $line): void
    {
        fwrite($fp, $line . "\r\n");
    }

    /** 读取服务器响应直到指定起始码；$throw=false 时返回实际码不抛错 */
    private static function expect($fp, string $wanted, bool $throw = true): string
    {
        $code = '';
        while (!feof($fp)) {
            $line = fgets($fp, 512);
            if ($line === false) {
                break;
            }
            $code = substr($line, 0, 3);
            // 多行响应的最后一行是 "code " 后跟空格
            if (substr($line, 3, 1) !== '-') {
                break;
            }
        }
        if ($throw && $code !== $wanted) {
            throw new \RuntimeException('SMTP 服务器返回 ' . ($code ?: '空响应'));
        }
        return $code;
    }

    private static function addr(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return $m[1];
        }
        return $from;
    }

    private static function fallbackFrom(): string
    {
        $user = (string) Settings::get('smtp_user', '');
        return $user !== '' ? $user : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}
