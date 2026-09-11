<?php
declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Version;
use Pafish\Services\Backup;

/** 在线更新服务。 */
final class Upgrade
{
    private const DEFAULT_META_URL = 'https://www.pafish.cn/pafish-php/pafish-php.json';
    private const DEFAULT_GITEE_REPO = 'shuyugit/pafish-php';
    private const GITHUB_RELEASES_URL = 'https://api.github.com/repos/shuyu0131/pafish-php/releases/latest';
    private const MAX_ZIP_BYTES = 50 * 1024 * 1024;
    private const CACHE_TTL = 86400; // 24h
    private const CACHE_FILE = 'update_check.json';
    private const CACHE_SCHEMA = 2;
    private const STATE_FILE = 'upgrade_state.json';

    // ---------- 公开 ----------

    /** 更新元数据地址。 */
    public static function metaUrl(): string
    {
        $env = trim((string) getenv('PAFISH_UPDATE_URL'));
        if ($env !== '' && preg_match('#^https?://#i', $env) === 1) {
            return rtrim($env, '/');
        }
        return self::DEFAULT_META_URL;
    }

    /** 更新目标根目录。 */
    public static function root(): string
    {
        $env = trim((string) getenv('PAFISH_UPGRADE_ROOT'));
        return $env !== '' ? rtrim($env, '/\\') : PAFISH_ROOT;
    }

    /** 读取上次升级状态；仅用于后台提示，不触发网络请求。 */
    public static function state(): ?array
    {
        $path = self::root() . '/runtime/' . self::STATE_FILE;
        $raw = @file_get_contents($path);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($state) && isset($state['phase']) ? $state : null;
    }

    /**
     * 检查更新：读远程元数据 → 与当前版本比较。force=true 跳过 24h 缓存。
     * 返回：['hasUpdate'=>bool, 'current'=>, 'latest'=>, 'notes'=>, 'zip'=>, 'error'?=>]
     */
    public static function check(bool $force = false): array
    {
        $current = Version::current();
        $cached = $force ? null : self::readCache();
        $meta = null;
        $error = '';
        if ($cached !== null && is_array($cached['meta'] ?? null)) {
            $meta = $cached['meta'];
            $error = (string) ($cached['error'] ?? '');
        } else {
            $customMeta = trim((string) getenv('PAFISH_UPDATE_URL')) !== '';
            if ($customMeta) {
                try {
                    // 私有部署显式指定更新地址时，不能被公共源覆盖。
                    $meta = self::loadOfficialMeta();
                } catch (\Throwable $sourceError) {
                    $error = '自定义更新源：' . $sourceError->getMessage();
                }
            } else {
                [$meta, $error] = self::loadDefaultMeta();
            }
            self::writeCache($meta, $error);
        }
        if ($meta === null) {
            return ['hasUpdate' => false, 'current' => $current, 'error' => $error !== '' ? $error : '检查失败'];
        }
        return self::resultFromMeta($meta, $current, $error);
    }

    /** 仅读缓存的检查结果（不触网，零延迟；用于后台布局/工作台渲染红点徽标） */
    public static function cached(): array
    {
        $current = Version::current();
        $cached = self::readCache();
        if ($cached === null || !is_array($cached['meta'] ?? null)) {
            return ['hasUpdate' => false, 'current' => $current, 'error' => ''];
        }
        return self::resultFromMeta($cached['meta'], $current, (string) ($cached['error'] ?? ''));
    }

    /**
     * 执行更新：下载 → 校验 → 备份 → 清空 → 解压 → upgrade.php → 完成/回滚。
     * 返回：['ok'=>true, 'current'=>, 'latest'=>]；失败抛异常（已回滚）
     */
    public static function run(): array
    {
        $info = self::check(true); // 强制拉最新元数据
        if (isset($info['error']) && $info['error'] !== '' && ($info['latest'] ?? '') === '') {
            throw new \RuntimeException('无法获取更新信息：' . $info['error']);
        }
        if (($info['latest'] ?? '') === '' || !$info['hasUpdate']) {
            throw new \RuntimeException('已是最新版本（v' . Version::current() . '）');
        }
        $minVersion = (string) ($info['minVersion'] ?? '');
        if ($minVersion !== '' && self::compareVersions(Version::current(), $minVersion) < 0) {
            throw new \RuntimeException('当前版本 v' . Version::current() . ' 过低，请先升级到 v' . $minVersion);
        }

        set_time_limit(300);
        $root = self::root();
        // 并发锁：同一站点同时只允许一个更新任务。Windows 上多进程同时覆盖同一
        // 目录会互相踩踏（一个进程解压、另一个进程正在清理/备份同一文件 → 部分
        // 写入失败、备份不完整），flock 在 PHP 进程间互斥，抢不到锁直接拒绝
        $lockPath = $root . '/runtime/upgrade.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException('已有更新任务正在进行中，请稍后再试');
        }
        $zipUrl = self::resolveZipUrl((string) $info['zip']);
        $tmpZip = tempnam(sys_get_temp_dir(), 'pfup');
        $bak = $root . '/runtime/.upgrade-bak-' . bin2hex(random_bytes(4));
        $dbBackup = null;
        $dbMigrationStarted = false;
        self::writeState($root, 'starting', ['target' => (string)$info['latest']]);
        try {
            if ($tmpZip === false) {
                throw new \RuntimeException('无法创建临时文件');
            }
            // 1. 下载
            self::writeState($root, 'downloading', ['target' => (string)$info['latest']]);
            $buffer = self::httpGet($zipUrl);
            if (@file_put_contents($tmpZip, $buffer) === false) {
                throw new \RuntimeException('无法写入更新包临时文件');
            }
            // 2. 校验（元数据声明 sha256 时先验哈希，防下载篡改/损坏）
            self::writeState($root, 'validating');
            self::verifySha256($buffer, (string) ($info['sha256'] ?? ''));
            self::validatePackage($tmpZip);
            // 3. 备份（排除 public/uploads、backups、runtime；config.php 一并备份）
            self::writeState($root, 'backing_up');
            try {
                $dbBackup = Backup::create();
            } catch (\Throwable $e) {
                throw new \RuntimeException('更新前数据库备份失败：' . $e->getMessage());
            }
            if (!self::copyDirFiltered($root, $bak, self::BACKUP_EXCLUDE_DIRS)) {
                throw new \RuntimeException('更新失败：无法备份站点（' . $bak . '）');
            }
            // 4. 清空非保留项
            self::writeState($root, 'replacing_files', ['database_backup' => $dbBackup]);
            if (!self::clearRoot($root)) {
                throw new \RuntimeException('更新失败：无法清理旧文件');
            }
            try {
                // 5. 解压覆盖（剥掉 pafish/ 顶层）
                self::extractPackage($tmpZip, $root);
                // 6. 执行包内迁移脚本 upgrade.php（执行后删除）
                $dbMigrationStarted = is_file($root . '/upgrade.php');
                self::runUpgradeScript($root);
                self::writeState($root, 'completed');
            } catch (\Throwable $e) {
                try {
                    self::rollback($root, $bak);
                } catch (\Throwable $re) {
                    throw new \RuntimeException('更新失败：' . $e->getMessage()
                        . '；自动回滚也失败：' . $re->getMessage()
                        . '（备份保留在 ' . $bak . '，请手动恢复）');
                }
                throw new \RuntimeException('更新失败：' . $e->getMessage() . '（已恢复旧版本）');
            }
        } catch (\Throwable $e) {
            // 校验/下载/备份阶段失败：备份目录可能不存在或未完成
            if (is_dir($bak) && self::isEmptyDir($bak) === false) {
                try {
                    self::rollback($root, $bak);
                } catch (\Throwable $re) {
                    throw new \RuntimeException($e->getMessage()
                        . '；自动回滚也失败：' . $re->getMessage()
                        . '（备份保留在 ' . $bak . '，请手动恢复）');
                }
            }
            if ($dbMigrationStarted && $dbBackup !== null) {
                try {
                    Backup::restore($dbBackup);
                } catch (\Throwable $dbError) {
                    self::writeState($root, 'failed', [
                        'message' => $e->getMessage() . '；数据库恢复失败：' . $dbError->getMessage(),
                        'database_backup' => $dbBackup,
                    ]);
                    throw new \RuntimeException($e->getMessage() . '；数据库自动恢复失败：' . $dbError->getMessage() . '；数据库备份：backups/' . $dbBackup);
                }
            }
            self::writeState($root, 'failed', ['message' => $e->getMessage(), 'database_backup' => $dbBackup]);
            throw new \RuntimeException($e->getMessage() . ($dbBackup ? '；数据库安全备份：backups/' . $dbBackup : ''));
        } finally {
            @unlink($tmpZip);
            if (is_dir($bak) && self::isEmptyDir($bak)) {
                self::rmDir($bak);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        // 7. 成功：删除备份、清静态缓存
        self::rmDir($bak);
        self::resetCache();
        @unlink($root . '/runtime/' . self::STATE_FILE);
        // 当前 PHP 请求仍加载着升级前的 Version 常量；返回安装包目标版本，
        // 让更新接口和前端成功提示反映实际已安装的版本。
        return ['ok' => true, 'current' => (string) $info['latest'], 'latest' => (string) $info['latest']];
    }

    /**
     * 检查所有可用更新源并选择最高版本。
     * Gitee 排在首位，因此相同版本时优先使用国内可访问的下载地址。
     *
     * @return array{0: ?array, 1: string}
     */
    private static function loadDefaultMeta(): array
    {
        $sources = [];
        $giteeRepo = self::giteeRepo();
        if ($giteeRepo !== '') {
            $sources[] = ['name' => 'Gitee 更新源', 'loader' => static fn (): array => self::loadGiteeMeta($giteeRepo)];
        }
        $sources[] = ['name' => 'GitHub 更新源', 'loader' => static fn (): array => self::loadGitHubMeta()];
        $sources[] = ['name' => '官网镜像', 'loader' => static fn (): array => self::loadOfficialMeta()];

        $selected = null;
        $errors = [];
        foreach ($sources as $source) {
            try {
                $candidate = ($source['loader'])();
                if ($selected === null || self::compareVersions((string) $candidate['version'], (string) $selected['version']) > 0) {
                    $selected = $candidate;
                }
            } catch (\Throwable $sourceError) {
                $errors[] = $source['name'] . '：' . $sourceError->getMessage();
            }
        }
        return [$selected, $selected === null ? implode('；', $errors) : ''];
    }

    /** 将不高于当前版本的远端结果归一化，避免旧 Release 的版本号和说明污染后台。 */
    private static function resultFromMeta(array $meta, string $current, string $error): array
    {
        $latest = (string) ($meta['version'] ?? '');
        if ($latest === '' || self::compareVersions($latest, $current) <= 0) {
            return [
                'hasUpdate' => false,
                'current' => $current,
                'latest' => $current,
                'notes' => '',
                'zip' => '',
                'minVersion' => '',
                'sha256' => '',
                'source' => (string) ($meta['source'] ?? ''),
                'error' => $error,
            ];
        }
        return [
            'hasUpdate' => true,
            'current' => $current,
            'latest' => $latest,
            'notes' => (string) ($meta['notes'] ?? ''),
            'zip' => (string) ($meta['zip'] ?? ''),
            'minVersion' => (string) ($meta['min_version'] ?? ''),
            'sha256' => (string) ($meta['sha256'] ?? ''),
            'source' => (string) ($meta['source'] ?? 'official'),
            'error' => $error,
        ];
    }

    /** 读取官网 JSON 更新元数据。 */
    private static function loadOfficialMeta(): array
    {
        $raw = json_decode(self::httpGet(self::metaUrl()), true);
        if (!is_array($raw)) {
            throw new \RuntimeException('元数据格式不正确');
        }
        $meta = [
            'version' => (string) ($raw['version'] ?? ''),
            'notes' => (string) ($raw['notes'] ?? ''),
            'zip' => (string) ($raw['zip'] ?? ''),
            'min_version' => (string) ($raw['min_version'] ?? ''),
            'sha256' => (string) ($raw['sha256'] ?? ''),
            'source' => 'official',
        ];
        if ($meta['version'] === '' || $meta['zip'] === '') {
            throw new \RuntimeException('元数据缺少版本或安装包地址');
        }
        return $meta;
    }

    /** 从 Gitee Release 组装更新元数据。 */
    private static function loadGiteeMeta(string $repo): array
    {
        $raw = json_decode(self::httpGet('https://gitee.com/api/v5/repos/' . $repo . '/releases/latest'), true);
        if (!is_array($raw)) {
            throw new \RuntimeException('Gitee Release 响应格式不正确');
        }
        $tag = trim((string) ($raw['tag_name'] ?? ''));
        if (preg_match('/^v?(\d+(?:\.\d+){1,3})$/', $tag, $m) !== 1) {
            throw new \RuntimeException('Gitee Release 版本号不正确');
        }
        $version = $m[1];
        foreach ((array) ($raw['assets'] ?? []) as $asset) {
            if (!is_array($asset) || (string) ($asset['name'] ?? '') !== 'pafish-php-v' . $version . '.zip') {
                continue;
            }
            $zip = trim((string) ($asset['browser_download_url'] ?? $asset['download_url'] ?? ''));
            $safePath = '#^https://gitee\\.com/' . preg_quote($repo, '#')
                . '/releases/download/v' . preg_quote($version, '#')
                . '/pafish-php-v' . preg_quote($version, '#') . '\\.zip(?:\\?.*)?$#i';
            if (preg_match($safePath, $zip) !== 1) {
                throw new \RuntimeException('Gitee Release 安装包地址不安全');
            }
            $digest = strtolower(trim((string) ($asset['sha256'] ?? $asset['digest'] ?? '')));
            if (str_starts_with($digest, 'sha256:')) {
                $digest = substr($digest, 7);
            }
            return [
                'version' => $version,
                'notes' => (string) ($raw['body'] ?? $raw['description'] ?? ''),
                'zip' => $zip,
                'min_version' => '',
                'sha256' => preg_match('/^[0-9a-f]{64}$/', $digest) === 1 ? $digest : '',
                'source' => 'gitee',
            ];
        }
        throw new \RuntimeException('Gitee Release 缺少匹配的 pafish-php 安装包');
    }

    private static function giteeRepo(): string
    {
        $repo = trim((string) getenv('PAFISH_GITEE_REPO'));
        if ($repo === '') {
            $repo = self::DEFAULT_GITEE_REPO;
        }
        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) === 1 ? $repo : '';
    }

    /**
     * 从 GitHub 最新 Release 组装与官网相同的更新元数据。
     * 仅接受 pafish-php-vX.Y.Z.zip 资产，避免误选源码包或其他附件。
     */
    private static function loadGitHubMeta(): array
    {
        $raw = json_decode(self::httpGet(self::GITHUB_RELEASES_URL), true);
        if (!is_array($raw)) {
            throw new \RuntimeException('GitHub Release 响应格式不正确');
        }
        $tag = trim((string) ($raw['tag_name'] ?? ''));
        if (preg_match('/^v?(\d+(?:\.\d+){1,3})$/', $tag, $m) !== 1) {
            throw new \RuntimeException('GitHub Release 版本号不正确');
        }
        $version = $m[1];
        $asset = null;
        foreach ((array) ($raw['assets'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $name = (string) ($candidate['name'] ?? '');
            if ($name === 'pafish-php-v' . $version . '.zip') {
                $asset = $candidate;
                break;
            }
        }
        if ($asset === null) {
            throw new \RuntimeException('GitHub Release 缺少匹配的 pafish-php 安装包');
        }
        $zip = trim((string) ($asset['browser_download_url'] ?? ''));
        if (preg_match('#^https://github\.com/[^/]+/[^/]+/releases/download/[^/]+/pafish-php-v[0-9.]+\.zip$#i', $zip) !== 1) {
            throw new \RuntimeException('GitHub Release 安装包地址不安全');
        }
        $digest = trim((string) ($asset['digest'] ?? ''));
        $sha256 = '';
        if (preg_match('/^sha256:([0-9a-f]{64})$/i', $digest, $digestMatch) === 1) {
            $sha256 = strtolower($digestMatch[1]);
        }
        return [
            'version' => $version,
            'notes' => (string) ($raw['body'] ?? ''),
            'zip' => $zip,
            'min_version' => '',
            'sha256' => $sha256,
            'source' => 'github',
        ];
    }

    /** 核心更新不接管的已安装应用目录。 */
    private const APP_DIRS = ['themes', 'plugins'];

    /** 升级备份排除运行期数据，主题和插件必须进入备份以支持完整回滚。 */
    private const BACKUP_EXCLUDE_DIRS = ['runtime', 'backups', 'public/uploads'];

    /** 解析 zip 下载地址：相对路径基于元数据 URL 的目录解析，http(s) 直用 */
    private static function resolveZipUrl(string $zip): string
    {
        if (preg_match('#^https?://#i', $zip) === 1) {
            return $zip;
        }
        $meta = self::metaUrl();
        $pos = strrpos($meta, '/');
        return ($pos !== false ? substr($meta, 0, $pos + 1) : $meta . '/') . ltrim($zip, '/');
    }

    private static function writeState(string $root, string $phase, array $extra = []): void
    {
        @file_put_contents($root . '/runtime/' . self::STATE_FILE, json_encode([
            'phase' => $phase,
            'at' => date('c'),
        ] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /** 下载 zip 地址（元数据 zip 是相对路径，相对元数据目录解析） */
    private static function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'pafish-upgrade/' . Version::current(),
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

    /** 元数据声明 sha256 时校验下载完整性（旧元数据无该字段则跳过） */
    private static function verifySha256(string $buffer, string $expected): void
    {
        $expected = strtolower(trim($expected));
        if ($expected === '') {
            return;
        }
        if (preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
            throw new \RuntimeException('更新元数据 sha256 格式不合法');
        }
        if (!hash_equals($expected, hash('sha256', $buffer))) {
            throw new \RuntimeException('更新包校验失败（sha256 不匹配），请重试或联系官方');
        }
    }

    /** 更新包校验：大小 ≤50MB、全部条目位于 pafish/ 顶层、逐段防 '..'/空段/冒号、关键文件存在 */
    private static function validatePackage(string $tmpZip): void
    {
        if (filesize($tmpZip) > self::MAX_ZIP_BYTES) {
            throw new \RuntimeException('更新包超过 50MB 限制');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            throw new \RuntimeException('更新包不是有效的 zip 压缩包');
        }
        try {
            $count = $zip->numFiles;
            if ($count <= 0) {
                throw new \RuntimeException('更新包为空');
            }
            $hasIndex = false;
            $hasBootstrap = false;
            for ($i = 0; $i < $count; $i++) {
                $entryName = (string) $zip->getNameIndex($i);
                $normalized = str_replace('\\', '/', $entryName);
                $trimmed = rtrim($normalized, '/');
                if ($trimmed === '') {
                    throw new \RuntimeException('更新包包含意外路径：' . $entryName);
                }
                $parts = explode('/', $trimmed);
                if (($parts[0] ?? '') !== 'pafish') {
                    throw new \RuntimeException('更新包必须全部位于 pafish/ 顶层目录');
                }
                foreach ($parts as $seg) {
                    if ($seg === '..' || $seg === '') {
                        throw new \RuntimeException('更新包包含意外路径：' . $entryName);
                    }
                    if (str_contains($seg, ':')) {
                        throw new \RuntimeException('更新包包含非法路径：' . $entryName);
                    }
                }
                if ($trimmed === 'pafish/index.php') {
                    $hasIndex = true;
                }
                if ($trimmed === 'pafish/app/bootstrap.php') {
                    $hasBootstrap = true;
                }
            }
            if (!$hasIndex || !$hasBootstrap) {
                throw new \RuntimeException('更新包缺少关键文件（index.php / app/bootstrap.php）');
            }
        } finally {
            @$zip->close();
        }
    }

    /** 解压更新包：extractTo 根目录（生成 pafish/），合并到根后删除 pafish/ */
    private static function extractPackage(string $tmpZip, string $root): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            throw new \RuntimeException('更新包无法解压');
        }
        if (!$zip->extractTo($root)) {
            @$zip->close();
            throw new \RuntimeException('更新包解压失败');
        }
        @$zip->close();
        $pafishDir = $root . '/pafish';
        if (!is_dir($pafishDir)) {
            throw new \RuntimeException('更新包缺少 pafish/ 目录');
        }
        // 合并 pafish/* → 根（存在目录跳过，文件覆盖）
        if (!self::mergeDir($pafishDir, $root)) {
            throw new \RuntimeException('更新包文件合并失败');
        }
        if (!self::rmDir($pafishDir)) {
            throw new \RuntimeException('无法清理更新包临时目录');
        }
    }

    /** 执行包内迁移脚本 upgrade.php（若存在；执行后删除）。脚本可用 $pdo / PAFISH_ROOT */
    private static function runUpgradeScript(string $root): void
    {
        $script = $root . '/upgrade.php';
        if (!is_file($script)) {
            return;
        }
        try {
            include $script;
        } finally {
            self::rmRemove($script, false);
        }
    }

    /** 回滚：清空当前非保留项 → 从备份整体复制回根 → 删备份（失败抛异常，备份保留） */
    private static function rollback(string $root, string $bak): void
    {
        self::clearRoot($root, false);
        if (!self::copyDirFiltered($bak, $root, [])) {
            throw new \RuntimeException('无法从备份恢复文件');
        }
        self::rmDir($bak);
    }

    /** 清空核心文件；正常升级保留已安装主题/插件，回滚时清理后从备份恢复。 */
    private static function clearRoot(string $root, bool $preserveApps = true): bool
    {
        $items = @scandir($root);
        if ($items === false) {
            return false;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($name === 'config.php') {
                continue;
            }
            if (in_array($name, ['runtime', 'backups'], true)) {
                continue;
            }
            if ($preserveApps && in_array($name, self::APP_DIRS, true)) {
                continue;
            }
            if ($name === 'public' && is_dir($root . '/public')) {
                // public 只保留运行期上传
                $sub = @scandir($root . '/public');
                if ($sub !== false) {
                    foreach ($sub as $subName) {
                        if ($subName === '.' || $subName === '..' || $subName === 'uploads') {
                            continue;
                        }
                        if (!self::rmDir($root . '/public/' . $subName)) {
                            return false;
                        }
                    }
                }
                continue;
            }
            if (!self::rmDir($root . '/' . $name)) {
                return false;
            }
        }
        return true;
    }

    /** 复制目录（跳过排除前缀相对路径；目录跳过创建，文件覆盖） */
    private static function copyDirFiltered(string $src, string $dst, array $exclude): bool
    {
        return self::copyRecursive($src, $dst, '', $exclude);
    }

    private static function copyRecursive(string $src, string $dst, string $rel, array $exclude): bool
    {
        if (!is_dir($src)) {
            return false;
        }
        if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
            return false;
        }
        $items = @scandir($src);
        if ($items === false) {
            return false;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $childRel = $rel === '' ? $name : $rel . '/' . $name;
            $skip = false;
            foreach ($exclude as $prefix) {
                if ($childRel === $prefix) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $from = $src . '/' . $name;
            $to = $dst . '/' . $name;
            if (is_dir($from)) {
                if (!self::copyRecursive($from, $to, $childRel, $exclude)) {
                    return false;
                }
            } elseif (is_file($to) && !@chmod($to, 0666)) {
                // Windows 只读目标（如 git 对象）先清只读位再覆盖
                return false;
            } elseif (!@copy($from, $to)) {
                return false;
            }
        }
        return true;
    }

    /** 合并 src 目录到 dst（目录跳过创建，文件覆盖） */
    private static function mergeDir(string $src, string $dst): bool
    {
        $items = @scandir($src);
        if ($items === false) {
            return false;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $from = $src . '/' . $name;
            $to = $dst . '/' . $name;
            if (is_dir($from)) {
                if (!is_dir($to) && !@mkdir($to, 0755, true)) {
                    return false;
                }
                if (!self::mergeDir($from, $to)) {
                    return false;
                }
            } elseif (is_file($to) && !@chmod($to, 0666)) {
                return false;
            } elseif (!@copy($from, $to)) {
                return false;
            }
        }
        return true;
    }

    /** 目录是否为空 */
    private static function isEmptyDir(string $dir): bool
    {
        $items = @scandir($dir);
        return $items === false || count($items) <= 2;
    }

    /** 递归删除（scandir 快照 + rename 换名再删，Windows 兼容，同 Plugin::rmRemove） */
    private static function rmDir(string $dir): bool
    {
        $items = @scandir($dir);
        if ($items === false) {
            return !is_dir($dir);
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                if (!self::rmDir($path)) {
                    return false;
                }
            } elseif (!self::rmRemove($path, false)) {
                return false;
            }
        }
        return self::rmRemove($dir, true);
    }

    /** 删除文件或空目录。Windows 环境先换名再删除，降低文件占用导致的失败概率。 */
    private static function rmRemove(string $path, bool $isDir): bool
    {
        // 清除只读属性，避免删除失败。
        if (!$isDir) {
            @chmod($path, 0666);
        }
        $tmp = dirname($path) . '/.' . basename($path) . '.del' . bin2hex(random_bytes(3));
        for ($i = 0; $i < 3; $i++) {
            if (@rename($path, $tmp)) {
                if ($isDir) {
                    return @rmdir($tmp) || !is_dir($tmp);
                }
                return @unlink($tmp) || !is_file($tmp);
            }
            if ($i < 2) {
                usleep(150000);
            }
        }
        return $isDir ? @rmdir($path) : @unlink($path);
    }

    /** 数字分段版本比较（统一走 Version::compare） */
    private static function compareVersions(string $a, string $b): int
    {
        return Version::compare($a, $b);
    }

    /** 读取检查缓存（24h TTL 内有效） */
    private static function readCache(): ?array
    {
        $path = PAFISH_ROOT . '/runtime/' . self::CACHE_FILE;
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['at']) || (int) ($data['schema'] ?? 0) !== self::CACHE_SCHEMA) {
            return null;
        }
        if (time() - (int) $data['at'] > self::CACHE_TTL) {
            return null;
        }
        return $data;
    }

    private static function writeCache(?array $meta, string $error): void
    {
        $path = PAFISH_ROOT . '/runtime/' . self::CACHE_FILE;
        $data = ['schema' => self::CACHE_SCHEMA, 'at' => time(), 'meta' => $meta, 'error' => $error];
        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private static function resetCache(): void
    {
        @unlink(PAFISH_ROOT . '/runtime/' . self::CACHE_FILE);
    }
}
