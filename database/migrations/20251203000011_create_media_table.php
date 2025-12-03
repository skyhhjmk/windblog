<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建媒体附件表迁移文件
 */
class CreateMediaTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $table = $this->table('media', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('filename', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '文件名',
        ]);

        $table->addColumn('original_name', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '原始文件名',
        ]);

        $table->addColumn('file_path', 'string', [
            'limit' => 512,
            'null' => false,
            'comment' => '文件路径',
        ]);

        $table->addColumn('thumb_path', 'string', [
            'limit' => 500,
            'null' => true,
            'default' => null,
            'comment' => '缩略图路径',
        ]);

        $table->addColumn('file_size', 'integer', [
            'null' => false,
            'default' => 0,
            'comment' => '文件大小',
        ]);

        $table->addColumn('mime_type', 'string', [
            'limit' => 100,
            'null' => false,
            'comment' => 'MIME类型',
        ]);

        $table->addColumn('alt_text', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '替代文本',
        ]);

        $table->addColumn('caption', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '标题',
        ]);

        $table->addColumn('description', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '描述',
        ]);

        $table->addColumn('author_id', 'integer', [
            'null' => true,
            'default' => null,
            'comment' => '作者ID',
        ]);

        $table->addColumn('author_type', 'string', [
            'limit' => 10,
            'null' => true,
            'default' => 'user',
            'comment' => '作者类型',
        ]);

        // 根据数据库类型选择合适的JSON类型
        if ($adapterType === 'pgsql') {
            $table->addColumn('custom_fields', 'jsonb', [
                'null' => true,
                'default' => '{}',
                'comment' => '自定义字段',
            ]);
        } else {
            $table->addColumn('custom_fields', 'json', [
                'null' => true,
                'default' => '{}',
                'comment' => '自定义字段',
            ]);
        }

        $table->addColumn('created_at', 'timestamp', [
            'null' => false,
            'default' => 'CURRENT_TIMESTAMP',
            'comment' => '创建时间',
        ]);

        $table->addColumn('updated_at', 'timestamp', [
            'null' => false,
            'default' => 'CURRENT_TIMESTAMP',
            'comment' => '更新时间',
        ]);

        $table->addColumn('deleted_at', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '删除时间',
        ]);

        $table->addIndex(['author_id'], ['name' => 'idx_media_author_id']);
        $table->addIndex(['author_type'], ['name' => 'idx_media_author_type']);
        $table->addIndex(['filename'], ['name' => 'idx_media_filename']);
        $table->addIndex(['mime_type'], ['name' => 'idx_media_mime_type']);
        $table->addIndex(['deleted_at'], ['name' => 'idx_media_deleted_at']);

        $table->create();

        // 为PostgreSQL添加GIN索引
        if ($adapterType === 'pgsql') {
            $this->execute('CREATE INDEX IF NOT EXISTS idx_media_custom_fields ON media USING GIN (custom_fields)');
        }
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        // 先删除PostgreSQL特有的索引
        if ($adapterType === 'pgsql') {
            $this->execute('DROP INDEX IF EXISTS idx_media_custom_fields');
        }

        $this->table('media')->drop()->save();
    }
}
