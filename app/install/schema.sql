-- ============================================================
-- pafish 博客 CMS（PHP 版）数据库结构
-- 数据库结构：BIGINT UNSIGNED 自增、utf8mb4、
-- 状态字段一律 VARCHAR + 应用层常量
-- 要求：MySQL 5.7.6+（ngram 全文解析器）或 8.0
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 用户
CREATE TABLE IF NOT EXISTS users (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username             VARCHAR(50)     NOT NULL,
  nickname             VARCHAR(50)     NULL,
  email                VARCHAR(255)    NOT NULL,
  password_hash        VARCHAR(255)    NOT NULL,
  role                 VARCHAR(20)     NOT NULL DEFAULT 'USER',
  avatar_url           VARCHAR(500)    NULL,
  disabled             TINYINT(1)      NOT NULL DEFAULT 0,
  reset_token          VARCHAR(64)     NULL,
  reset_token_expires  DATETIME        NULL,
  created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_username (username),
  UNIQUE KEY uk_users_email (email),
  KEY idx_users_reset_token (reset_token)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 文章
CREATE TABLE IF NOT EXISTS posts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title           VARCHAR(255)    NOT NULL,
  slug            VARCHAR(255)    NOT NULL,
  excerpt         VARCHAR(500)    NOT NULL DEFAULT '',
  content         MEDIUMTEXT      NOT NULL,
  cover_url       VARCHAR(500)    NULL,
  status          VARCHAR(20)     NOT NULL DEFAULT 'DRAFT',
  published_at    DATETIME        NULL,
  view_count      INT             NOT NULL DEFAULT 0,
  is_pinned       TINYINT(1)      NOT NULL DEFAULT 0,
  category_pinned TINYINT(1)      NOT NULL DEFAULT 0,
  password        VARCHAR(100)    NULL,
  external_url    VARCHAR(500)    NULL,
  custom_fields   TEXT            NULL,
  like_count      INT             NOT NULL DEFAULT 0,
  favorite_count  INT             NOT NULL DEFAULT 0,
  deleted_at      DATETIME        NULL,
  author_id       BIGINT UNSIGNED NOT NULL,
  category_id     BIGINT UNSIGNED NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_posts_slug (slug),
  KEY idx_posts_status_published (status, published_at),
  KEY idx_posts_author (author_id),
  KEY idx_posts_category (category_id),
  CONSTRAINT fk_posts_author   FOREIGN KEY (author_id)   REFERENCES users (id),
  CONSTRAINT fk_posts_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 分类（无限层级）
CREATE TABLE IF NOT EXISTS categories (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100)    NOT NULL,
  slug        VARCHAR(100)    NOT NULL,
  description VARCHAR(500)    NULL,
  parent_id   BIGINT UNSIGNED NULL,
  sort_order  INT             NOT NULL DEFAULT 0,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_categories_name (name),
  UNIQUE KEY uk_categories_slug (slug),
  KEY idx_categories_parent_sort (parent_id, sort_order),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 标签
CREATE TABLE IF NOT EXISTS tags (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100)    NOT NULL,
  slug       VARCHAR(100)    NOT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_tags_name (name),
  UNIQUE KEY uk_tags_slug (slug)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 文章-标签（多对多）
CREATE TABLE IF NOT EXISTS post_tags (
  post_id BIGINT UNSIGNED NOT NULL,
  tag_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, tag_id),
  KEY idx_post_tags_tag (tag_id),
  CONSTRAINT fk_post_tags_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
  CONSTRAINT fk_post_tags_tag  FOREIGN KEY (tag_id)  REFERENCES tags (id)  ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 独立页面（关于/说明等，可设为站点首页）
CREATE TABLE IF NOT EXISTS pages (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title        VARCHAR(255)    NOT NULL,
  slug         VARCHAR(255)    NOT NULL,
  content      MEDIUMTEXT      NOT NULL,
  status       VARCHAR(20)     NOT NULL DEFAULT 'DRAFT',
  template     VARCHAR(50)     NOT NULL DEFAULT 'default',
  published_at DATETIME        NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pages_slug (slug),
  KEY idx_pages_status_published (status, published_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 友情链接
CREATE TABLE IF NOT EXISTS links (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100)    NOT NULL,
  url         VARCHAR(500)    NOT NULL,
  description VARCHAR(255)    NULL,
  sort_order  INT             NOT NULL DEFAULT 0,
  visible     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 导航菜单项
CREATE TABLE IF NOT EXISTS nav_items (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  label       VARCHAR(100)    NOT NULL,
  url         VARCHAR(500)    NOT NULL,
  sort_order  INT             NOT NULL DEFAULT 0,
  visible     TINYINT(1)      NOT NULL DEFAULT 1,
  is_external TINYINT(1)      NOT NULL DEFAULT 0,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nav_items_sort (sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 侧边栏组件
CREATE TABLE IF NOT EXISTS widgets (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type       VARCHAR(30)     NOT NULL,
  title      VARCHAR(100)    NULL,
  content    TEXT            NULL,
  sort_order INT             NOT NULL DEFAULT 0,
  visible    TINYINT(1)      NOT NULL DEFAULT 1,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_widgets_sort (sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 评论（楼中楼自关联）
CREATE TABLE IF NOT EXISTS comments (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id      BIGINT UNSIGNED NOT NULL,
  author_name  VARCHAR(100)    NOT NULL,
  author_email VARCHAR(255)    NOT NULL,
  content      TEXT            NOT NULL,
  status       VARCHAR(20)     NOT NULL DEFAULT 'PENDING',
  ip           VARCHAR(45)     NULL,
  is_pinned    TINYINT(1)      NOT NULL DEFAULT 0,
  parent_id    BIGINT UNSIGNED NULL,
  user_id      BIGINT UNSIGNED NULL,
  notify_reply TINYINT(1)      NOT NULL DEFAULT 0,
  like_count   INT             NOT NULL DEFAULT 0,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_comments_post_status (post_id, status),
  KEY idx_comments_status_created (status, created_at),
  CONSTRAINT fk_comments_post   FOREIGN KEY (post_id)   REFERENCES posts (id)     ON DELETE CASCADE,
  CONSTRAINT fk_comments_parent FOREIGN KEY (parent_id) REFERENCES comments (id)  ON DELETE CASCADE,
  CONSTRAINT fk_comments_user   FOREIGN KEY (user_id)   REFERENCES users (id)     ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 全站设置（键值表：站点/主题/插件数据都存这里）
CREATE TABLE IF NOT EXISTS settings (
  `key`   VARCHAR(100) NOT NULL,
  `value` TEXT         NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 邮箱验证码（注册/忘记密码）
CREATE TABLE IF NOT EXISTS email_codes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(255)    NOT NULL,
  purpose    VARCHAR(20)     NOT NULL,
  code       VARCHAR(10)     NOT NULL,
  expires_at DATETIME        NOT NULL,
  used       TINYINT(1)      NOT NULL DEFAULT 0,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_codes_email_purpose (email, purpose)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 媒体库
CREATE TABLE IF NOT EXISTS uploads (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  original_name VARCHAR(255)    NOT NULL,
  url           VARCHAR(500)    NOT NULL,
  mime          VARCHAR(100)    NOT NULL,
  size          INT             NOT NULL,
  width         INT             NULL,
  height        INT             NULL,
  uploader_id   BIGINT UNSIGNED NULL,
  usage_count   INT             NOT NULL DEFAULT 0,
  last_used_at  DATETIME        NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_uploads_created (created_at),
  CONSTRAINT fk_uploads_uploader FOREIGN KEY (uploader_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 站内通知（新评论/新回复）
CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type       VARCHAR(20)     NOT NULL,
  message    VARCHAR(255)    NOT NULL,
  post_id    BIGINT UNSIGNED NULL,
  comment_id BIGINT UNSIGNED NULL,
  `read`     TINYINT(1)      NOT NULL DEFAULT 0,
  created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_notifications_read (`read`),
  KEY idx_notifications_created (created_at),
  CONSTRAINT fk_notifications_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 全文搜索索引（ngram 中文分词；MySQL 5.7.6+ / 8.0）
ALTER TABLE posts ADD FULLTEXT INDEX ft_posts_search (title, excerpt, content) WITH PARSER ngram;

-- 积分账本（核心用户能力）
CREATE TABLE IF NOT EXISTS user_points (
  user_id    BIGINT UNSIGNED NOT NULL,
  balance    BIGINT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_points_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS point_transactions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,
  amount         BIGINT NOT NULL,
  reason         VARCHAR(120) NOT NULL,
  reference_type VARCHAR(40) NULL,
  reference_id   BIGINT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_point_transactions_user_created (user_id, created_at),
  UNIQUE KEY uk_point_transactions_reference (user_id, reference_type, reference_id),
  CONSTRAINT fk_point_transactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 积分红包数据表
CREATE TABLE IF NOT EXISTS redpackets (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id         BIGINT UNSIGNED NOT NULL,
  creator_id      BIGINT UNSIGNED NOT NULL,
  mode            VARCHAR(10) NOT NULL DEFAULT 'random',
  total_points    BIGINT UNSIGNED NOT NULL,
  remaining_points BIGINT UNSIGNED NOT NULL,
  total_count     INT UNSIGNED NOT NULL,
  remaining_count INT UNSIGNED NOT NULL,
  title           VARCHAR(120) NOT NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'OPEN',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_redpackets_post (post_id),
  KEY idx_redpackets_creator (creator_id),
  CONSTRAINT fk_redpackets_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
  CONSTRAINT fk_redpackets_creator FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redpacket_claims (
  packet_id  BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  amount     BIGINT UNSIGNED NOT NULL,
  claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (packet_id, user_id),
  KEY idx_redpacket_claims_user (user_id, claimed_at),
  CONSTRAINT fk_redpacket_claims_packet FOREIGN KEY (packet_id) REFERENCES redpackets (id) ON DELETE CASCADE,
  CONSTRAINT fk_redpacket_claims_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
