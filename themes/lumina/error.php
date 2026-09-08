<?php

declare(strict_types=1);

get_header();
?>
<main class="centent lumina-layout lumina-layout-single"><div class="lumina-layout-wrap"><section class="sh-main"><?= lumina_profile_header(true) ?><div class="sh-nrbk"><section class="lumina-error"><h1><?= e((string) ($status ?? 404)) ?></h1><p><?= e((string) ($message ?? '页面不存在')) ?></p><a href="<?= e($backUrl ?? url_to('/')) ?>">返回首页</a></section></div><footer class="sh-footer"><span class="sh-copyright">© <?= date('Y') ?> <?= e(site_name()) ?></span></footer></section></div></main>
<?php get_footer();
