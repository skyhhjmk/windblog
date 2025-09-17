create table media
(
    id            bigserial
        primary key,
    filename      varchar(255)                       not null,
    original_name varchar(255)                       not null,
    file_path     varchar(512)                       not null,
    thumb_path    varchar(500)             default NULL::character varying,
    file_size     integer                  default 0 not null,
    mime_type     varchar(100)                       not null,
    alt_text      varchar(255)             default NULL::character varying,
    caption       text,
    description   text,
    author_id     integer,
    author_type   varchar(10)              default 'user'::character varying,
    created_at    timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at    timestamp with time zone default CURRENT_TIMESTAMP,
    deleted_at    timestamp with time zone
);

comment on column media.filename is '文件名';

comment on column media.original_name is '原始文件名';

comment on column media.file_path is '文件路径';

comment on column media.thumb_path is '缩略图路径';

comment on column media.file_size is '文件大小';

comment on column media.mime_type is 'MIME类型';

comment on column media.alt_text is '替代文本';

comment on column media.caption is '标题';

comment on column media.description is '描述';

comment on column media.author_id is '作者ID';

comment on column media.author_type is '作者类型';

comment on column media.created_at is '创建时间';

comment on column media.updated_at is '更新时间';

comment on column media.deleted_at is '删除时间';

alter table media
    owner to postgres;

create index idx_media_author_id
    on media (author_id);

create index idx_media_author_type
    on media (author_type);

create index idx_media_filename
    on media (filename);

create index idx_media_mime_type
    on media (mime_type);

create index idx_media_deleted_at
    on media (deleted_at);

