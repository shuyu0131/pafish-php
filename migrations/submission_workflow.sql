-- 前台投稿审核反馈与审核记录。
ALTER TABLE posts ADD COLUMN review_note TEXT NULL AFTER status;
ALTER TABLE posts ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER review_note;
ALTER TABLE posts ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by;
