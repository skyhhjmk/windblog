create table pages
(
    id         bigserial
        primary key,
    title      varchar(255)                                                not null,
    slug       varchar(255)                                                not null
        unique,
    content    text                                                        not null,
    status     varchar(15)              default 'draft'::character varying not null,
    template   varchar(50)              default NULL::character varying,
    sort_order integer                  default 0,
    created_at timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at timestamp with time zone default CURRENT_TIMESTAMP,
    deleted_at timestamp with time zone
);

comment on column pages.title is '页面标题';

comment on column pages.slug is '页面别名';

comment on column pages.content is '页面内容';

comment on column pages.status is '页面状态';

comment on column pages.template is '页面模板';

comment on column pages.sort_order is '排序顺序';

comment on column pages.created_at is '创建时间';

comment on column pages.updated_at is '更新时间';

comment on column pages.deleted_at is '删除时间';

alter table pages
    owner to postgres;

create index idx_pages_deleted_at
    on pages (deleted_at);

