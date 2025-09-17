create table wa_uploads
(
    id           serial
        primary key,
    name         varchar(128)                                                not null,
    url          varchar(255)                                                not null,
    admin_id     integer,
    file_size    integer                                                     not null,
    mime_type    varchar(255)                                                not null,
    image_width  integer,
    image_height integer,
    ext          varchar(128)                                                not null,
    storage      varchar(255)             default 'local'::character varying not null,
    category     varchar(128)             default NULL::character varying,
    created_at   timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at   timestamp with time zone default CURRENT_TIMESTAMP
);

comment on column wa_uploads.name is '名称';

comment on column wa_uploads.url is '文件';

comment on column wa_uploads.admin_id is '管理员';

comment on column wa_uploads.file_size is '文件大小';

comment on column wa_uploads.mime_type is 'mime类型';

comment on column wa_uploads.image_width is '图片宽度';

comment on column wa_uploads.image_height is '图片高度';

comment on column wa_uploads.ext is '扩展名';

comment on column wa_uploads.storage is '存储位置';

comment on column wa_uploads.category is '类别';

comment on column wa_uploads.created_at is '上传时间';

comment on column wa_uploads.updated_at is '更新时间';

alter table wa_uploads
    owner to postgres;

create index idx_wa_uploads_category
    on wa_uploads (category);

create index idx_wa_uploads_admin_id
    on wa_uploads (admin_id);

create index idx_wa_uploads_name
    on wa_uploads (name);

create index idx_wa_uploads_ext
    on wa_uploads (ext);

