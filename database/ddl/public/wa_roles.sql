create table wa_roles
(
    id         serial
        primary key,
    name       varchar(80)                                        not null,
    rules      text,
    created_at timestamp with time zone default CURRENT_TIMESTAMP not null,
    updated_at timestamp with time zone default CURRENT_TIMESTAMP not null,
    pid        integer
);

comment on column wa_roles.name is '角色组';

comment on column wa_roles.rules is '权限';

comment on column wa_roles.created_at is '创建时间';

comment on column wa_roles.updated_at is '更新时间';

comment on column wa_roles.pid is '父级';

alter table wa_roles
    owner to postgres;

