-- Upgrade script: add SEO fields to posts table (MySQL)
-- 为文章表添加自定义 SEO 字段，支持搜索引擎优化

ALTER TABLE posts
    ADD COLUMN seo_title VARCHAR(255) NULL COMMENT '自定义 SEO 标题，如果为空则使用文章标题',
    ADD COLUMN seo_description TEXT NULL COMMENT '自定义 SEO 描述，如果为空则使用摘要',
    ADD COLUMN seo_keywords VARCHAR(500) NULL COMMENT '自定义 SEO 关键词，逗号分隔';
