create table links
(
    id            bigserial
        primary key,
    name          varchar(255)                                               not null,
    url           varchar(255)                                               not null,
    description   text,
    image         varchar(255)             default NULL::character varying,
    sort_order    integer                  default 0,
    status        boolean                  default true                      not null,
    target        varchar(20)              default '_blank'::character varying,
    redirect_type varchar(10)              default 'info'::character varying not null,
    show_url      boolean                  default true                      not null,
    content       text,
    created_at    timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at    timestamp with time zone default CURRENT_TIMESTAMP,
    deleted_at    timestamp with time zone
);

comment on column links.name is '友链名称';

comment on column links.url is '友链URL';

comment on column links.description is '友链描述';

comment on column links.image is '友链图片';

comment on column links.sort_order is '排序顺序';

comment on column links.status is '状态：1显示，0隐藏';

comment on column links.target is '打开方式 (_blank, _self等)';

comment on column links.redirect_type is '跳转方式: direct=直接跳转, goto=中转页跳转, iframe=内嵌页面, info=详情页';

comment on column links.show_url is '是否在中转页显示原始URL';

comment on column links.content is '链接详细介绍(Markdown格式)';

comment on column links.created_at is '创建时间';

comment on column links.updated_at is '更新时间';

comment on column links.deleted_at is '删除时间';

alter table links
    owner to postgres;

create index idx_links_status
    on links (status);

create index idx_links_sort_order
    on links (sort_order);

create index idx_links_deleted_at
    on links (deleted_at);

