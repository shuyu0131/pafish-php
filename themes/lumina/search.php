<?php

declare(strict_types=1);

$keyword = (string) ($keyword ?? '');
$luminaPosts = $posts ?? [];
$luminaHeading = $keyword === '' ? '搜索' : '“' . $keyword . '”';
$luminaCountLabel = $keyword === '' ? '输入关键词开始搜索' : '共找到 ' . (int) ($total ?? 0) . ' 篇文章';
$luminaEmptyText = $keyword === '' ? '输入关键词开始搜索' : '没有找到相关文章';
$luminaPage = (int) ($pageNum ?? 1);
$luminaTotalPages = (int) ($totalPages ?? 1);
$luminaBaseUrl = (string) ($listBaseUrl ?? url_to('/search'));
require __DIR__ . '/listing.php';
