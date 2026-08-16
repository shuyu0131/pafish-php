<?php

declare(strict_types=1);

$category = $category ?? [];
$luminaPosts = $posts ?? [];
$luminaHeading = (string) ($category['name'] ?? '分类');
$luminaCountLabel = '共 ' . (int) ($total ?? 0) . ' 篇文章';
$luminaDescription = (string) ($category['description'] ?? '');
$luminaEmptyText = '该分类下还没有文章';
$luminaPage = (int) ($pageNum ?? 1);
$luminaTotalPages = (int) ($totalPages ?? 1);
$luminaBaseUrl = (string) ($listBaseUrl ?? '');
require __DIR__ . '/listing.php';
