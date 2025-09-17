create table posts
(
    id            bigserial
        primary key,
    title         varchar(255)                                                   not null,
    slug          varchar(255)                                                   not null
        unique,
    content_type  varchar(10)              default 'markdown'::character varying not null
        constraint chk_posts_content_type
            check ((content_type)::text = ANY
                   ((ARRAY ['markdown'::character varying, 'html'::character varying, 'text'::character varying, 'visual'::character varying])::text[])),
    content       text                                                           not null,
    excerpt       text,
    status        varchar(15)              default 'draft'::character varying    not null
        constraint chk_posts_status
            check ((status)::text = ANY
                   ((ARRAY ['draft'::character varying, 'published'::character varying, 'archived'::character varying])::text[])),
    featured      boolean                  default false                         not null,
    view_count    integer                  default 0                             not null,
    comment_count integer                  default 0                             not null,
    published_at  timestamp with time zone,
    created_at    timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at    timestamp with time zone default CURRENT_TIMESTAMP,
    deleted_at    timestamp with time zone
);

comment on column posts.title is '文章标题';

comment on column posts.slug is '文章别名';

comment on column posts.content_type is '内容类型';

comment on column posts.content is '文章内容';

comment on column posts.excerpt is '文章摘要';

comment on column posts.status is '文章状态';

comment on column posts.featured is '是否精选';

comment on column posts.view_count is '浏览次数';

comment on column posts.comment_count is '评论数量';

comment on column posts.published_at is '发布时间';

comment on column posts.created_at is '创建时间';

comment on column posts.updated_at is '更新时间';

comment on column posts.deleted_at is '删除时间';

alter table posts
    owner to postgres;

create index idx_posts_status
    on posts (status);

create index idx_posts_featured
    on posts (featured);

create index idx_posts_published_at
    on posts (published_at);

create index idx_posts_deleted_at
    on posts (deleted_at);

