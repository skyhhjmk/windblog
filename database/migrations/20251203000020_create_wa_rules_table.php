<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建权限规则表迁移文件
 */
class CreateWaRulesTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $table = $this->table('wa_rules', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('title', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '标题',
        ]);

        $table->addColumn('icon', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '图标',
        ]);

        $table->addColumn('key', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '标识',
        ]);

        $table->addColumn('pid', 'integer', [
            'null' => false,
            'default' => 0,
            'comment' => '上级菜单',
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

        $table->addColumn('href', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => 'url',
        ]);

        $table->addColumn('type', 'integer', [
            'null' => false,
            'default' => 1,
            'comment' => '类型',
        ]);

        $table->addColumn('weight', 'integer', [
            'null' => true,
            'default' => 0,
            'comment' => '排序',
        ]);

        $table->create();
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('wa_rules')->drop()->save();
    }
}
