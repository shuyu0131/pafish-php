-- 核心字段职责注释；完整列定义与当前基线一致，重复执行不变。

ALTER TABLE `users`
  MODIFY COLUMN id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN username             VARCHAR(50)     NOT NULL COMMENT '登录用户名',
  MODIFY COLUMN nickname             VARCHAR(50)     NULL COMMENT '显示昵称',
  MODIFY COLUMN email                VARCHAR(255)    NOT NULL COMMENT '邮箱',
  MODIFY COLUMN password_hash        VARCHAR(255)    NOT NULL COMMENT '账号密码哈希',
  MODIFY COLUMN role                 VARCHAR(20)     NOT NULL DEFAULT 'USER' COMMENT 'ADMIN/EDITOR/USER，能力与内容归属另行校验',
  MODIFY COLUMN avatar_url           VARCHAR(500)    NULL COMMENT '用户头像地址',
  MODIFY COLUMN description          VARCHAR(500)    NULL COMMENT '描述',
  MODIFY COLUMN last_login_ip        VARCHAR(45)     NULL COMMENT '最近登录 IP',
  MODIFY COLUMN last_active_at       DATETIME        NULL COMMENT '最近活动时间',
  MODIFY COLUMN disabled             TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '账号是否禁用',
  MODIFY COLUMN reset_token          VARCHAR(64)     NULL COMMENT '旧找回凭据字段，生命周期另行审查',
  MODIFY COLUMN reset_token_expires  DATETIME        NULL COMMENT '旧找回凭据到期时间',
  MODIFY COLUMN created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  MODIFY COLUMN updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  COMMENT='用户账号、资料、角色与登录状态';

ALTER TABLE `posts`
  MODIFY COLUMN id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN title           VARCHAR(255)    NOT NULL COMMENT '标题',
  MODIFY COLUMN slug            VARCHAR(255)    NOT NULL COMMENT 'URL 别名，受唯一索引保护',
  MODIFY COLUMN excerpt         VARCHAR(500)    NOT NULL DEFAULT '' COMMENT '文章列表摘要',
  MODIFY COLUMN seo_title       VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '独立 SEO 标题；空时回退内容或栏目标题',
  MODIFY COLUMN meta_description VARCHAR(500)   NOT NULL DEFAULT '' COMMENT '独立 SEO 描述；空时使用既有自动描述',
  MODIFY COLUMN allow_comments  TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '逐篇评论开关；全站开关优先，关闭仍展示历史评论',
  MODIFY COLUMN content         MEDIUMTEXT      NOT NULL COMMENT '正文或组件内容',
  MODIFY COLUMN content_format  VARCHAR(16)     NOT NULL DEFAULT 'markdown' COMMENT 'markdown/html，HTML 写入需净化',
  MODIFY COLUMN cover_url       VARCHAR(500)    NULL COMMENT '内容或分类封面地址',
  MODIFY COLUMN status          VARCHAR(20)     NOT NULL DEFAULT 'DRAFT' COMMENT 'DRAFT/PENDING/CHANGES_REQUESTED/PUBLISHED/SCHEDULED，公开还需检查软删除和时间',
  MODIFY COLUMN published_at    DATETIME        NULL COMMENT '发布时间；公开查询允许为空、未来时间不公开',
  MODIFY COLUMN view_count      INT             NOT NULL DEFAULT 0 COMMENT '浏览次数',
  MODIFY COLUMN is_pinned       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否置顶',
  MODIFY COLUMN category_pinned TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否分类内置顶',
  MODIFY COLUMN password        VARCHAR(100)    NULL COMMENT '阅读密码哈希；旧明文兼容是否移除仍需核实',
  MODIFY COLUMN external_url    VARCHAR(500)    NULL COMMENT '外链条目目标地址',
  MODIFY COLUMN custom_fields   TEXT            NULL COMMENT '文章自定义字段 JSON，供模板详情等调用',
  MODIFY COLUMN like_count      INT             NOT NULL DEFAULT 0 COMMENT '点赞汇总计数',
  MODIFY COLUMN favorite_count  INT             NOT NULL DEFAULT 0 COMMENT '收藏汇总次数',
  MODIFY COLUMN deleted_at      DATETIME        NULL COMMENT '软删除时间；为空表示未删除，回收站保留媒体引用',
  MODIFY COLUMN author_id       BIGINT UNSIGNED NOT NULL COMMENT '内容作者 ID',
  MODIFY COLUMN category_id     BIGINT UNSIGNED NULL COMMENT '分类 ID',
  MODIFY COLUMN created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  MODIFY COLUMN updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  COMMENT='文章正文、发布状态、摘要、自定义字段及互动汇总';

ALTER TABLE `categories`
  MODIFY COLUMN id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN name        VARCHAR(100)    NOT NULL COMMENT '名称',
  MODIFY COLUMN slug        VARCHAR(100)    NOT NULL COMMENT 'URL 别名，受唯一索引保护',
  MODIFY COLUMN description VARCHAR(500)    NULL COMMENT '描述',
  MODIFY COLUMN seo_title   VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '独立 SEO 标题；空时回退内容或栏目标题',
  MODIFY COLUMN meta_description VARCHAR(500) NOT NULL DEFAULT '' COMMENT '独立 SEO 描述；空时使用既有自动描述',
  MODIFY COLUMN cover_url   VARCHAR(500)    NULL COMMENT '内容或分类封面地址',
  MODIFY COLUMN parent_id   BIGINT UNSIGNED NULL COMMENT '父记录 ID，具体关系见外键',
  MODIFY COLUMN sort_order  INT             NOT NULL DEFAULT 0 COMMENT '显示顺序',
  MODIFY COLUMN created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  COMMENT='分类及父子层级';

ALTER TABLE `tags`
  MODIFY COLUMN id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN name       VARCHAR(100)    NOT NULL COMMENT '名称',
  MODIFY COLUMN slug       VARCHAR(100)    NOT NULL COMMENT 'URL 别名，受唯一索引保护',
  MODIFY COLUMN description VARCHAR(500)   NOT NULL DEFAULT '' COMMENT '描述',
  MODIFY COLUMN seo_title VARCHAR(255)     NOT NULL DEFAULT '' COMMENT '独立 SEO 标题；空时回退内容或栏目标题',
  MODIFY COLUMN meta_description VARCHAR(500) NOT NULL DEFAULT '' COMMENT '独立 SEO 描述；空时使用既有自动描述',
  MODIFY COLUMN created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  COMMENT='标签';

ALTER TABLE `post_tags`
  MODIFY COLUMN post_id BIGINT UNSIGNED NOT NULL COMMENT '文章 ID',
  MODIFY COLUMN tag_id  BIGINT UNSIGNED NOT NULL COMMENT '标签 ID',
  COMMENT='文章与标签的多对多关系';

ALTER TABLE `pages`
  MODIFY COLUMN id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN title        VARCHAR(255)    NOT NULL COMMENT '标题',
  MODIFY COLUMN seo_title    VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '独立 SEO 标题；空时回退内容或栏目标题',
  MODIFY COLUMN meta_description VARCHAR(500) NOT NULL DEFAULT '' COMMENT '独立 SEO 描述；空时使用既有自动描述',
  MODIFY COLUMN slug         VARCHAR(255)    NOT NULL COMMENT 'URL 别名，受唯一索引保护',
  MODIFY COLUMN content      MEDIUMTEXT      NOT NULL COMMENT '正文或组件内容',
  MODIFY COLUMN content_format VARCHAR(16)    NOT NULL DEFAULT 'markdown' COMMENT 'markdown/html，HTML 写入需净化',
  MODIFY COLUMN status       VARCHAR(20)     NOT NULL DEFAULT 'DRAFT' COMMENT '所属业务状态',
  MODIFY COLUMN template     VARCHAR(50)     NOT NULL DEFAULT 'default' COMMENT '页面模板标识',
  MODIFY COLUMN published_at DATETIME        NULL COMMENT '发布时间；公开查询允许为空、未来时间不公开',
  MODIFY COLUMN created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  MODIFY COLUMN updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  COMMENT='独立页面';

ALTER TABLE `micro_statuses`
  MODIFY COLUMN id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN content      MEDIUMTEXT      NOT NULL COMMENT '正文或组件内容',
  MODIFY COLUMN content_format VARCHAR(16)   NOT NULL DEFAULT 'markdown' COMMENT 'markdown/html，HTML 写入需净化',
  MODIFY COLUMN media_json   TEXT            NULL COMMENT '微语媒体列表 JSON',
  MODIFY COLUMN status       VARCHAR(20)     NOT NULL DEFAULT 'DRAFT' COMMENT '所属业务状态',
  MODIFY COLUMN published_at DATETIME        NULL COMMENT '发布时间；公开查询允许为空、未来时间不公开',
  MODIFY COLUMN is_pinned    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否置顶',
  MODIFY COLUMN is_private   TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否仅自己可见',
  MODIFY COLUMN deleted_at   DATETIME        NULL COMMENT '软删除时间；为空表示未删除，回收站保留媒体引用',
  MODIFY COLUMN author_id    BIGINT UNSIGNED NOT NULL COMMENT '内容作者 ID',
  MODIFY COLUMN created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  MODIFY COLUMN updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  COMMENT='微语正文、图片与发布状态';

ALTER TABLE `links`
  MODIFY COLUMN id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN name        VARCHAR(100)    NOT NULL COMMENT '名称',
  MODIFY COLUMN url         VARCHAR(500)    NOT NULL COMMENT '媒体或链接地址',
  MODIFY COLUMN description VARCHAR(255)    NULL COMMENT '描述',
  MODIFY COLUMN icon_url    VARCHAR(500)    NULL COMMENT '友情链接图标地址',
  MODIFY COLUMN sort_order  INT             NOT NULL DEFAULT 0 COMMENT '显示顺序',
  MODIFY COLUMN visible     TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '前台是否显示',
  MODIFY COLUMN created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  COMMENT='友情链接';

ALTER TABLE `nav_items`
  MODIFY COLUMN id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN label       VARCHAR(100)    NOT NULL COMMENT '导航显示文字',
  MODIFY COLUMN url         VARCHAR(500)    NOT NULL COMMENT '媒体或链接地址',
  MODIFY COLUMN sort_order  INT             NOT NULL DEFAULT 0 COMMENT '显示顺序',
  MODIFY COLUMN visible     TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '前台是否显示',
  MODIFY COLUMN is_external TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '现有导航同时用它控制站外地址处理和新窗口，语义待拆分',
  MODIFY COLUMN created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  COMMENT='导航菜单';

ALTER TABLE `widgets`
  MODIFY COLUMN id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN type       VARCHAR(30)     NOT NULL COMMENT '所属业务类型',
  MODIFY COLUMN title      VARCHAR(100)    NULL COMMENT '标题',
  MODIFY COLUMN content    TEXT            NULL COMMENT '正文或组件内容',
  MODIFY COLUMN area       VARCHAR(50)     NOT NULL DEFAULT 'sidebar' COMMENT '组件显示区域',
  MODIFY COLUMN sort_order INT             NOT NULL DEFAULT 0 COMMENT '显示顺序',
  MODIFY COLUMN visible    TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '前台是否显示',
  MODIFY COLUMN created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  COMMENT='侧边栏组件';

ALTER TABLE `comments`
  MODIFY COLUMN id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN post_id      BIGINT UNSIGNED NOT NULL COMMENT '文章 ID',
  MODIFY COLUMN author_name  VARCHAR(100)    NOT NULL COMMENT '访客填写的评论昵称',
  MODIFY COLUMN author_email VARCHAR(255)    NOT NULL COMMENT '访客填写的邮箱，不公开返回',
  MODIFY COLUMN content      TEXT            NOT NULL COMMENT '正文或组件内容',
  MODIFY COLUMN status       VARCHAR(20)     NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING/APPROVED/REJECTED/SPAM/TRASH，隐藏级联子树，通过沿祖先',
  MODIFY COLUMN ip           VARCHAR(45)     NULL COMMENT '来源 IP',
  MODIFY COLUMN is_pinned    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否置顶',
  MODIFY COLUMN parent_id    BIGINT UNSIGNED NULL COMMENT '回复父评论，服务校验同文章、已审祖先与无环',
  MODIFY COLUMN user_id      BIGINT UNSIGNED NULL COMMENT '用户 ID',
  MODIFY COLUMN notify_reply TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否接收回复通知',
  MODIFY COLUMN created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  COMMENT='文章评论、回复与审核';

ALTER TABLE `settings`
  MODIFY COLUMN `key`   VARCHAR(191) NOT NULL COMMENT '配置项键名',
  MODIFY COLUMN `value` TEXT         NOT NULL COMMENT '站点核心设置；主题/插件数据使用 extension_storage',
  COMMENT='站点核心设置';

ALTER TABLE `extension_storage`
  MODIFY COLUMN extension_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'plugin/theme/system 命名空间类型',
  MODIFY COLUMN extension_name VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '扩展目录名或系统子域',
  MODIFY COLUMN data_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '命名空间内数据键；system/table_owners 内为完整物理表名',
  MODIFY COLUMN `value` MEDIUMTEXT NOT NULL COMMENT '配置、小数据、版本与归属；不是大型业务集合',
  MODIFY COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  COMMENT='所有扩展共用的配置、小数据、版本及业务表归属';

ALTER TABLE `email_codes`
  MODIFY COLUMN id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN email      VARCHAR(255)    NOT NULL COMMENT '邮箱',
  MODIFY COLUMN purpose    VARCHAR(20)     NOT NULL COMMENT '验证码用途',
  MODIFY COLUMN code       VARCHAR(255)    NOT NULL COMMENT '验证码的密码哈希，VARCHAR(255)，非明文',
  MODIFY COLUMN expires_at DATETIME        NOT NULL COMMENT '验证码到期时间',
  MODIFY COLUMN used       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否已消费，事务内一次消费',
  MODIFY COLUMN created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  COMMENT='按邮箱、用途隔离的一次性验证码；code 存哈希';

ALTER TABLE `uploads`
  MODIFY COLUMN id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN original_name VARCHAR(255)    NOT NULL COMMENT '媒体原始名称',
  MODIFY COLUMN url           VARCHAR(500)    NOT NULL COMMENT '媒体或链接地址',
  MODIFY COLUMN mime          VARCHAR(100)    NOT NULL COMMENT '媒体 MIME 类型',
  MODIFY COLUMN size          INT             NOT NULL COMMENT '文件字节数',
  MODIFY COLUMN width         INT             NULL COMMENT '图片宽度',
  MODIFY COLUMN height        INT             NULL COMMENT '图片高度',
  MODIFY COLUMN uploader_id   BIGINT UNSIGNED NULL COMMENT '上传用户 ID',
  MODIFY COLUMN url_hash      BINARY(32)      NULL COMMENT '标准化媒体地址的 SHA-256 二进制索引',
  MODIFY COLUMN usage_count   INT             NOT NULL DEFAULT 0 COMMENT '已登记引用数量，不保证任意插件都无引用',
  MODIFY COLUMN last_used_at  DATETIME        NULL COMMENT '最近引用登记时间',
  MODIFY COLUMN created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  COMMENT='媒体文件信息与引用统计';

ALTER TABLE `post_reactions`
  MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL COMMENT '用户 ID',
  MODIFY COLUMN post_id BIGINT UNSIGNED NOT NULL COMMENT '文章 ID',
  MODIFY COLUMN kind VARCHAR(16) NOT NULL COMMENT 'like/favorite；收藏要求登录',
  MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  COMMENT='登录用户的文章点赞、收藏明细';

ALTER TABLE `user_points`
  MODIFY COLUMN user_id    BIGINT UNSIGNED NOT NULL COMMENT '用户 ID',
  MODIFY COLUMN balance    BIGINT NOT NULL DEFAULT 0 COMMENT '积分余额，与流水合计保持一致',
  MODIFY COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近修改时间',
  COMMENT='用户积分余额';

ALTER TABLE `point_transactions`
  MODIFY COLUMN id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录主键',
  MODIFY COLUMN user_id        BIGINT UNSIGNED NOT NULL COMMENT '用户 ID',
  MODIFY COLUMN amount         BIGINT NOT NULL COMMENT '积分变化，增加为正、扣减为负',
  MODIFY COLUMN reason         VARCHAR(120) NOT NULL COMMENT '积分变动原因',
  MODIFY COLUMN reference_type VARCHAR(40) NULL COMMENT '积分业务引用类型，与引用 ID 共同保证幂等',
  MODIFY COLUMN reference_id   BIGINT UNSIGNED NULL COMMENT '积分业务引用 ID',
  MODIFY COLUMN created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  COMMENT='积分增减流水';

ALTER TABLE `media_references`
  MODIFY COLUMN source_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'post/page/micro/user/setting/extension/link/category/nav/widget',
  MODIFY COLUMN source_hash BINARY(32) NOT NULL COMMENT '完整来源键的 SHA-256 二进制索引',
  MODIFY COLUMN source_key VARCHAR(512) COLLATE utf8mb4_bin NOT NULL COMMENT '完整来源键，读取时同时校验',
  MODIFY COLUMN upload_id BIGINT UNSIGNED NOT NULL COMMENT '真实外键关联的附件 ID',
  MODIFY COLUMN role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '来源记录内字段；同正文重复地址去重，不同字段分别计数',
  MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  COMMENT='媒体引用关系：按来源记录和字段保护附件';

ALTER TABLE schema_migrations MODIFY COLUMN version VARCHAR(64) NOT NULL COMMENT '已执行迁移文件的版本标识', MODIFY COLUMN applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '迁移成功登记时间', COMMENT='核心数据库迁移记录';
