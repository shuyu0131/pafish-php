-- Replace the existing category index; retain its name and category foreign key.
-- One atomic ALTER; a retry after DDL completed only records the migration version.
ALTER TABLE posts DROP INDEX idx_posts_category, ADD INDEX idx_posts_category (category_id, status, deleted_at, is_pinned, category_pinned, published_at, id);
