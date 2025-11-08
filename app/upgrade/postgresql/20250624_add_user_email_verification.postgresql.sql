-- PostgreSQL 数据库升级脚本
-- 版本: 添加用户邮箱验证和OAuth字段
-- 日期: 2025-06-24
-- 说明: 为 wa_users 表添加邮箱验证、激活令牌和OAuth相关字段

-- 设置搜索路径，确保找到表
SET search_path TO public;

-- 检查并添加 email_verified_at 字段
DO
$body$
    BEGIN
        IF NOT EXISTS (SELECT 1
                       FROM information_schema.columns
                       WHERE table_schema = 'public'
                         AND table_name = 'wa_users'
                         AND column_name = 'email_verified_at') THEN
            ALTER TABLE public.wa_users
                ADD COLUMN email_verified_at TIMESTAMP WITH TIME ZONE DEFAULT NULL;
            COMMENT ON COLUMN public.wa_users.email_verified_at IS '邮箱验证时间';
            RAISE NOTICE 'Column email_verified_at added successfully.';
        ELSE
            RAISE NOTICE 'Column email_verified_at already exists.';
        END IF;
    END
$body$;

-- 检查并添加 activation_token 字段
DO
$body$
    BEGIN
        IF NOT EXISTS (SELECT 1
                       FROM information_schema.columns
                       WHERE table_schema = 'public'
                         AND table_name = 'wa_users'
                         AND column_name = 'activation_token') THEN
            ALTER TABLE public.wa_users
                ADD COLUMN activation_token VARCHAR(64) DEFAULT NULL;
            COMMENT ON COLUMN public.wa_users.activation_token IS '激活令牌';
            RAISE NOTICE 'Column activation_token added successfully.';
        ELSE
            RAISE NOTICE 'Column activation_token already exists.';
        END IF;
    END
$body$;

-- 检查并添加 activation_token_expires_at 字段
DO
$body$
    BEGIN
        IF NOT EXISTS (SELECT 1
                       FROM information_schema.columns
                       WHERE table_schema = 'public'
                         AND table_name = 'wa_users'
                         AND column_name = 'activation_token_expires_at') THEN
            ALTER TABLE public.wa_users
                ADD COLUMN activation_token_expires_at TIMESTAMP WITH TIME ZONE DEFAULT NULL;
            COMMENT ON COLUMN public.wa_users.activation_token_expires_at IS '激活令牌过期时间';
            RAISE NOTICE 'Column activation_token_expires_at added successfully.';
        ELSE
            RAISE NOTICE 'Column activation_token_expires_at already exists.';
        END IF;
    END
$body$;

-- 检查并添加 oauth_provider 字段
DO
$body$
    BEGIN
        IF NOT EXISTS (SELECT 1
                       FROM information_schema.columns
                       WHERE table_schema = 'public'
                         AND table_name = 'wa_users'
                         AND column_name = 'oauth_provider') THEN
            ALTER TABLE public.wa_users
                ADD COLUMN oauth_provider VARCHAR(50) DEFAULT NULL;
            COMMENT ON COLUMN public.wa_users.oauth_provider IS 'OAuth提供商(预留)';
            RAISE NOTICE 'Column oauth_provider added successfully.';
        ELSE
            RAISE NOTICE 'Column oauth_provider already exists.';
        END IF;
    END
$body$;

-- 检查并添加 oauth_id 字段
DO
$body$
    BEGIN
        IF NOT EXISTS (SELECT 1
                       FROM information_schema.columns
                       WHERE table_schema = 'public'
                         AND table_name = 'wa_users'
                         AND column_name = 'oauth_id') THEN
            ALTER TABLE public.wa_users
                ADD COLUMN oauth_id VARCHAR(255) DEFAULT NULL;
            COMMENT ON COLUMN public.wa_users.oauth_id IS 'OAuth用户ID(预留)';
            RAISE NOTICE 'Column oauth_id added successfully.';
        ELSE
            RAISE NOTICE 'Column oauth_id already exists.';
        END IF;
    END
$body$;

-- 检查并添加 email 索引
DO
$body$
    BEGIN
        IF NOT EXISTS (SELECT 1
                       FROM pg_indexes
                       WHERE schemaname = 'public'
                         AND tablename = 'wa_users'
                         AND indexname = 'idx_wa_users_email') THEN
            CREATE INDEX idx_wa_users_email ON public.wa_users (email);
            RAISE NOTICE 'Index idx_wa_users_email created successfully.';
        ELSE
            RAISE NOTICE 'Index idx_wa_users_email already exists.';
        END IF;
    END
$body$;

-- 检查并添加 activation_token 索引
DO
$body$
    BEGIN
        IF NOT EXISTS (SELECT 1
                       FROM pg_indexes
                       WHERE schemaname = 'public'
                         AND tablename = 'wa_users'
                         AND indexname = 'idx_wa_users_activation_token') THEN
            CREATE INDEX idx_wa_users_activation_token ON public.wa_users (activation_token);
            RAISE NOTICE 'Index idx_wa_users_activation_token created successfully.';
        ELSE
            RAISE NOTICE 'Index idx_wa_users_activation_token already exists.';
        END IF;
    END
$body$;

-- 检查并添加 OAuth 组合索引
DO
$body$
    BEGIN
        IF NOT EXISTS (SELECT 1
                       FROM pg_indexes
                       WHERE schemaname = 'public'
                         AND tablename = 'wa_users'
                         AND indexname = 'idx_wa_users_oauth') THEN
            CREATE INDEX idx_wa_users_oauth ON public.wa_users (oauth_provider, oauth_id);
            RAISE NOTICE 'Index idx_wa_users_oauth created successfully.';
        ELSE
            RAISE NOTICE 'Index idx_wa_users_oauth already exists.';
        END IF;
    END
$body$;

-- 完成提示
SELECT 'User email verification and OAuth fields upgrade completed successfully!' AS result;
