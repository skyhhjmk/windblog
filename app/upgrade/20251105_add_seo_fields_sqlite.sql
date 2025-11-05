-- Upgrade script: add SEO fields to posts table (SQLite)
-- 为文章表添加自定义 SEO 字段，支持搜索引擎优化

ALTER TABLE posts
    ADD COLUMN seo_title VARCHAR(255) NULL;

ALTER TABLE posts
    ADD COLUMN seo_description TEXT NULL;

ALTER TABLE posts
    ADD COLUMN seo_keywords VARCHAR(500) NULL;
