create table wa_admins
(
    id         serial
        primary key,
    username   varchar(32)  not null
        unique,
    nickname   varchar(40)  not null,
    password   varchar(255) not null,
    avatar     varchar(255)             default '/app/admin/avatar.png'::character varying,
    email      varchar(100)             default NULL::character varying,
    mobile     varchar(16)              default NULL::character varying,
    created_at timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at timestamp with time zone default CURRENT_TIMESTAMP,
    login_at   timestamp with time zone,
    status     integer
);

comment on column wa_admins.username is '用户名';

comment on column wa_admins.nickname is '昵称';

comment on column wa_admins.password is '密码';

comment on column wa_admins.avatar is '头像';

comment on column wa_admins.email is '邮箱';

comment on column wa_admins.mobile is '手机';

comment on column wa_admins.created_at is '创建时间';

comment on column wa_admins.updated_at is '更新时间';

comment on column wa_admins.login_at is '登录时间';

comment on column wa_admins.status is '禁用';

alter table wa_admins
    owner to postgres;

