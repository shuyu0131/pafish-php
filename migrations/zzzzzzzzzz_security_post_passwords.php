<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $select = $pdo->prepare('SELECT id,password FROM posts WHERE id>? AND password IS NOT NULL ORDER BY id LIMIT 100');
    $update = $pdo->prepare('UPDATE posts SET password=? WHERE id=? AND CAST(password AS BINARY)=CAST(? AS BINARY)');
    $lastId = 0;
    do {
        $select->execute([$lastId]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $lastId = (int) $row['id'];
            try {
                $hash = \Pafish\Services\PostPasswords::normalize($row['password']);
            } catch (Throwable $e) {
                throw new RuntimeException('文章密码迁移失败，文章 ID：' . $lastId . '；请检查 PHP 密码算法支持', 0, $e);
            }
            if ($hash !== $row['password']) $update->execute([$hash, $lastId, $row['password']]);
        }
    } while (count($rows) === 100);
};
