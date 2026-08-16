<?php

declare(strict_types=1);

get_header();
?>
<section class="lumina-error"><h1><?= e((string) ($status ?? 404)) ?></h1><p><?= e((string) ($message ?? '页面不存在')) ?></p><a href="<?= e($backUrl ?? url_to('/')) ?>">返回首页</a></section>
<?php get_footer();
