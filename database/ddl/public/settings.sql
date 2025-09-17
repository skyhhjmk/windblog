create table settings
(
    id         bigserial
        primary key,
    key        varchar(255) not null
        unique,
    value      jsonb,
    type       varchar(50)              default 'string'::character varying,
    "group"    varchar(50)              default 'general'::character varying,
    created_at timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at timestamp with time zone default CURRENT_TIMESTAMP
);

comment on column settings.key is '设置键名';

comment on column settings.value is '设置值';

comment on column settings.type is '值类型';

comment on column settings."group" is '设置分组';

comment on column settings.created_at is '创建时间';

comment on column settings.updated_at is '更新时间';

alter table settings
    owner to postgres;

create index idx_settings_group
    on settings ("group");

