<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建标签表迁移文件
 */
class CreateTagsTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $table = $this->table('tags', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('name', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '标签名称',
        ]);

        $table->addColumn('slug', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '标签别名',
        ]);

        $table->addColumn('description', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '标签描述',
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
        $table->addIndex(['deleted_at'], ['name' => 'idx_tags_deleted_at']);

        $table->create();
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('tags')->drop()->save();
    }
}
