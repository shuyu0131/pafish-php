ALTER TABLE pages
  ADD COLUMN deleted_at DATETIME NULL COMMENT '软删除时间；为空表示未删除' AFTER published_at;
