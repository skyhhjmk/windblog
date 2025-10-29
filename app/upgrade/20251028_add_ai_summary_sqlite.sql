-- Upgrade script: add ai_summary column to posts (SQLite)
ALTER TABLE posts
    ADD COLUMN ai_summary TEXT NULL;
