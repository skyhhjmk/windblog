create table wa_users
(
    id         bigserial
        primary key,
    username   varchar(32)                                             not null
        unique,
    nickname   varchar(40)                                             not null,
    password   varchar(255)                                            not null,
    sex        varchar(1)               default '1'::character varying not null,
    avatar     varchar(255)             default NULL::character varying,
    email      varchar(128)             default NULL::character varying,
    mobile     varchar(16)              default NULL::character varying,
    level      integer                  default 0                      not null,
    birthday   date,
    money      numeric(10, 2)           default 0.00                   not null,
    score      integer                  default 0                      not null,
    last_time  timestamp with time zone,
    last_ip    varchar(50)              default NULL::character varying,
    join_time  timestamp with time zone,
    join_ip    varchar(50)              default NULL::character varying,
    token      varchar(50)              default NULL::character varying,
    created_at timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at timestamp with time zone default CURRENT_TIMESTAMP,
    deleted_at timestamp with time zone,
    role       integer                  default 1                      not null,
    status     integer                  default 0                      not null
);

comment on column wa_users.username is '用户名';

comment on column wa_users.nickname is '昵称';

comment on column wa_users.password is '密码';

comment on column wa_users.sex is '性别';

comment on column wa_users.avatar is '头像';

comment on column wa_users.email is '邮箱';

comment on column wa_users.mobile is '手机';

comment on column wa_users.level is '等级';

comment on column wa_users.birthday is '生日';

comment on column wa_users.money is '余额(元)';

comment on column wa_users.score is '积分';

comment on column wa_users.last_time is '登录时间';

comment on column wa_users.last_ip is '登录ip';

comment on column wa_users.join_time is '注册时间';

comment on column wa_users.join_ip is '注册ip';

comment on column wa_users.token is 'token';

comment on column wa_users.created_at is '创建时间';

comment on column wa_users.updated_at is '更新时间';

comment on column wa_users.deleted_at is '删除时间';

comment on column wa_users.role is '角色';

comment on column wa_users.status is '禁用';

alter table wa_users
    owner to postgres;

create index idx_wa_users_join_time
    on wa_users (join_time);

create index idx_wa_users_mobile
    on wa_users (mobile);

create index idx_wa_users_email
    on wa_users (email);

