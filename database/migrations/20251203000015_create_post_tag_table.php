<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建文章-标签关联表迁移文件
 */
class CreatePostTagTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $table = $this->table('post_tag', [
            'id' => false,
            'primary_key' => ['post_id', 'tag_id'],
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('post_id', 'integer', [
            'null' => false,
            'comment' => '文章ID',
        ]);

        $table->addColumn('tag_id', 'integer', [
            'null' => false,
            'comment' => '标签ID',
        ]);

        $table->addColumn('created_at', 'timestamp', [
            'null' => false,
            'default' => 'CURRENT_TIMESTAMP',
            'comment' => '创建时间',
        ]);

        $table->addIndex(['post_id'], ['name' => 'idx_post_tag_post_id']);
        $table->addIndex(['tag_id'], ['name' => 'idx_post_tag_tag_id']);

        $table->create();
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('post_tag')->drop()->save();
    }
}
