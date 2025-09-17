create table post_author
(
    id           bigserial
        primary key,
    post_id      bigint                                 not null
        constraint post_author_post_id_foreign
            references posts
            on delete cascade,
    author_id    integer                                not null
        constraint post_author_author_id_foreign
            references wa_users
            on delete cascade,
    is_primary   boolean                  default false not null,
    contribution varchar(50)              default NULL::character varying,
    created_at   timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at   timestamp with time zone default CURRENT_TIMESTAMP,
    unique (post_id, author_id)
);

comment on column post_author.post_id is '文章ID';

comment on column post_author.author_id is '作者ID';

comment on column post_author.is_primary is '是否主要作者';

comment on column post_author.contribution is '贡献类型';

comment on column post_author.created_at is '创建时间';

comment on column post_author.updated_at is '更新时间';

alter table post_author
    owner to postgres;

create index idx_post_author_post_id
    on post_author (post_id);

create index idx_post_author_author_id
    on post_author (author_id);

