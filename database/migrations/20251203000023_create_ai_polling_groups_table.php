<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建AI轮询组表迁移文件
 */
class CreateAiPollingGroupsTable extends AbstractMigration
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

        $table = $this->table('ai_polling_groups', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('name', 'string', [
            'limit' => 100,
            'null' => false,
            'comment' => '轮询组名称',
        ]);

        $table->addColumn('description', 'string', [
            'limit' => 500,
            'null' => true,
            'default' => null,
            'comment' => '轮询组描述',
        ]);

        $table->addColumn('strategy', 'string', [
            'limit' => 20,
            'null' => false,
            'default' => 'polling',
            'comment' => '调度策略：polling=轮询, failover=主备',
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

        $table->addIndex(['name'], ['unique' => true]);

        $table->create();

        // SQLite不支持表级约束，使用单独的SQL语句添加约束
        if ($adapterType !== 'sqlite') {
            $this->execute("ALTER TABLE ai_polling_groups ADD CONSTRAINT chk_ai_polling_groups_strategy CHECK (strategy IN ('polling', 'failover'))");
        }
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('ai_polling_groups')->drop()->save();
    }
}
