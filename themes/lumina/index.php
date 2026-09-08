<?php

declare(strict_types=1);

$luminaPosts = $posts ?? [];
$luminaHeading = '最新动态';
$luminaCountLabel = ($totalPages ?? 1) > 1 ? '第 ' . (int) ($pageNum ?? 1) . ' 页' : '';
$luminaEmptyText = (string) ($emptyText ?? '还没有文章，敬请期待');
$luminaPage = (int) ($pageNum ?? 1);
$luminaTotalPages = (int) ($totalPages ?? 1);
$luminaBaseUrl = (string) ($listBaseUrl ?? url_to('/'));
require __DIR__ . '/listing.php';
