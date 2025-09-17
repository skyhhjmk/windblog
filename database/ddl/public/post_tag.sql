create table post_tag
(
    post_id    bigint not null
        constraint post_tag_post_id_foreign
            references posts
            on delete cascade,
    tag_id     bigint not null
        constraint post_tag_tag_id_foreign
            references tags
            on delete cascade,
    created_at timestamp with time zone default CURRENT_TIMESTAMP,
    primary key (post_id, tag_id)
);

comment on column post_tag.post_id is '文章ID';

comment on column post_tag.tag_id is '标签ID';

comment on column post_tag.created_at is '创建时间';

alter table post_tag
    owner to postgres;

create index idx_post_tag_post_id
    on post_tag (post_id);

create index idx_post_tag_tag_id
    on post_tag (tag_id);

