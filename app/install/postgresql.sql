-- PostgreSQL数据库初始化脚本
-- 设置数据库字符集
SET client_encoding = 'UTF8';

-- 创建用户表
CREATE TABLE IF NOT EXISTS wa_users
(
    id                          BIGSERIAL PRIMARY KEY,
    username                    VARCHAR(32)    NOT NULL,
    nickname                    VARCHAR(40)    NOT NULL,
    password                    VARCHAR(255)   NOT NULL,
    sex                         VARCHAR(1)     NOT NULL  DEFAULT '1',
    avatar                      VARCHAR(255)             DEFAULT NULL,
    email                       VARCHAR(128)             DEFAULT NULL,
    mobile                      VARCHAR(16)              DEFAULT NULL,
    level                       INTEGER        NOT NULL  DEFAULT 0,
    birthday                    DATE                     DEFAULT NULL,
    money                       DECIMAL(10, 2) NOT NULL  DEFAULT 0.00,
    score                       INTEGER        NOT NULL  DEFAULT 0,
    last_time                   TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    last_ip                     VARCHAR(50)              DEFAULT NULL,
    join_time                   TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    join_ip                     VARCHAR(50)              DEFAULT NULL,
    token                       VARCHAR(50)              DEFAULT NULL,
    email_verified_at           TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    activation_token            VARCHAR(64)              DEFAULT NULL,
    activation_token_expires_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    created_at                  TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    deleted_at                  TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    "role"                      INTEGER        NOT NULL  DEFAULT 1,
    status                      INTEGER        NOT NULL  DEFAULT 0,
    UNIQUE (username)
);

CREATE INDEX IF NOT EXISTS idx_wa_users_email ON wa_users (email);
CREATE INDEX IF NOT EXISTS idx_wa_users_activation_token ON wa_users (activation_token);

COMMENT ON TABLE wa_users IS '用户表';
COMMENT ON COLUMN wa_users.username IS '用户名';
COMMENT ON COLUMN wa_users.nickname IS '昵称';
COMMENT ON COLUMN wa_users.password IS '密码';
COMMENT ON COLUMN wa_users.sex IS '性别';
COMMENT ON COLUMN wa_users.avatar IS '头像';
COMMENT ON COLUMN wa_users.email IS '邮箱';
COMMENT ON COLUMN wa_users.mobile IS '手机';
COMMENT ON COLUMN wa_users.level IS '等级';
COMMENT ON COLUMN wa_users.birthday IS '生日';
COMMENT ON COLUMN wa_users.money IS '余额(元)';
COMMENT ON COLUMN wa_users.score IS '积分';
COMMENT ON COLUMN wa_users.last_time IS '登录时间';
COMMENT ON COLUMN wa_users.last_ip IS '登录ip';
COMMENT ON COLUMN wa_users.join_time IS '注册时间';
COMMENT ON COLUMN wa_users.join_ip IS '注册ip';
COMMENT ON COLUMN wa_users.token IS 'token';
COMMENT ON COLUMN wa_users.email_verified_at IS '邮箱验证时间';
COMMENT ON COLUMN wa_users.activation_token IS '激活令牌';
COMMENT ON COLUMN wa_users.activation_token_expires_at IS '激活令牌过期时间';
COMMENT ON COLUMN wa_users.created_at IS '创建时间';
COMMENT ON COLUMN wa_users.updated_at IS '更新时间';
COMMENT ON COLUMN wa_users.deleted_at IS '删除时间';
COMMENT ON COLUMN wa_users."role" IS '角色';
COMMENT ON COLUMN wa_users.status IS '禁用';

-- 创建用户OAuth绑定表
CREATE TABLE IF NOT EXISTS user_oauth_bindings
(
    id                BIGSERIAL PRIMARY KEY,
    user_id           INTEGER      NOT NULL,
    provider          VARCHAR(50)  NOT NULL,
    provider_user_id  VARCHAR(255) NOT NULL,
    provider_username VARCHAR(255) DEFAULT NULL,
    provider_email    VARCHAR(255) DEFAULT NULL,
    provider_avatar   VARCHAR(500) DEFAULT NULL,
    access_token      TEXT         DEFAULT NULL,
    refresh_token     TEXT         DEFAULT NULL,
    expires_at        TIMESTAMP    DEFAULT NULL,
    extra_data        JSONB        DEFAULT NULL,
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_provider_user UNIQUE (provider, provider_user_id),
    CONSTRAINT user_oauth_bindings_user_id_foreign FOREIGN KEY (user_id) REFERENCES wa_users (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_user_oauth_user_id ON user_oauth_bindings (user_id);
CREATE INDEX IF NOT EXISTS idx_user_oauth_provider ON user_oauth_bindings (provider);

COMMENT ON TABLE user_oauth_bindings IS '用户OAuth绑定表';
COMMENT ON COLUMN user_oauth_bindings.id IS '主键';
COMMENT ON COLUMN user_oauth_bindings.user_id IS '用户ID';
COMMENT ON COLUMN user_oauth_bindings.provider IS 'OAuth提供商(github, google, wechat等)';
COMMENT ON COLUMN user_oauth_bindings.provider_user_id IS 'OAuth提供商的用户ID';
COMMENT ON COLUMN user_oauth_bindings.provider_username IS 'OAuth提供商的用户名';
COMMENT ON COLUMN user_oauth_bindings.provider_email IS 'OAuth提供商的邮箱';
COMMENT ON COLUMN user_oauth_bindings.provider_avatar IS 'OAuth提供商的头像URL';
COMMENT ON COLUMN user_oauth_bindings.access_token IS '访问令牌(加密存储)';
COMMENT ON COLUMN user_oauth_bindings.refresh_token IS '刷新令牌(加密存储)';
COMMENT ON COLUMN user_oauth_bindings.expires_at IS '令牌过期时间';
COMMENT ON COLUMN user_oauth_bindings.extra_data IS '额外数据(JSON格式)';
COMMENT ON COLUMN user_oauth_bindings.created_at IS '绑定时间';
COMMENT ON COLUMN user_oauth_bindings.updated_at IS '更新时间';

-- 创建分类表
CREATE TABLE IF NOT EXISTS categories
(
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    slug        VARCHAR(255) NOT NULL,
    description TEXT                     DEFAULT NULL,
    parent_id   BIGINT                   DEFAULT NULL,
    sort_order  INTEGER                  DEFAULT 0,
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    status      BOOLEAN      NOT NULL    DEFAULT true,
    deleted_at  TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    UNIQUE (slug),
    CONSTRAINT categories_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
);

COMMENT ON TABLE categories IS '分类表';
COMMENT ON COLUMN categories.name IS '分类名称';
COMMENT ON COLUMN categories.slug IS '分类别名';
COMMENT ON COLUMN categories.description IS '分类描述';
COMMENT ON COLUMN categories.parent_id IS '父分类ID';
COMMENT ON COLUMN categories.sort_order IS '排序顺序';
COMMENT ON COLUMN categories.created_at IS '创建时间';
COMMENT ON COLUMN categories.updated_at IS '更新时间';
COMMENT ON COLUMN categories.status IS '状态：1启用，0禁用';
COMMENT ON COLUMN categories.deleted_at IS '删除时间';

-- 创建文章表
CREATE TABLE IF NOT EXISTS posts
(
    id             BIGSERIAL PRIMARY KEY,
    title          VARCHAR(255)             NOT NULL,
    slug           VARCHAR(255)             NOT NULL,
    content_type   VARCHAR(10)              NOT NULL DEFAULT 'markdown',
    content        TEXT                     NOT NULL,
    excerpt        TEXT                              DEFAULT NULL,
    status         VARCHAR(15)              NOT NULL DEFAULT 'draft',
    visibility     VARCHAR(20)              NOT NULL DEFAULT 'public',
    password       VARCHAR(255)                      DEFAULT NULL,
    featured       BOOLEAN                  NOT NULL DEFAULT false,
    allow_comments BOOLEAN                  NOT NULL DEFAULT true,
    comment_count  INTEGER                  NOT NULL DEFAULT 0,
    published_at   TIMESTAMP WITH TIME ZONE NULL     DEFAULT NULL,
    created_at     TIMESTAMP WITH TIME ZONE          DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP WITH TIME ZONE          DEFAULT CURRENT_TIMESTAMP,
    deleted_at     TIMESTAMP WITH TIME ZONE NULL     DEFAULT NULL,
    UNIQUE (slug),
    CONSTRAINT chk_posts_content_type CHECK (content_type IN ('markdown', 'html', 'text', 'visual')),
    CONSTRAINT chk_posts_status CHECK (status IN ('draft', 'published', 'archived')),
    CONSTRAINT chk_posts_visibility CHECK (visibility IN ('public', 'private', 'password'))
);

CREATE INDEX IF NOT EXISTS idx_posts_visibility ON posts (visibility);
CREATE INDEX IF NOT EXISTS idx_posts_allow_comments ON posts (allow_comments);

COMMENT ON TABLE posts IS '文章表';
COMMENT ON COLUMN posts.title IS '文章标题';
COMMENT ON COLUMN posts.slug IS '文章别名';
COMMENT ON COLUMN posts.content_type IS '内容类型';
COMMENT ON COLUMN posts.content IS '文章内容';
COMMENT ON COLUMN posts.excerpt IS '文章摘要';
COMMENT ON COLUMN posts.status IS '文章状态';
COMMENT ON COLUMN posts.visibility IS '文章可见性';
COMMENT ON COLUMN posts.password IS '文章密码';
COMMENT ON COLUMN posts.featured IS '是否精选';
COMMENT ON COLUMN posts.allow_comments IS '是否允许评论';
COMMENT ON COLUMN posts.comment_count IS '评论数量';
COMMENT ON COLUMN posts.published_at IS '发布时间';
COMMENT ON COLUMN posts.created_at IS '创建时间';
COMMENT ON COLUMN posts.updated_at IS '更新时间';
COMMENT ON COLUMN posts.deleted_at IS '删除时间';

-- 创建文章-分类关联表
CREATE TABLE IF NOT EXISTS post_category
(
    post_id     BIGINT NOT NULL,
    category_id BIGINT NOT NULL,
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id, category_id),
    CONSTRAINT post_category_post_id_foreign FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT post_category_category_id_foreign FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
);

COMMENT ON TABLE post_category IS '文章-分类关联表';
COMMENT ON COLUMN post_category.post_id IS '文章ID';
COMMENT ON COLUMN post_category.category_id IS '分类ID';
COMMENT ON COLUMN post_category.created_at IS '创建时间';
COMMENT ON COLUMN post_category.updated_at IS '更新时间';

-- 创建文章-作者关联表
CREATE TABLE IF NOT EXISTS post_author
(
    id           BIGSERIAL PRIMARY KEY,
    post_id      BIGINT  NOT NULL,
    author_id    INTEGER                  DEFAULT NULL,
    is_primary   BOOLEAN NOT NULL         DEFAULT false,
    contribution VARCHAR(50)              DEFAULT NULL,
    created_at   TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (post_id, author_id),
    CONSTRAINT post_author_post_id_foreign FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT post_author_author_id_foreign FOREIGN KEY (author_id) REFERENCES wa_users (id) ON DELETE CASCADE
);

COMMENT ON TABLE post_author IS '文章-作者关联表';
COMMENT ON COLUMN post_author.post_id IS '文章ID';
COMMENT ON COLUMN post_author.author_id IS '作者ID';
COMMENT ON COLUMN post_author.is_primary IS '是否主要作者';
COMMENT ON COLUMN post_author.contribution IS '贡献类型';
COMMENT ON COLUMN post_author.created_at IS '创建时间';
COMMENT ON COLUMN post_author.updated_at IS '更新时间';

-- 创建友链表
CREATE TABLE IF NOT EXISTS links
(
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    url             VARCHAR(255) NOT NULL,
    description     TEXT                     DEFAULT NULL,
    icon            VARCHAR(255)             DEFAULT NULL,
    image           VARCHAR(255)             DEFAULT NULL,
    sort_order      INTEGER                  DEFAULT 0,
    status          BOOLEAN      NOT NULL    DEFAULT true,
    "target"        VARCHAR(20)              DEFAULT '_blank',
    redirect_type   VARCHAR(10)  NOT NULL    DEFAULT 'info',
    show_url        BOOLEAN      NOT NULL    DEFAULT true,
    content         TEXT                     DEFAULT NULL,
    email           VARCHAR(255)             DEFAULT NULL,
    callback_url    VARCHAR(255)             DEFAULT NULL,
    note            TEXT                     DEFAULT NULL,
    seo_title       VARCHAR(255)             DEFAULT NULL,
    seo_keywords    VARCHAR(255)             DEFAULT NULL,
    seo_description VARCHAR(255)             DEFAULT NULL,
    custom_fields   jsonb                    DEFAULT NULL,
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP WITH TIME ZONE DEFAULT NULL
);

COMMENT ON TABLE links IS '友链表';
COMMENT ON COLUMN links.name IS '友链名称';
COMMENT ON COLUMN links.url IS '友链URL';
COMMENT ON COLUMN links.description IS '友链描述';
COMMENT ON COLUMN links.icon IS '友链图标';
COMMENT ON COLUMN links.image IS '友链图片';
COMMENT ON COLUMN links.sort_order IS '排序顺序';
COMMENT ON COLUMN links.status IS '状态：1显示，0隐藏';
COMMENT ON COLUMN links.target IS '打开方式 (_blank, _self等)';
COMMENT ON COLUMN links.redirect_type IS '跳转方式: direct=直接跳转, goto=中转页跳转, iframe=内嵌页面, info=详情页';
COMMENT ON COLUMN links.show_url IS '是否在中转页显示原始URL';
COMMENT ON COLUMN links.content IS '链接详细介绍(Markdown格式)';
COMMENT ON COLUMN links.email IS '所有者电子邮件';
comment on column links.callback_url is '回调地址，用户访问链接时异步通知';
COMMENT ON column links.note IS '管理员备注';
COMMENT ON column links.seo_title IS 'SEO 标题';
COMMENT ON column links.seo_keywords IS 'SEO 关键词';
COMMENT ON column links.seo_description IS 'SEO 描述';
COMMENT ON column links.custom_fields IS '自定义字段';
COMMENT ON COLUMN links.created_at IS '创建时间';
COMMENT ON COLUMN links.updated_at IS '更新时间';
COMMENT ON COLUMN links.deleted_at IS '删除时间';

-- 创建浮动链接表（FloLink）
CREATE TABLE IF NOT EXISTS flo_links
(
    id               BIGSERIAL PRIMARY KEY,
    keyword          VARCHAR(255) NOT NULL,
    url              VARCHAR(500) NOT NULL,
    title            VARCHAR(255)             DEFAULT NULL,
    description      TEXT                     DEFAULT NULL,
    image            VARCHAR(500)             DEFAULT NULL,
    priority         INTEGER                  DEFAULT 100,
    match_mode       VARCHAR(10)              DEFAULT 'first' CHECK (match_mode IN ('first', 'all')),
    case_sensitive   BOOLEAN                  DEFAULT false,
    replace_existing BOOLEAN                  DEFAULT true,
    "target"         VARCHAR(20)              DEFAULT '_blank',
    rel              VARCHAR(100)             DEFAULT 'noopener noreferrer',
    css_class        VARCHAR(100)             DEFAULT 'flo-link',
    enable_hover     BOOLEAN                  DEFAULT true,
    hover_delay      INTEGER                  DEFAULT 200,
    status           BOOLEAN                  DEFAULT true,
    sort_order       INTEGER                  DEFAULT 999,
    custom_fields    jsonb                    DEFAULT NULL,
    created_at       TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    deleted_at       TIMESTAMP WITH TIME ZONE DEFAULT NULL
);

CREATE INDEX idx_flo_links_keyword ON flo_links (keyword);
CREATE INDEX idx_flo_links_status ON flo_links (status);
CREATE INDEX idx_flo_links_priority ON flo_links (priority);
CREATE INDEX idx_flo_links_sort_order ON flo_links (sort_order);

COMMENT ON TABLE flo_links IS 'FloLink浮动链接表';
COMMENT ON COLUMN flo_links.id IS '浮动链接ID';
COMMENT ON COLUMN flo_links.keyword IS '关键词';
COMMENT ON COLUMN flo_links.url IS '目标链接地址';
COMMENT ON COLUMN flo_links.title IS '链接标题(用于悬浮窗显示)';
COMMENT ON COLUMN flo_links.description IS '链接描述(用于悬浮窗显示)';
COMMENT ON COLUMN flo_links.image IS '图片URL(用于悬浮窗显示)';
COMMENT ON COLUMN flo_links.priority IS '优先级(数字越小优先级越高)';
COMMENT ON COLUMN flo_links.match_mode IS '匹配模式: first=仅替换首次出现, all=替换所有';
COMMENT ON COLUMN flo_links.case_sensitive IS '是否区分大小写';
COMMENT ON COLUMN flo_links.replace_existing IS '是否替换已有链接(智能替换aff等)';
COMMENT ON COLUMN flo_links.target IS '打开方式';
COMMENT ON COLUMN flo_links.rel IS 'rel属性';
COMMENT ON COLUMN flo_links.css_class IS 'CSS类名';
COMMENT ON COLUMN flo_links.enable_hover IS '是否启用悬浮窗';
COMMENT ON COLUMN flo_links.hover_delay IS '悬浮窗延迟显示时间(毫秒)';
COMMENT ON COLUMN flo_links.status IS '状态: true=启用, false=禁用';
COMMENT ON COLUMN flo_links.sort_order IS '排序权重';
COMMENT ON COLUMN flo_links.custom_fields IS '自定义字段(JSON格式)';
COMMENT ON COLUMN flo_links.created_at IS '创建时间';
COMMENT ON COLUMN flo_links.updated_at IS '更新时间';
COMMENT ON COLUMN flo_links.deleted_at IS '软删除时间';

-- 创建页面表
CREATE TABLE IF NOT EXISTS pages
(
    id         BIGSERIAL PRIMARY KEY,
    title      VARCHAR(255)             NOT NULL,
    slug       VARCHAR(255)             NOT NULL,
    content    TEXT                     NOT NULL,
    status     VARCHAR(15)              NOT NULL DEFAULT 'draft',
    template   VARCHAR(50)                       DEFAULT NULL,
    sort_order INTEGER                           DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE          DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE          DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP WITH TIME ZONE NULL     DEFAULT NULL,
    UNIQUE (slug)
);

COMMENT ON TABLE pages IS '页面表';
COMMENT ON COLUMN pages.title IS '页面标题';
COMMENT ON COLUMN pages.slug IS '页面别名';
COMMENT ON COLUMN pages.content IS '页面内容';
COMMENT ON COLUMN pages.status IS '页面状态';
COMMENT ON COLUMN pages.template IS '页面模板';
COMMENT ON COLUMN pages.sort_order IS '排序顺序';
COMMENT ON COLUMN pages.created_at IS '创建时间';
COMMENT ON COLUMN pages.updated_at IS '更新时间';
COMMENT ON COLUMN pages.deleted_at IS '删除时间';

-- 创建网站设置表（使用jsonb类型）
CREATE TABLE IF NOT EXISTS settings
(
    id         BIGSERIAL PRIMARY KEY,
    key        VARCHAR(255) NOT NULL,
    value      JSONB                    DEFAULT NULL,
    "group"    VARCHAR(50)              DEFAULT 'general',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (key)
);

COMMENT ON TABLE settings IS '网站设置表';
COMMENT ON COLUMN settings.key IS '设置键名';
COMMENT ON COLUMN settings.value IS '设置值';
COMMENT ON COLUMN settings."group" IS '设置分组';
COMMENT ON COLUMN settings.created_at IS '创建时间';
COMMENT ON COLUMN settings.updated_at IS '更新时间';

-- 创建媒体附件表
CREATE TABLE IF NOT EXISTS media
(
    id            BIGSERIAL PRIMARY KEY,
    filename      VARCHAR(255)             NOT NULL,
    original_name VARCHAR(255)             NOT NULL,
    file_path     VARCHAR(512)             NOT NULL,
    thumb_path    VARCHAR(500)                      DEFAULT NULL,
    file_size     INTEGER                  NOT NULL DEFAULT 0,
    mime_type     VARCHAR(100)             NOT NULL,
    alt_text      VARCHAR(255)                      DEFAULT NULL,
    caption       TEXT                              DEFAULT NULL,
    description   TEXT                              DEFAULT NULL,
    author_id     INTEGER                           DEFAULT NULL,
    author_type   VARCHAR(10)                       DEFAULT 'user',
    created_at    TIMESTAMP WITH TIME ZONE          DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP WITH TIME ZONE          DEFAULT CURRENT_TIMESTAMP,
    deleted_at    TIMESTAMP WITH TIME ZONE NULL     DEFAULT NULL
);

COMMENT ON TABLE media IS '媒体附件表';
COMMENT ON COLUMN media.filename IS '文件名';
COMMENT ON COLUMN media.original_name IS '原始文件名';
COMMENT ON COLUMN media.file_path IS '文件路径';
COMMENT ON COLUMN media.thumb_path IS '缩略图路径';
COMMENT ON COLUMN media.file_size IS '文件大小';
COMMENT ON COLUMN media.mime_type IS 'MIME类型';
COMMENT ON COLUMN media.alt_text IS '替代文本';
COMMENT ON COLUMN media.caption IS '标题';
COMMENT ON COLUMN media.description IS '描述';
COMMENT ON COLUMN media.author_id IS '作者ID';
COMMENT ON COLUMN media.author_type IS '作者类型';
COMMENT ON COLUMN media.created_at IS '创建时间';
COMMENT ON COLUMN media.updated_at IS '更新时间';
COMMENT ON COLUMN media.deleted_at IS '删除时间';

-- 创建导入任务表
CREATE TABLE IF NOT EXISTS import_jobs
(
    id           BIGSERIAL PRIMARY KEY,
    name         VARCHAR(255)             NOT NULL,
    type         VARCHAR(50)              NOT NULL,
    file_path    VARCHAR(512)             NOT NULL,
    status       VARCHAR(15)              NOT NULL DEFAULT 'pending',
    options      TEXT                              DEFAULT NULL,
    progress     INTEGER                  NOT NULL DEFAULT 0,
    message      TEXT                              DEFAULT NULL,
    author_id    INTEGER                           DEFAULT NULL,
    created_at   TIMESTAMP WITH TIME ZONE          DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP WITH TIME ZONE          DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP WITH TIME ZONE NULL     DEFAULT NULL,
    CONSTRAINT chk_import_jobs_status CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
    CONSTRAINT import_jobs_author_id_foreign FOREIGN KEY (author_id) REFERENCES wa_users (id) ON DELETE SET NULL
);

COMMENT ON TABLE import_jobs IS '导入任务表';
COMMENT ON COLUMN import_jobs.name IS '任务名称';
COMMENT ON COLUMN import_jobs.type IS '任务类型';
COMMENT ON COLUMN import_jobs.file_path IS '文件路径';
COMMENT ON COLUMN import_jobs.status IS '任务状态';
COMMENT ON COLUMN import_jobs.options IS '导入选项';
COMMENT ON COLUMN import_jobs.progress IS '导入进度 0-100';
COMMENT ON COLUMN import_jobs.message IS '状态消息';
COMMENT ON COLUMN import_jobs.author_id IS '默认作者ID';
COMMENT ON COLUMN import_jobs.created_at IS '创建时间';
COMMENT ON COLUMN import_jobs.updated_at IS '更新时间';
COMMENT ON COLUMN import_jobs.completed_at IS '完成时间';

-- 创建评论表
CREATE TABLE IF NOT EXISTS comments
(
    id          BIGSERIAL PRIMARY KEY,
    post_id     BIGINT                   NOT NULL,
    user_id     INTEGER                           DEFAULT NULL,
    parent_id   BIGINT                            DEFAULT NULL,
    guest_name  VARCHAR(255)                      DEFAULT NULL,
    guest_email VARCHAR(255)                      DEFAULT NULL,
    content     TEXT                     NOT NULL,
    quoted_data TEXT                              DEFAULT NULL,
    status      VARCHAR(10)              NOT NULL DEFAULT 'pending',
    ip_address  VARCHAR(45)                       DEFAULT NULL,
    user_agent  VARCHAR(255)                      DEFAULT NULL,
    created_at  TIMESTAMP WITH TIME ZONE          DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP WITH TIME ZONE          DEFAULT CURRENT_TIMESTAMP,
    deleted_at  TIMESTAMP WITH TIME ZONE NULL     DEFAULT NULL,
    CONSTRAINT chk_comments_status CHECK (status IN ('pending', 'approved', 'spam', 'trash')),
    CONSTRAINT comments_post_id_foreign FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT comments_user_id_foreign FOREIGN KEY (user_id) REFERENCES wa_users (id) ON DELETE SET NULL,
    CONSTRAINT comments_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES comments (id) ON DELETE SET NULL
);

COMMENT ON TABLE comments IS '评论表';
COMMENT ON COLUMN comments.post_id IS '文章ID';
COMMENT ON COLUMN comments.user_id IS '用户ID';
COMMENT ON COLUMN comments.parent_id IS '父评论ID';
COMMENT ON COLUMN comments.guest_name IS '访客姓名';
COMMENT ON COLUMN comments.guest_email IS '访客邮箱';
COMMENT ON COLUMN comments.content IS '评论内容';
COMMENT ON COLUMN comments.quoted_data IS '引用数据(JSON格式,包含被引用评论的ID、作者、内容等信息)';
COMMENT ON COLUMN comments.status IS '评论状态';
COMMENT ON COLUMN comments.ip_address IS 'IP地址';
COMMENT ON COLUMN comments.user_agent IS '用户代理';
COMMENT ON COLUMN comments.created_at IS '创建时间';
COMMENT ON COLUMN comments.updated_at IS '更新时间';
COMMENT ON COLUMN comments.deleted_at IS '删除时间';

-- 创建标签表
CREATE TABLE IF NOT EXISTS tags
(
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(255)             NOT NULL,
    slug        VARCHAR(255)             NOT NULL,
    description TEXT                          DEFAULT NULL,
    created_at  TIMESTAMP WITH TIME ZONE      DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP WITH TIME ZONE      DEFAULT CURRENT_TIMESTAMP,
    deleted_at  TIMESTAMP WITH TIME ZONE NULL DEFAULT NULL,
    UNIQUE (slug)
);

COMMENT ON TABLE tags IS '标签表';
COMMENT ON COLUMN tags.name IS '标签名称';
COMMENT ON COLUMN tags.slug IS '标签别名';
COMMENT ON COLUMN tags.description IS '标签描述';
COMMENT ON COLUMN tags.created_at IS '创建时间';
COMMENT ON COLUMN tags.updated_at IS '更新时间';
COMMENT ON COLUMN tags.deleted_at IS '删除时间';

-- 创建文章-标签关联表
CREATE TABLE IF NOT EXISTS post_tag
(
    post_id    BIGINT NOT NULL,
    tag_id     BIGINT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id, tag_id),
    CONSTRAINT post_tag_post_id_foreign FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT post_tag_tag_id_foreign FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
);

COMMENT ON TABLE post_tag IS '文章-标签关联表';
COMMENT ON COLUMN post_tag.post_id IS '文章ID';
COMMENT ON COLUMN post_tag.tag_id IS '标签ID';
COMMENT ON COLUMN post_tag.created_at IS '创建时间';

-- 创建管理员角色关联表（只保留一次）
CREATE TABLE IF NOT EXISTS wa_admin_roles
(
    role_id  INTEGER,
    admin_id INTEGER,
    UNIQUE (role_id, admin_id)
);

COMMENT ON TABLE wa_admin_roles IS '管理员角色关联表';
COMMENT ON COLUMN wa_admin_roles.role_id IS '角色id';
COMMENT ON COLUMN wa_admin_roles.admin_id IS '管理员id';

-- 创建管理员表
CREATE TABLE IF NOT EXISTS wa_admins
(
    id         SERIAL PRIMARY KEY,
    username   VARCHAR(32)  NOT NULL,
    nickname   VARCHAR(40)  NOT NULL,
    password   VARCHAR(255) NOT NULL,
    avatar     VARCHAR(255)             DEFAULT '/app/admin/avatar.png',
    email      VARCHAR(100)             DEFAULT NULL,
    mobile     VARCHAR(16)              DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    login_at   TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    status     INTEGER                  DEFAULT NULL,
    UNIQUE (username)
);

COMMENT ON TABLE wa_admins IS '管理员表';
COMMENT ON COLUMN wa_admins.username IS '用户名';
COMMENT ON COLUMN wa_admins.nickname IS '昵称';
COMMENT ON COLUMN wa_admins.password IS '密码';
COMMENT ON COLUMN wa_admins.avatar IS '头像';
COMMENT ON COLUMN wa_admins.email IS '邮箱';
COMMENT ON COLUMN wa_admins.mobile IS '手机';
COMMENT ON COLUMN wa_admins.created_at IS '创建时间';
COMMENT ON COLUMN wa_admins.updated_at IS '更新时间';
COMMENT ON COLUMN wa_admins.login_at IS '登录时间';
COMMENT ON COLUMN wa_admins.status IS '禁用';

-- 创建选项表
CREATE TABLE IF NOT EXISTS wa_options
(
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(128) NOT NULL,
    value      jsonb        NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP, -- 修正
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP, -- 修正
    UNIQUE (name)
);

COMMENT ON TABLE wa_options IS '选项表';
COMMENT ON COLUMN wa_options.name IS '键';
COMMENT ON COLUMN wa_options.value IS '值';
COMMENT ON COLUMN wa_options.created_at IS '创建时间';
COMMENT ON COLUMN wa_options.updated_at IS '更新时间';

-- 创建管理员角色表（权限角色组）
CREATE TABLE IF NOT EXISTS wa_roles
(
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(80)              NOT NULL,
    rules      TEXT                              DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pid        INTEGER                           DEFAULT NULL
);

COMMENT ON TABLE wa_roles IS '管理员角色表';
COMMENT ON COLUMN wa_roles.name IS '角色组';
COMMENT ON COLUMN wa_roles.rules IS '权限';
COMMENT ON COLUMN wa_roles.created_at IS '创建时间';
COMMENT ON COLUMN wa_roles.updated_at IS '更新时间';
COMMENT ON COLUMN wa_roles.pid IS '父级';

-- 创建权限规则表
CREATE TABLE IF NOT EXISTS wa_rules
(
    id         SERIAL PRIMARY KEY,
    title      VARCHAR(255)             NOT NULL,
    "icon"     VARCHAR(255)                      DEFAULT NULL,
    key        VARCHAR(255)             NOT NULL,
    pid        INTEGER                  NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    href       VARCHAR(255)                      DEFAULT NULL,
    type       INTEGER                  NOT NULL DEFAULT 1,
    weight     INTEGER                           DEFAULT 0
);

COMMENT ON TABLE wa_rules IS '权限规则表';
COMMENT ON COLUMN wa_rules.title IS '标题';
COMMENT ON COLUMN wa_rules."icon" IS '图标';
COMMENT ON COLUMN wa_rules.key IS '标识';
COMMENT ON COLUMN wa_rules.pid IS '上级菜单';
COMMENT ON COLUMN wa_rules.created_at IS '创建时间';
COMMENT ON COLUMN wa_rules.updated_at IS '更新时间';
COMMENT ON COLUMN wa_rules.href IS 'url';
COMMENT ON COLUMN wa_rules.type IS '类型';
COMMENT ON COLUMN wa_rules.weight IS '排序';

-- 创建附件表
CREATE TABLE IF NOT EXISTS wa_uploads
(
    id           SERIAL PRIMARY KEY,
    name         VARCHAR(128) NOT NULL,
    url          VARCHAR(255) NOT NULL,
    admin_id     INTEGER                  DEFAULT NULL,
    file_size    INTEGER      NOT NULL,
    mime_type    VARCHAR(255) NOT NULL,
    image_width  INTEGER                  DEFAULT NULL,
    image_height INTEGER                  DEFAULT NULL,
    ext          VARCHAR(128) NOT NULL,
    storage      VARCHAR(255) NOT NULL    DEFAULT 'local',
    category     VARCHAR(128)             DEFAULT NULL,
    created_at   TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE wa_uploads IS '附件表';
COMMENT ON COLUMN wa_uploads.name IS '名称';
COMMENT ON COLUMN wa_uploads.url IS '文件';
COMMENT ON COLUMN wa_uploads.admin_id IS '管理员';
COMMENT ON COLUMN wa_uploads.file_size IS '文件大小';
COMMENT ON COLUMN wa_uploads.mime_type IS 'mime类型';
COMMENT ON COLUMN wa_uploads.image_width IS '图片宽度';
COMMENT ON COLUMN wa_uploads.image_height IS '图片高度';
COMMENT ON COLUMN wa_uploads.ext IS '扩展名';
COMMENT ON COLUMN wa_uploads.storage IS '存储位置';
COMMENT ON COLUMN wa_uploads.created_at IS '上传时间';
COMMENT ON COLUMN wa_uploads.category IS '类别';
COMMENT ON COLUMN wa_uploads.updated_at IS '更新时间';

-- 创建posts_ext表
CREATE TABLE IF NOT EXISTS post_ext
(
    id      BIGSERIAL PRIMARY KEY,
    post_id BIGINT       NOT NULL,
    key     varchar(255) NOT NULL,
    value   jsonb        NOT NULL,
    CONSTRAINT posts_ext_post_id_foreign FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
);

COMMENT ON TABLE post_ext IS '文章扩展表';
COMMENT ON COLUMN post_ext.post_id IS '文章ID';
COMMENT ON COLUMN post_ext.key IS '键';
COMMENT ON COLUMN post_ext.value IS '值';

-- 添加索引
CREATE INDEX idx_wa_users_join_time ON wa_users USING btree (join_time);
CREATE INDEX idx_wa_users_mobile ON wa_users USING btree (mobile);
CREATE INDEX idx_wa_users_email ON wa_users USING btree (email);

CREATE INDEX idx_categories_parent_id ON categories USING btree (parent_id);
CREATE INDEX idx_categories_status ON categories USING btree (status);
CREATE INDEX idx_categories_deleted_at ON categories USING btree (deleted_at);

CREATE INDEX idx_posts_status ON posts USING btree (status);
CREATE INDEX idx_posts_featured ON posts USING btree (featured);
CREATE INDEX idx_posts_published_at ON posts USING btree (published_at);
CREATE INDEX idx_posts_deleted_at ON posts USING btree (deleted_at);

CREATE INDEX idx_post_category_post_id ON post_category USING btree (post_id);
CREATE INDEX idx_post_category_category_id ON post_category USING btree (category_id);

CREATE INDEX idx_post_author_post_id ON post_author USING btree (post_id);
CREATE INDEX idx_post_author_author_id ON post_author USING btree (author_id);


CREATE INDEX idx_links_status ON links USING btree (status);
CREATE INDEX idx_links_sort_order ON links USING btree (sort_order);
CREATE INDEX idx_links_deleted_at ON links USING btree (deleted_at);

CREATE INDEX idx_pages_deleted_at ON pages USING btree (deleted_at);

CREATE INDEX idx_settings_group ON settings USING btree ("group");
CREATE INDEX idx_settings_value ON settings USING GIN (value);

CREATE INDEX idx_media_author_id ON media USING btree (author_id);
CREATE INDEX idx_media_author_type ON media USING btree (author_type);
CREATE INDEX idx_media_filename ON media USING btree (filename);
CREATE INDEX idx_media_mime_type ON media USING btree (mime_type);
CREATE INDEX idx_media_deleted_at ON media USING btree (deleted_at);

CREATE INDEX idx_import_jobs_status ON import_jobs USING btree (status);
CREATE INDEX idx_import_jobs_author_id ON import_jobs USING btree (author_id);

CREATE INDEX idx_comments_post_id ON comments USING btree (post_id);
CREATE INDEX idx_comments_user_id ON comments USING btree (user_id);
CREATE INDEX idx_comments_parent_id ON comments USING btree (parent_id);
CREATE INDEX idx_comments_status ON comments USING btree (status);
CREATE INDEX idx_comments_deleted_at ON comments USING btree (deleted_at);

CREATE INDEX idx_tags_deleted_at ON tags USING btree (deleted_at);

CREATE INDEX idx_post_tag_post_id ON post_tag USING btree (post_id);
CREATE INDEX idx_post_tag_tag_id ON post_tag USING btree (tag_id);

CREATE INDEX idx_wa_uploads_category ON wa_uploads USING btree (category);
CREATE INDEX idx_wa_uploads_admin_id ON wa_uploads USING btree (admin_id);
CREATE INDEX idx_wa_uploads_name ON wa_uploads USING btree (name);
CREATE INDEX idx_wa_uploads_ext ON wa_uploads USING btree (ext);

CREATE INDEX idx_post_ext_id ON post_ext USING btree (id);
CREATE INDEX idx_post_ext_key ON post_ext USING btree (key);
-- 插入预定义表数据

-- Data for Name: settings; Type: TABLE DATA; Schema: public; Owner: postgres
INSERT INTO public.settings (key, value, created_at, updated_at)
VALUES ('system_config', '{
  "logo": {
    "title": "风屿岛管理页",
    "image": "/app/admin/admin/images/logo.png"
  },
  "menu": {
    "data": "/app/admin/rule/get",
    "method": "GET",
    "accordion": true,
    "collapse": false,
    "control": false,
    "controlWidth": 2000,
    "select": "0",
    "async": true
  },
  "tab": {
    "enable": true,
    "keepState": true,
    "session": true,
    "preload": false,
    "max": "30",
    "index": {
      "id": "0",
      "href": "/app/admin/index/dashboard",
      "title": "仪表盘"
    }
  },
  "theme": {
    "defaultColor": "2",
    "defaultMenu": "light-theme",
    "defaultHeader": "light-theme",
    "allowCustom": true,
    "banner": false
  },
  "colors": [
    {
      "id": "1",
      "color": "#36b368",
      "second": "#f0f9eb"
    },
    {
      "id": "2",
      "color": "#2d8cf0",
      "second": "#ecf5ff"
    },
    {
      "id": "3",
      "color": "#f6ad55",
      "second": "#fdf6ec"
    },
    {
      "id": "4",
      "color": "#f56c6c",
      "second": "#fef0f0"
    },
    {
      "id": "5",
      "color": "#3963bc",
      "second": "#ecf5ff"
    }
  ],
  "other": {
    "keepLoad": "500",
    "autoHead": false,
    "footer": false
  },
  "header": {
    "message": false
  }
}', '2022-12-05 14:49:01+08', '2022-12-08 20:20:28+08'),
       ('table_form_schema_wa_users', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "enable_sort": true,
           "searchable": true,
           "search_type": "normal",
           "form_show": false
         },
         "username": {
           "field": "username",
           "_field_id": "1",
           "comment": "用户名",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "nickname": {
           "field": "nickname",
           "_field_id": "2",
           "comment": "昵称",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "password": {
           "field": "password",
           "_field_id": "3",
           "comment": "密码",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "sex": {
           "field": "sex",
           "_field_id": "4",
           "comment": "性别",
           "control": "select",
           "control_args": "url:/app/admin/dict/get/sex",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "avatar": {
           "field": "avatar",
           "_field_id": "5",
           "comment": "头像",
           "control": "uploadImage",
           "control_args": "url:/app/admin/upload/avatar",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "email": {
           "field": "email",
           "_field_id": "6",
           "comment": "邮箱",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "mobile": {
           "field": "mobile",
           "_field_id": "7",
           "comment": "手机",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "level": {
           "field": "level",
           "_field_id": "8",
           "comment": "等级",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "birthday": {
           "field": "birthday",
           "_field_id": "9",
           "comment": "生日",
           "control": "datePicker",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false
         },
         "money": {
           "field": "money",
           "_field_id": "10",
           "comment": "余额(元)",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "score": {
           "field": "score",
           "_field_id": "11",
           "comment": "积分",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "last_time": {
           "field": "last_time",
           "_field_id": "12",
           "comment": "登录时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false
         },
         "last_ip": {
           "field": "last_ip",
           "_field_id": "13",
           "comment": "登录ip",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "join_time": {
           "field": "join_time",
           "_field_id": "14",
           "comment": "注册时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false
         },
         "join_ip": {
           "field": "join_ip",
           "_field_id": "15",
           "comment": "注册ip",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "token": {
           "field": "token",
           "_field_id": "16",
           "comment": "token",
           "control": "input",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "17",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "18",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "between",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "role": {
           "field": "role",
           "_field_id": "19",
           "comment": "角色",
           "control": "inputNumber",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "status": {
           "field": "status",
           "_field_id": "20",
           "comment": "禁用",
           "control": "switch",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00+08', '2022-12-23 15:28:13+08'),
       ('table_form_schema_wa_roles', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "name": {
           "field": "name",
           "_field_id": "1",
           "comment": "角色组",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "rules": {
           "field": "rules",
           "_field_id": "2",
           "comment": "权限",
           "control": "treeSelectMulti",
           "control_args": "url:/app/admin/rule/get?type=0,1,2",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "3",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "4",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "pid": {
           "field": "pid",
           "_field_id": "5",
           "comment": "父级",
           "control": "select",
           "control_args": "url:/app/admin/role/select?format=tree",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00+08', '2022-12-19 14:24:25+08'),
       ('table_form_schema_wa_rules', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "title": {
           "field": "title",
           "_field_id": "1",
           "comment": "标题",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "icon": {
           "field": "icon",
           "_field_id": "2",
           "comment": "图标",
           "control": "iconPicker",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "key": {
           "field": "key",
           "_field_id": "3",
           "comment": "标识",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "pid": {
           "field": "pid",
           "_field_id": "4",
           "comment": "上级菜单",
           "control": "treeSelect",
           "control_args": "/app/admin/rule/select?format=tree&type=0,1",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "5",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "6",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "href": {
           "field": "href",
           "_field_id": "7",
           "comment": "url",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "type": {
           "field": "type",
           "_field_id": "8",
           "comment": "类型",
           "control": "select",
           "control_args": "data:0:目录,1:菜单,2:权限",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "weight": {
           "field": "weight",
           "_field_id": "9",
           "comment": "排序",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00+08', '2022-12-08 11:44:45+08'),
       ('table_form_schema_wa_admins', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "ID",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "enable_sort": true,
           "search_type": "between",
           "form_show": false,
           "searchable": false
         },
         "username": {
           "field": "username",
           "_field_id": "1",
           "comment": "用户名",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "nickname": {
           "field": "nickname",
           "_field_id": "2",
           "comment": "昵称",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "password": {
           "field": "password",
           "_field_id": "3",
           "comment": "密码",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "avatar": {
           "field": "avatar",
           "_field_id": "4",
           "comment": "头像",
           "control": "uploadImage",
           "control_args": "url:/app/admin/upload/avatar",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "email": {
           "field": "email",
           "_field_id": "5",
           "comment": "邮箱",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "mobile": {
           "field": "mobile",
           "_field_id": "6",
           "comment": "手机",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "7",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "8",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "login_at": {
           "field": "login_at",
           "_field_id": "9",
           "comment": "登录时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "between",
           "enable_sort": false,
           "searchable": false
         },
         "status": {
           "field": "status",
           "_field_id": "10",
           "comment": "禁用",
           "control": "switch",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00+08', '2022-12-23 15:36:48+08'),
       ('table_form_schema_wa_options', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "name": {
           "field": "name",
           "_field_id": "1",
           "comment": "键",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "value": {
           "field": "value",
           "_field_id": "2",
           "comment": "值",
           "control": "textArea",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "3",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "4",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00+08', '2022-12-08 11:36:57+08'),
       ('table_form_schema_wa_uploads', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "enable_sort": true,
           "search_type": "normal",
           "form_show": false,
           "searchable": false
         },
         "name": {
           "field": "name",
           "_field_id": "1",
           "comment": "名称",
           "control": "input",
           "control_args": "",
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false
         },
         "url": {
           "field": "url",
           "_field_id": "2",
           "comment": "文件",
           "control": "upload",
           "control_args": "url:/app/admin/upload/file",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "admin_id": {
           "field": "admin_id",
           "_field_id": "3",
           "comment": "管理员",
           "control": "select",
           "control_args": "url:/app/admin/admin/select?format=select",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "file_size": {
           "field": "file_size",
           "_field_id": "4",
           "comment": "文件大小",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "between",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "mime_type": {
           "field": "mime_type",
           "_field_id": "5",
           "comment": "mime类型",
           "control": "input",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "image_width": {
           "field": "image_width",
           "_field_id": "6",
           "comment": "图片宽度",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "image_height": {
           "field": "image_height",
           "_field_id": "7",
           "comment": "图片高度",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "ext": {
           "field": "ext",
           "_field_id": "8",
           "comment": "扩展名",
           "control": "input",
           "control_args": "",
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false
         },
         "storage": {
           "field": "storage",
           "_field_id": "9",
           "comment": "存储位置",
           "control": "input",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "10",
           "comment": "上传时间",
           "control": "dateTimePicker",
           "control_args": "",
           "searchable": true,
           "search_type": "between",
           "form_show": false,
           "list_show": false,
           "enable_sort": false
         },
         "category": {
           "field": "category",
           "_field_id": "11",
           "comment": "类别",
           "control": "select",
           "control_args": "url:/app/admin/dict/get/upload",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "12",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00+08', '2022-12-08 11:47:45+08'),
       ('dict_upload', '[
         {
           "value": "1",
           "name": "分类1"
         },
         {
           "value": "2",
           "name": "分类2"
         },
         {
           "value": "3",
           "name": "分类3"
         }
       ]', '2022-12-04 16:24:13+08', '2022-12-04 16:24:13+08'),
       ('dict_sex', '[
         {
           "value": "0",
           "name": "女"
         },
         {
           "value": "1",
           "name": "男"
         }
       ]', '2022-12-04 15:04:40+08', '2022-12-04 15:04:40+08'),
       ('dict_status', '[
         {
           "value": "0",
           "name": "正常"
         },
         {
           "value": "1",
           "name": "禁用"
         }
       ]', '2022-12-04 15:05:09+08', '2022-12-04 15:05:09+08'),
       ('table_form_schema_wa_admin_roles', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "enable_sort": true,
           "searchable": true,
           "search_type": "normal",
           "form_show": false
         },
         "role_id": {
           "field": "role_id",
           "_field_id": "1",
           "comment": "角色id",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "admin_id": {
           "field": "admin_id",
           "_field_id": "2",
           "comment": "管理员id",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00+08', '2022-12-20 19:42:51+08'),
       ('dict_dict_name', '[
         {
           "value": "dict_name",
           "name": "字典名称"
         },
         {
           "value": "status",
           "name": "启禁用状态"
         },
         {
           "value": "sex",
           "name": "性别"
         },
         {
           "value": "upload",
           "name": "附件分类"
         }
       ]', '2022-08-15 00:00:00+08', '2022-12-20 19:42:51+08');


INSERT INTO wa_roles
VALUES (1, '超级管理员', '*', '2022-08-13 16:15:01', '2022-12-23 12:05:07', NULL);
INSERT INTO links
VALUES (default, '雨云',
        'https://www.rainyun.com/github_?s=blog-sys-ads',
        '超高性价比云服务商，使用优惠码github注册并绑定微信即可获得5折优惠',
        'https://www.rainyun.com/favicon.ico',
        null,
        '1',
        true,
        '_blank',
        'direct',
        false,
        '# 超高性价比云服务商，使用优惠码github注册并绑定微信即可获得5折优惠',
        'admin@biliwind.com',
        '',
        null,
        '雨云',
        '雨云,云服务器,服务器,性价比',
        '超高性价比云服务商，使用优惠码github注册并绑定微信即可获得5折优惠',
        null, '2025-9-26 11:00:00+08', '2022-12-23 12:05:07',
        null);
