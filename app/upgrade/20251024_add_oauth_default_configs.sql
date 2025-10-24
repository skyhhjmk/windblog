-- OAuth配置初始化脚本（通用）
-- 为settings表插入默认的OAuth配置

-- GitHub OAuth 配置
INSERT INTO ` settings ` (` key `, ` value `, ` group `, ` created_at `, ` updated_at `)
VALUES ('oauth_github',
        '{"enabled":false,"client_id":"","client_secret":"","name":"GitHub","icon":"fab fa-github","color":"#333"}',
        'oauth', NOW(), NOW()) ON DUPLICATE KEY
UPDATE ` key ` = ` key `;

-- Google OAuth 配置
INSERT INTO ` settings ` (` key `, ` value `, ` group `, ` created_at `, ` updated_at `)
VALUES ('oauth_google',
        '{"enabled":false,"client_id":"","client_secret":"","name":"Google","icon":"fab fa-google","color":"#DB4437"}',
        'oauth', NOW(), NOW()) ON DUPLICATE KEY
UPDATE ` key ` = ` key `;

-- 微信 OAuth 配置
INSERT INTO ` settings ` (` key `, ` value `, ` group `, ` created_at `, ` updated_at `)
VALUES ('oauth_wechat',
        '{"enabled":false,"app_id":"","app_secret":"","name":"微信","icon":"fab fa-weixin","color":"#07C160"}', 'oauth',
        NOW(), NOW()) ON DUPLICATE KEY
UPDATE ` key ` = ` key `;

-- QQ OAuth 配置
INSERT INTO ` settings ` (` key `, ` value `, ` group `, ` created_at `, ` updated_at `)
VALUES ('oauth_qq', '{"enabled":false,"app_id":"","app_key":"","name":"QQ","icon":"fab fa-qq","color":"#12B7F5"}',
        'oauth', NOW(), NOW()) ON DUPLICATE KEY
UPDATE ` key ` = ` key `;

-- 完成提示
SELECT 'OAuth default configurations added successfully!' AS result;
