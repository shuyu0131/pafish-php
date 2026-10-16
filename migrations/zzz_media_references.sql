ALTER TABLE uploads ADD COLUMN url_hash BINARY(32) NULL;
ALTER TABLE uploads ADD INDEX idx_uploads_url_hash (url_hash);

CREATE TABLE IF NOT EXISTS media_references (
  source_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_hash BINARY(32) NOT NULL,
  source_key VARCHAR(512) COLLATE utf8mb4_bin NOT NULL,
  upload_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (source_type, source_hash, upload_id, role),
  KEY idx_media_references_upload (upload_id),
  CONSTRAINT fk_media_references_upload FOREIGN KEY (upload_id) REFERENCES uploads (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- A GET never rebuilds references. An administrator explicitly initializes them.
INSERT INTO extension_storage (extension_type, extension_name, data_key, value)
VALUES ('system', 'media_references', 'state', 'pending')
ON DUPLICATE KEY UPDATE data_key = VALUES(data_key);
