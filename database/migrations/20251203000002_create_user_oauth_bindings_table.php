<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建用户OAuth绑定表迁移文件
 */
class CreateUserOauthBindingsTable extends AbstractMigration
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

        $table = $this->table('user_oauth_bindings', $tableOptions);

        $table->addColumn('user_id', 'integer', [
            'null' => false,
            'comment' => '用户ID',
        ]);

        $table->addColumn('provider', 'string', [
            'limit' => 50,
            'null' => false,
            'comment' => 'OAuth提供商(github, google, wechat等)',
        ]);

        $table->addColumn('provider_user_id', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => 'OAuth提供商的用户ID',
        ]);

        $table->addColumn('provider_username', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => 'OAuth提供商的用户名',
        ]);

        $table->addColumn('provider_email', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => 'OAuth提供商的邮箱',
        ]);

        $table->addColumn('provider_avatar', 'string', [
            'limit' => 500,
            'null' => true,
            'default' => null,
            'comment' => 'OAuth提供商的头像URL',
        ]);

        $table->addColumn('access_token', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '访问令牌(加密存储)',
        ]);

        $table->addColumn('refresh_token', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '刷新令牌(加密存储)',
        ]);

        $table->addColumn('expires_at', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '令牌过期时间',
        ]);

        // 根据数据库类型选择合适的JSON类型
        $adapterType = $this->getAdapter()->getAdapterType();
        if ($adapterType === 'pgsql') {
            $table->addColumn('extra_data', 'jsonb', [
                'null' => true,
                'default' => null,
                'comment' => '额外数据(JSON格式)',
            ]);
        } else {
            $table->addColumn('extra_data', 'json', [
                'null' => true,
                'default' => null,
                'comment' => '额外数据(JSON格式)',
            ]);
        }

        $table->addColumn('created_at', 'timestamp', [
            'null' => false,
            'default' => 'CURRENT_TIMESTAMP',
            'comment' => '绑定时间',
        ]);

        $table->addColumn('updated_at', 'timestamp', [
            'null' => false,
            'default' => 'CURRENT_TIMESTAMP',
            'comment' => '更新时间',
        ]);

        $table->addIndex(['provider', 'provider_user_id'], ['unique' => true, 'name' => 'unique_provider_user']);
        $table->addIndex(['user_id'], ['name' => 'idx_user_oauth_user_id']);
        $table->addIndex(['provider'], ['name' => 'idx_user_oauth_provider']);

        $table->create();
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('user_oauth_bindings')->drop()->save();
    }
}
