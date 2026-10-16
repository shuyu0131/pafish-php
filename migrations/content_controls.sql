-- Existing contents remain open to comments. Empty SEO fields retain generated metadata.
-- Migrator checks ADD COLUMN individually, so interrupted upgrades can be retried.
ALTER TABLE posts ADD COLUMN seo_title VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'SEO 标题；空值使用文章标题';
ALTER TABLE posts ADD COLUMN meta_description VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'SEO 描述；空值使用摘要';
ALTER TABLE posts ADD COLUMN allow_comments TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否接受新评论；仍受全站开关控制';
ALTER TABLE pages ADD COLUMN seo_title VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'SEO 标题；空值使用页面标题';
ALTER TABLE pages ADD COLUMN meta_description VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'SEO 描述；空值从正文提取';
ALTER TABLE categories ADD COLUMN seo_title VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'SEO 标题；空值使用分类名称';
ALTER TABLE categories ADD COLUMN meta_description VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'SEO 描述；空值使用分类描述';
ALTER TABLE categories ADD COLUMN cover_url VARCHAR(500) NULL COMMENT '分类封面地址';
ALTER TABLE tags ADD COLUMN description VARCHAR(500) NOT NULL DEFAULT '' COMMENT '标签页面描述';
ALTER TABLE tags ADD COLUMN seo_title VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'SEO 标题；空值使用标签名称';
ALTER TABLE tags ADD COLUMN meta_description VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'SEO 描述；空值使用标签描述';
ALTER TABLE links ADD COLUMN icon_url VARCHAR(500) NULL COMMENT '友链图标地址';
