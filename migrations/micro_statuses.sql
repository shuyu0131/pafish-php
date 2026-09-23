-- 微语核心内容类型（存量库增量迁移）
CREATE TABLE IF NOT EXISTS micro_statuses (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  content      TEXT            NOT NULL,
  media_json   TEXT            NULL,
  status       VARCHAR(20)     NOT NULL DEFAULT 'DRAFT',
  published_at DATETIME        NULL,
  is_pinned    TINYINT(1)      NOT NULL DEFAULT 0,
  is_private   TINYINT(1)      NOT NULL DEFAULT 0,
  deleted_at   DATETIME        NULL,
  author_id    BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_micro_status_published (status, published_at),
  KEY idx_micro_author (author_id),
  KEY idx_micro_pinned_published (is_pinned, published_at),
  CONSTRAINT fk_micro_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
