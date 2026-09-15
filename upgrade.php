<?php

declare(strict_types=1);

// 兼容旧版本升级时的主题恢复包。
$luminaRecovery = PAFISH_ROOT . '/runtime/.pafish-lumina-recovery.zip';
if (is_file($luminaRecovery) && !is_dir(PAFISH_ROOT . '/themes/lumina')) {
    $activeTheme = (string) \Pafish\Services\Settings::get('active_theme', 'default');
    if ($activeTheme === 'lumina') {
        $zip = new \ZipArchive();
        if ($zip->open($luminaRecovery) !== true || !$zip->extractTo(PAFISH_ROOT . '/themes')) {
            throw new \RuntimeException('主题恢复失败');
        }
        $zip->close();
        if (!is_file(PAFISH_ROOT . '/themes/lumina/theme.json')) {
            throw new \RuntimeException('主题恢复包不完整');
        }
    }
}
@unlink($luminaRecovery);

// 在线更新补齐核心积分账本。红包业务由独立插件维护，不随核心升级创建表。
$pdo = \Pafish\Core\DB::pdo();

$pdo->exec('CREATE TABLE IF NOT EXISTS user_points (
  user_id    BIGINT UNSIGNED NOT NULL,
  balance    BIGINT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_points_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci');

$pdo->exec('CREATE TABLE IF NOT EXISTS point_transactions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,
  amount         BIGINT NOT NULL,
  reason         VARCHAR(120) NOT NULL,
  reference_type VARCHAR(40) NULL,
  reference_id   BIGINT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_point_transactions_user_created (user_id, created_at),
  UNIQUE KEY uk_point_transactions_reference (user_id, reference_type, reference_id),
  CONSTRAINT fk_point_transactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci');
