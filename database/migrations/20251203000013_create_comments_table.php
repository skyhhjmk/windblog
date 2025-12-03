<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建评论表迁移文件
 */
class CreateCommentsTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        $table = $this->table('comments', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('post_id', 'integer', [
            'null' => false,
            'comment' => '文章ID',
        ]);

        $table->addColumn('user_id', 'integer', [
            'null' => true,
            'default' => null,
            'comment' => '用户ID',
        ]);

        $table->addColumn('parent_id', 'integer', [
            'null' => true,
            'default' => null,
            'comment' => '父评论ID',
        ]);

        $table->addColumn('guest_name', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '访客姓名',
        ]);

        $table->addColumn('guest_email', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '访客邮箱',
        ]);

        $table->addColumn('content', 'text', [
            'null' => false,
            'comment' => '评论内容',
        ]);

        $table->addColumn('quoted_data', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '引用数据',
        ]);

        $table->addColumn('status', 'string', [
            'limit' => 10,
            'null' => false,
            'default' => 'pending',
            'comment' => '评论状态',
        ]);

        $table->addColumn('ai_moderation_result', 'string', [
            'limit' => 20,
            'null' => true,
            'default' => null,
            'comment' => 'AI审核结果',
        ]);

        $table->addColumn('ai_moderation_reason', 'text', [
            'null' => true,
            'default' => null,
            'comment' => 'AI审核原因',
        ]);

        $table->addColumn('ai_moderation_confidence', 'decimal', [
            'precision' => 3,
            'scale' => 2,
            'null' => true,
            'default' => null,
            'comment' => 'AI审核置信度',
        ]);

        // 根据数据库类型选择合适的JSON类型
        if ($adapterType === 'pgsql') {
            $table->addColumn('ai_moderation_categories', 'jsonb', [
                'null' => true,
                'default' => null,
                'comment' => 'AI检测到的问题类别',
            ]);
        } else {
            $table->addColumn('ai_moderation_categories', 'json', [
                'null' => true,
                'default' => null,
                'comment' => 'AI检测到的问题类别',
            ]);
        }

        $table->addColumn('ip_address', 'string', [
            'limit' => 45,
            'null' => true,
            'default' => null,
            'comment' => 'IP地址',
        ]);

        $table->addColumn('user_agent', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '用户代理',
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

        $table->addIndex(['post_id'], ['name' => 'idx_comments_post_id']);
        $table->addIndex(['user_id'], ['name' => 'idx_comments_user_id']);
        $table->addIndex(['parent_id'], ['name' => 'idx_comments_parent_id']);
        $table->addIndex(['status'], ['name' => 'idx_comments_status']);
        $table->addIndex(['deleted_at'], ['name' => 'idx_comments_deleted_at']);

        $table->create();

        // SQLite不支持表级约束，使用单独的SQL语句添加约束
        if ($adapterType !== 'sqlite') {
            $this->execute("ALTER TABLE comments ADD CONSTRAINT chk_comments_status CHECK (status IN ('pending', 'approved', 'spam', 'trash'))");
        }
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('comments')->drop()->save();
    }
}
