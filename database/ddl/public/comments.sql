create table comments
(
    id          bigserial
        primary key,
    post_id     bigint                                                        not null
        constraint comments_post_id_foreign
            references posts
            on delete cascade,
    user_id     integer
        constraint comments_user_id_foreign
            references wa_users
            on delete set null,
    parent_id   bigint
        constraint comments_parent_id_foreign
            references comments
            on delete set null,
    guest_name  varchar(255)             default NULL::character varying,
    guest_email varchar(255)             default NULL::character varying,
    content     text                                                          not null,
    status      varchar(10)              default 'pending'::character varying not null
        constraint chk_comments_status
            check ((status)::text = ANY
                   ((ARRAY ['pending'::character varying, 'approved'::character varying, 'spam'::character varying, 'trash'::character varying])::text[])),
    ip_address  varchar(45)              default NULL::character varying,
    user_agent  varchar(255)             default NULL::character varying,
    created_at  timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at  timestamp with time zone default CURRENT_TIMESTAMP,
    deleted_at  timestamp with time zone
);

comment on column comments.post_id is '文章ID';

comment on column comments.user_id is '用户ID';

comment on column comments.parent_id is '父评论ID';

comment on column comments.guest_name is '访客姓名';

comment on column comments.guest_email is '访客邮箱';

comment on column comments.content is '评论内容';

comment on column comments.status is '评论状态';

comment on column comments.ip_address is 'IP地址';

comment on column comments.user_agent is '用户代理';

comment on column comments.created_at is '创建时间';

comment on column comments.updated_at is '更新时间';

comment on column comments.deleted_at is '删除时间';

alter table comments
    owner to postgres;

create index idx_comments_post_id
    on comments (post_id);

create index idx_comments_user_id
    on comments (user_id);

create index idx_comments_parent_id
    on comments (parent_id);

create index idx_comments_status
    on comments (status);

create index idx_comments_deleted_at
    on comments (deleted_at);

