-- 通知接收人：非管理员只能读取发给自己账户的通知。
ALTER TABLE notifications
  ADD COLUMN recipient_id BIGINT UNSIGNED NULL AFTER comment_id;

ALTER TABLE notifications
  ADD KEY idx_notifications_recipient_read_created (recipient_id, `read`, created_at);

ALTER TABLE notifications
  ADD CONSTRAINT fk_notifications_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE SET NULL;

-- 历史评论通知默认归属到关联文章作者。
UPDATE notifications n
JOIN posts p ON p.id = n.post_id
SET n.recipient_id = p.author_id
WHERE n.recipient_id IS NULL;
