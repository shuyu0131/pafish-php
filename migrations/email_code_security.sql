-- 放宽为密码哈希容量；旧明文验证码不再被接受，重新发送即可。
ALTER TABLE email_codes MODIFY COLUMN code VARCHAR(255) NOT NULL COMMENT '验证码密码哈希';
ALTER TABLE email_codes ADD KEY idx_email_codes_expires (expires_at);
