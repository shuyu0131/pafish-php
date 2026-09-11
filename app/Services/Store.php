<?php
declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Version;

/** 官方应用商店：目录读取、包校验、安装更新和付费权益校验。 */
final class Store
{
    /** 官方商店地址。 */
    private const OFFICIAL_STORE_URL = 'https://www.pafish.cn';

    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/';
    private const MAX_ZIP_BYTES = 10 * 1024 * 1024;
    private const KIND_FILE = ['theme' => 'themes.json', 'plugin' => 'plugins.json'];
    private const CATALOG_CACHE_TTL = 300;

    /** 返回商店地址。 */
    public static function baseUrl(): string
    {
        $env = trim((string) getenv('PAFISH_STORE_URL'));
        if ($env !== '' && preg_match('#^https?://#i', $env) === 1) {
            return rtrim($env, '/');
        }
        return self::OFFICIAL_STORE_URL;
    }

    /** 查询当前绑定的官方商城账号及已购应用。 */
    public static function account(): ?array
    {
        $state = self::accountStatus();
        return $state['status'] === 'bound' ? $state['data'] : null;
    }

    /** 查询账号绑定状态，区分未绑定、令牌失效和官方源不可达。 */
    public static function accountStatus(): array
    {
        $token = trim((string) Settings::get('store_account_token', ''));
        if ($token === '') {
            return ['status' => 'unbound', 'data' => null];
        }
        $ch = curl_init(self::baseUrl() . '/api/store/account');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'pafish-store/1.0',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            return ['status' => 'unreachable', 'data' => null];
        }
        $data = json_decode((string) $body, true);
        if ($status === 401 || $status === 403) {
            return ['status' => 'invalid_token', 'data' => null];
        }
        if ($status !== 200 || !is_array($data) || !is_array($data['account'] ?? null)) {
            return ['status' => 'unreachable', 'data' => null];
        }
        return ['status' => 'bound', 'data' => $data];
    }

    /** 数字分段版本比较：$a < $b → -1，相等 → 0，$a > $b → 1 */
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
     * 拉取目录：缓存（5 分钟）优先 → 官方源（www.pafish.cn）→ 远程失败回退本地内置源 public/store。
     * 返回 ['items' => 条目数组, 'base' => 源地址（远程=官网，本地兜底=''）, 'error'? => 回退原因]
     */
    public static function fetchCatalog(string $kind): array
    {
        $base = self::baseUrl();
        $label = '官方商店';
        $cached = self::readCatalogCache($kind);
        if ($cached !== null) {
            return ['items' => $cached, 'base' => $base];
        }
        try {
            $items = self::parseRuntimeCatalog(
                self::httpGet($base . '/api/runtime-store/v1/catalog?kind=' . rawurlencode($kind)),
                $kind
            );
            self::writeCatalogCache($kind, $items);
            return ['items' => $items, 'base' => $base];
        } catch (\Throwable $e) {
            $fallbackHint = $label . '目录获取失败：' . $e->getMessage();
        }
        // 回退：本地内置商店（public/store，zip 本地直读）
        return [
            'items' => self::readLocalCatalog($kind),
            'base' => '',
            'error' => $fallbackHint . '，已回退内置商店（public/store）',
        ];
    }

    /** 清除本地目录缓存，供后台在官网审核发布后立即拉取最新上架结果。 */
    public static function clearCatalogCache(): void
    {
        foreach (array_keys(self::KIND_FILE) as $kind) {
            @unlink(PAFISH_ROOT . '/runtime/store_catalog_' . $kind . '.json');
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
        self::assertCompatible($item);
        if (self::getInstalledVersion($kind, $name) !== null) {
            throw new \RuntimeException('已安装，可直接更新');
        }
        $buffer = self::downloadZip($base, $item);
        self::validateZip($buffer);
        if ($kind === 'theme') {
            return Theme::installFromBuffer($buffer);
        }
        return Plugin::installFromBuffer($buffer, $name . '@store');
    }

    /**
     * 从商店更新：备份旧版本 → 移除 → 安装新版；失败自动恢复旧版本
     * updateFromStore 的 .bak 回滚语义，但不依赖 rename——SPL 复制/删除）
     */
    public static function updateFromStore(string $kind, string $name, string $base, array $item): array
    {
        self::assertValidName($name);
        self::assertCompatible($item);
        $installedVersion = self::getInstalledVersion($kind, $name);
        if ($installedVersion === null) {
            throw new \RuntimeException('未安装，请先安装');
        }
        $targetVersion = trim((string) ($item['version'] ?? ''));
        if ($targetVersion === '' || self::compareVersions($targetVersion, $installedVersion) <= 0) {
            throw new \RuntimeException('该商店版本不高于当前已安装版本（v' . $installedVersion . '），无需更新');
        }
        $buffer = self::downloadZip($base, $item);
        self::validateZip($buffer);
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

    /**
     * 安装/更新前校验 PHP 版本门槛（目录条目 requiresPhp）。
     * 兼容旧数据：仅当值形如 PHP 版本（主版本 ≥ 5）时校验，旧式 pafish 版本号（如 1.x）忽略。
     */
    private static function assertCompatible(array $item): void
    {
        $requiresPhp = trim((string) ($item['requiresPhp'] ?? ''));
        if ($requiresPhp === '' || preg_match('/^(?:[5-9]|[1-9][0-9])(?:\.[0-9]+){0,2}$/', $requiresPhp) !== 1) {
            return;
        }
        $parts = array_pad(array_map('intval', explode('.', $requiresPhp)), 3, 0);
        $minId = $parts[0] * 10000 + $parts[1] * 100 + $parts[2];
        if (PHP_VERSION_ID < $minId) {
            throw new \RuntimeException('该应用要求 PHP ' . $requiresPhp . ' 及以上版本（当前 PHP ' . PHP_VERSION . '），请升级 PHP 后重试');
        }
    }

    /** 读取目录缓存。 */
    private static function readCatalogCache(string $kind): ?array
    {
        $path = PAFISH_ROOT . '/runtime/store_catalog_' . $kind . '.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['at']) || !is_array($data['items'] ?? null)) {
            return null;
        }
        if (time() - (int) $data['at'] > self::CATALOG_CACHE_TTL) {
            return null;
        }
        // A catalog cached before text normalization may still contain legacy
        // rich-text fields. Normalize on read as well so the fix takes effect
        // immediately, without requiring an administrator to clear the cache.
        foreach ($data['items'] as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $item['description'] = self::plainText($item['description'] ?? '');
            $item['changelog'] = self::plainText($item['changelog'] ?? '');
        }
        unset($item);
        return $data['items'];
    }

    private static function writeCatalogCache(string $kind, array $items): void
    {
        $path = PAFISH_ROOT . '/runtime/store_catalog_' . $kind . '.json';
        @file_put_contents($path, json_encode(['at' => time(), 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * 下载 zip 包：base=''（本地兜底）→ 从 public/store 直读；否则相对路径拼 base，
     * http(s) 直用。远程下载失败也尝试本地兜底（目录来自远程但 zip 失效时）
     */
    private static function downloadZip(string $base, array $item): string
    {
        $zip = (string) ($item['zip'] ?? '');
        if ($zip === '') {
            throw new \RuntimeException('商店条目缺少 zip 地址');
        }
        $headers = [];
        // 付费应用必须携带官网账号令牌，由官方商店按购买记录放行。
        if (!empty($item['paid'])) {
            $token = trim((string) Settings::get('store_account_token', ''));
            if ($token === '') {
                throw new \RuntimeException('该应用需要已绑定且已购买的官网账号，请先在「站点设置 → 应用商店」中绑定账号');
            }
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if (preg_match('#^https?://#i', $zip) === 1) {
            $buffer = self::httpGet($zip, $headers);
        } elseif ($base !== '') {
            try {
                $buffer = self::httpGet(rtrim($base, '/') . '/' . ltrim($zip, '/'), $headers);
            } catch (\Throwable $e) {
                // 仅免费包允许本地兜底；付费包必须经过官网权益校验。
                if (!empty($item['paid'])) {
                    throw $e;
                }
                $local = self::readLocalZip($zip);
                if ($local === null) {
                    throw $e;
                }
                $buffer = $local;
            }
        } else {
            if (!empty($item['paid'])) {
                throw new \RuntimeException('付费应用需要连接官方商城完成账号权益校验');
            }
            $local = self::readLocalZip($zip);
            if ($local === null) {
                throw new \RuntimeException('内置商店缺少安装包：' . $zip);
            }
            $buffer = $local;
        }
        // 目录声明 sha256 时校验包完整性（防下载篡改/损坏；旧目录无该字段则跳过）
        self::verifySha256($buffer, (string) ($item['sha256'] ?? ''));
        return $buffer;
    }

    /** 目录声明 sha256 时校验包完整性（旧目录无该字段则跳过） */
    private static function verifySha256(string $buffer, string $expected): void
    {
        $expected = strtolower(trim($expected));
        if ($expected === '') {
            return;
        }
        if (preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
            throw new \RuntimeException('商店条目 sha256 格式不合法');
        }
        if (!hash_equals($expected, hash('sha256', $buffer))) {
            throw new \RuntimeException('安装包校验失败（sha256 不匹配），可能已被篡改，请勿安装');
        }
    }

    /** 从 public/store 读取本地安装包（zip 路径如 /store/hello-pafish.zip 或 hello-pafish.zip） */
    private static function readLocalZip(string $zip): ?string
    {
        $name = ltrim($zip, '/');
        if (str_starts_with($name, 'store/')) {
            $name = substr($name, strlen('store/'));
        }
        $path = dirname(__DIR__, 2) . '/public/store/' . $name;
        if (!is_file($path)) {
            return null;
        }
        $data = @file_get_contents($path);
        return $data === false ? null : $data;
    }

    /** 读取本地内置商店目录（public/store，直读文件；损坏时返回空） */
    private static function readLocalCatalog(string $kind): array
    {
        $file = self::KIND_FILE[$kind] ?? 'themes.json';
        $path = dirname(__DIR__, 2) . '/public/store/' . $file;
        $body = @file_get_contents($path);
        if ($body === false) {
            return [];
        }
        return self::parseCatalog($body);
    }

    /**
     * 解析官网运行时目录（runtime-store/v1）：{protocol, items:[{slug, title, version,
     * requiresPhp, description, author, licenseRequired, packageSha256, packageSize,
     * zip, changelog, screenshots, publishedAt, ...}]} → 统一条目格式
     * kind：theme → kind=theme；plugin → kind=plugin
     */
    private static function parseRuntimeCatalog(string $body, string $kind): array
    {
        $raw = json_decode($body, true);
        if (!is_array($raw) || ($raw['protocol'] ?? '') !== 'pafish-runtime-store/v1'
            || !isset($raw['items']) || !is_array($raw['items'])) {
            throw new \RuntimeException('目录格式不正确');
        }
        $expectKind = $kind === 'theme' ? 'theme' : 'plugin';
        $items = [];
        foreach ($raw['items'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['kind'] ?? '') !== $expectKind) {
                continue;
            }
            $name = (string) ($entry['slug'] ?? '');
            $title = (string) ($entry['title'] ?? '');
            $version = (string) ($entry['version'] ?? '');
            $zip = (string) ($entry['zip'] ?? '');
            if ($name === '' || $title === '' || $version === '' || $zip === '') {
                continue;
            }
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                continue;
            }
            $shots = $entry['screenshots'] ?? [];
            $items[] = [
                'id' => isset($entry['id']) ? (string) $entry['id'] : $name,
                'name' => $name,
                'title' => $title,
                'version' => $version,
                'description' => self::plainText($entry['description'] ?? ''),
                'author' => isset($entry['author']) ? (string) $entry['author'] : '',
                'category' => isset($entry['category']) ? (string) $entry['category'] : '',
                'zip' => $zip,
                'preview' => is_array($shots) && isset($shots[0]) ? (string) $shots[0] : '',
                'screenshots' => is_array($shots) ? array_values(array_filter($shots, static fn ($shot): bool => is_string($shot) && trim($shot) !== '')) : [],
                'sha256' => isset($entry['packageSha256']) ? (string) $entry['packageSha256'] : '',
                'packageSize' => isset($entry['packageSize']) ? max(0, (int) $entry['packageSize']) : 0,
                'publishedAt' => isset($entry['publishedAt']) ? (string) $entry['publishedAt'] : '',
                'homepage' => isset($entry['homepage']) ? (string) $entry['homepage'] : '',
                'requires' => self::normalizeRequirements($entry['requires'] ?? ($entry['dependencies'] ?? [])),
                'requiresPhp' => isset($entry['requiresPhp']) ? (string) $entry['requiresPhp'] : (isset($entry['requiresPafish']) ? (string) $entry['requiresPafish'] : ''),
                'paid' => !empty($entry['licenseRequired']),
                'changelog' => self::plainText($entry['changelog'] ?? ''),
            ];
        }
        return $items;
    }

    /**
     * 下载包预校验：唯一顶层目录、无穿越、大小合法。
     * 不再要求顶层目录等于条目名——安装名以包内顶层目录为准（Theme/Plugin::installFromBuffer
     * 会校验其合法性），官网侧同样放宽了该约束。
     */
    private static function validateZip(string $buffer): void
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
        } finally {
            if ($zip->status !== \ZipArchive::ER_OK) {
                @$zip->close();
            }
            @unlink($tmp);
        }
    }

    private static function httpGet(string $url, array $headers = []): string
    {
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
            // 官网错误为结构化 JSON（{error, code, message}）：透传 message 给用户可读文案
            $message = '下载失败（HTTP ' . $status . '）';
            $err = json_decode((string) $body, true);
            if (is_array($err) && is_string($err['message'] ?? null) && $err['message'] !== '') {
                $message = '下载失败：' . $err['message'];
            }
            throw new \RuntimeException($message);
        }
        return (string) $body;
    }

    /** 解析本地目录 JSON：非法条目（缺字段/名称不合法）跳过 */
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
                'id' => isset($entry['id']) ? (string) $entry['id'] : $name,
                'name' => $name,
                'title' => $title,
                'version' => $version,
                'description' => self::plainText($entry['description'] ?? ''),
                'author' => isset($entry['author']) ? (string) $entry['author'] : '',
                'category' => isset($entry['category']) ? (string) $entry['category'] : '',
                'zip' => $zip,
                'preview' => isset($entry['preview']) ? (string) $entry['preview'] : '',
                'screenshots' => isset($entry['screenshots']) && is_array($entry['screenshots']) ? array_values(array_filter($entry['screenshots'], static fn ($shot): bool => is_string($shot) && trim($shot) !== '')) : [],
                'paid' => !empty($entry['paid']) || !empty($entry['licenseRequired']),
                'changelog' => self::plainText($entry['changelog'] ?? ''),
                'packageSize' => isset($entry['packageSize']) ? max(0, (int) $entry['packageSize']) : 0,
                'publishedAt' => isset($entry['publishedAt']) ? (string) $entry['publishedAt'] : '',
                'homepage' => isset($entry['homepage']) ? (string) $entry['homepage'] : '',
                'requires' => self::normalizeRequirements($entry['requires'] ?? ($entry['dependencies'] ?? [])),
            ];
        }
        return $items;
    }

    /** 兼容目录中的 requires/dependencies 字段，仅保留可读字符串项。 */
    private static function normalizeRequirements(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && !is_int($key) && trim($key) !== '' && is_scalar($item) && trim((string) $item) !== '') {
                $out[] = trim($key) . ' ' . trim((string) $item);
            } elseif (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The catalog is controlled remotely. Descriptions are deliberately shown
     * as text in the admin UI, so convert legacy rich-text values here instead
     * of rendering remote HTML or exposing its tags to administrators.
     */
    private static function plainText(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }
        $html = (string) $value;
        // Older catalog records may have stored the complete rich-text value
        // as entities (for example &lt;p&gt;...&lt;/p&gt;). Two passes cover that
        // representation without repeatedly decoding arbitrary input.
        for ($i = 0; $i < 2; $i++) {
            $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $html) {
                break;
            }
            $html = $decoded;
        }
        $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\s*\/?\s*(?:p|div|li|h[1-6]|tr)\b[^>]*>/i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[ \t]*\R[ \t]*/u', "\n", $text) ?? $text;
        return trim($text);
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
        $paths = [];
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $paths[] = [$item->getPathname(), $item->isDir()];
            }
        } catch (\UnexpectedValueException $e) {
            return !is_dir($dir);
        }
        foreach ($paths as [$path, $isDir]) {
            if (!self::rmRemove($path, $isDir)) {
                return false;
            }
        }
        return self::rmRemove($dir, true);
    }

    /** 删除文件/空目录；失败时换名重删（同 Plugin::rmRemove，见其注释） */
    private static function rmRemove(string $path, bool $isDir): bool
    {
        if ($isDir ? @rmdir($path) : @unlink($path)) {
            return true;
        }
        $tmp = dirname($path) . '/.' . basename($path) . '.del' . bin2hex(random_bytes(3));
        if (!@rename($path, $tmp)) {
            return false;
        }
        return $isDir ? @rmdir($tmp) : @unlink($tmp);
    }
}
