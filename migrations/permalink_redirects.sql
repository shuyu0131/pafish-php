CREATE TABLE IF NOT EXISTS permalink_redirects (
  old_path    VARCHAR(3072) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '已失效内容地址；URL 编码的站点内路径，不含部署子目录',
  old_path_hash BINARY(32) NOT NULL COMMENT '旧路径 SHA-256 索引键，避免依赖超长主键索引',
  target_path VARCHAR(3072) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '永久跳转目标路径',
  content_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'post/page/category/tag',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '别名创建时间',
  PRIMARY KEY (old_path_hash),
  KEY idx_permalink_redirect_target (target_path(191))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='内容别名永久跳转';
