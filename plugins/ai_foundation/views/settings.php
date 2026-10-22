<?php
use Pafish\Core\DB;
use Pafish\Core\Url;

$manageUrl = Url::to('/admin/plugin/ai_foundation/manage');
$csrf = csrf_token();
$isEditing = is_array($editing);
$tabLabels = ['models' => '模型', 'usage' => '用量', 'quota' => '配额', 'functions' => '函数', 'test' => '测试'];
$tabUrl = static fn (string $tab): string => Url::to('/admin/plugins/ai_foundation?tab=' . rawurlencode($tab));
$chatModels = array_values(array_filter($models, static fn (array $model): bool => $model['type'] === 'chat'));
$providerRoots = [
    'openai' => 'https://api.openai.com/v1',
    'deepseek' => 'https://api.deepseek.com/v1',
    '通义千问' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
    '智谱' => 'https://open.bigmodel.cn/api/paas/v4',
    '硅基流动' => 'https://api.siliconflow.cn/v1',
];
?>
<style>
[data-ai-foundation] > .admin-page-head { margin-bottom: 0; }
[data-ai-foundation] .ai-foundation-form { width: 100%; max-width: 820px; gap: 18px; }
[data-ai-foundation] .ai-foundation-form form { gap: 18px; }
[data-ai-foundation] .ai-foundation-stats { grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); }
[data-ai-foundation] .admin-ai-stat-row { display: flex; align-items: baseline; gap: 8px; }
[data-ai-foundation] .admin-ai-stat-value { font-size: 24px; font-variant-numeric: tabular-nums; }
[data-ai-foundation] .admin-ai-result { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
<section class="admin-stack admin-plugin-workspace" data-ai-foundation aria-label="AI Foundation">
  <header class="admin-page-head">
    <div><h1 class="admin-h1">AI Foundation</h1><p class="admin-page-sub">统一管理模型连接、默认模型和插件调用能力。</p></div>
  </header>
  <nav class="admin-tabs" aria-label="AI Foundation 模块">
    <?php foreach ($tabLabels as $key => $label): ?><a class="admin-tab <?= $activeTab === $key ? 'is-active' : '' ?>" <?= $activeTab === $key ? 'aria-current="page"' : '' ?> href="<?= e($tabUrl($key)) ?>"><?= e($label) ?></a><?php endforeach; ?>
  </nav>
  <p class="admin-backup-msg" id="aiFoundationMsg" role="status" hidden></p>

  <?php if ($activeTab === 'models'): ?>
    <div class="admin-page-head"><div><h2 class="admin-h2">模型</h2><p class="admin-page-sub">管理聊天、向量、图像和语音模型，按类型设置默认模型。</p></div><?php if (!$isEditing): ?><a class="btn btn-primary" href="<?= e(Url::to('/admin/plugins/ai_foundation?tab=models&edit=0')) ?>">添加模型</a><?php endif; ?></div>
    
    <?php if ($isEditing): ?>
    <section class="admin-stack ai-foundation-form">
      <div><h3 class="admin-h2"><?= $isEditing && (int) ($editing['id'] ?? 0) > 0 ? '编辑模型' : '添加模型' ?></h3><p class="admin-page-sub">API Key 加密保存；编辑时留空可保留原密钥。</p></div>
      <form data-ai-form class="admin-stack">
        <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
        <div class="admin-form-grid">
          <label class="admin-field"><span class="label">模型名称</span><input class="input" name="name" required maxlength="100" value="<?= e((string) ($editing['name'] ?? '')) ?>" placeholder="例如：DeepSeek 主力"></label>
          <label class="admin-field"><span class="label">服务商</span><select class="input" name="provider"><?php foreach (['openai-compatible' => 'OpenAI 兼容', 'openai' => 'OpenAI', 'deepseek' => 'DeepSeek', '通义千问' => '通义千问', '智谱' => '智谱', '硅基流动' => '硅基流动', '自定义' => '自定义'] as $value => $label): ?><option value="<?= e($value) ?>"<?= ($editing['provider'] ?? 'openai-compatible') === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="admin-form-grid">
          <label class="admin-field"><span class="label">模型类型</span><select class="input" name="type"><?php foreach (['chat' => '聊天（Chat Completions）', 'embedding' => '向量（Embeddings）', 'image' => '图像生成（DALL-E）', 'audio' => '语音转文字（Whisper）'] as $value => $label): ?><option value="<?= e($value) ?>"<?= ($editing['type'] ?? 'chat') === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
          <label class="admin-field" data-ai-purpose<?= ($editing['type'] ?? 'chat') !== 'chat' ? ' hidden' : '' ?>><span class="label">模型用途</span><select class="input" name="purpose"<?= ($editing['type'] ?? 'chat') !== 'chat' ? ' disabled' : '' ?>><?php foreach (['general' => '通用', 'moderation' => '内容审核', 'generation' => '长文生成', 'search' => '搜索与检索'] as $value => $label): ?><option value="<?= e($value) ?>"<?= ($editing['purpose'] ?? 'general') === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><span class="muted">用于自动选择聊天模型，不限制模型能力。</span></label>
        </div>
        <div class="admin-form-grid">
          <label class="admin-field"><span class="label">API 根地址</span><input class="input" type="url" name="base_url" list="aiFoundationApiRoots" required maxlength="2048" value="<?= e((string) ($editing['base_url'] ?? '')) ?>" placeholder="https://api.example.com/v1"><datalist id="aiFoundationApiRoots"><?php foreach ($providerRoots as $provider => $root): ?><option value="<?= e($root) ?>" label="<?= e($provider) ?>"></option><?php endforeach; ?></datalist></label>
          <label class="admin-field"><span class="label">模型 ID</span><input class="input" name="model" required maxlength="160" value="<?= e((string) ($editing['model'] ?? '')) ?>" placeholder="例如：deepseek-chat"></label>
        </div>
        <label class="admin-field"><span class="label">API Key</span><input class="input" type="password" name="api_key" autocomplete="new-password" placeholder="<?= $isEditing && (int) ($editing['id'] ?? 0) > 0 && (string) ($editing['api_key'] ?? '') !== '' ? '已加密保存；留空以保留现有密钥' : '输入服务商 API Key' ?>"></label>
        <label class="admin-check-row"><input type="checkbox" name="is_default" value="1"<?= empty($editing['id']) || !empty($editing['is_default']) ? ' checked' : '' ?>>设为该类型的默认模型</label>
        <div class="admin-head-actions"><button class="btn btn-primary" type="submit"><?= (int) ($editing['id'] ?? 0) > 0 ? '保存' : '添加' ?></button><a class="btn btn-ghost" href="<?= e($tabUrl('models')) ?>">取消</a></div>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($models === []): ?><p class="admin-empty">尚未配置模型。</p><?php else: ?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>模型</th><th>类型</th><th>用途</th><th>状态</th><th>操作</th></tr></thead><tbody><?php foreach ($models as $model): 
      $health = $model['type'] === 'chat' ? \AiFoundation\HealthCheck::cachedStatus((int) $model['id']) : null;
      $statusText = $model['type'] !== 'chat' ? '不支持检查' : ($health === null ? '未检查' : ($health['status'] === 'ok' ? '正常 (' . $health['latency_ms'] . 'ms)' : ($health['status'] === 'warn' ? '可连接 (' . $health['latency_ms'] . 'ms)' : $health['error'])));
    ?><tr>
      <td><strong><?= e((string) $model['name']) ?></strong><?= !empty($model['is_default']) ? ' <span class="badge badge-accent">默认</span>' : '' ?><div class="muted"><?= e((string) $model['model']) ?></div></td>
      <td><?= e(['chat' => '聊天', 'embedding' => '向量', 'image' => '图像', 'audio' => '语音'][(string) $model['type']] ?? '聊天') ?></td>
      <td><?= $model['type'] !== 'chat' ? '—' : e(['general' => '通用', 'moderation' => '审核', 'generation' => '生成', 'search' => '搜索'][(string) $model['purpose']] ?? '通用') ?></td>
      <td><span class="muted" data-ai-health title="<?= e($statusText) ?>"><?= e($statusText) ?></span></td>
      <td><div class="admin-row-ops">
        <?php if (empty($model['is_default'])): ?><form data-ai-form><input type="hidden" name="action" value="default"><input type="hidden" name="id" value="<?= (int) $model['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">设为默认</button></form><?php endif; ?>
        <form data-ai-form data-ai-model-type="<?= e((string) $model['type']) ?>"><input type="hidden" name="action" value="check"><input type="hidden" name="id" value="<?= (int) $model['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">检查</button></form>
        <a class="btn btn-sm btn-ghost" href="<?= e(Url::to('/admin/plugins/ai_foundation?tab=models&edit=' . (int) $model['id'])) ?>">编辑</a>
        <form data-ai-form><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $model['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">删除</button></form>
      </div></td>
    </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

  <?php elseif ($activeTab === 'usage'): ?>
    <?php
    $usageTable = '`' . $ctx->table('usage_log') . '`';
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    
    $dailyStats = DB::fetchOne("SELECT COUNT(*) AS calls, SUM(prompt_tokens) AS prompt, SUM(completion_tokens) AS completion, SUM(total_tokens) AS total FROM {$usageTable} WHERE DATE(created_at) = ?", [$today]) ?? ['calls' => 0, 'prompt' => 0, 'completion' => 0, 'total' => 0];
    $monthlyStats = DB::fetchOne("SELECT COUNT(*) AS calls, SUM(prompt_tokens) AS prompt, SUM(completion_tokens) AS completion, SUM(total_tokens) AS total FROM {$usageTable} WHERE created_at >= ?", [$monthStart]) ?? ['calls' => 0, 'prompt' => 0, 'completion' => 0, 'total' => 0];
    
    $byCaller = DB::fetchAll("SELECT caller_plugin, COUNT(*) AS calls, SUM(total_tokens) AS tokens FROM {$usageTable} WHERE created_at >= ? GROUP BY caller_plugin ORDER BY tokens DESC LIMIT 20", [$monthStart]);
    
    $byModel = DB::fetchAll("SELECT m.name, m.model, COUNT(u.id) AS calls, SUM(u.total_tokens) AS tokens FROM {$usageTable} u LEFT JOIN `" . $ctx->table('models') . "` m ON u.model_id = m.id WHERE u.created_at >= ? GROUP BY u.model_id, m.name, m.model ORDER BY tokens DESC LIMIT 20", [$monthStart]);
    
    $formatTokens = static function (int $tokens): string {
        if ($tokens >= 1000000) return sprintf('%.2f M', $tokens / 1000000);
        if ($tokens >= 1000) return sprintf('%.1f K', $tokens / 1000);
        return (string) $tokens;
    };
    ?>
    <div class="admin-page-head"><div><h2 class="admin-h2">用量统计</h2><p class="admin-page-sub">调用次数与 Token 用量（本月）。</p></div></div>
    
    <div class="admin-form-grid ai-foundation-stats">
      <section class="card admin-form-card admin-stack">
        <h3 class="admin-h3">今日</h3>
        <div class="admin-ai-stat-row"><span class="admin-ai-stat-value"><?= number_format((int) $dailyStats['calls']) ?></span><span class="muted">次调用</span></div>
        <div class="admin-ai-stat-row"><span class="admin-ai-stat-value"><?= $formatTokens((int) $dailyStats['total']) ?></span><span class="muted">tokens</span></div>
        <div class="muted"><?= $formatTokens((int) $dailyStats['prompt']) ?> 输入 + <?= $formatTokens((int) $dailyStats['completion']) ?> 输出</div>
      </section>
      <section class="card admin-form-card admin-stack">
        <h3 class="admin-h3">本月累计</h3>
        <div class="admin-ai-stat-row"><span class="admin-ai-stat-value"><?= number_format((int) $monthlyStats['calls']) ?></span><span class="muted">次调用</span></div>
        <div class="admin-ai-stat-row"><span class="admin-ai-stat-value"><?= $formatTokens((int) $monthlyStats['total']) ?></span><span class="muted">tokens</span></div>
        <div class="muted"><?= $formatTokens((int) $monthlyStats['prompt']) ?> 输入 + <?= $formatTokens((int) $monthlyStats['completion']) ?> 输出</div>
      </section>
    </div>

    <?php if ($byCaller !== []): ?>
    <section class="admin-stack">
      <h3 class="admin-h3">按调用方（本月）</h3>
      <div class="admin-table-wrap"><table class="admin-table">
        <thead><tr><th>插件</th><th>调用次数</th><th>Token 用量</th></tr></thead>
        <tbody>
          <?php foreach ($byCaller as $row): ?>
          <tr>
            <td><code><?= e((string) $row['caller_plugin']) ?></code></td>
            <td><?= number_format((int) $row['calls']) ?></td>
            <td><?= $formatTokens((int) $row['tokens']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <?php endif; ?>

    <?php if ($byModel !== []): ?>
    <section class="admin-stack">
      <h3 class="admin-h3">按模型（本月）</h3>
      <div class="admin-table-wrap"><table class="admin-table">
        <thead><tr><th>模型</th><th>调用次数</th><th>Token 用量</th></tr></thead>
        <tbody>
          <?php foreach ($byModel as $row): ?>
          <tr>
            <td><strong><?= e((string) ($row['name'] ?? '已删除模型')) ?></strong><div class="muted"><?= e((string) ($row['model'] ?? '')) ?></div></td>
            <td><?= number_format((int) $row['calls']) ?></td>
            <td><?= $formatTokens((int) $row['tokens']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <?php endif; ?>
    
    <?php if ($byCaller === [] && $byModel === []): ?>
      <p class="admin-empty">本月暂无调用记录。</p>
    <?php endif; ?>

  <?php elseif ($activeTab === 'quota'): ?>
    <?php
    $quotaTable = '`' . $ctx->table('quota_settings') . '`';
    $quota = DB::fetchOne("SELECT * FROM {$quotaTable} WHERE id = 1") ?? ['hourly_calls_limit' => 1000, 'daily_tokens_limit' => 100000, 'max_tokens_per_call' => 4000];
    ?>
    <div class="admin-page-head"><div><h2 class="admin-h2">配额与安全</h2><p class="admin-page-sub">限制单位时间内的调用次数与 Token 用量，防止账单失控。</p></div></div>
    <section class="admin-stack ai-foundation-form">
      <form data-ai-form class="admin-stack">
        <input type="hidden" name="action" value="quota">
        <label class="admin-field"><span class="label">每小时最大调用次数</span><input class="input" type="number" name="hourly_calls_limit" min="1" max="1000000" value="<?= (int) $quota['hourly_calls_limit'] ?>" required></label>
        <label class="admin-field"><span class="label">每日最大 Token 数</span><input class="input" type="number" name="daily_tokens_limit" min="1000" max="10000000" value="<?= (int) $quota['daily_tokens_limit'] ?>" required></label>
        <label class="admin-field"><span class="label">单次最大 Token</span><input class="input" type="number" name="max_tokens_per_call" min="100" max="128000" value="<?= (int) $quota['max_tokens_per_call'] ?>" required></label>
        <div><button class="btn btn-primary" type="submit">保存配额</button></div>
      </form>
    </section>

  <?php elseif ($activeTab === 'functions'): ?>
    <?php
    $funcsTable = '`' . $ctx->table('registered_functions') . '`';
    $functions = DB::fetchAll("SELECT * FROM {$funcsTable} ORDER BY is_enabled DESC, name ASC");
    ?>
    <div class="admin-page-head"><div><h2 class="admin-h2">已注册函数（Function Calling）</h2><p class="admin-page-sub">插件可注册工具函数，AI 模型自动调用完成复杂任务。</p></div></div>
    
    <?php if ($functions === []): ?>
      <p class="admin-empty">尚无插件注册函数。插件通过 <code>registerAiFunction</code> provider 注册。</p>
    <?php else: ?>
      <div class="admin-table-wrap"><table class="admin-table">
        <thead><tr><th>函数名</th><th>描述</th><th>插件</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          <?php foreach ($functions as $fn): ?>
          <tr>
            <td><code><?= e((string) $fn['name']) ?></code></td>
            <td><?= e((string) $fn['description']) ?></td>
            <td><?= e((string) $fn['provider_plugin']) ?></td>
            <td><?= (int) $fn['is_enabled'] === 1 ? '<span class="badge badge-success">启用</span>' : '<span class="badge badge-muted">已禁用</span>' ?></td>
            <td><div class="admin-row-ops">
              <form data-ai-form>
                <input type="hidden" name="action" value="toggle_function">
                <input type="hidden" name="id" value="<?= (int) $fn['id'] ?>">
                <button class="btn btn-sm btn-ghost" type="submit"><?= (int) $fn['is_enabled'] === 1 ? '禁用' : '启用' ?></button>
              </form>
              <form data-ai-form>
                <input type="hidden" name="action" value="delete_function">
                <input type="hidden" name="id" value="<?= (int) $fn['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">删除</button>
              </form>
            </div></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>


  <?php elseif ($activeTab === 'test'): ?>
    <div class="admin-page-head"><div><h2 class="admin-h2">测试</h2><p class="admin-page-sub">向聊天模型发送测试请求，验证连接与响应。</p></div></div>
    <?php if ($chatModels === []): ?><p class="admin-empty">请先在“模型”中添加聊天模型。</p><?php else: ?>
    <section class="admin-stack ai-foundation-form"><form data-ai-form class="admin-stack"><input type="hidden" name="action" value="chat"><label class="admin-field"><span class="label">模型</span><select class="input" name="id"><?php foreach ($chatModels as $model): ?><option value="<?= (int) $model['id'] ?>"<?= !empty($model['is_default']) ? ' selected' : '' ?>><?= e((string) $model['name'] . ' (' . $model['model'] . ')') ?></option><?php endforeach; ?></select></label><label class="admin-field"><span class="label">提示词</span><textarea class="input" name="prompt" rows="5" maxlength="100000" required placeholder="输入测试内容"></textarea></label><div><button class="btn btn-primary" type="submit">发送</button></div></form><pre class="admin-ai-result" id="aiFoundationResult" hidden></pre></section>
    <?php endif; ?>
  <?php endif; ?>
</section>
<script>
(function () {
  "use strict";
  var endpoint = <?= json_encode($manageUrl) ?>;
  var csrf = <?= json_encode($csrf) ?>;
  var modelsUrl = <?= json_encode($tabUrl('models')) ?>;
  var providerRoots = <?= json_encode($providerRoots, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var workspace = document.querySelector("[data-ai-foundation]");
  var message = document.getElementById("aiFoundationMsg");
  var result = document.getElementById("aiFoundationResult");
  function show(text, type, keep) {
    if (typeof window.pafishToast === "function") window.pafishToast(text, type);
    if (keep || typeof window.pafishToast !== "function") {
      message.textContent = text;
      message.className = "admin-backup-msg " + (type === "error" ? "admin-backup-msg-error" : type === "success" ? "admin-backup-msg-ok" : "");
      message.setAttribute("role", type === "error" ? "alert" : "status");
      message.hidden = false;
    }
  }
  var provider = workspace.querySelector('select[name="provider"]');
  var baseUrl = workspace.querySelector('input[name="base_url"]');
  if (provider && baseUrl) {
    var previousRoot = providerRoots[provider.value] || "";
    if (!baseUrl.value.trim() && previousRoot) baseUrl.value = previousRoot;
    provider.addEventListener("change", function () {
      var nextRoot = providerRoots[provider.value] || "";
      if (nextRoot && (!baseUrl.value.trim() || baseUrl.value.trim().replace(/\/+$/, "") === previousRoot)) baseUrl.value = nextRoot;
      previousRoot = nextRoot;
    });
  }
  var modelType = workspace.querySelector('select[name="type"]');
  var purpose = workspace.querySelector('select[name="purpose"]');
  var purposeField = workspace.querySelector("[data-ai-purpose]");
  if (modelType && purpose && purposeField) {
    function syncPurpose() {
      purposeField.hidden = modelType.value !== "chat";
      purpose.disabled = purposeField.hidden;
      purpose.dispatchEvent(new Event("change", { bubbles: true }));
    }
    modelType.addEventListener("change", syncPurpose);
    syncPurpose();
  }
  workspace.querySelectorAll("[data-ai-form]").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var action = form.elements.action.value;
      var button = form.querySelector("button[type=submit]");
      if ((action === "delete" || action === "delete_function") && !window.confirm(action === "delete" ? "删除此模型配置？" : "删除此函数注册？")) return;
      message.hidden = true;
      if (action === "check" && form.dataset.aiModelType !== "chat") { show("检查仅支持聊天类型模型。", "error", true); return; }
      if (button) button.disabled = true;
      var data = new FormData(form); data.append("_csrf", csrf);
      fetch(endpoint, { method: "POST", body: data, headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (response) { return response.json().then(function (payload) { if (!response.ok || !payload.ok) throw new Error(payload.error || "操作失败"); return payload; }); })
        .then(function (payload) {
          if (action === "chat") { result.textContent = payload.result || ""; result.hidden = false; show(payload.message, "success"); }
          else if (action === "check") {
            var health = payload.health;
            var text = health.status === "error" ? health.error : (health.detail || (health.status === "warn" ? "连接正常，响应较慢" : "连接正常")) + "（" + health.latency_ms + "ms）";
            var status = form.closest("tr").querySelector("[data-ai-health]");
            status.textContent = text; status.title = text;
            show("检查结果：" + text, health.status === "error" ? "error" : health.status === "warn" ? "warning" : "success", true);
          }
          else if (action === "quota") { show(payload.message, "success"); }
          else if (action === "save" && typeof window.pafishToastNavigate === "function") { window.pafishToastNavigate(modelsUrl, payload.message, "success"); }
          else if (typeof window.pafishToastReload === "function") { window.pafishToastReload(payload.message, "success"); }
          else { show(payload.message, "success", true); }
        })
        .catch(function (error) { show(error.message, "error", true); })
        .finally(function () { if (button) button.disabled = false; });
    });
  });
})();
</script>
