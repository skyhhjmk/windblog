<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建文章-作者关联表迁移文件
 */
class CreatePostAuthorTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        // SQLite不支持布尔类型的默认值，需要使用tinyint
        $isPrimaryType = $adapterType === 'sqlite' ? 'integer' : 'boolean';
        $isPrimaryDefault = $adapterType === 'sqlite' ? 0 : false;

        $table = $this->table('post_author', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('post_id', 'integer', [
            'null' => false,
            'comment' => '文章ID',
        ]);

        $table->addColumn('author_id', 'integer', [
            'null' => true,
            'default' => null,
            'comment' => '作者ID',
        ]);

        $table->addColumn('is_primary', $isPrimaryType, [
            'null' => false,
            'default' => $isPrimaryDefault,
            'comment' => '是否主要作者',
        ]);

        $table->addColumn('contribution', 'string', [
            'limit' => 50,
            'null' => true,
            'default' => null,
            'comment' => '贡献类型',
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

        $table->addIndex(['post_id', 'author_id'], ['unique' => true]);
        $table->addIndex(['post_id'], ['name' => 'idx_post_author_post_id']);
        $table->addIndex(['author_id'], ['name' => 'idx_post_author_author_id']);

        $table->create();
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('post_author')->drop()->save();
    }
}
