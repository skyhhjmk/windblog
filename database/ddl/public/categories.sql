create table categories
(
    id          bigserial
        primary key,
    name        varchar(255)                          not null,
    slug        varchar(255)                          not null
        unique,
    description text,
    parent_id   bigint
        constraint categories_parent_id_foreign
            references categories
            on delete set null,
    sort_order  integer                  default 0,
    created_at  timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at  timestamp with time zone default CURRENT_TIMESTAMP,
    status      boolean                  default true not null,
    deleted_at  timestamp with time zone
);

comment on column categories.name is '分类名称';

comment on column categories.slug is '分类别名';

comment on column categories.description is '分类描述';

comment on column categories.parent_id is '父分类ID';

comment on column categories.sort_order is '排序顺序';

comment on column categories.created_at is '创建时间';

comment on column categories.updated_at is '更新时间';

comment on column categories.status is '状态：1启用，0禁用';

comment on column categories.deleted_at is '删除时间';

alter table categories
    owner to postgres;

create index idx_categories_parent_id
    on categories (parent_id);

create index idx_categories_status
    on categories (status);

create index idx_categories_deleted_at
    on categories (deleted_at);

