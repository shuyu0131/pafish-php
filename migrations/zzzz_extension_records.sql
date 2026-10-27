CREATE TABLE IF NOT EXISTS extension_records (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键与稳定分页游标',
  extension_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'plugin/theme 数据归属类型',
  extension_name VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '扩展目录名',
  model_name     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '扩展内逻辑模型名',
  record_key     VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '模型内稳定记录键',
  payload        MEDIUMTEXT NOT NULL COMMENT '经核心校验的 JSON 对象，单条上限 256 KiB',
  revision       BIGINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '每次更新递增，用于乐观并发控制',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  PRIMARY KEY (id),
  UNIQUE KEY uk_extension_records_identity (extension_type, extension_name, model_name, record_key),
  KEY idx_extension_records_page (extension_type, extension_name, model_name, id),
  KEY idx_extension_records_owner (extension_type, extension_name, id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='扩展命名空间内的共用结构化记录';
