ALTER TABLE nav_items ADD COLUMN parent_id BIGINT UNSIGNED NULL COMMENT '父菜单项 ID；NULL 表示顶级' AFTER is_external;
ALTER TABLE nav_items ADD COLUMN category_id BIGINT UNSIGNED NULL COMMENT '关联分类；NULL 表示自定义链接' AFTER parent_id;
ALTER TABLE nav_items ADD KEY idx_nav_items_parent_sort (parent_id, sort_order, id);
ALTER TABLE nav_items ADD KEY idx_nav_items_category (category_id);
ALTER TABLE nav_items ADD CONSTRAINT fk_nav_items_parent FOREIGN KEY (parent_id) REFERENCES nav_items (id) ON DELETE SET NULL;
ALTER TABLE nav_items ADD CONSTRAINT fk_nav_items_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE;
