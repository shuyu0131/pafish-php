CREATE TABLE IF NOT EXISTS app_tokens (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '设备令牌主键',
  user_id      BIGINT UNSIGNED NOT NULL COMMENT '令牌所属用户；权限始终从用户当前角色读取',
  token_hash   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '随机 Bearer Token 的 SHA-256 哈希',
  device_name  VARCHAR(100) NOT NULL COMMENT '用户提供的设备标签',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '签发时间',
  expires_at   DATETIME NOT NULL COMMENT '过期时间',
  last_used_at DATETIME NULL COMMENT '最近使用时间',
  revoked_at   DATETIME NULL COMMENT '撤销时间',
  PRIMARY KEY (id),
  UNIQUE KEY uk_app_tokens_hash (token_hash),
  KEY idx_app_tokens_user_created (user_id, created_at, id),
  KEY idx_app_tokens_user_status (user_id, revoked_at, expires_at),
  CONSTRAINT fk_app_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='原生客户端逐设备访问令牌';
