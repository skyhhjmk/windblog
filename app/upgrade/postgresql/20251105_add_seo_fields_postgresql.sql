-- Upgrade script: add SEO fields to posts table (PostgreSQL)
-- 为文章表添加自定义 SEO 字段，支持搜索引擎优化

ALTER TABLE posts
    ADD COLUMN IF NOT EXISTS seo_title VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS seo_description TEXT NULL,
    ADD COLUMN IF NOT EXISTS seo_keywords VARCHAR(500) NULL;

COMMENT ON COLUMN posts.seo_title IS '自定义 SEO 标题，如果为空则使用文章标题';
COMMENT ON COLUMN posts.seo_description IS '自定义 SEO 描述，如果为空则使用摘要';
COMMENT ON COLUMN posts.seo_keywords IS '自定义 SEO 关键词，逗号分隔';
