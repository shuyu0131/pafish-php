<?php
declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Version;
use Pafish\Services\Backup;

/** 在线更新服务。 */
final class Upgrade
{
    private const DEFAULT_GITEE_REPO = 'shuyugit/pafish-php';
    // OutboundHttp 的统一响应上限为 16 MB，升级包上限必须与其一致。
    private const MAX_ZIP_BYTES = 16 * 1024 * 1024;
    private const CACHE_TTL = 3600; // 自动检查最多延迟 1 小时发现新 Release
    private const FAILURE_CACHE_TTL = 900; // 失败后 15 分钟再自动重试
    private const CACHE_FILE = 'update_check.json';
    private const CACHE_SCHEMA = 3;
    private const STATE_FILE = 'upgrade_state.json';
    private static string $deleteFailure = '';

    // ---------- 公开 ----------

    /** Gitee 更新源地址。 */
    public static function metaUrl(): string
    {
        return 'https://gitee.com/api/v5/repos/' . self::giteeRepo() . '/releases/latest';
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
     * 检查更新：读远程元数据 → 与当前版本比较。force=true 跳过自动检查缓存。
     * 返回：['hasUpdate'=>bool, 'current'=>, 'latest'=>, 'notes'=>, 'zip'=>, 'error'?=>]
     */
    public static function check(bool $force = false): array
    {
        $current = Version::current();
        $cached = $force ? null : self::readCache();
        $meta = null;
        $error = '';
        if ($cached !== null) {
            $meta = $cached['meta'];
            $error = (string) ($cached['error'] ?? '');
        } else {
            [$meta, $error] = self::loadDefaultMeta();
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
        $backupReady = false;
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
            // 3. 备份（排除 public/uploads、backups、runtime；旧根目录 config.php 一并备份）
            self::writeState($root, 'backing_up');
            try {
                $dbBackup = Backup::create();
            } catch (\Throwable $e) {
                throw new \RuntimeException('更新前数据库备份失败：' . $e->getMessage());
            }
            if (!self::copyDirFiltered($root, $bak, self::BACKUP_EXCLUDE_DIRS)) {
                throw new \RuntimeException('更新失败：无法完整备份站点（备份保留在 ' . $bak . '）');
            }
            if (!self::hasCoreFiles($bak)) {
                throw new \RuntimeException('更新失败：站点备份不完整（备份保留在 ' . $bak . '）');
            }
            $backupReady = true;
            // 4. 直接覆盖核心文件，不预先清空站点目录。
            // 这和 Emlog 的升级方式一致：不会触碰 .well-known、上传目录、主题插件
            // 或站点自行创建的目录，因此证书挑战文件不会阻塞正常升级。
            // 旧文件如确实需要移除，应由发行包内 upgrade.php 显式处理。
            self::writeState($root, 'replacing_files', ['database_backup' => $dbBackup]);
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
            if ($backupReady && is_dir($bak) && self::isEmptyDir($bak) === false) {
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

    /** 读取 Gitee 最新 Release。 */
    private static function loadDefaultMeta(): array
    {
        $giteeRepo = self::giteeRepo();
        if ($giteeRepo === '') {
            return [null, 'Gitee 更新源配置无效'];
        }
        try {
            return [self::loadGiteeMeta($giteeRepo), ''];
        } catch (\Throwable $sourceError) {
            return [null, 'Gitee 更新源：' . $sourceError->getMessage()];
        }
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
                'source' => 'gitee',
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
            'source' => 'gitee',
            'error' => $error,
        ];
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

    /** 核心更新不接管的已安装应用目录。 */
    private const APP_DIRS = ['themes', 'plugins'];

    /** 由证书工具或 Web 服务器管理、升级时不得删除的根目录。 */
    private const PRESERVE_ROOT_DIRS = ['runtime', 'backups', '.well-known'];

    /** 升级备份排除运行期数据，主题和插件必须进入备份以支持完整回滚。 */
    private const BACKUP_EXCLUDE_DIRS = ['runtime', 'backups', 'public/uploads', '.well-known'];

    /** 解析 Gitee Release 安装包地址。 */
    private static function resolveZipUrl(string $zip): string
    {
        if (preg_match('#^https://gitee\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/releases/download/v[0-9.]+/pafish-php-v[0-9.]+\.zip(?:\?.*)?$#i', $zip) !== 1) {
            throw new \RuntimeException('Gitee Release 安装包地址无效');
        }
        return $zip;
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
        return OutboundHttp::get($url, self::MAX_ZIP_BYTES, ['Accept: application/json, application/zip']);
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

    /** 更新包校验：大小 ≤16MB、全部条目位于 pafish/ 顶层、逐段防 '..'/空段/冒号、关键文件存在 */
    private static function validatePackage(string $tmpZip): void
    {
        if (filesize($tmpZip) > self::MAX_ZIP_BYTES) {
            throw new \RuntimeException('更新包超过 16MB 限制');
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
                $relative = implode('/', array_slice($parts, 1));
                foreach (['.well-known', 'runtime', 'backups', 'public/uploads'] as $protected) {
                    if (($relative === $protected || str_starts_with($relative, $protected . '/'))
                        && $relative !== $protected . '/.gitkeep') {
                        throw new \RuntimeException('更新包不得覆盖受保护目录：' . $entryName);
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

    /** 核心文件必须存在，避免不完整备份被用于清空并回滚站点。 */
    private static function hasCoreFiles(string $root): bool
    {
        return is_file($root . '/index.php') && is_file($root . '/app/bootstrap.php');
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
        if (!self::hasCoreFiles($bak)) {
            throw new \RuntimeException('备份不完整，无法安全恢复');
        }
        if (!self::clearRoot($root, false)) {
            throw new \RuntimeException('无法清理当前文件' . self::deleteFailureSuffix());
        }
        if (!self::copyDirFiltered($bak, $root, [])) {
            throw new \RuntimeException('无法从备份恢复文件');
        }
        if (!self::hasCoreFiles($root)) {
            throw new \RuntimeException('恢复后缺少核心文件');
        }
        self::rmDir($bak);
    }

    /** 清空核心文件；正常升级保留已安装主题/插件，回滚时清理后从备份恢复。 */
    private static function clearRoot(string $root, bool $preserveApps = true): bool
    {
        self::$deleteFailure = '';
        $items = @scandir($root);
        if ($items === false) {
            self::$deleteFailure = $root . '（无法读取目录）';
            return false;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($name === 'config.php') {
                continue;
            }
            if (self::isPreservedRoot($name)) {
                continue;
            }
            if ($preserveApps && in_array($name, self::APP_DIRS, true)) {
                continue;
            }
            if ($name === 'public' && is_dir($root . '/public')) {
                // public 只保留运行期上传
                $sub = @scandir($root . '/public');
                if ($sub !== false) {
                    $publicMode = @fileperms($root . '/public');
                    $publicMode = is_int($publicMode) ? ($publicMode & 0777) : null;
                    @chmod($root . '/public', 0755);
                    try {
                        foreach ($sub as $subName) {
                            if ($subName === '.' || $subName === '..' || $subName === 'uploads') {
                                continue;
                            }
                            if (!self::rmDir($root . '/public/' . $subName)) {
                                return false;
                            }
                        }
                    } finally {
                        if ($publicMode !== null && is_dir($root . '/public')) {
                            @chmod($root . '/public', $publicMode);
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
            } elseif (!self::copyFile($from, $to)) {
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
            } elseif (!self::copyFile($from, $to)) {
                return false;
            }
        }
        return true;
    }

    /** 覆盖文件；先直接复制，失败时再清除只读位并重试。 */
    private static function copyFile(string $from, string $to): bool
    {
        if (!is_file($from) || is_dir($to)) {
            return false;
        }
        if (@copy($from, $to)) {
            return true;
        }
        if (is_file($to)) {
            @chmod($to, 0666);
        }
        return @copy($from, $to);
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
        // 清理入口之外再做一次保护，避免未来新增调用路径时误触运行期目录。
        // .well-known 常由 ACME/证书服务写入，任何更新阶段都不能删除其中的挑战文件。
        if (self::isProtectedPath($dir)) {
            return true;
        }
        if (is_link($dir)) {
            return self::rmRemove($dir, false);
        }
        if (!is_dir($dir)) {
            return true;
        }
        // 目录本身的写权限决定了能否删除其中的文件；安装包可能带有只读目录。
        $originalMode = @fileperms($dir);
        $originalMode = is_int($originalMode) ? ($originalMode & 0777) : null;
        @chmod($dir, 0755);
        $items = @scandir($dir);
        if ($items === false) {
            if ($originalMode !== null) {
                @chmod($dir, $originalMode);
            }
            self::$deleteFailure = $dir . '（无法读取目录）';
            return !is_dir($dir);
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_link($path)) {
                if (!self::rmRemove($path, false)) {
                    if ($originalMode !== null) {
                        @chmod($dir, $originalMode);
                    }
                    return false;
                }
            } elseif (is_dir($path)) {
                if (!self::rmDir($path)) {
                    if ($originalMode !== null) {
                        @chmod($dir, $originalMode);
                    }
                    return false;
                }
            } elseif (!self::rmRemove($path, false)) {
                if ($originalMode !== null) {
                    @chmod($dir, $originalMode);
                }
                return false;
            }
        }
        return self::rmRemove($dir, true);
    }

    /** 删除文件或空目录。Windows 环境先换名再删除，降低文件占用导致的失败概率。 */
    private static function rmRemove(string $path, bool $isDir): bool
    {
        // 清除只读属性，避免删除失败。
        $isLink = is_link($path);
        $originalMode = $isLink ? null : @fileperms($path);
        $originalMode = is_int($originalMode) ? ($originalMode & 0777) : null;
        if (!$isLink) {
            if ($isDir) {
                @chmod($path, 0755);
            } else {
                @chmod($path, 0666);
            }
        }
        $tmp = dirname($path) . '/.' . basename($path) . '.del' . bin2hex(random_bytes(3));
        for ($i = 0; $i < 3; $i++) {
            if (@rename($path, $tmp)) {
                if ($isDir) {
                    if (@rmdir($tmp) || !is_dir($tmp)) {
                        return true;
                    }
                    @chmod($tmp, 0755);
                    if (@rmdir($tmp) || !is_dir($tmp)) {
                        return true;
                    }
                    @rename($tmp, $path);
                    if ($originalMode !== null) {
                        @chmod($path, $originalMode);
                    }
                    self::$deleteFailure = $path . '（目录不可写或仍被占用）';
                    return false;
                }
                if (@unlink($tmp) || !is_file($tmp)) {
                    return true;
                }
                @rename($tmp, $path);
                if ($originalMode !== null) {
                    @chmod($path, $originalMode);
                }
                self::$deleteFailure = $path . '（文件不可写或仍被占用）';
                return false;
            }
            if ($i < 2) {
                usleep(150000);
            }
        }
        $removed = $isDir ? @rmdir($path) : @unlink($path);
        if (!$removed && ($isDir ? is_dir($path) : is_file($path))) {
            if ($originalMode !== null) {
                @chmod($path, $originalMode);
            }
            self::$deleteFailure = $path . ($isDir ? '（目录不可写或仍被占用）' : '（文件不可写或仍被占用）');
        }
        return $removed || ($isDir ? !is_dir($path) : !is_file($path));
    }

    private static function deleteFailureSuffix(): string
    {
        return self::$deleteFailure !== '' ? '：' . self::$deleteFailure : '';
    }

    private static function isPreservedRoot(string $name): bool
    {
        foreach (self::PRESERVE_ROOT_DIRS as $preserved) {
            if (strcasecmp($name, $preserved) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function isProtectedPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', rtrim($path, '/\\'));
        // 这里只保护可能被外部证书服务占用的目录。runtime 下的升级备份仍需允许清理。
        $needle = '/.well-known/';
        $haystack = '/' . strtolower($normalized) . '/';
        if (str_contains($haystack, $needle)) {
            return true;
        }
        return false;
    }

    /** 数字分段版本比较（统一走 Version::compare） */
    private static function compareVersions(string $a, string $b): int
    {
        return Version::compare($a, $b);
    }

    /** 成功结果缓存 1h；失败结果短暂缓存，避免刷新工作台时反复请求。 */
    private static function readCache(): ?array
    {
        $path = PAFISH_ROOT . '/runtime/' . self::CACHE_FILE;
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['at']) || (int) ($data['schema'] ?? 0) !== self::CACHE_SCHEMA
            || !array_key_exists('meta', $data) || ($data['meta'] !== null && !is_array($data['meta']))) {
            return null;
        }
        $ttl = is_array($data['meta'] ?? null) ? self::CACHE_TTL : self::FAILURE_CACHE_TTL;
        $age = time() - (int) $data['at'];
        if ($age < 0 || $age >= $ttl) {
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
