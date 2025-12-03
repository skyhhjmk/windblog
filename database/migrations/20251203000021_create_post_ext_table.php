<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建文章扩展表迁移文件
 */
class CreatePostExtTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $table = $this->table('post_ext', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('post_id', 'integer', [
            'null' => false,
            'comment' => '文章ID',
        ]);

        $table->addColumn('key', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '键',
        ]);

        // 根据数据库类型选择合适的JSON类型
        if ($adapterType === 'pgsql') {
            $table->addColumn('value', 'jsonb', [
                'null' => false,
                'comment' => '值',
            ]);
        } else {
            $table->addColumn('value', 'json', [
                'null' => false,
                'comment' => '值',
            ]);
        }

        $table->addIndex(['id'], ['name' => 'idx_post_ext_id']);
        $table->addIndex(['key'], ['name' => 'idx_post_ext_key']);

        $table->create();
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('post_ext')->drop()->save();
    }
}
