<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建AI轮询组提供方关系表迁移文件
 */
class CreateAiPollingGroupProvidersTable extends AbstractMigration
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
        $enabledType = $adapterType === 'sqlite' ? 'integer' : 'boolean';
        $enabledDefault = $adapterType === 'sqlite' ? 1 : true;

        $table = $this->table('ai_polling_group_providers', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('group_id', 'integer', [
            'null' => false,
            'comment' => '轮询组ID',
        ]);

        $table->addColumn('provider_id', 'string', [
            'limit' => 64,
            'null' => false,
            'comment' => '提供方ID',
        ]);

        $table->addColumn('weight', 'integer', [
            'null' => false,
            'default' => 1,
            'comment' => '权重',
        ]);

        $table->addColumn('enabled', $enabledType, [
            'null' => false,
            'default' => $enabledDefault,
            'comment' => '是否启用',
        ]);

        $table->addColumn('created_at', 'timestamp', [
            'null' => true,
            'default' => 'CURRENT_TIMESTAMP',
            'comment' => '创建时间',
        ]);

        $table->addColumn('updated_at', 'timestamp', [
            'null' => true,
            'default' => 'CURRENT_TIMESTAMP',
            'comment' => '更新时间',
        ]);

        $table->addIndex(['group_id', 'provider_id'], ['unique' => true]);
        $table->addIndex(['group_id'], ['name' => 'idx_polling_group_id']);
        $table->addIndex(['provider_id'], ['name' => 'idx_polling_provider_id']);

        $table->create();
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('ai_polling_group_providers')->drop()->save();
    }
}
