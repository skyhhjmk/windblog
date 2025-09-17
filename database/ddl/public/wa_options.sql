create table wa_options
(
    id         serial
        primary key,
    name       varchar(128) not null
        unique,
    value      text         not null,
    created_at timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at timestamp with time zone default CURRENT_TIMESTAMP
);

comment on column wa_options.name is '键';

comment on column wa_options.value is '值';

comment on column wa_options.created_at is '创建时间';

comment on column wa_options.updated_at is '更新时间';

alter table wa_options
    owner to postgres;

