ALTER TABLE comments
  ADD COLUMN author_url VARCHAR(500) NOT NULL DEFAULT '' COMMENT '访客填写的网址，公开展示前仅允许 http/https' AFTER author_email;
