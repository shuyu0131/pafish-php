<?php

declare(strict_types=1);

$tag = $tag ?? [];
$luminaPosts = $posts ?? [];
$luminaHeading = '#' . (string) ($tag['name'] ?? '标签');
$luminaCountLabel = '共 ' . (int) ($total ?? 0) . ' 篇文章';
$luminaEmptyText = '该标签下还没有文章';
$luminaPage = (int) ($pageNum ?? 1);
$luminaTotalPages = (int) ($totalPages ?? 1);
$luminaBaseUrl = (string) ($listBaseUrl ?? '');
require __DIR__ . '/listing.php';
