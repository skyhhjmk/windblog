create table wa_admin_roles
(
    role_id  integer,
    admin_id integer,
    unique (role_id, admin_id)
);

comment on column wa_admin_roles.role_id is '角色id';

comment on column wa_admin_roles.admin_id is '管理员id';

alter table wa_admin_roles
    owner to postgres;

