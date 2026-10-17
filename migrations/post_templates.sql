-- 每篇文章可选主题布局；存量文章保持既有分类/默认模板解析。
ALTER TABLE posts ADD COLUMN template VARCHAR(40) NOT NULL DEFAULT 'default' COMMENT '文章模板标识；default 使用分类或默认布局' AFTER content_format;
