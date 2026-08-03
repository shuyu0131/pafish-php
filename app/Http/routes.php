<?php

declare(strict_types=1);

use Pafish\Http\HomeController;
use Pafish\Http\PostController;
use Pafish\Http\CategoryController;
use Pafish\Http\TagController;
use Pafish\Http\ArchiveController;
use Pafish\Http\SearchController;

/**
 * 路由注册（$app 来自 bootstrap.php include 上下文）
 * 前台路由随里程碑扩展；后台走 /admin 前缀（M3）
 */

$app->get('/', [HomeController::class, 'index']);

// ---- M2：文章详情与互动 ----
$app->get('/post/{slug}', [PostController::class, 'show']);
$app->post('/api/post/{id}/{kind}', [PostController::class, 'toggle']); // kind: like | favorite

// ---- M2：分类 / 标签 / 归档 / 搜索 ----
$app->get('/category/{slug}', [CategoryController::class, 'show']);
$app->get('/tag/{slug}', [TagController::class, 'show']);
$app->get('/archives', [ArchiveController::class, 'index']);
$app->get('/search', [SearchController::class, 'index']);
