<?php
/**
 * 错误页（404/500；系统默认模板，主题可覆盖 themes/{active}/error.php）
 * 可用数据：$status、$message、$backUrl
 * 注意：绝不输出异常详情或用户输入（防 XSS）
 */
get_header();
?>
<div class="container">
  <div class="error-box">
    <h1><?= e((string) $status) ?></h1>
    <p><?= e((string) $message) ?></p>
    <a href="<?= e($backUrl ?? url_to('/')) ?>" class="btn btn-primary">返回首页</a>
  </div>
</div>
<?php
get_footer();
