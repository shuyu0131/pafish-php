ALTER TABLE uploads ADD COLUMN usage_count INT NOT NULL DEFAULT 0 AFTER uploader_id;
ALTER TABLE uploads ADD COLUMN last_used_at DATETIME NULL AFTER usage_count;
