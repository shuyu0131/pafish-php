-- Measured public/admin post ordering and per-post recent-comment lookup.
-- Indexes only: no content changes. DDL implicitly commits; back up before upgrading.
ALTER TABLE posts ADD INDEX idx_posts_public (status, deleted_at, is_pinned, published_at, id);
ALTER TABLE posts ADD INDEX idx_posts_list (deleted_at, published_at, id);
ALTER TABLE comments DROP INDEX idx_comments_post_status, ADD INDEX idx_comments_post_status (post_id, status, parent_id, created_at, id);
