<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建网站设置表迁移文件
 */
class CreateSettingsTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $table = $this->table('settings', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('key', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '设置键名',
        ]);

        // 根据数据库类型选择合适的JSON类型
        if ($adapterType === 'pgsql') {
            $table->addColumn('value', 'jsonb', [
                'null' => true,
                'default' => null,
                'comment' => '设置值',
            ]);
        } else {
            $table->addColumn('value', 'json', [
                'null' => true,
                'default' => null,
                'comment' => '设置值',
            ]);
        }

        $table->addColumn('group', 'string', [
            'limit' => 50,
            'null' => true,
            'default' => 'general',
            'comment' => '设置分组',
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

        $table->addIndex(['key'], ['unique' => true]);
        $table->addIndex(['group'], ['name' => 'idx_settings_group']);

        $table->create();

        // 为PostgreSQL添加GIN索引
        if ($adapterType === 'pgsql') {
            $this->execute('CREATE INDEX IF NOT EXISTS idx_settings_value ON settings USING GIN (value)');
        }
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        // 先删除PostgreSQL特有的索引
        if ($adapterType === 'pgsql') {
            $this->execute('DROP INDEX IF EXISTS idx_settings_value');
        }

        $this->table('settings')->drop()->save();
    }
}
