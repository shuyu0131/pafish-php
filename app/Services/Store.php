<?php
declare(strict_types=1);

namespace Pafish\Services;

/**
 * 应用商店（对齐 Node 版 store.ts 协议）：
 * - 设置键 store_url（空 → 内置本地源 store/*.json）与 store_token（Bearer）
 * - 目录：{base}/themes.json、{base}/plugins.json（顶层数组，条目含 name/title/version/zip）
 * - 名称正则 /^[a-z0-9_-]{1,50}$/；zip 相对路径拼 base，http(s) 直用
 * - 版本比较：数字分段（容忍 v 前缀，非数字段按 0）
 * - 安装拒绝已存在（提示直接更新）；更新 = 备份旧版 → 移除 → 装新版，失败自动恢复旧版
 * - Windows 兼容：全程 SPL 递归复制/删除，不依赖 rename（PHP 8.5 + Windows 上
 *   stat 句柄会导致 rename/rmdir「拒绝访问」且时好时坏）
 */
final class Store
{
    private const NAME_PATTERN = '/^[a-z0-9_-]{1,50}$/';
    private const MAX_ZIP_BYTES = 10 * 1024 * 1024;
    private const KIND_FILE = ['theme' => 'themes.json', 'plugin' => 'plugins.json'];

    /** 商店地址（未配置或非 http(s) 视为使用内置本地源） */
    public static function baseUrl(): string
    {
        $url = trim((string) Settings::get('store_url', ''));
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }
        return rtrim($url, '/');
    }

    /** 商店访问令牌（下载私有包时带 Authorization: Bearer） */
    public static function token(): string
    {
        return trim((string) Settings::get('store_token', ''));
    }

    /** 数字分段版本比较：$a < $b → -1，相等 → 0，$a > $b → 1（对齐 Node compareVersions） */
    public static function compareVersions(string $a, string $b): int
    {
        $pa = self::versionParts($a);
        $pb = self::versionParts($b);
        $n = max(count($pa), count($pb));
        for ($i = 0; $i < $n; $i++) {
            $x = $pa[$i] ?? 0;
            $y = $pb[$i] ?? 0;
            if ($x < $y) {
                return -1;
            }
            if ($x > $y) {
                return 1;
            }
        }
        return 0;
    }

    private static function versionParts(string $v): array
    {
        $v = ltrim(trim($v), 'vV');
        return array_map(static fn (string $s): int => ctype_digit($s) ? (int) $s : 0, explode('.', $v));
    }

    /**
     * 拉取目录：未配置 store_url → 内置本地源；配置后远程失败 → 回退内置源并附 error。
     * 返回 ['items' => 条目数组, 'base' => 源地址, 'error'? => 回退原因]
     */
    public static function fetchCatalog(string $kind): array
    {
        $file = self::KIND_FILE[$kind] ?? 'themes.json';
        $base = self::baseUrl();
        if ($base === '') {
            return ['items' => self::readLocalCatalog($file), 'base' => ''];
        }
        try {
            return ['items' => self::parseCatalog(self::httpGet($base . '/' . $file)), 'base' => $base];
        } catch (\Throwable $e) {
            return [
                'items' => self::readLocalCatalog($file),
                'base' => '',
                'error' => '远程商店不可用：' . $e->getMessage() . '，已回退到内置商店',
            ];
        }
    }

    /** 已安装版本（未安装返回 null） */
    public static function getInstalledVersion(string $kind, string $name): ?string
    {
        $manifest = $kind === 'theme' ? Theme::manifest($name) : Plugin::manifest($name);
        if ($manifest === null) {
            return null;
        }
        $v = $manifest['version'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** 从商店安装（已安装 → 拒绝，提示直接更新） */
    public static function installFromStore(string $kind, string $name, string $base, array $item): array
    {
        self::assertValidName($name);
        if (self::getInstalledVersion($kind, $name) !== null) {
            throw new \RuntimeException('已安装，可直接更新');
        }
        $buffer = self::downloadZip($base, $item);
        self::validateZip($buffer, $name);
        if ($kind === 'theme') {
            return Theme::installFromBuffer($buffer);
        }
        return Plugin::installFromBuffer($buffer, $name . '@store');
    }

    /**
     * 从商店更新：备份旧版本 → 移除 → 安装新版；失败自动恢复旧版本（对齐 Node
     * updateFromStore 的 .bak 回滚语义，但不依赖 rename——SPL 复制/删除）
     */
    public static function updateFromStore(string $kind, string $name, string $base, array $item): array
    {
        self::assertValidName($name);
        if (self::getInstalledVersion($kind, $name) === null) {
            throw new \RuntimeException('未安装，请先安装');
        }
        $buffer = self::downloadZip($base, $item);
        self::validateZip($buffer, $name);
        $root = $kind === 'theme' ? Theme::root() : Plugin::root();
        $target = $root . '/' . $name;
        $bak = $root . '/.bak-store-' . bin2hex(random_bytes(4));
        if (!self::copyDir($target, $bak)) {
            throw new \RuntimeException('更新失败：无法备份旧版本');
        }
        try {
            if (!self::rmDir($target)) {
                throw new \RuntimeException('无法移除旧版本');
            }
            $result = $kind === 'theme'
                ? Theme::installFromBuffer($buffer)
                : Plugin::installFromBuffer($buffer, $name . '@store');
        } catch (\Throwable $e) {
            self::rmDir($target);
            self::copyDir($bak, $target);
            self::rmDir($bak);
            throw new \RuntimeException('更新失败：' . $e->getMessage() . '（已恢复旧版本）');
        }
        self::rmDir($bak);
        return $result;
    }

    // ---------- 内部 ----------

    private static function assertValidName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \RuntimeException('商店条目名称不合法');
        }
    }

    /** 下载 zip 包（远程带 Bearer token；内置源直接读本地文件） */
    private static function downloadZip(string $base, array $item): string
    {
        $zip = (string) ($item['zip'] ?? '');
        if ($zip === '') {
            throw new \RuntimeException('商店条目缺少 zip 地址');
        }
        if (preg_match('#^https?://#i', $zip) === 1) {
            return self::httpGet($zip);
        }
        if ($base === '') {
            // 内置源：zip 是网站根相对路径（/store/xxx.zip，与 Node 内置源格式一致），
            // 本地 fs 直读项目根/store/ 下的包文件（无网络依赖）
            $rel = ltrim($zip, '/');
            if (str_starts_with($rel, 'store/')) {
                $rel = substr($rel, 6);
            }
            $local = self::localRoot() . '/' . $rel;
            $buf = @file_get_contents($local);
            if ($buf === false) {
                throw new \RuntimeException('内置商店缺少包文件：' . $zip);
            }
            return $buf;
        }
        return self::httpGet(rtrim($base, '/') . '/' . ltrim($zip, '/'));
    }

    /**
     * 下载包预校验：唯一顶层目录且等于条目名、无穿越、大小合法
     * （对齐 Node validateZip；installFromBuffer 会再做一遍完整校验）
     */
    private static function validateZip(string $buffer, string $name): void
    {
        if (strlen($buffer) <= 0 || strlen($buffer) > self::MAX_ZIP_BYTES) {
            throw new \RuntimeException('包大小需在 10MB 以内');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pfstore');
        if ($tmp === false || @file_put_contents($tmp, $buffer) === false) {
            throw new \RuntimeException('包校验失败：无法写入临时文件');
        }
        $zip = new \ZipArchive();
        try {
            if ($zip->open($tmp) !== true) {
                throw new \RuntimeException('不是有效的 zip 压缩包');
            }
            $count = $zip->numFiles;
            if ($count <= 0) {
                throw new \RuntimeException('zip 包为空');
            }
            $top = null;
            for ($i = 0; $i < $count; $i++) {
                $entryName = (string) $zip->getNameIndex($i);
                $normalized = str_replace('\\', '/', $entryName);
                $trimmed = rtrim($normalized, '/');
                if ($trimmed === '') {
                    throw new \RuntimeException('商店包包含意外路径：' . $entryName);
                }
                $parts = explode('/', $trimmed);
                if ($top === null) {
                    $top = $parts[0];
                } elseif ($top !== $parts[0]) {
                    throw new \RuntimeException('商店包必须只含一个顶层目录');
                }
                foreach ($parts as $seg) {
                    if ($seg === '..' || $seg === '') {
                        throw new \RuntimeException('商店包包含意外路径：' . $entryName);
                    }
                    if (str_contains($seg, ':')) {
                        throw new \RuntimeException('商店包包含非法路径：' . $entryName);
                    }
                }
            }
            if ($top !== $name) {
                throw new \RuntimeException('包顶层目录与条目名称不符（' . $top . ' ≠ ' . $name . '）');
            }
        } finally {
            if ($zip->status !== \ZipArchive::ER_OK) {
                @$zip->close();
            }
            @unlink($tmp);
        }
    }

    private static function httpGet(string $url): string
    {
        $headers = [];
        $token = self::token();
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'pafish-store/1.0',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // 不调 curl_close（PHP 8.5 起 deprecated，输出会污染响应体）
        if ($body === false) {
            throw new \RuntimeException('下载失败（连接错误）');
        }
        if ($status !== 200) {
            throw new \RuntimeException('下载失败（HTTP ' . $status . '）');
        }
        return (string) $body;
    }

    /** 内置本地源目录（项目根/store/） */
    private static function localRoot(): string
    {
        return dirname(__DIR__, 2) . '/store';
    }

    private static function readLocalCatalog(string $file): array
    {
        $body = @file_get_contents(self::localRoot() . '/' . $file);
        if ($body === false) {
            return [];
        }
        return self::parseCatalog($body);
    }

    /** 解析目录 JSON：非法条目（缺字段/名称不合法）跳过 */
    private static function parseCatalog(string $body): array
    {
        $raw = json_decode($body, true);
        if (!is_array($raw)) {
            return [];
        }
        $items = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            $title = (string) ($entry['title'] ?? '');
            $version = (string) ($entry['version'] ?? '');
            $zip = (string) ($entry['zip'] ?? '');
            if ($name === '' || $title === '' || $version === '' || $zip === '') {
                continue;
            }
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                continue;
            }
            $items[] = [
                'name' => $name,
                'title' => $title,
                'version' => $version,
                'description' => isset($entry['description']) ? (string) $entry['description'] : '',
                'author' => isset($entry['author']) ? (string) $entry['author'] : '',
                'zip' => $zip,
                'preview' => isset($entry['preview']) ? (string) $entry['preview'] : '',
            ];
        }
        return $items;
    }

    /** SPL 递归复制目录（Windows 上不依赖 rename） */
    private static function copyDir(string $src, string $dst): bool
    {
        if (!is_dir($src) || !@mkdir($dst, 0755, true)) {
            return false;
        }
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($items as $item) {
                $to = $dst . '/' . $items->getSubPathname();
                if ($item->isDir()) {
                    if (!@mkdir($to, 0755, true)) {
                        return false;
                    }
                } elseif (!@copy($item->getPathname(), $to)) {
                    return false;
                }
            }
        } catch (\UnexpectedValueException $e) {
            return false;
        }
        return true;
    }

    /** SPL 递归删除目录（不存在/成功 → true；失败 → false） */
    private static function rmDir(string $dir): bool
    {
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                if ($item->isDir()) {
                    if (!@rmdir($item->getPathname())) {
                        return false;
                    }
                } elseif (!@unlink($item->getPathname())) {
                    return false;
                }
            }
        } catch (\UnexpectedValueException $e) {
            return !is_dir($dir);
        }
        return @rmdir($dir);
    }
}
