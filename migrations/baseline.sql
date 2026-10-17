-- ============================================================
-- pafish 博客 CMS（PHP 版）数据库结构
-- 数据库结构：BIGINT UNSIGNED 自增、utf8mb4、
-- 状态字段一律 VARCHAR + 应用层常量
-- 要求：MySQL 5.7.6+（ngram 全文解析器）或 8.0
--
-- 迁移基线：本文件必须与 app/install/schema.sql 内容一致
-- （build-release.php 会校验，不一致直接报错）。安装走 schema.sql 并把本
-- 基线标记为已应用；存量库升级时自动视为已应用；后续结构演进新增
-- migrations/ 下的语义增量文件（见 app/Services/Migrator.php）
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 用户
CREATE TABLE IF NOT EXISTS users (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  username             VARCHAR(50)     NOT NULL COMMENT '登录用户名',
  nickname             VARCHAR(50)     NULL COMMENT '显示昵称',
  email                VARCHAR(255)    NOT NULL COMMENT '邮箱',
  password_hash        VARCHAR(255)    NOT NULL COMMENT '账号密码哈希',
  role                 VARCHAR(20)     NOT NULL DEFAULT 'USER' COMMENT 'ADMIN/EDITOR/USER，能力与内容归属另行校验',
  avatar_url           VARCHAR(500)    NULL COMMENT '用户头像地址',
  description          VARCHAR(500)    NULL COMMENT '描述',
  last_login_ip        VARCHAR(45)     NULL COMMENT '最近登录 IP',
  last_active_at       DATETIME        NULL COMMENT '最近活动时间',
  disabled             TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '账号是否禁用',
  reset_token          VARCHAR(64)     NULL COMMENT '旧找回凭据字段，生命周期另行审查',
  reset_token_expires  DATETIME        NULL COMMENT '旧找回凭据到期时间',
  created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_username (username),
  UNIQUE KEY uk_users_email (email),
  KEY idx_users_reset_token (reset_token),
  KEY idx_users_last_active (last_active_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='用户账号、资料、角色与登录状态';

-- 原生管理客户端的逐用户、逐设备访问凭证；只保存不可逆哈希。
CREATE TABLE IF NOT EXISTS app_tokens (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '设备令牌主键',
  user_id      BIGINT UNSIGNED NOT NULL COMMENT '令牌所属用户；权限始终从用户当前角色读取',
  token_hash   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '随机 Bearer Token 的 SHA-256 哈希',
  device_name  VARCHAR(100) NOT NULL COMMENT '用户提供的设备标签',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '签发时间',
  expires_at   DATETIME NOT NULL COMMENT '过期时间',
  last_used_at DATETIME NULL COMMENT '最近使用时间',
  revoked_at   DATETIME NULL COMMENT '撤销时间',
  PRIMARY KEY (id),
  UNIQUE KEY uk_app_tokens_hash (token_hash),
  KEY idx_app_tokens_user_created (user_id, created_at, id),
  KEY idx_app_tokens_user_status (user_id, revoked_at, expires_at),
  CONSTRAINT fk_app_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='原生客户端逐设备访问令牌';

-- 文章
CREATE TABLE IF NOT EXISTS posts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  title           VARCHAR(255)    NOT NULL COMMENT '标题',
  slug            VARCHAR(255)    NOT NULL COMMENT 'URL 别名，受唯一索引保护',
  excerpt         VARCHAR(500)    NOT NULL DEFAULT '' COMMENT '文章列表摘要',
  seo_title       VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '独立 SEO 标题；空时回退内容或栏目标题',
  meta_description VARCHAR(500)   NOT NULL DEFAULT '' COMMENT '独立 SEO 描述；空时使用既有自动描述',
  allow_comments  TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '逐篇评论开关；全站开关优先，关闭仍展示历史评论',
  content         MEDIUMTEXT      NOT NULL COMMENT '正文或组件内容',
  content_format  VARCHAR(16)     NOT NULL DEFAULT 'markdown' COMMENT 'markdown/html，HTML 写入需净化',
  template        VARCHAR(40)     NOT NULL DEFAULT 'default' COMMENT '文章模板标识；default 使用分类或默认布局',
  cover_url       VARCHAR(500)    NULL COMMENT '内容或分类封面地址',
  status          VARCHAR(20)     NOT NULL DEFAULT 'DRAFT' COMMENT 'DRAFT/PUBLISHED/SCHEDULED，公开还需检查软删除和时间',
  published_at    DATETIME        NULL COMMENT '发布时间；公开查询允许为空、未来时间不公开',
  view_count      INT             NOT NULL DEFAULT 0 COMMENT '浏览次数',
  is_pinned       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否置顶',
  category_pinned TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否分类内置顶',
  password        VARCHAR(100)    NULL COMMENT '阅读密码哈希；旧明文兼容是否移除仍需核实',
  external_url    VARCHAR(500)    NULL COMMENT '外链条目目标地址',
  custom_fields   TEXT            NULL COMMENT '文章自定义字段 JSON，供模板详情等调用',
  like_count      INT             NOT NULL DEFAULT 0 COMMENT '点赞汇总计数',
  favorite_count  INT             NOT NULL DEFAULT 0 COMMENT '收藏汇总次数',
  deleted_at      DATETIME        NULL COMMENT '软删除时间；为空表示未删除，回收站保留媒体引用',
  author_id       BIGINT UNSIGNED NOT NULL COMMENT '内容作者 ID',
  category_id     BIGINT UNSIGNED NULL COMMENT '分类 ID',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  PRIMARY KEY (id),
  UNIQUE KEY uk_posts_slug (slug),
  KEY idx_posts_status_published (status, published_at),
  KEY idx_posts_public (status, deleted_at, is_pinned, published_at, id),
  KEY idx_posts_list (deleted_at, published_at, id),
  KEY idx_posts_author (author_id),
  KEY idx_posts_category (category_id, status, deleted_at, is_pinned, category_pinned, published_at, id),
  CONSTRAINT fk_posts_author   FOREIGN KEY (author_id)   REFERENCES users (id),
  CONSTRAINT fk_posts_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='文章正文、发布状态、摘要、自定义字段及互动汇总';

-- 分类（无限层级）
CREATE TABLE IF NOT EXISTS categories (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  name        VARCHAR(100)    NOT NULL COMMENT '名称',
  slug        VARCHAR(100)    NOT NULL COMMENT 'URL 别名，受唯一索引保护',
  description VARCHAR(500)    NULL COMMENT '描述',
  seo_title   VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '独立 SEO 标题；空时回退内容或栏目标题',
  meta_description VARCHAR(500) NOT NULL DEFAULT '' COMMENT '独立 SEO 描述；空时使用既有自动描述',
  cover_url   VARCHAR(500)    NULL COMMENT '内容或分类封面地址',
  parent_id   BIGINT UNSIGNED NULL COMMENT '父记录 ID，具体关系见外键',
  sort_order  INT             NOT NULL DEFAULT 0 COMMENT '显示顺序',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (id),
  UNIQUE KEY uk_categories_name (name),
  UNIQUE KEY uk_categories_slug (slug),
  KEY idx_categories_parent_sort (parent_id, sort_order),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='分类及父子层级';

-- 标签
CREATE TABLE IF NOT EXISTS tags (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  name       VARCHAR(100)    NOT NULL COMMENT '名称',
  slug       VARCHAR(100)    NOT NULL COMMENT 'URL 别名，受唯一索引保护',
  description VARCHAR(500)   NOT NULL DEFAULT '' COMMENT '描述',
  seo_title VARCHAR(255)     NOT NULL DEFAULT '' COMMENT '独立 SEO 标题；空时回退内容或栏目标题',
  meta_description VARCHAR(500) NOT NULL DEFAULT '' COMMENT '独立 SEO 描述；空时使用既有自动描述',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (id),
  UNIQUE KEY uk_tags_name (name),
  UNIQUE KEY uk_tags_slug (slug)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='标签';

-- 文章-标签（多对多）
CREATE TABLE IF NOT EXISTS post_tags (
  post_id BIGINT UNSIGNED NOT NULL COMMENT '文章 ID',
  tag_id  BIGINT UNSIGNED NOT NULL COMMENT '标签 ID',
  PRIMARY KEY (post_id, tag_id),
  KEY idx_post_tags_tag (tag_id),
  CONSTRAINT fk_post_tags_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
  CONSTRAINT fk_post_tags_tag  FOREIGN KEY (tag_id)  REFERENCES tags (id)  ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='文章与标签的多对多关系';

-- 独立页面（关于/说明等，可设为站点首页）
CREATE TABLE IF NOT EXISTS pages (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  title        VARCHAR(255)    NOT NULL COMMENT '标题',
  seo_title    VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '独立 SEO 标题；空时回退内容或栏目标题',
  meta_description VARCHAR(500) NOT NULL DEFAULT '' COMMENT '独立 SEO 描述；空时使用既有自动描述',
  slug         VARCHAR(255)    NOT NULL COMMENT 'URL 别名，受唯一索引保护',
  content      MEDIUMTEXT      NOT NULL COMMENT '正文或组件内容',
  content_format VARCHAR(16)    NOT NULL DEFAULT 'markdown' COMMENT 'markdown/html，HTML 写入需净化',
  status       VARCHAR(20)     NOT NULL DEFAULT 'DRAFT' COMMENT '所属业务状态',
  template     VARCHAR(50)     NOT NULL DEFAULT 'default' COMMENT '页面模板标识',
  published_at DATETIME        NULL COMMENT '发布时间；公开查询允许为空、未来时间不公开',
  deleted_at   DATETIME        NULL COMMENT '软删除时间；为空表示未删除',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  PRIMARY KEY (id),
  UNIQUE KEY uk_pages_slug (slug),
  KEY idx_pages_status_published (status, published_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='独立页面';

-- 微语（独立内容类型；第一版不复用文章评论/反应）
CREATE TABLE IF NOT EXISTS micro_statuses (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  content      MEDIUMTEXT      NOT NULL COMMENT '正文或组件内容',
  content_format VARCHAR(16)   NOT NULL DEFAULT 'markdown' COMMENT 'markdown/html，HTML 写入需净化',
  media_json   TEXT            NULL COMMENT '微语媒体列表 JSON',
  status       VARCHAR(20)     NOT NULL DEFAULT 'DRAFT' COMMENT '所属业务状态',
  published_at DATETIME        NULL COMMENT '发布时间；公开查询允许为空、未来时间不公开',
  is_pinned    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否置顶',
  is_private   TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否仅自己可见',
  deleted_at   DATETIME        NULL COMMENT '软删除时间；为空表示未删除，回收站保留媒体引用',
  author_id    BIGINT UNSIGNED NOT NULL COMMENT '内容作者 ID',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  PRIMARY KEY (id),
  KEY idx_micro_status_published (status, published_at),
  KEY idx_micro_author (author_id),
  KEY idx_micro_pinned_published (is_pinned, published_at),
  CONSTRAINT fk_micro_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='微语正文、图片与发布状态';

-- 友情链接
CREATE TABLE IF NOT EXISTS links (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  name        VARCHAR(100)    NOT NULL COMMENT '名称',
  url         VARCHAR(500)    NOT NULL COMMENT '媒体或链接地址',
  description VARCHAR(255)    NULL COMMENT '描述',
  icon_url    VARCHAR(500)    NULL COMMENT '友情链接图标地址',
  sort_order  INT             NOT NULL DEFAULT 0 COMMENT '显示顺序',
  visible     TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '前台是否显示',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='友情链接';

-- 导航菜单项
CREATE TABLE IF NOT EXISTS nav_items (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  label       VARCHAR(100)    NOT NULL COMMENT '导航显示文字',
  url         VARCHAR(500)    NOT NULL COMMENT '媒体或链接地址',
  sort_order  INT             NOT NULL DEFAULT 0 COMMENT '显示顺序',
  visible     TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '前台是否显示',
  is_external TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '现有导航同时用它控制站外地址处理和新窗口，语义待拆分',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (id),
  KEY idx_nav_items_sort (sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='导航菜单';

-- 侧边栏组件
CREATE TABLE IF NOT EXISTS widgets (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  type       VARCHAR(30)     NOT NULL COMMENT '所属业务类型',
  title      VARCHAR(100)    NULL COMMENT '标题',
  content    TEXT            NULL COMMENT '正文或组件内容',
  area       VARCHAR(50)     NOT NULL DEFAULT 'sidebar' COMMENT '组件显示区域',
  sort_order INT             NOT NULL DEFAULT 0 COMMENT '显示顺序',
  visible    TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '前台是否显示',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (id),
  KEY idx_widgets_sort (sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='侧边栏组件';

-- 评论（楼中楼自关联）
CREATE TABLE IF NOT EXISTS comments (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  post_id      BIGINT UNSIGNED NOT NULL COMMENT '文章 ID',
  author_name  VARCHAR(100)    NOT NULL COMMENT '访客填写的评论昵称',
  author_email VARCHAR(255)    NOT NULL COMMENT '访客填写的邮箱，不公开返回',
  content      TEXT            NOT NULL COMMENT '正文或组件内容',
  status       VARCHAR(20)     NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING/APPROVED/REJECTED/SPAM/TRASH，隐藏级联子树，通过沿祖先',
  ip           VARCHAR(45)     NULL COMMENT '来源 IP',
  is_pinned    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否置顶',
  parent_id    BIGINT UNSIGNED NULL COMMENT '回复父评论，服务校验同文章、已审祖先与无环',
  user_id      BIGINT UNSIGNED NULL COMMENT '用户 ID',
  notify_reply TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否接收回复通知',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (id),
  KEY idx_comments_post_status (post_id, status, parent_id, created_at, id),
  KEY idx_comments_status_created (status, created_at),
  CONSTRAINT fk_comments_post   FOREIGN KEY (post_id)   REFERENCES posts (id)     ON DELETE CASCADE,
  CONSTRAINT fk_comments_parent FOREIGN KEY (parent_id) REFERENCES comments (id)  ON DELETE CASCADE,
  CONSTRAINT fk_comments_user   FOREIGN KEY (user_id)   REFERENCES users (id)     ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='文章评论、回复与审核';

-- 站点核心设置；扩展配置与运行数据使用 extension_storage。
CREATE TABLE IF NOT EXISTS settings (
  `key`   VARCHAR(191) NOT NULL COMMENT '配置项键名',
  `value` TEXT         NOT NULL COMMENT '站点核心设置；主题/插件数据使用 extension_storage',
  PRIMARY KEY (`key`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='站点核心设置';

-- 扩展共用配置、小数据及业务表归属登记。
CREATE TABLE IF NOT EXISTS extension_storage (
  extension_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'plugin/theme/system 命名空间类型',
  extension_name VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '扩展目录名或系统子域',
  data_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '命名空间内数据键；system/table_owners 内为完整物理表名',
  `value` MEDIUMTEXT NOT NULL COMMENT '配置、小数据、版本与归属；不是大型业务集合',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  PRIMARY KEY (extension_type, extension_name, data_key)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='所有扩展共用的配置、小数据、版本及业务表归属';

-- 媒体库
CREATE TABLE IF NOT EXISTS uploads (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  original_name VARCHAR(255)    NOT NULL COMMENT '媒体原始名称',
  url           VARCHAR(500)    NOT NULL COMMENT '媒体或链接地址',
  mime          VARCHAR(100)    NOT NULL COMMENT '媒体 MIME 类型',
  size          INT             NOT NULL COMMENT '文件字节数',
  width         INT             NULL COMMENT '图片宽度',
  height        INT             NULL COMMENT '图片高度',
  uploader_id   BIGINT UNSIGNED NULL COMMENT '上传用户 ID',
  url_hash      BINARY(32)      NULL COMMENT '标准化媒体地址的 SHA-256 二进制索引',
  usage_count   INT             NOT NULL DEFAULT 0 COMMENT '已登记引用数量，不保证任意插件都无引用',
  last_used_at  DATETIME        NULL COMMENT '最近引用登记时间',
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (id),
  KEY idx_uploads_created (created_at),
  KEY idx_uploads_url_hash (url_hash),
  CONSTRAINT fk_uploads_uploader FOREIGN KEY (uploader_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='媒体文件信息与引用统计';

-- 登录用户的文章点赞与收藏（匿名点赞状态保存在服务端 Session）
CREATE TABLE IF NOT EXISTS post_reactions (
  user_id BIGINT UNSIGNED NOT NULL COMMENT '用户 ID',
  post_id BIGINT UNSIGNED NOT NULL COMMENT '文章 ID',
  kind VARCHAR(16) NOT NULL COMMENT 'like/favorite；收藏要求登录',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (user_id, post_id, kind),
  KEY idx_post_reactions_user_kind_created (user_id, kind, created_at),
  KEY idx_post_reactions_post_kind (post_id, kind),
  CONSTRAINT fk_post_reactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_post_reactions_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='登录用户的文章点赞、收藏明细';

-- 全文搜索索引（ngram 中文分词；MySQL 5.7.6+ / 8.0）
ALTER TABLE posts ADD FULLTEXT INDEX ft_posts_search (title, excerpt, content) WITH PARSER ngram;

-- 积分账本（核心用户能力）
CREATE TABLE IF NOT EXISTS user_points (
  user_id    BIGINT UNSIGNED NOT NULL COMMENT '用户 ID',
  balance    BIGINT NOT NULL DEFAULT 0 COMMENT '积分余额，与流水合计保持一致',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_points_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='用户积分余额';

CREATE TABLE IF NOT EXISTS point_transactions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  user_id        BIGINT UNSIGNED NOT NULL COMMENT '用户 ID',
  amount         BIGINT NOT NULL COMMENT '积分变化，增加为正、扣减为负',
  reason         VARCHAR(120) NOT NULL COMMENT '积分变动原因',
  reference_type VARCHAR(40) NULL COMMENT '积分业务引用类型，与引用 ID 共同保证幂等',
  reference_id   BIGINT UNSIGNED NULL COMMENT '积分业务引用 ID',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (id),
  KEY idx_point_transactions_user_created (user_id, created_at),
  UNIQUE KEY uk_point_transactions_reference (user_id, reference_type, reference_id),
  CONSTRAINT fk_point_transactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='积分增减流水';

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE IF NOT EXISTS media_references (
  source_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'post/page/micro/user/setting/extension/link/category/nav/widget',
  source_hash BINARY(32) NOT NULL COMMENT '完整来源键的 SHA-256 二进制索引',
  source_key VARCHAR(512) COLLATE utf8mb4_bin NOT NULL COMMENT '完整来源键，读取时同时校验',
  upload_id BIGINT UNSIGNED NOT NULL COMMENT '真实外键关联的附件 ID',
  role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '来源记录内字段；同正文重复地址去重，不同字段分别计数',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (source_type, source_hash, upload_id, role),
  KEY idx_media_references_upload (upload_id),
  CONSTRAINT fk_media_references_upload FOREIGN KEY (upload_id) REFERENCES uploads (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='媒体引用关系：按来源记录和字段保护附件';


INSERT INTO extension_storage (extension_type, extension_name, data_key, value) VALUES ('system', 'media_references', 'state', 'ready') ON DUPLICATE KEY UPDATE data_key = VALUES(data_key);

-- 核心实体与扩展命名空间的通用字段；多态关系由 EntityMeta 生命周期服务维护。
CREATE TABLE IF NOT EXISTS entity_meta (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  subject_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'post/page/user/comment/micro/category/tag 等核心实体类型',
  subject_id   BIGINT UNSIGNED NOT NULL COMMENT '所属实体 ID；由应用层校验存在并在删除时清理',
  scope        VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'core 或 plugin:name / theme:name 命名空间',
  meta_key     VARCHAR(191) NOT NULL COMMENT '字段键；允许同一键以 position 保留历史重复项',
  key_hash     BINARY(32) NOT NULL COMMENT 'meta_key SHA-256，用于紧凑复合索引',
  position     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '同键值顺序，支持旧文章自定义字段重复键',
  meta_value   MEDIUMTEXT NOT NULL COMMENT '字段值；数值范围查询需由声明类型和专用索引另行定义',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  PRIMARY KEY (id),
  UNIQUE KEY uk_entity_meta_slot (subject_type, subject_id, scope, key_hash, position),
  KEY idx_entity_meta_lookup (subject_type, scope, key_hash, subject_id),
  KEY idx_entity_meta_owner (scope, subject_type, subject_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT='核心实体扩展字段与字段归属';
