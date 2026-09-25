ALTER TABLE users ADD COLUMN description VARCHAR(500) NULL AFTER avatar_url;
ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NULL AFTER description;
ALTER TABLE users ADD COLUMN last_active_at DATETIME NULL AFTER last_login_ip;
ALTER TABLE users ADD INDEX idx_users_last_active (last_active_at);
