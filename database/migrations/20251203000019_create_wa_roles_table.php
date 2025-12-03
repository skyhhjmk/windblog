<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建管理员角色表迁移文件
 */
class CreateWaRolesTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $table = $this->table('wa_roles', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('name', 'string', [
            'limit' => 80,
            'null' => false,
            'comment' => '角色组',
        ]);

        $table->addColumn('rules', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '权限',
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

        $table->addColumn('pid', 'integer', [
            'null' => true,
            'default' => null,
            'comment' => '父级',
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
        $this->table('wa_roles')->drop()->save();
    }
}
