<?php

declare(strict_types=1);

namespace Pafish\Services;

/**
 * 图形验证码：PNG 栅格化并嵌入 SVG + 文件缓存校验
 * 验证码：4 位、5 分钟有效、校验通过即销毁（用后即焚）
 * 字符集避开 0/O/1/I/L 等易混字符
 */
final class Captcha
{
    private const CHARS = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const CODE_LEN = 4;
    private const TTL = 300; // 5 分钟

    /** 生成验证码，返回 [token, svg]；可选绑定当前会话/请求身份 */
    public static function create(string $identity = ''): array
    {
        $token = bin2hex(random_bytes(16));
        $chars = [];
        for ($i = 0; $i < self::CODE_LEN; $i++) {
            $chars[] = self::CHARS[random_int(0, strlen(self::CHARS) - 1)];
        }
        $svg = self::renderSvg($chars);
        self::store($token, implode('', $chars), $identity);
        return [$token, $svg];
    }

    /** 校验：文件锁使失败计数与一次性消费在并发请求间保持原子 */
    public static function verify(string $token, string $answer, string $identity = ''): bool
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token) || $answer === '') {
            return false;
        }
        $file = self::file($token);
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            return false;
        }
        try {
            $raw = @file_get_contents($file);
            if (!is_string($raw) || $raw === '') return false;
            $parts = explode('|', $raw, 4);
            $savedAnswer = (string) ($parts[0] ?? '');
            $expiresAt = (int) ($parts[1] ?? 0);
            $savedIdentity = (string) ($parts[2] ?? '');
            $attempts = (int) ($parts[3] ?? 0);
            if ($expiresAt < time()) { @unlink($file); return false; }
            if ($identity !== '' && ($savedIdentity === '' || !hash_equals($savedIdentity, self::identityHash($identity)))) return false;
            if (hash_equals($savedAnswer, strtoupper(trim($answer)))) { @unlink($file); return true; }
            $attempts++;
            if ($attempts >= 5) { @unlink($file); return false; }
            if (@file_put_contents($file, $savedAnswer . '|' . $expiresAt . '|' . $savedIdentity . '|' . $attempts, LOCK_EX) === false) return false;
            return false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/runtime/captcha';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function file(string $token): string
    {
        return self::dir() . '/' . preg_replace('/[^a-f0-9]/', '', $token) . '.cap';
    }

    private static function store(string $token, string $answer, string $identity = ''): void
    {
        $identityHash = $identity !== '' ? self::identityHash($identity) : '';
        @file_put_contents(self::file($token), $answer . '|' . (time() + self::TTL) . '|' . $identityHash . '|0', LOCK_EX);
        // 顺带清理过期文件（每次写入最多扫 32 个，防膨胀）
        $files = glob(self::dir() . '/*.cap');
        if (is_array($files) && count($files) > 200) {
            $now = time();
            $expired = 0;
            foreach ($files as $f) {
                $raw = @file_get_contents($f);
                if ($raw !== false) {
                    $ts = (int) explode('|', $raw, 2)[1];
                    if ($ts < $now && @unlink($f)) {
                        $expired++;
                    }
                }
                if ($expired >= 32) {
                    break;
                }
            }
        }
    }

    private static function identityHash(string $identity): string
    {
        return hash('sha256', $identity);
    }

    /** 渲染 4 位验证码 SVG：随机旋转 + 干扰线，currentColor 适配亮暗主题 */
    private static function renderSvg(array $chars): string
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            throw new \RuntimeException('图形验证码需要启用 PHP GD 扩展');
        }
        $width = 150;
        $height = 48;
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 246, 248, 250);
        imagefill($image, 0, 0, $background);
        for ($i = 0; $i < 5; $i++) {
            $color = imagecolorallocate($image, random_int(120, 210), random_int(120, 210), random_int(120, 210));
            imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $color);
        }
        for ($i = 0; $i < 36; $i++) {
            $color = imagecolorallocate($image, random_int(120, 220), random_int(120, 220), random_int(120, 220));
            imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $color);
        }
        foreach ($chars as $index => $char) {
            $color = imagecolorallocate($image, random_int(10, 75), random_int(10, 75), random_int(10, 75));
            $font = random_int(4, 5);
            imagestring($image, $font, 17 + $index * 31 + random_int(-2, 2), random_int(13, 20), $char, $color);
        }
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        if (!is_string($png) || $png === '') throw new \RuntimeException('无法生成图形验证码');
        return '<svg xmlns="http://www.w3.org/2000/svg" width="150" height="48" viewBox="0 0 150 48" role="img" aria-label="验证码">'
            . '<image width="150" height="48" href="data:image/png;base64,' . base64_encode($png) . '"/></svg>';
    }
}
