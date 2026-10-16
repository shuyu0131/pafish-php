CREATE TABLE IF NOT EXISTS entity_meta (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  subject_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '核心实体类型',
  subject_id   BIGINT UNSIGNED NOT NULL COMMENT '所属实体 ID，由应用层维护关系',
  scope        VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'core 或 plugin:name / theme:name 命名空间',
  meta_key     VARCHAR(191) NOT NULL COMMENT '字段键',
  key_hash     BINARY(32) NOT NULL COMMENT 'meta_key SHA-256 紧凑索引',
  position     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '同键值顺序',
  meta_value   MEDIUMTEXT NOT NULL COMMENT '字段值',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  PRIMARY KEY (id),
  UNIQUE KEY uk_entity_meta_slot (subject_type, subject_id, scope, key_hash, position),
  KEY idx_entity_meta_lookup (subject_type, scope, key_hash, subject_id),
  KEY idx_entity_meta_owner (scope, subject_type, subject_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='核心实体扩展字段与字段归属';
