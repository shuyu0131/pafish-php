-- 在 micro_statuses 建表后执行，旧数据默认 Markdown。
ALTER TABLE micro_statuses ADD COLUMN content_format VARCHAR(16) NOT NULL DEFAULT 'markdown' AFTER content;
