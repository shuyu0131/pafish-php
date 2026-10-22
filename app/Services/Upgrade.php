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
    private static string $lastFailurePath = '';
    private static string $stateWriteFailure = '';

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
            // 当前缓存无效或已过期，尝试 fallback 到旧的成功缓存（24 小时内）
            $stale = self::readCache(true);
            if ($stale !== null && is_array($stale['meta'] ?? null)) {
                // 有旧缓存可用，标记为陈旧
                $result = self::resultFromMeta($stale['meta'], $current, '');
                $result['stale'] = true;
                $result['staleAge'] = time() - (int) ($stale['at'] ?? time());
                return $result;
            }
            return ['hasUpdate' => false, 'current' => $current, 'error' => ''];
        }
        return self::resultFromMeta($cached['meta'], $current, (string) ($cached['error'] ?? ''));
    }

    /**
     * 执行更新：下载 → 校验 → 预检 → 私有暂存解压 → 备份 → 原子合并 → upgrade.php → 完成/回滚。
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
        // 锁文件打不开（runtime 不可写、被占用）与真的已有任务在跑是两种问题，
        // 分开报错，避免让人去找并不存在的另一个更新任务。
        if ($lock === false) {
            throw new \RuntimeException('无法创建更新锁文件 ' . $lockPath . '，请确认 runtime 目录可写');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('已有更新任务正在进行中，请稍后再试');
        }
        $zipUrl = self::resolveZipUrl((string) $info['zip']);
        $tmpZip = tempnam(sys_get_temp_dir(), 'pfup');
        $bak = $root . '/runtime/.upgrade-bak-' . bin2hex(random_bytes(4));
        $stage = $root . '/runtime/.upgrade-stage-' . bin2hex(random_bytes(8));
        $backupReady = false;
        $dbBackup = null;
        $dbMigrationStarted = false;
        $filesModified = false;
        $phase = 'starting';
        $packageHash = '';
        self::$lastFailurePath = '';
        try {
            // 状态文件是故障恢复的唯一应用层现场记录。若 runtime 只能创建锁文件、
            // 却不能可靠地写入状态，就不要继续覆盖程序文件。
            self::writeStateOrThrow($root, $phase, ['target' => (string)$info['latest']]);
            if ($tmpZip === false) {
                throw new \RuntimeException('无法创建临时文件');
            }
            // 1. 下载
            $phase = 'downloading';
            self::writeStateOrThrow($root, $phase, ['target' => (string)$info['latest']]);
            $result = self::httpGet($zipUrl);
            $buffer = (string) ($result['content'] ?? '');
            if ($buffer === '' || @file_put_contents($tmpZip, $buffer) === false) {
                throw new \RuntimeException('无法写入更新包临时文件');
            }
            // 2. 校验（元数据声明 sha256 时先验哈希，防下载篡改/损坏）
            $phase = 'validating';
            self::writeStateOrThrow($root, $phase, ['target' => (string)$info['latest']]);
            self::verifySha256($buffer, (string) ($info['sha256'] ?? ''));
            self::validatePackage($tmpZip);
            $packageHash = hash('sha256', $buffer);
            // 与站点内容完全相同的文件无需解压、覆盖或备份；vendor 等目录在小版本间
            // 通常不变，跳过它们可省去大部分磁盘写入和删除。
            [$changedEntries, $unchangedFiles, $hasUpgradeScript, $newPackageFiles] = self::packageChanges($tmpZip, $root);
            // 2.5 只预检确实要写入的包内路径；未变化文件不需要 PHP-FPM 对其目录有写权限。
            $phase = 'checking_permissions';
            self::writeStateOrThrow($root, $phase, ['target' => (string)$info['latest'], 'package_sha256' => $packageHash]);
            self::assertPackageWritable($tmpZip, $root, $changedEntries);
            self::$lastFailurePath = '';
            // 先解压到 runtime 私有目录；压缩包解压失败时还没有执行数据库或站点文件写入。
            $phase = 'extracting';
            self::writeStateOrThrow($root, $phase, ['target' => (string)$info['latest'], 'package_sha256' => $packageHash]);
            self::extractPackage($tmpZip, $root, $changedEntries, $stage);
            // 回滚前会校验备份中的核心文件，因此核心文件即使未变化也始终备份。
            unset($unchangedFiles['index.php'], $unchangedFiles['app/bootstrap.php']);
            // 3. 备份（排除 public/uploads、backups、runtime；旧根目录 config.php 一并备份）
            $phase = 'backing_up';
            self::writeStateOrThrow($root, $phase, ['target' => (string)$info['latest'], 'package_sha256' => $packageHash]);
            try {
                $dbBackup = Backup::create();
            } catch (\Throwable $e) {
                throw new \RuntimeException('更新前数据库备份失败：' . $e->getMessage());
            }
            if (!self::copyDirFiltered($root, $bak, self::BACKUP_EXCLUDE_DIRS, $unchangedFiles)) {
                throw new \RuntimeException('更新失败：无法完整备份站点'
                    . (self::$lastFailurePath !== '' ? '，失败路径：' . self::$lastFailurePath : '')
                    . '（备份保留在 ' . $bak . '）');
            }
            if (!self::hasCoreFiles($bak)) {
                throw new \RuntimeException('更新失败：站点备份不完整（备份保留在 ' . $bak . '）');
            }
            $backupReady = true;
            // 4. 直接覆盖核心文件，不预先清空站点目录。
            // 这样不会触碰 .well-known、上传目录、主题插件或站点自行创建的目录，
            // 因此证书挑战文件不会阻塞正常升级。
            // 旧文件如确实需要移除，应由发行包内 upgrade.php 显式处理。
            $phase = 'replacing_files';
            self::writeStateOrThrow($root, $phase, ['target' => (string)$info['latest'], 'package_sha256' => $packageHash, 'database_backup' => $dbBackup]);
            // 在 runtime 中使用每次更新独占的临时目录，避免覆盖/删除站点自己的 pafish/ 目录。
            if ($changedEntries !== []) {
                $filesModified = true;
                self::mergePackageStage($stage, $root);
            }
            // 6. 执行包内迁移脚本 upgrade.php（执行后删除）
            $dbMigrationStarted = $hasUpgradeScript;
            $phase = 'migrating';
            self::writeStateOrThrow($root, $phase, ['target' => (string)$info['latest'], 'package_sha256' => $packageHash, 'database_backup' => $dbBackup]);
            self::runUpgradeScript($root, $hasUpgradeScript);
            $phase = 'completed';
            self::writeStateOrThrow($root, $phase, ['target' => (string)$info['latest'], 'package_sha256' => $packageHash]);
        } catch (\Throwable $e) {
            // 先保存最初失败原因；即使恢复过程或错误页加载失败，仍可定位触发点。
            \Pafish\Core\Logger::exception('system upgrade failure', $e);
            $failureState = [
                'message' => $e->getMessage(),
                'failed_phase' => $phase,
                'failed_path' => self::relativeFailurePath($root),
                'files_modified' => $filesModified,
                'target' => (string)$info['latest'],
                'package_sha256' => $packageHash !== '' ? $packageHash : null,
                'database_backup' => $dbBackup,
                'file_backup' => $backupReady ? $bak : null,
            ];
            self::writeState($root, 'failed', $failureState);
            // 校验/下载/备份阶段失败：备份目录可能不存在或未完成
            $restored = false;
            if ($backupReady && is_dir($bak) && self::isEmptyDir($bak) === false) {
                try {
                    self::rollback($root, $bak, $newPackageFiles);
                    $restored = true;
                } catch (\Throwable $re) {
                    $failureState['message'] = $e->getMessage() . '；文件恢复失败：' . $re->getMessage();
                    $failureState['rollback_error'] = $re->getMessage();
                    $failureState['rollback_completed'] = false;
                    $failureState['file_backup'] = $bak;
                    self::writeState($root, 'failed', $failureState);
                    throw new \RuntimeException($e->getMessage()
                        . '；自动回滚也失败：' . $re->getMessage()
                        . '（备份保留在 ' . $bak . '，请手动恢复）');
                }
            }
            if ($dbMigrationStarted && $dbBackup !== null) {
                try {
                    Backup::restore($dbBackup);
                } catch (\Throwable $dbError) {
                    $failureState['message'] = $e->getMessage() . '；数据库恢复失败：' . $dbError->getMessage();
                    $failureState['database_restore_error'] = $dbError->getMessage();
                    $failureState['rollback_completed'] = $restored;
                    self::writeState($root, 'failed', $failureState);
                    throw new \RuntimeException($e->getMessage() . '；数据库自动恢复失败：' . $dbError->getMessage() . '；数据库备份：backups/' . $dbBackup);
                }
            }
            $failureState['rollback_completed'] = $restored;
            self::writeState($root, 'failed', $failureState);
            throw new \RuntimeException($e->getMessage() . ($restored ? '（已恢复旧版本文件）' : '') . ($dbBackup ? '；数据库安全备份：backups/' . $dbBackup : ''));
        } finally {
            if (is_link($stage) || file_exists($stage)) {
                try {
                    self::removeUpgradeStage($stage);
                } catch (\Throwable $stagingError) {
                    \Pafish\Core\Logger::exception('system upgrade staging cleanup failure', $stagingError);
                }
            }
            @unlink($tmpZip);
            if (is_dir($bak) && self::isEmptyDir($bak)) {
                self::rmDir($bak);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        // 7. 成功：重命名备份（保留 7 天）、清静态缓存
        $keepName = 'before-v' . $info['latest'] . '-' . date('Ymd-His');
        $keepPath = $root . '/backups/' . $keepName;
        if (!rename($bak, $keepPath)) {
            // 重命名失败不阻断升级成功，记录但继续
            error_log('pafish-upgrade: 无法重命名备份 ' . $bak . ' 到 ' . $keepPath);
        }
        // 清理 7 天前的旧备份
        foreach ((array) glob($root . '/backups/before-v*') as $old) {
            if (is_dir($old) && filemtime($old) < time() - 7 * 86400) {
                self::rmDir($old);
            }
        }
        self::resetCache();
        @unlink($root . '/runtime/' . self::STATE_FILE);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
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
        $result = self::httpGet('https://gitee.com/api/v5/repos/' . $repo . '/releases/latest');
        $raw = json_decode((string) ($result['content'] ?? ''), true);
        if (!is_array($raw)) {
            throw new \RuntimeException('Gitee Release 响应格式不正确');
        }
        $tag = trim((string) ($raw['tag_name'] ?? ''));
        if (preg_match('/^v?(\d+(?:\.\d+){1,3})$/', $tag, $m) !== 1) {
            throw new \RuntimeException('Gitee Release 版本号不正确');
        }
        $version = $m[1];
        $zip = '';
        $digest = '';
        $digestUrl = '';

        foreach ((array) ($raw['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            if ($name === 'pafish-php-v' . $version . '.zip') {
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
            } elseif ($name === 'pafish-php-v' . $version . '.zip.sha256') {
                $digestUrl = trim((string) ($asset['browser_download_url'] ?? ''));
            }
        }

        if ($zip === '') {
            throw new \RuntimeException('Gitee Release 缺少匹配的 pafish-php 安装包');
        }

        // 如果 zip 资产本身没有 sha256 字段（Gitee API 不提供），尝试获取 .sha256 文件
        if ($digest === '' && $digestUrl !== '') {
            try {
                $result = self::httpGet($digestUrl);
                if ((int) ($result['status'] ?? 0) === 200) {
                    // 标准 sha256sum 格式: "abc123...  pafish-php-v1.2.3.zip\n"
                    $parts = preg_split('/\s+/', trim((string) ($result['content'] ?? '')), 2);
                    $digest = strtolower(trim((string) ($parts[0] ?? '')));
                }
            } catch (\Throwable $e) {
                // .sha256 文件获取失败不阻断升级（向后兼容），仅记录
            }
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
        self::$stateWriteFailure = '';
        $path = $root . '/runtime/' . self::STATE_FILE;
        $json = json_encode([
            'phase' => $phase,
            'at' => date('c'),
        ] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            self::$stateWriteFailure = '更新状态无法编码';
            return;
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $written = @file_put_contents($tmp, $json, LOCK_EX);
        if ($written !== strlen($json) || !@rename($tmp, $path)) {
            @unlink($tmp);
            self::$stateWriteFailure = 'runtime/' . self::STATE_FILE . '（' . self::pathModeDetails(dirname($path)) . '）';
        }
    }

    /** 状态无法可靠落盘时停止更新，避免失败后没有任何可诊断现场。 */
    private static function writeStateOrThrow(string $root, string $phase, array $extra = []): void
    {
        self::writeState($root, $phase, $extra);
        if (self::$stateWriteFailure !== '') {
            throw new \RuntimeException('无法记录更新状态：' . self::$stateWriteFailure . '，尚未替换任何程序文件');
        }
    }

    /** 让后台状态只暴露站点内的相对位置，不把服务器绝对路径写入响应。 */
    private static function relativeFailurePath(string $root): ?string
    {
        if (self::$lastFailurePath === '') {
            return null;
        }
        $prefix = rtrim($root, '/\\') . '/';
        if (str_starts_with(self::$lastFailurePath, $prefix)) {
            return substr(self::$lastFailurePath, strlen($prefix));
        }
        return '[系统临时文件]';
    }

    /** 下载 zip 地址（Gitee Release 包和 .sha256 文件） */
    private static function httpGet(string $url): array
    {
        $content = OutboundHttp::get($url, self::MAX_ZIP_BYTES, ['Accept: application/json, application/zip, text/plain']);
        return ['status' => 200, 'content' => $content];
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

    /** 替换前检查包内目标路径，失败时站点文件仍保持原样。 */
    private static function assertPackageWritable(string $tmpZip, string $root, ?array $entries = null): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) throw new \RuntimeException('无法读取更新包');
        try {
            if (!is_dir($root)) throw new \RuntimeException('站点根目录不存在，尚未替换任何程序文件');
            $selected = $entries === null ? null : array_fill_keys($entries, true);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rawEntry = (string) $zip->getNameIndex($i);
                if ($selected !== null && !isset($selected[$rawEntry])) continue;
                $entry = str_replace('\\', '/', $rawEntry);
                if (str_ends_with($entry, '/') || $entry === 'pafish') continue;
                $relative = substr($entry, strlen('pafish/'));
                if (in_array($relative, ['runtime/.gitkeep', 'backups/.gitkeep', 'public/uploads/.gitkeep'], true)) continue;
                self::$lastFailurePath = $root . '/' . $relative;
                $parent = self::writableTargetDir($root, $relative);
                if ($parent === null) {
                    throw new \RuntimeException('更新目标不可写或类型冲突：' . $relative . '，尚未替换任何程序文件');
                }
                if (!is_writable($parent)) {
                    throw new \RuntimeException('更新目标目录不可写：' . $relative . '（目录 ' . $parent . '，' . self::pathModeDetails($parent) . '），尚未替换任何程序文件');
                }
            }
        } finally { $zip->close(); }
    }

    /** 返回权限位、属主和当前 PHP 有效用户，便于直接修复部署权限。 */
    private static function pathModeDetails(string $path): string
    {
        clearstatcache(true, $path);
        $mode = @fileperms($path);
        $owner = @fileowner($path);
        $group = @filegroup($path);
        return 'mode=' . (is_int($mode) ? substr(sprintf('%o', $mode), -4) : 'unknown')
            . ', owner=' . self::accountName($owner, false)
            . ', group=' . self::accountName($group, true)
            . ', uid=' . (is_int($owner) ? (string)$owner : 'unknown')
            . ', gid=' . (is_int($group) ? (string)$group : 'unknown')
            . ', php_user=' . self::processAccountName();
    }

    private static function accountName(mixed $id, bool $group): string
    {
        if (!is_int($id)) {
            return 'unknown';
        }
        $lookup = $group ? 'posix_getgrgid' : 'posix_getpwuid';
        if (function_exists($lookup)) {
            $record = @$lookup($id);
            if (is_array($record)) {
                $name = $group ? ($record['name'] ?? '') : ($record['name'] ?? '');
                if (is_string($name) && $name !== '') {
                    return $name;
                }
            }
        }
        return (string) $id;
    }

    private static function processAccountName(): string
    {
        if (function_exists('posix_geteuid')) {
            return self::accountName((int) @posix_geteuid(), false);
        }
        return 'unknown';
    }

    /**
     * 返回实际接收写入的目录；符号链接或类型冲突返回 null。
     * 只有真正写入的那一层目录需要可写——中间层目录即使只读，也不影响
     * 原子替换（先写同目录临时文件再 rename，目标文件自身权限无关）。
     */
    private static function writableTargetDir(string $root, string $relative): ?string
    {
        $segments = explode('/', $relative);
        $last = count($segments) - 1;
        $dir = rtrim($root, '/\\');
        $path = $dir;
        foreach ($segments as $index => $segment) {
            $path .= '/' . $segment;
            clearstatcache(true, $path);
            if (is_link($path)) {
                return null;
            }
            if (!file_exists($path)) {
                break; // 目标尚不存在：最近的已存在祖先目录就是接收写入的目录
            }
            if ($index === $last) {
                return is_file($path) ? dirname($path) : null;
            }
            if (!is_dir($path)) {
                return null;
            }
            $dir = $path;
        }
        return $dir;
    }

    /** 核心文件必须存在，避免不完整备份被用于恢复站点。 */
    private static function hasCoreFiles(string $root): bool
    {
        return is_file($root . '/index.php') && is_file($root . '/app/bootstrap.php');
    }

    /** 清理解压器在 runtime 中为本次更新创建的专用临时目录。 */
    private static function removeUpgradeStage(string $stage): void
    {
        if (!is_link($stage) && !file_exists($stage)) {
            return;
        }
        self::$deleteFailure = '';
        $removed = is_dir($stage) && !is_link($stage)
            ? self::rmDir($stage)
            : self::rmRemove($stage, false);
        if (!$removed) {
            throw new \RuntimeException('无法清理本次更新临时目录 ' . $stage . self::deleteFailureSuffix());
        }
    }

    /**
     * 比较更新包与站点现有文件，返回 [需要解压的条目, 内容相同的相对路径集合]。
     * 内容相同的文件覆盖后不会改变站点，回滚时也无需恢复。
     */
    private static function packageChanges(string $tmpZip, string $root): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            throw new \RuntimeException('无法读取更新包');
        }
        $changed = [];
        $unchanged = [];
        $newFiles = [];
        $hasUpgradeScript = false;
        clearstatcache();
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                $normalized = str_replace('\\', '/', $entry);
                $relative = (string) substr(rtrim($normalized, '/'), strlen('pafish/'));
                if ($relative === '') {
                    continue;
                }
                $target = $root . '/' . $relative;
                if (str_ends_with($normalized, '/')) {
                    if (!is_dir($target)) {
                        $changed[] = $entry;
                    }
                    continue;
                }
                if (in_array($relative, ['runtime/.gitkeep', 'backups/.gitkeep', 'public/uploads/.gitkeep'], true)) {
                    continue;
                }
                $targetExists = file_exists($target) || is_link($target);
                if ($relative === 'upgrade.php') {
                    $hasUpgradeScript = true;
                    // 迁移入口必须随每次发行包重新落地并执行，即使旧站点残留了同名内容。
                    $changed[] = $entry;
                    if (!$targetExists) {
                        $newFiles[] = $relative;
                    }
                    continue;
                }
                $stat = $zip->statIndex($i);
                if (is_file($target) && !is_link($target) && is_array($stat)
                    && @filesize($target) === (int) $stat['size']
                    && @file_get_contents($target) === $zip->getFromIndex($i)) {
                    $unchanged[$relative] = true;
                } else {
                    $changed[] = $entry;
                    if (!$targetExists) {
                        $newFiles[] = $relative;
                    }
                }
            }
        } finally {
            $zip->close();
        }
        return [$changed, $unchanged, $hasUpgradeScript, $newFiles];
    }

    /** 把更新包解压到本次更新独占的 runtime 临时目录，不修改站点程序文件。 */
    private static function extractPackage(string $tmpZip, string $root, ?array $entries = null, ?string $stage = null): void
    {
        if ($entries === []) {
            return; // 包内文件与站点完全一致，无需替换
        }
        $stage ??= $root . '/runtime/.upgrade-stage-' . bin2hex(random_bytes(8));
        $runtime = realpath(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'runtime');
        $stageParent = realpath(dirname($stage));
        $sameRuntime = is_string($runtime) && is_string($stageParent)
            && (DIRECTORY_SEPARATOR === '\\' ? strcasecmp($runtime, $stageParent) === 0 : $runtime === $stageParent);
        if (!$sameRuntime) {
            throw new \RuntimeException('更新临时目录位置无效');
        }
        if (file_exists($stage) || is_link($stage) || !@mkdir($stage, 0700)) {
            self::$lastFailurePath = $stage;
            throw new \RuntimeException('无法创建更新临时目录：' . $stage . '（' . self::pathModeDetails(dirname($stage)) . '）');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            self::$lastFailurePath = $tmpZip;
            throw new \RuntimeException('更新包无法解压');
        }
        if (!($entries === null ? $zip->extractTo($stage) : $zip->extractTo($stage, $entries))) {
            @$zip->close();
            self::$lastFailurePath = $stage;
            throw new \RuntimeException('更新包解压失败，临时目录：' . $stage);
        }
        @$zip->close();
        $pafishDir = $stage . '/pafish';
        if (!is_dir($pafishDir)) {
            self::$lastFailurePath = $pafishDir;
            throw new \RuntimeException('更新包缺少 pafish/ 目录');
        }
    }

    /** 原子合并已预检并解压的更新包文件。 */
    private static function mergePackageStage(string $stage, string $root): void
    {
        $pafishDir = $stage . '/pafish';
        if (!is_dir($pafishDir)) {
            self::$lastFailurePath = $pafishDir;
            throw new \RuntimeException('更新临时目录缺少 pafish/ 文件');
        }
        self::$lastFailurePath = '';
        if (!self::mergeDir($pafishDir, $root)) {
            throw new \RuntimeException('更新包文件合并失败' . (self::$lastFailurePath !== '' ? '：' . self::$lastFailurePath : ''));
        }
    }

    /** 执行包内迁移脚本 upgrade.php（若存在；执行后删除）。脚本可用 $pdo / PAFISH_ROOT */
    private static function runUpgradeScript(string $root, bool $includedInPackage): void
    {
        if (!$includedInPackage) {
            return;
        }
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

    /** 覆盖恢复旧文件；不清空站点、不删除备份，失败或重复调用仍可继续恢复。 */
    private static function rollback(string $root, string $bak, array $newPackageFiles = []): void
    {
        if (!self::hasCoreFiles($bak)) {
            throw new \RuntimeException('备份不完整，无法安全恢复');
        }
        self::$lastFailurePath = '';
        if (!self::copyDirFiltered($bak, $root, [])) {
            throw new \RuntimeException('无法从备份恢复文件'
                . (self::$lastFailurePath !== '' ? '，失败路径：' . self::$lastFailurePath : ''));
        }
        self::removeCreatedPackageFiles($root, $newPackageFiles);
        if (!self::hasCoreFiles($root)) {
            throw new \RuntimeException('恢复后缺少核心文件');
        }
    }

    /** 回滚时删除本次更新新增的程序文件，避免新旧版本文件混杂。 */
    private static function removeCreatedPackageFiles(string $root, array $relativeFiles): void
    {
        foreach (array_unique($relativeFiles) as $relative) {
            $relative = str_replace('\\', '/', (string) $relative);
            $segments = explode('/', $relative);
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, ':')
                || in_array('.', $segments, true) || in_array('..', $segments, true)) {
                throw new \RuntimeException('新增文件回滚路径无效：' . $relative);
            }
            $path = rtrim($root, '/\\') . '/' . $relative;
            if (is_link($path)) {
                if (!self::rmRemove($path, false)) {
                    self::$lastFailurePath = $path;
                    throw new \RuntimeException('无法删除更新新增的符号链接：' . $relative);
                }
            } elseif (is_file($path)) {
                if (!self::rmRemove($path, false)) {
                    self::$lastFailurePath = $path;
                    throw new \RuntimeException('无法删除更新新增的文件：' . $relative);
                }
            } elseif (file_exists($path)) {
                self::$lastFailurePath = $path;
                throw new \RuntimeException('更新新增文件回滚时发现类型冲突：' . $relative);
            }
        }
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

    /** 复制目录（跳过排除前缀相对路径与 $skipFiles 中的文件；目录跳过创建，文件覆盖） */
    private static function copyDirFiltered(string $src, string $dst, array $exclude, array $skipFiles = []): bool
    {
        return self::copyRecursive($src, $dst, '', $exclude, $skipFiles);
    }

    private static function copyRecursive(string $src, string $dst, string $rel, array $exclude, array $skipFiles = []): bool
    {
        if (!is_dir($src)) {
            self::$lastFailurePath = $src;
            return false;
        }
        if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
            self::$lastFailurePath = $dst;
            return false;
        }
        $items = @scandir($src);
        if ($items === false) {
            self::$lastFailurePath = $src;
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
                if (!self::copyRecursive($from, $to, $childRel, $exclude, $skipFiles)) {
                    return false;
                }
            } elseif (isset($skipFiles[$childRel])) {
                continue;
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
            self::$lastFailurePath = $src;
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
                    self::$lastFailurePath = $to;
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

    /** 先完整写入同目录临时文件，再原子替换，复制失败不截断原文件。 */
    private static function copyFile(string $from, string $to): bool
    {
        if (!is_file($from) || is_link($from) || is_dir($to) || is_link($to)) {
            self::$lastFailurePath = $to;
            return false;
        }
        $tmp = @tempnam(dirname($to), '.pafish-copy-');
        if ($tmp === false) {
            self::$lastFailurePath = $to;
            return false;
        }
        try {
            if (realpath(dirname($tmp)) !== realpath(dirname($to))) {
                self::$lastFailurePath = $to;
                return false;
            }
            if (!@copy($from, $tmp)) {
                self::$lastFailurePath = $to;
                return false;
            }
            $mode = @fileperms($to);
            @chmod($tmp, is_int($mode) ? ($mode & 0777) : 0644);
            if (@rename($tmp, $to)) {
                return true;
            }
            if (PHP_OS_FAMILY === 'Windows' && is_file($to)) {
                @chmod($to, 0666);
                if (@rename($tmp, $to)) {
                    return true;
                }
            }
            self::$lastFailurePath = $to;
            return false;
        } finally {
            @unlink($tmp);
        }
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
    private static function readCache(bool $stale = false): ?array
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
        if ($stale) {
            // stale 模式：只回退成功结果，最多接受 24 小时陈旧数据
            if (!is_array($data['meta'] ?? null)) {
                return null;
            }
            $ttl = 86400;
        }
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
