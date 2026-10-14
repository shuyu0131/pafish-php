-- 扩展设置键包含主题名与字段名；完整合法键最长 146 字符。
ALTER TABLE settings MODIFY COLUMN `key` VARCHAR(191) NOT NULL;
