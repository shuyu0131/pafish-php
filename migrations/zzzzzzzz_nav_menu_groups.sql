CREATE TABLE IF NOT EXISTS nav_menus (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  slug       VARCHAR(100) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_nav_menus_slug (slug),
  KEY idx_nav_menus_primary (is_primary, sort_order, id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO nav_menus (id, name, slug, is_primary, sort_order)
VALUES (1, '主导航', 'main', 1, 0)
ON DUPLICATE KEY UPDATE name = VALUES(name), is_primary = 1;
ALTER TABLE nav_items ADD COLUMN menu_id BIGINT UNSIGNED NULL DEFAULT 1 COMMENT '所属菜单组' AFTER parent_id;
UPDATE nav_items SET menu_id = 1 WHERE menu_id IS NULL;
ALTER TABLE nav_items MODIFY COLUMN menu_id BIGINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '所属菜单组';
ALTER TABLE nav_items ADD KEY idx_nav_items_menu_parent_sort (menu_id, parent_id, sort_order, id);
ALTER TABLE nav_items ADD CONSTRAINT fk_nav_items_menu FOREIGN KEY (menu_id) REFERENCES nav_menus (id) ON DELETE CASCADE;
