<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建用户表迁移文件
 */
class CreateWaUsersTable extends AbstractMigration
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

        $table = $this->table('wa_users', $tableOptions);

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

        $table->addColumn('sex', 'string', [
            'limit' => 1,
            'null' => false,
            'default' => '1',
            'comment' => '性别',
        ]);

        $table->addColumn('avatar', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '头像',
        ]);

        $table->addColumn('email', 'string', [
            'limit' => 128,
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

        $table->addColumn('level', 'integer', [
            'null' => false,
            'default' => 0,
            'comment' => '等级',
        ]);

        $table->addColumn('birthday', 'date', [
            'null' => true,
            'default' => null,
            'comment' => '生日',
        ]);

        $table->addColumn('money', 'decimal', [
            'precision' => 10,
            'scale' => 2,
            'null' => false,
            'default' => 0.00,
            'comment' => '余额(元)',
        ]);

        $table->addColumn('score', 'integer', [
            'null' => false,
            'default' => 0,
            'comment' => '积分',
        ]);

        $table->addColumn('last_time', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '登录时间',
        ]);

        $table->addColumn('last_ip', 'string', [
            'limit' => 50,
            'null' => true,
            'default' => null,
            'comment' => '登录ip',
        ]);

        $table->addColumn('join_time', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '注册时间',
        ]);

        $table->addColumn('join_ip', 'string', [
            'limit' => 50,
            'null' => true,
            'default' => null,
            'comment' => '注册ip',
        ]);

        $table->addColumn('token', 'string', [
            'limit' => 50,
            'null' => true,
            'default' => null,
            'comment' => 'token',
        ]);

        $table->addColumn('email_verified_at', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '邮箱验证时间',
        ]);

        $table->addColumn('activation_token', 'string', [
            'limit' => 64,
            'null' => true,
            'default' => null,
            'comment' => '激活令牌',
        ]);

        $table->addColumn('activation_token_expires_at', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '激活令牌过期时间',
        ]);

        $table->addColumn('password_reset_token', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '密码重置令牌',
        ]);

        $table->addColumn('password_reset_expire', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '密码重置令牌过期时间',
        ]);

        $table->addColumn('timezone', 'string', [
            'limit' => 50,
            'null' => true,
            'default' => 'UTC',
            'comment' => '用户时区',
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

        $table->addColumn('role', 'integer', [
            'null' => false,
            'default' => 1,
            'comment' => '角色',
        ]);

        $table->addColumn('status', 'integer', [
            'null' => false,
            'default' => 0,
            'comment' => '禁用',
        ]);

        $table->addIndex(['username'], ['unique' => true]);
        $table->addIndex(['activation_token'], ['name' => 'idx_wa_users_activation_token']);
        $table->addIndex(['password_reset_token'], ['name' => 'idx_wa_users_password_reset_token']);
        $table->addIndex(['join_time'], ['name' => 'idx_wa_users_join_time']);
        $table->addIndex(['mobile'], ['name' => 'idx_wa_users_mobile']);
        $table->addIndex(['email'], ['name' => 'idx_wa_users_email']);

        $table->create();
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('wa_users')->drop()->save();
    }
}
