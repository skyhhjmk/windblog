<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建导入任务表迁移文件
 */
class CreateImportJobsTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $table = $this->table('import_jobs', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('name', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '任务名称',
        ]);

        $table->addColumn('type', 'string', [
            'limit' => 50,
            'null' => false,
            'comment' => '任务类型',
        ]);

        $table->addColumn('file_path', 'string', [
            'limit' => 512,
            'null' => false,
            'comment' => '文件路径',
        ]);

        $table->addColumn('status', 'string', [
            'limit' => 15,
            'null' => false,
            'default' => 'pending',
            'comment' => '任务状态',
        ]);

        // 根据数据库类型选择合适的JSON类型
        if ($adapterType === 'pgsql') {
            $table->addColumn('options', 'jsonb', [
                'null' => true,
                'default' => null,
                'comment' => '导入选项',
            ]);
        } else {
            $table->addColumn('options', 'json', [
                'null' => true,
                'default' => null,
                'comment' => '导入选项',
            ]);
        }

        $table->addColumn('progress', 'integer', [
            'null' => false,
            'default' => 0,
            'comment' => '导入进度 0-100',
        ]);

        $table->addColumn('message', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '状态消息',
        ]);

        $table->addColumn('author_id', 'integer', [
            'null' => true,
            'default' => null,
            'comment' => '默认作者ID',
        ]);

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

        $table->addColumn('completed_at', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '完成时间',
        ]);

        $table->addIndex(['status'], ['name' => 'idx_import_jobs_status']);
        $table->addIndex(['author_id'], ['name' => 'idx_import_jobs_author_id']);

        $table->create();

        // SQLite不支持表级约束，使用单独的SQL语句添加约束
        if ($adapterType !== 'sqlite') {
            $this->execute("ALTER TABLE import_jobs ADD CONSTRAINT chk_import_jobs_status CHECK (status IN ('pending', 'processing', 'completed', 'failed'))");
        }
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('import_jobs')->drop()->save();
    }
}
