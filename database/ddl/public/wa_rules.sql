create table wa_rules
(
    id         serial
        primary key,
    title      varchar(255)                                       not null,
    icon       varchar(255)             default NULL::character varying,
    key        varchar(255)                                       not null,
    pid        integer                  default 0                 not null,
    created_at timestamp with time zone default CURRENT_TIMESTAMP not null,
    updated_at timestamp with time zone default CURRENT_TIMESTAMP not null,
    href       varchar(255)             default NULL::character varying,
    type       integer                  default 1                 not null,
    weight     integer                  default 0
);

comment on column wa_rules.title is '标题';

comment on column wa_rules.icon is '图标';

comment on column wa_rules.key is '标识';

comment on column wa_rules.pid is '上级菜单';

comment on column wa_rules.created_at is '创建时间';

comment on column wa_rules.updated_at is '更新时间';

comment on column wa_rules.href is 'url';

comment on column wa_rules.type is '类型';

comment on column wa_rules.weight is '排序';

alter table wa_rules
    owner to postgres;

