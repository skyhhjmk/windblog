-- Upgrade script: add ai_summary column to posts (MySQL)
ALTER TABLE ` posts `
    ADD COLUMN ` ai_summary ` LONGTEXT NULL
COMMENT
'AI 摘要' AFTER `excerpt`;
