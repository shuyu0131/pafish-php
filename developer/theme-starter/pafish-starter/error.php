<?php
/** 错误页数据：$status、$message、$backUrl。不要输出异常详情。 */
get_header();
?>
<section class="starter-error"><p class="starter-error-code"><?= e((string) ($status ?? 404)) ?></p><h1 class="starter-title"><?= e((string) ($message ?? '页面不存在')) ?></h1><p><a class="starter-button" href="<?= e($backUrl ?? url_to('/')) ?>">返回首页</a></p></section>
<?php get_footer(); ?>
