<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建管理员表迁移文件
 */
class CreateWaAdminsTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $table = $this->table('wa_admins', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('username', 'string', [
            'limit' => 32,
            'null' => false,
            'comment' => '用户名',
        ]);

        $table->addColumn('nickname', 'string', [
            'limit' => 40,
            'null' => false,
            'comment' => '昵称',
        ]);

        $table->addColumn('password', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '密码',
        ]);

        $table->addColumn('avatar', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => '/app/admin/avatar.png',
            'comment' => '头像',
        ]);

        $table->addColumn('email', 'string', [
            'limit' => 100,
            'null' => true,
            'default' => null,
            'comment' => '邮箱',
        ]);

        $table->addColumn('mobile', 'string', [
            'limit' => 16,
            'null' => true,
            'default' => null,
            'comment' => '手机',
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

        $table->addColumn('login_at', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '登录时间',
        ]);

        $table->addColumn('status', 'integer', [
            'null' => true,
            'default' => null,
            'comment' => '禁用',
        ]);

        $table->addIndex(['username'], ['unique' => true]);

        $table->create();
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('wa_admins')->drop()->save();
    }
}
