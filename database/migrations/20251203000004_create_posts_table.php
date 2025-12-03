<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建文章表迁移文件
 */
class CreatePostsTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        // SQLite不支持布尔类型的默认值为false，需要使用tinyint
        $featuredType = $adapterType === 'sqlite' ? 'integer' : 'boolean';
        $allowCommentsType = $adapterType === 'sqlite' ? 'integer' : 'boolean';
        $featuredDefault = $adapterType === 'sqlite' ? 0 : false;
        $allowCommentsDefault = $adapterType === 'sqlite' ? 1 : true;

        $table = $this->table('posts', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('title', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '文章标题',
        ]);

        $table->addColumn('slug', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '文章别名',
        ]);

        $table->addColumn('content_type', 'string', [
            'limit' => 10,
            'null' => false,
            'default' => 'markdown',
            'comment' => '内容类型',
        ]);

        $table->addColumn('content', 'text', [
            'null' => false,
            'comment' => '文章内容',
        ]);

        $table->addColumn('excerpt', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '文章摘要',
        ]);

        $table->addColumn('ai_summary', 'text', [
            'null' => true,
            'default' => null,
            'comment' => 'AI生成的文章摘要',
        ]);

        $table->addColumn('seo_title', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '自定义 SEO 标题，如果为空则使用文章标题',
        ]);

        $table->addColumn('seo_description', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '自定义 SEO 描述，如果为空则使用摘要',
        ]);

        $table->addColumn('seo_keywords', 'string', [
            'limit' => 500,
            'null' => true,
            'default' => null,
            'comment' => '自定义 SEO 关键词，逗号分隔',
        ]);

        $table->addColumn('status', 'string', [
            'limit' => 15,
            'null' => false,
            'default' => 'draft',
            'comment' => '文章状态',
        ]);

        $table->addColumn('visibility', 'string', [
            'limit' => 20,
            'null' => false,
            'default' => 'public',
            'comment' => '文章可见性',
        ]);

        $table->addColumn('password', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '文章密码',
        ]);

        $table->addColumn('featured', $featuredType, [
            'null' => false,
            'default' => $featuredDefault,
            'comment' => '是否精选',
        ]);

        $table->addColumn('allow_comments', $allowCommentsType, [
            'null' => false,
            'default' => $allowCommentsDefault,
            'comment' => '是否允许评论',
        ]);

        $table->addColumn('comment_count', 'integer', [
            'null' => false,
            'default' => 0,
            'comment' => '评论数量',
        ]);

        $table->addColumn('published_at', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '发布时间',
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

        $table->addIndex(['slug'], ['unique' => true]);
        $table->addIndex(['visibility'], ['name' => 'idx_posts_visibility']);
        $table->addIndex(['allow_comments'], ['name' => 'idx_posts_allow_comments']);
        $table->addIndex(['status'], ['name' => 'idx_posts_status']);
        $table->addIndex(['featured'], ['name' => 'idx_posts_featured']);
        $table->addIndex(['published_at'], ['name' => 'idx_posts_published_at']);
        $table->addIndex(['deleted_at'], ['name' => 'idx_posts_deleted_at']);

        $table->create();

        // SQLite不支持表级约束，使用单独的SQL语句添加约束
        if ($adapterType !== 'sqlite') {
            $this->execute("ALTER TABLE posts ADD CONSTRAINT chk_posts_content_type CHECK (content_type IN ('markdown', 'html', 'text', 'visual'))");
            $this->execute("ALTER TABLE posts ADD CONSTRAINT chk_posts_status CHECK (status IN ('draft', 'published', 'archived'))");
            $this->execute("ALTER TABLE posts ADD CONSTRAINT chk_posts_visibility CHECK (visibility IN ('public', 'private', 'password'))");
        }
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('posts')->drop()->save();
    }
}
