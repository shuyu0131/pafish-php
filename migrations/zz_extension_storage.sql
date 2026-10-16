-- 在旧 theme_settings 等迁移之后运行；仅在已核对值完全一致后移除旧键。
CREATE TABLE IF NOT EXISTS extension_storage (
  extension_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'plugin/theme/system',
  extension_name VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '扩展目录名',
  data_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '数据键或已声明表的归属',
  `value` MEDIUMTEXT NOT NULL COMMENT '配置、私有数据或元数据，不存整套业务记录',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (extension_type, extension_name, data_key)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '所有扩展共用的配置与小数据';

START TRANSACTION;

INSERT INTO extension_storage (extension_type, extension_name, data_key, `value`)
SELECT
  CASE WHEN SUBSTRING_INDEX(s.`key`, ':', 1) IN ('plugin_data','plugin_settings') THEN 'plugin' WHEN SUBSTRING_INDEX(s.`key`, ':', 1) IN ('theme_data','theme_settings') THEN 'theme' ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(s.`key`, ':', 2), ':', -1) END,
  CASE WHEN SUBSTRING_INDEX(s.`key`, ':', 1) IN ('extension_version','extension_schema_fingerprint') THEN SUBSTRING_INDEX(s.`key`, ':', -1) ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(s.`key`, ':', 2), ':', -1) END,
  CASE WHEN SUBSTRING_INDEX(s.`key`, ':', 1) IN ('plugin_data','theme_data') THEN 'data' WHEN SUBSTRING_INDEX(s.`key`, ':', 1) = 'plugin_settings' THEN 'settings' WHEN SUBSTRING_INDEX(s.`key`, ':', 1) = 'theme_settings' THEN CONCAT('setting:', SUBSTRING(s.`key`, LOCATE(':', s.`key`, LOCATE(':', s.`key`) + 1) + 1)) WHEN SUBSTRING_INDEX(s.`key`, ':', 1) = 'extension_version' THEN 'version' ELSE 'schema_fingerprint' END,
  s.`value`
FROM settings s
WHERE s.`key` COLLATE utf8mb4_bin REGEXP '^((plugin_data|theme_data|plugin_settings):[a-z0-9_-]{1,50}|theme_settings:[a-z0-9_-]{1,50}:[ -~]{1,128}|extension_(version|schema_fingerprint):(plugin|theme):[a-z0-9_-]{1,50})$'
AND RIGHT(s.`key`, 1) NOT IN (CHAR(10), CHAR(13))
ON DUPLICATE KEY UPDATE data_key = VALUES(data_key);

DELETE s FROM settings s JOIN extension_storage e
ON BINARY e.extension_type = BINARY CASE WHEN SUBSTRING_INDEX(s.`key`, ':', 1) IN ('plugin_data','plugin_settings') THEN 'plugin' WHEN SUBSTRING_INDEX(s.`key`, ':', 1) IN ('theme_data','theme_settings') THEN 'theme' ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(s.`key`, ':', 2), ':', -1) END
AND BINARY e.extension_name = BINARY CASE WHEN SUBSTRING_INDEX(s.`key`, ':', 1) IN ('extension_version','extension_schema_fingerprint') THEN SUBSTRING_INDEX(s.`key`, ':', -1) ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(s.`key`, ':', 2), ':', -1) END
AND BINARY e.data_key = BINARY CASE WHEN SUBSTRING_INDEX(s.`key`, ':', 1) IN ('plugin_data','theme_data') THEN 'data' WHEN SUBSTRING_INDEX(s.`key`, ':', 1) = 'plugin_settings' THEN 'settings' WHEN SUBSTRING_INDEX(s.`key`, ':', 1) = 'theme_settings' THEN CONCAT('setting:', SUBSTRING(s.`key`, LOCATE(':', s.`key`, LOCATE(':', s.`key`) + 1) + 1)) WHEN SUBSTRING_INDEX(s.`key`, ':', 1) = 'extension_version' THEN 'version' ELSE 'schema_fingerprint' END
WHERE s.`key` COLLATE utf8mb4_bin REGEXP '^((plugin_data|theme_data|plugin_settings):[a-z0-9_-]{1,50}|theme_settings:[a-z0-9_-]{1,50}:[ -~]{1,128}|extension_(version|schema_fingerprint):(plugin|theme):[a-z0-9_-]{1,50})$'
AND RIGHT(s.`key`, 1) NOT IN (CHAR(10), CHAR(13))
AND BINARY e.`value` = BINARY s.`value`;

COMMIT;
