create table post_category
(
    post_id     bigint not null
        constraint post_category_post_id_foreign
            references posts
            on delete cascade,
    category_id bigint not null
        constraint post_category_category_id_foreign
            references categories
            on delete cascade,
    created_at  timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at  timestamp with time zone default CURRENT_TIMESTAMP,
    primary key (post_id, category_id)
);

comment on column post_category.post_id is '文章ID';

comment on column post_category.category_id is '分类ID';

comment on column post_category.created_at is '创建时间';

comment on column post_category.updated_at is '更新时间';

alter table post_category
    owner to postgres;

create index idx_post_category_post_id
    on post_category (post_id);

create index idx_post_category_category_id
    on post_category (category_id);

