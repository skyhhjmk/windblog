-- Upgrade script: add ai_summary column to posts (PostgreSQL)
ALTER TABLE posts
    ADD COLUMN ai_summary TEXT NULL;
