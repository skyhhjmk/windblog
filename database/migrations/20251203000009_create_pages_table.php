<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建页面表迁移文件
 */
class CreatePagesTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $table = $this->table('pages', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('title', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '页面标题',
        ]);

        $table->addColumn('slug', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '页面别名',
        ]);

        $table->addColumn('content', 'text', [
            'null' => false,
            'comment' => '页面内容',
        ]);

        $table->addColumn('status', 'string', [
            'limit' => 15,
            'null' => false,
            'default' => 'draft',
            'comment' => '页面状态',
        ]);

        $table->addColumn('template', 'string', [
            'limit' => 50,
            'null' => true,
            'default' => null,
            'comment' => '页面模板',
        ]);

        $table->addColumn('sort_order', 'integer', [
            'null' => true,
            'default' => 0,
            'comment' => '排序顺序',
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

        $table->addColumn('deleted_at', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '删除时间',
        ]);

        $table->addIndex(['slug'], ['unique' => true]);
        $table->addIndex(['deleted_at'], ['name' => 'idx_pages_deleted_at']);

        $table->create();
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('pages')->drop()->save();
    }
}
