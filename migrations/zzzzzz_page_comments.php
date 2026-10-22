<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    };
    $hasIndex = static function (string $table, string $index) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    };
    $hasForeignKey = static function (string $table, string $column, string $referencedTable) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME=?');
        $stmt->execute([$table, $column, $referencedTable]);
        return (int) $stmt->fetchColumn() > 0;
    };

    if (!$hasColumn('pages', 'allow_comments')) {
        $pdo->query("ALTER TABLE pages ADD COLUMN allow_comments TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否接受新评论；仍受全站开关控制'");
    }
    if (!$hasColumn('comments', 'page_id')) {
        $pdo->query("ALTER TABLE comments ADD COLUMN page_id BIGINT UNSIGNED NULL COMMENT '页面 ID；文章或页面评论目标之一' AFTER post_id");
    }
    $postIdQuery = $pdo->prepare('SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $postIdQuery->execute(['comments', 'post_id']);
    $postId = $postIdQuery->fetchColumn();
    if ($postId === 'NO') {
        $pdo->query("ALTER TABLE comments MODIFY COLUMN post_id BIGINT UNSIGNED NULL COMMENT '文章 ID；文章或页面评论目标之一'");
    }
    if (!$hasIndex('comments', 'idx_comments_page_status')) {
        $pdo->query('ALTER TABLE comments ADD KEY idx_comments_page_status (page_id, status, parent_id, created_at, id)');
    }
    if (!$hasForeignKey('comments', 'page_id', 'pages')) {
        $pdo->query('ALTER TABLE comments ADD CONSTRAINT fk_comments_page FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE');
    }
};
