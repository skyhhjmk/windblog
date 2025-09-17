create table posts_ext
(
    id      bigserial
        primary key,
    post_id bigint not null
);

comment on column posts_ext.post_id is '文章ID';

alter table posts_ext
    owner to postgres;

