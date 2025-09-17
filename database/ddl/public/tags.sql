create table tags
(
    id          bigserial
        primary key,
    name        varchar(255) not null,
    slug        varchar(255) not null
        unique,
    description text,
    created_at  timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at  timestamp with time zone default CURRENT_TIMESTAMP,
    deleted_at  timestamp with time zone
);

comment on column tags.name is '标签名称';

comment on column tags.slug is '标签别名';

comment on column tags.description is '标签描述';

comment on column tags.created_at is '创建时间';

comment on column tags.updated_at is '更新时间';

comment on column tags.deleted_at is '删除时间';

alter table tags
    owner to postgres;

create index idx_tags_deleted_at
    on tags (deleted_at);

