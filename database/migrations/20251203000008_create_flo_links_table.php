<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建浮动链接表迁移文件
 */
class CreateFloLinksTable extends AbstractMigration
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
        $caseSensitiveType = $adapterType === 'sqlite' ? 'integer' : 'boolean';
        $replaceExistingType = $adapterType === 'sqlite' ? 'integer' : 'boolean';
        $enableHoverType = $adapterType === 'sqlite' ? 'integer' : 'boolean';
        $statusType = $adapterType === 'sqlite' ? 'integer' : 'boolean';
        $caseSensitiveDefault = $adapterType === 'sqlite' ? 0 : false;
        $replaceExistingDefault = $adapterType === 'sqlite' ? 1 : true;
        $enableHoverDefault = $adapterType === 'sqlite' ? 1 : true;
        $statusDefault = $adapterType === 'sqlite' ? 1 : true;

        $table = $this->table('flo_links', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('keyword', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '关键词',
        ]);

        $table->addColumn('url', 'string', [
            'limit' => 500,
            'null' => false,
            'comment' => '目标链接地址',
        ]);

        $table->addColumn('title', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '链接标题(用于悬浮窗显示)',
        ]);

        $table->addColumn('description', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '链接描述(用于悬浮窗显示)',
        ]);

        $table->addColumn('image', 'string', [
            'limit' => 500,
            'null' => true,
            'default' => null,
            'comment' => '图片URL(用于悬浮窗显示)',
        ]);

        $table->addColumn('priority', 'integer', [
            'null' => true,
            'default' => 100,
            'comment' => '优先级(数字越小优先级越高)',
        ]);

        $table->addColumn('match_mode', 'string', [
            'limit' => 10,
            'null' => true,
            'default' => 'first',
            'comment' => '匹配模式: first=仅替换首次出现, all=替换所有',
        ]);

        $table->addColumn('case_sensitive', $caseSensitiveType, [
            'null' => true,
            'default' => $caseSensitiveDefault,
            'comment' => '是否区分大小写',
        ]);

        $table->addColumn('replace_existing', $replaceExistingType, [
            'null' => true,
            'default' => $replaceExistingDefault,
            'comment' => '是否替换已有链接',
        ]);

        $table->addColumn('target', 'string', [
            'limit' => 20,
            'null' => true,
            'default' => '_blank',
            'comment' => '打开方式',
        ]);

        $table->addColumn('rel', 'string', [
            'limit' => 100,
            'null' => true,
            'default' => 'noopener noreferrer',
            'comment' => 'rel属性',
        ]);

        $table->addColumn('css_class', 'string', [
            'limit' => 100,
            'null' => true,
            'default' => 'flo-link',
            'comment' => 'CSS类名',
        ]);

        $table->addColumn('enable_hover', $enableHoverType, [
            'null' => true,
            'default' => $enableHoverDefault,
            'comment' => '是否启用悬浮窗',
        ]);

        $table->addColumn('hover_delay', 'integer', [
            'null' => true,
            'default' => 200,
            'comment' => '悬浮窗延迟显示时间(毫秒)',
        ]);

        $table->addColumn('status', $statusType, [
            'null' => true,
            'default' => $statusDefault,
            'comment' => '状态: true=启用, false=禁用',
        ]);

        $table->addColumn('sort_order', 'integer', [
            'null' => true,
            'default' => 999,
            'comment' => '排序权重',
        ]);

        // 根据数据库类型选择合适的JSON类型
        if ($adapterType === 'pgsql') {
            $table->addColumn('custom_fields', 'jsonb', [
                'null' => true,
                'default' => null,
                'comment' => '自定义字段(JSON格式)',
            ]);
        } else {
            $table->addColumn('custom_fields', 'json', [
                'null' => true,
                'default' => null,
                'comment' => '自定义字段(JSON格式)',
            ]);
        }

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
            'comment' => '软删除时间',
        ]);

        $table->addIndex(['keyword'], ['name' => 'idx_flo_links_keyword']);
        $table->addIndex(['status'], ['name' => 'idx_flo_links_status']);
        $table->addIndex(['priority'], ['name' => 'idx_flo_links_priority']);
        $table->addIndex(['sort_order'], ['name' => 'idx_flo_links_sort_order']);

        $table->create();

        // SQLite不支持表级约束，使用单独的SQL语句添加约束
        if ($adapterType !== 'sqlite') {
            $this->execute("ALTER TABLE flo_links ADD CONSTRAINT chk_flo_links_match_mode CHECK (match_mode IN ('first', 'all'))");
        }
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('flo_links')->drop()->save();
    }
}
