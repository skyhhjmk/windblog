create table phinxlog
(
    version        bigint                not null
        primary key,
    migration_name varchar(100),
    start_time     timestamp,
    end_time       timestamp,
    breakpoint     boolean default false not null
);

alter table phinxlog
    owner to postgres;

