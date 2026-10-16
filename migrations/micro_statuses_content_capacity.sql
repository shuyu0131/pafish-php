-- 正文上限为 160000 字符，TEXT 的 65535 字节容量不足以保存合法中文正文。
ALTER TABLE micro_statuses MODIFY COLUMN content MEDIUMTEXT NOT NULL;
