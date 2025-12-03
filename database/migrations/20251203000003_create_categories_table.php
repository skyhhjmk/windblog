<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建分类表迁移文件
 */
class CreateCategoriesTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $tableOptions = [
            'id' => 'id',
            'primary_key' => 'id',
        ];

        // 只对MySQL设置engine和collation
        if ($adapterType === 'mysql') {
            $tableOptions['engine'] = 'InnoDB';
            $tableOptions['collation'] = 'utf8mb4_unicode_ci';
        }

        $table = $this->table('categories', $tableOptions);

        $table->addColumn('name', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '分类名称',
        ]);

        $table->addColumn('slug', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '分类别名',
        ]);

        $table->addColumn('description', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '分类描述',
        ]);

        $table->addColumn('parent_id', 'integer', [
            'null' => true,
            'default' => null,
            'comment' => '父分类ID',
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

        // SQLite不支持布尔类型的默认值，需要使用tinyint
        $statusType = $adapterType === 'sqlite' ? 'integer' : 'boolean';
        $statusDefault = $adapterType === 'sqlite' ? 1 : true;

        $table->addColumn('status', $statusType, [
            'null' => false,
            'default' => $statusDefault,
            'comment' => '状态：1启用，0禁用',
        ]);

        $table->addColumn('deleted_at', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '删除时间',
        ]);

        $table->addIndex(['slug'], ['unique' => true]);
        $table->addIndex(['parent_id'], ['name' => 'idx_categories_parent_id']);
        $table->addIndex(['status'], ['name' => 'idx_categories_status']);
        $table->addIndex(['deleted_at'], ['name' => 'idx_categories_deleted_at']);

        $table->create();
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('categories')->drop()->save();
    }
}
