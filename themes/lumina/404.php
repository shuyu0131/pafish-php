<?php
// pafish: emlog guard removed
$lumina_page_identity = '404';
// pafish: header already loaded by get_header()
?>
<div class="centent">
    <div class="sh-main">
        <div class="sh-404">
            <h1>404</h1>
            <p>Page not found.</p>
            <p><a href="<?= lumina_blog_base() ?>">Back to home</a></p>
        </div>
        <?php lumina_render_main_footer(); ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
