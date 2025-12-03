<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建管理员角色关联表迁移文件
 */
class CreateWaAdminRolesTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $table = $this->table('wa_admin_roles', [
            'id' => false,
            'primary_key' => ['role_id', 'admin_id'],
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('role_id', 'integer', [
            'null' => false,
            'comment' => '角色id',
        ]);

        $table->addColumn('admin_id', 'integer', [
            'null' => false,
            'comment' => '管理员id',
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
        $this->table('wa_admin_roles')->drop()->save();
    }
}
