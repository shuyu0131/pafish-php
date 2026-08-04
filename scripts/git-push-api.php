<?php

declare(strict_types=1);

/**
 * Git Data API 推送脚本（本机 git 缺少 remote-https helper 时的替代通道）：
 *   1) 用 `git ls-files --stage` 读取本地 HEAD 树的 mode/sha（不重算内容哈希）
 *   2) 把工作区文件 POST 到 GitHub git/blobs（sha 与本地一致）
 *   3) 递归构建嵌套 trees → 创建 commit → 创建 refs/heads/main 与 refs/tags/{tag}
 * 用法：php scripts/git-push-api.php --token=<GITHUB_TOKEN> --repo=shuyu0131/pafish-php [--tag=v0.1.0]
 * 前提：工作区已全部提交（git status 干净）；token 有 repo 权限。
 */

$root = dirname(__DIR__);

// ---------- 参数 ----------
$token = '';
$repo = '';
$tag = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--token=')) {
        $token = substr($arg, 8);
    } elseif (str_starts_with($arg, '--repo=')) {
        $repo = substr($arg, 7);
    } elseif (str_starts_with($arg, '--tag=')) {
        $tag = substr($arg, 6);
    }
}
if ($token === '' || $repo === '') {
    fwrite(STDERR, "用法：php scripts/git-push-api.php --token=<TOKEN> --repo=<owner/name> [--tag=v0.1.0]\n");
    exit(1);
}

// ---------- 工具 ----------
function api(string $method, string $url, array|string|null $body = null, int $attempt = 1): array
{
    global $token;
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.github+json',
        'User-Agent: pafish-release',
    ];
    if (is_array($body) || is_string($body)) {
        $headers[] = 'Content-Type: application/json';
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 120,
    ];
    // 本机 PHP 可能未配置 CA 包（curl.cainfo 为空）：优先用 ~/.cacert.pem
    $cacert = getenv('USERPROFILE') . '/.cacert.pem';
    if (is_file($cacert)) {
        $opts[CURLOPT_CAINFO] = $cacert;
    }
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = is_array($body) ? json_encode($body) : $body;
    }
    curl_setopt_array($ch, $opts);
    $resp = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $json = json_decode($resp, true);
    // 网络错误 / 5xx / 限流 → 重试（最多 4 次，指数退避）
    if (($status === 0 || $status >= 500 || $status === 429) && $attempt <= 4) {
        usleep(500000 * $attempt);
        return api($method, $url, $body, $attempt + 1);
    }
    return [$status, is_array($json) ? $json : ['raw' => $resp]];
}

function checkApi(array $r, string $what): array
{
    if ($r[0] < 200 || $r[0] >= 300) {
        fwrite(STDERR, "API 失败（{$what}）：HTTP {$r[0]} " . json_encode($r[1], JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
    return $r[1];
}

// ---------- 1. 读取本地 HEAD 树 ----------
$clean = trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && git status --porcelain'));
if ($clean !== '') {
    fwrite(STDERR, "工作区有未提交改动，请先提交：\n{$clean}\n");
    exit(1);
}
$stage = shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files --stage');
if ($stage === null || trim($stage) === '') {
    fwrite(STDERR, "git ls-files 无输出\n");
    exit(1);
}
// mode → tree entry type
$modeType = ['100644' => 'blob', '100755' => 'blob', '120000' => 'blob', '160000' => 'commit'];

// 文件: relPath => [mode, sha]
$files = [];
foreach (preg_split('/\r?\n/', trim($stage)) as $line) {
    if (preg_match('/^(\d{6}) ([0-9a-f]{40}) 0\t(.+)$/', $line, $m)) {
        $files[$m[3]] = [$m[1], $m[2]];
    }
}
$count = count($files);
echo "本地 HEAD：{$count} 个文件\n";

// ---------- 2. 上传 blobs（复用本地 sha，内容从工作区读） ----------
echo "上传 blobs...\n";
$uploaded = 0;
foreach ($files as $rel => [$mode, $sha]) {
    if ($mode === '160000') {
        continue; // submodule 不支持（本项目无）
    }
    if ($mode === '120000') {
        $content = (string) shell_exec('cd ' . escapeshellarg($root) . ' && git cat-file blob ' . $sha);
    } else {
        $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $content = is_file($path) ? (string) file_get_contents($path) : '';
    }
    $r = api('POST', "https://api.github.com/repos/{$repo}/git/blobs", [
        'content' => base64_encode($content),
        'encoding' => 'base64',
    ]);
    $got = checkApi($r, "blob {$rel}")['sha'] ?? '';
    if ($got !== $sha) {
        fwrite(STDERR, "blob sha 不一致：{$rel}（本地 {$sha} ≠ API {$got}）\n");
        exit(1);
    }
    $uploaded++;
    if ($uploaded % 100 === 0) {
        echo "  {$uploaded}/{$count}\n";
    }
}
echo "blobs 完成（{$uploaded}）\n";

// ---------- 3. 递归构建 tree ----------
echo "构建 trees...\n";
$treeCache = [];

/** 构建一层：$items = [relPath => [mode, sha]]，返回该层 tree sha */
$buildLevel = function (array $items) use (&$buildLevel, &$treeCache, $repo, $modeType): string {
    $entries = [];
    $dirs = [];
    foreach ($items as $rel => [$mode, $sha]) {
        if (str_contains($rel, '/')) {
            $top = explode('/', $rel, 2)[0];
            $dirs[$top][substr($rel, strlen($top) + 1)] = [$mode, $sha];
        } else {
            $entries[] = ['path' => $rel, 'mode' => $mode, 'type' => $modeType[$mode] ?? 'blob', 'sha' => $sha];
        }
    }
    foreach ($dirs as $dname => $sub) {
        $entries[] = ['path' => $dname, 'mode' => '040000', 'type' => 'tree', 'sha' => $buildLevel($sub)];
    }
    // git tree 排序：目录名尾部带 '/' 参与字节比较
    usort($entries, static function (array $a, array $b): int {
        $ka = $a['type'] === 'tree' ? $a['path'] . '/' : $a['path'];
        $kb = $b['type'] === 'tree' ? $b['path'] . '/' : $b['path'];
        return strcmp($ka, $kb);
    });
    $key = md5(json_encode($entries));
    if (isset($treeCache[$key])) {
        return $treeCache[$key];
    }
    $r = api('POST', "https://api.github.com/repos/{$repo}/git/trees", ['tree' => $entries]);
    $sha = checkApi($r, 'tree 层')['sha'] ?? '';
    $treeCache[$key] = $sha;
    return $sha;
};

$treeSha = $buildLevel($files);
echo "根 tree：{$treeSha}\n";

// ---------- 4. 创建 commit ----------
$name = trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && git config user.name'));
$email = trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && git config user.email'));
$message = trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && git log -1 --format=%B'));
echo "commit：{$name} <{$email}>\n";

$commitBody = [
    'message' => $message,
    'tree' => $treeSha,
    'author' => ['name' => $name, 'email' => $email, 'date' => gmdate('Y-m-d\TH:i:s\Z')],
    'committer' => ['name' => $name, 'email' => $email, 'date' => gmdate('Y-m-d\TH:i:s\Z')],
];
$r = api('POST', "https://api.github.com/repos/{$repo}/git/commits", $commitBody);
$commitSha = checkApi($r, 'commit')['sha'] ?? '';
echo "commit：{$commitSha}\n";

// ---------- 5. 创建 refs（main + tag） ----------
$r = api('POST', "https://api.github.com/repos/{$repo}/git/refs", ['ref' => 'refs/heads/main', 'sha' => $commitSha]);
$status = $r[0];
if ($status === 422) {
    // 已存在（如 contents API 的 init commit）→ force 覆盖：本地历史即权威
    $r = api('PATCH', "https://api.github.com/repos/{$repo}/git/refs/heads/main", ['sha' => $commitSha, 'force' => true]);
    checkApi($r, 'PATCH main');
    echo "refs/heads/main 已覆盖更新\n";
} else {
    checkApi($r, 'refs/heads/main');
    echo "refs/heads/main 已创建\n";
}

if ($tag !== '') {
    $r = api('POST', "https://api.github.com/repos/{$repo}/git/refs", ['ref' => "refs/tags/{$tag}", 'sha' => $commitSha]);
    if ($r[0] === 422) {
        echo "refs/tags/{$tag} 已存在，跳过\n";
    } else {
        checkApi($r, "refs/tags/{$tag}");
        echo "refs/tags/{$tag} 已创建\n";
    }
}

echo "推送完成：https://github.com/{$repo}\n";
