<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建友链表迁移文件
 */
class CreateLinksTable extends AbstractMigration
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
        $statusType = $adapterType === 'sqlite' ? 'integer' : 'boolean';
        $showUrlType = $adapterType === 'sqlite' ? 'integer' : 'boolean';
        $statusDefault = $adapterType === 'sqlite' ? 1 : true;
        $showUrlDefault = $adapterType === 'sqlite' ? 1 : true;

        $table = $this->table('links', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('name', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '友链名称',
        ]);

        $table->addColumn('url', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '友链URL',
        ]);

        $table->addColumn('description', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '友链描述',
        ]);

        $table->addColumn('icon', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '友链图标',
        ]);

        $table->addColumn('image', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '友链图片',
        ]);

        $table->addColumn('sort_order', 'integer', [
            'null' => true,
            'default' => 0,
            'comment' => '排序顺序',
        ]);

        $table->addColumn('status', $statusType, [
            'null' => false,
            'default' => $statusDefault,
            'comment' => '状态：1显示，0隐藏',
        ]);

        $table->addColumn('target', 'string', [
            'limit' => 20,
            'null' => true,
            'default' => '_blank',
            'comment' => '打开方式 (_blank, _self等)',
        ]);

        $table->addColumn('redirect_type', 'string', [
            'limit' => 10,
            'null' => false,
            'default' => 'info',
            'comment' => '跳转方式: direct=直接跳转, goto=中转页跳转, iframe=内嵌页面, info=详情页',
        ]);

        $table->addColumn('show_url', $showUrlType, [
            'null' => false,
            'default' => $showUrlDefault,
            'comment' => '是否在中转页显示原始URL',
        ]);

        $table->addColumn('content', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '链接详细介绍(Markdown格式)',
        ]);

        $table->addColumn('email', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '所有者电子邮件',
        ]);

        $table->addColumn('note', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '管理员备注',
        ]);

        $table->addColumn('seo_title', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => 'SEO 标题',
        ]);

        $table->addColumn('seo_keywords', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => 'SEO 关键词',
        ]);

        $table->addColumn('seo_description', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => 'SEO 描述',
        ]);

        // 根据数据库类型选择合适的JSON类型
        if ($adapterType === 'pgsql') {
            $table->addColumn('custom_fields', 'jsonb', [
                'null' => true,
                'default' => '{}',
                'comment' => '自定义字段',
            ]);
        } else {
            $table->addColumn('custom_fields', 'json', [
                'null' => true,
                'default' => '{}',
                'comment' => '自定义字段',
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
            'comment' => '删除时间',
        ]);

        $table->addIndex(['status'], ['name' => 'idx_links_status']);
        $table->addIndex(['sort_order'], ['name' => 'idx_links_sort_order']);
        $table->addIndex(['deleted_at'], ['name' => 'idx_links_deleted_at']);

        $table->create();

        // 为PostgreSQL添加GIN索引
        if ($adapterType === 'pgsql') {
            $this->execute('CREATE INDEX IF NOT EXISTS idx_links_custom_fields_gin ON links USING GIN (custom_fields)');
            $this->execute('CREATE INDEX IF NOT EXISTS idx_links_ai_audit_status ON links ((custom_fields ->> \'ai_audit_status\'))');
            $this->execute('CREATE INDEX IF NOT EXISTS idx_links_last_audit_time ON links ((custom_fields ->> \'last_audit_time\'))');
            $this->execute('CREATE INDEX IF NOT EXISTS idx_links_last_monitor_time ON links ((custom_fields ->> \'last_monitor_time\'))');
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
            $this->execute('DROP INDEX IF EXISTS idx_links_custom_fields_gin');
            $this->execute('DROP INDEX IF EXISTS idx_links_ai_audit_status');
            $this->execute('DROP INDEX IF EXISTS idx_links_last_audit_time');
            $this->execute('DROP INDEX IF EXISTS idx_links_last_monitor_time');
        }

        $this->table('links')->drop()->save();
    }
}
