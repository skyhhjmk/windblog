create table import_jobs
(
    id           bigserial
        primary key,
    name         varchar(255)                                                  not null,
    type         varchar(50)                                                   not null,
    file_path    varchar(512)                                                  not null,
    status       varchar(15)              default 'pending'::character varying not null
        constraint chk_import_jobs_status
            check ((status)::text = ANY
                   ((ARRAY ['pending'::character varying, 'processing'::character varying, 'completed'::character varying, 'failed'::character varying])::text[])),
    options      text,
    progress     integer                  default 0                            not null,
    message      text,
    author_id    integer
        constraint import_jobs_author_id_foreign
            references wa_users
            on delete set null,
    created_at   timestamp with time zone default CURRENT_TIMESTAMP,
    updated_at   timestamp with time zone default CURRENT_TIMESTAMP,
    completed_at timestamp with time zone
);

comment on column import_jobs.name is '任务名称';

comment on column import_jobs.type is '任务类型';

comment on column import_jobs.file_path is '文件路径';

comment on column import_jobs.status is '任务状态';

comment on column import_jobs.options is '导入选项';

comment on column import_jobs.progress is '导入进度 0-100';

comment on column import_jobs.message is '状态消息';

comment on column import_jobs.author_id is '默认作者ID';

comment on column import_jobs.created_at is '创建时间';

comment on column import_jobs.updated_at is '更新时间';

comment on column import_jobs.completed_at is '完成时间';

alter table import_jobs
    owner to postgres;

create index idx_import_jobs_status
    on import_jobs (status);

create index idx_import_jobs_author_id
    on import_jobs (author_id);

