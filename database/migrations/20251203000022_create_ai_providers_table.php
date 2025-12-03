<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建AI提供方表迁移文件
 */
class CreateAiProvidersTable extends AbstractMigration
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

        $table = $this->table('ai_providers', [
            'id' => false,
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('id', 'string', [
            'limit' => 64,
            'null' => false,
            'comment' => '提供方唯一标识',
        ]);

        $table->addColumn('name', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '提供方名称',
        ]);

        $table->addColumn('template', 'string', [
            'limit' => 64,
            'null' => true,
            'default' => null,
            'comment' => '模板类型',
        ]);

        $table->addColumn('type', 'string', [
            'limit' => 64,
            'null' => false,
            'default' => 'openai',
            'comment' => '提供方类型',
        ]);

        $table->addColumn('config', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '配置JSON',
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

        $table->addIndex(['enabled'], ['name' => 'idx_ai_providers_enabled']);
        $table->addIndex(['template'], ['name' => 'idx_ai_providers_template']);

        $table->create();
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('ai_providers')->drop()->save();
    }
}
