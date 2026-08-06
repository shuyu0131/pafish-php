<?php

declare(strict_types=1);

namespace Pafish\Services;

/**
 * 图形验证码：SVG 生成（零依赖，currentColor 自适应亮暗主题）+ 文件缓存校验
 * 对齐 Node 版 src/lib/captcha.ts：4 位、5 分钟有效、校验通过即销毁（用后即焚）
 * 字符集避开 0/O/1/I/L 等易混字符
 */
final class Captcha
{
    private const CHARS = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const CODE_LEN = 4;
    private const TTL = 300; // 5 分钟

    /** 生成验证码，返回 [token, svg] */
    public static function create(): array
    {
        $token = bin2hex(random_bytes(16));
        $chars = [];
        for ($i = 0; $i < self::CODE_LEN; $i++) {
            $chars[] = self::CHARS[random_int(0, strlen(self::CHARS) - 1)];
        }
        $svg = self::renderSvg($chars);
        self::store($token, implode('', $chars));
        return [$token, $svg];
    }

    /** 校验：正确则销毁（用后即焚），错误保留可重试 */
    public static function verify(string $token, string $answer): bool
    {
        if ($token === '' || $answer === '') {
            return false;
        }
        $file = self::file($token);
        $raw = is_file($file) ? (string) file_get_contents($file) : '';
        if ($raw === '') {
            return false;
        }
        [$savedAnswer, $expiresAt] = explode('|', $raw, 2);
        if ((int) $expiresAt < time()) {
            @unlink($file);
            return false;
        }
        if (strtoupper($savedAnswer) === strtoupper(trim($answer))) {
            @unlink($file);
            return true;
        }
        return false;
    }

    // ---------- 内部 ----------

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

    private static function store(string $token, string $answer): void
    {
        @file_put_contents(self::file($token), $answer . '|' . (time() + self::TTL));
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

    /** 渲染 4 位验证码 SVG：随机旋转 + 干扰线，currentColor 适配亮暗主题 */
    private static function renderSvg(array $chars): string
    {
        $width = 120;
        $height = 40;
        $parts = [];
        $parts[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height
            . '" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="验证码">';

        // 干扰线（3 条，随机角度位置）
        for ($i = 0; $i < 3; $i++) {
            $x1 = random_int(0, $width);
            $y1 = random_int(0, $height);
            $x2 = random_int(0, $width);
            $y2 = random_int(0, $height);
            $parts[] = '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2
                . '" stroke="currentColor" stroke-opacity="0.25" stroke-width="1" />';
        }

        // 干扰点（12 个）
        for ($i = 0; $i < 12; $i++) {
            $parts[] = '<circle cx="' . random_int(0, $width) . '" cy="' . random_int(0, $height)
                . '" r="1" fill="currentColor" fill-opacity="0.3" />';
        }

        // 字符（随机旋转 ±25°，轻微上下浮动）
        $step = $width / (count($chars) + 1);
        foreach ($chars as $i => $ch) {
            $x = (int) (($i + 1) * $step);
            $y = (int) (28 + random_int(-4, 4));
            $rot = random_int(-25, 25);
            $parts[] = '<text x="' . $x . '" y="' . $y
                . '" text-anchor="middle" dominant-baseline="middle" font-size="22" font-family="monospace" '
                . 'font-weight="bold" fill="currentColor" transform="rotate(' . $rot . ' ' . $x . ' ' . $y . ')">'
                . $ch . '</text>';
        }

        $parts[] = '</svg>';
        return implode('', $parts);
    }
}
