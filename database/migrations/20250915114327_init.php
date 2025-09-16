<?php /** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 *
 */
final class Init extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        // 创建用户表
        $table = $this->table('wa_users');
        $table->addColumn('username', 'string', ['limit' => 32])
            ->addColumn('nickname', 'string', ['limit' => 40])
            ->addColumn('password', 'string', ['limit' => 255])
            ->addColumn('sex', 'string', ['limit' => 1, 'default' => '1']) // 替换enum为string
            ->addColumn('avatar', 'string', ['null' => true, 'limit' => 255])
            ->addColumn('email', 'string', ['null' => true, 'limit' => 128])
            ->addColumn('mobile', 'string', ['null' => true, 'limit' => 16])
            ->addColumn('level', 'integer', ['default' => 0])
            ->addColumn('birthday', 'date', ['null' => true])
            ->addColumn('money', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0.00'])
            ->addColumn('score', 'integer', ['default' => 0])
            ->addColumn('last_time', 'datetime', ['null' => true])
            ->addColumn('last_ip', 'string', ['null' => true, 'limit' => 50])
            ->addColumn('join_time', 'datetime', ['null' => true])
            ->addColumn('join_ip', 'string', ['null' => true, 'limit' => 50])
            ->addColumn('token', 'string', ['null' => true, 'limit' => 50])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addColumn('role', 'integer', ['default' => 1])
            ->addColumn('status', 'integer', ['default' => 0])
            ->addIndex(['username'], ['unique' => true])
            ->addIndex(['join_time'])
            ->addIndex(['mobile'])
            ->addIndex(['email'])
            ->create();

        // 创建分类表
        $table = $this->table('categories');
        $table->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('slug', 'string', ['limit' => 255])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('parent_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addColumn('status', 'boolean', ['default' => true])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
            ->addIndex(['parent_id'])
            ->addIndex(['status'])
            ->addIndex(['deleted_at'])
            ->create();

        // 创建文章表
        $table = $this->table('posts');
        $table->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('slug', 'string', ['limit' => 255])
            ->addColumn('content_type', 'string', ['limit' => 10, 'default' => 'markdown']) // 替换enum为string
            ->addColumn('content', 'text')
            ->addColumn('excerpt', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 15, 'default' => 'draft']) // 替换enum为string
            ->addColumn('featured', 'boolean', ['default' => false])
            ->addColumn('view_count', 'integer', ['default' => 0])
            ->addColumn('comment_count', 'integer', ['default' => 0])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
            ->addIndex(['status'])
            ->addIndex(['featured'])
            ->addIndex(['published_at'])
            ->addIndex(['deleted_at'])
            ->create();

        // 创建文章-分类关联表
        $table = $this->table('post_category');
        $table->addColumn('post_id', 'biginteger', ['signed' => false])
            ->addColumn('category_id', 'biginteger', ['signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['post_id'])
            ->addIndex(['category_id'])
            ->create();

        // 添加外键约束
        $this->table('post_category')
            ->addForeignKey('post_id', 'posts', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('category_id', 'categories', 'id', ['delete' => 'CASCADE'])
            ->update();

        // 创建文章-作者关联表
        $table = $this->table('post_author');
        $table->addColumn('post_id', 'biginteger', ['signed' => false])
            ->addColumn('author_id', 'integer', ['signed' => false])
            ->addColumn('is_primary', 'boolean', ['default' => false])
            ->addColumn('contribution', 'string', ['null' => true, 'limit' => 50])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['post_id'])
            ->addIndex(['author_id'])
            ->create();

        // 添加外键约束
        $this->table('post_author')
            ->addForeignKey('post_id', 'posts', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('author_id', 'wa_users', 'id', ['delete' => 'CASCADE'])
            ->update();

        // 创建友链表
        $table = $this->table('links');
        $table->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('url', 'string', ['limit' => 255])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('image', 'string', ['null' => true, 'limit' => 255])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('status', 'boolean', ['default' => true])
            ->addColumn('target', 'string', ['default' => '_blank', 'limit' => 20])
            ->addColumn('redirect_type', 'string', ['limit' => 10, 'default' => 'info']) // 替换enum为string
            ->addColumn('show_url', 'boolean', ['default' => true])
            ->addColumn('content', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addIndex(['status'])
            ->addIndex(['sort_order'])
            ->addIndex(['deleted_at'])
            ->create();

        // 创建页面表
        $table = $this->table('pages');
        $table->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('slug', 'string', ['limit' => 255])
            ->addColumn('content', 'text')
            ->addColumn('status', 'string', ['limit' => 15, 'default' => 'draft']) // 替换enum为string
            ->addColumn('template', 'string', ['null' => true, 'limit' => 50])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
            ->addIndex(['deleted_at'])
            ->create();

        // 创建网站设置表
        $table = $this->table('settings');
        $table->addColumn('key', 'string', ['limit' => 255])
            ->addColumn('value', 'text', ['null' => true])
            ->addColumn('type', 'string', ['default' => 'string', 'limit' => 50])
            ->addColumn('group', 'string', ['default' => 'general', 'limit' => 50])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['key'], ['unique' => true])
            ->addIndex(['group'])
            ->create();

        // 创建媒体附件表
        $table = $this->table('media');
        $table->addColumn('filename', 'string', ['limit' => 255])
            ->addColumn('original_name', 'string', ['limit' => 255])
            ->addColumn('file_path', 'string', ['limit' => 512])
            ->addColumn('thumb_path', 'string', ['null' => true, 'limit' => 500])
            ->addColumn('file_size', 'integer', ['default' => 0])
            ->addColumn('mime_type', 'string', ['limit' => 100])
            ->addColumn('alt_text', 'string', ['null' => true, 'limit' => 255])
            ->addColumn('caption', 'text', ['null' => true])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('author_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('author_type', 'string', ['limit' => 10, 'default' => 'user']) // 替换enum为string
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->addIndex(['author_id'])
            ->addIndex(['author_type'])
            ->addIndex(['filename'])
            ->addIndex(['mime_type'])
            ->addIndex(['deleted_at'])
            ->create();

        // 创建导入任务表
        $table = $this->table('import_jobs');
        $table->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('type', 'string', ['limit' => 50])
            ->addColumn('file_path', 'string', ['limit' => 512])
            ->addColumn('status', 'string', ['limit' => 15, 'default' => 'pending']) // 替换enum为string
            ->addColumn('options', 'text', ['null' => true])
            ->addColumn('progress', 'integer', ['default' => 0])
            ->addColumn('message', 'text', ['null' => true])
            ->addColumn('author_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('completed_at', 'timestamp', ['null' => true])
            ->addIndex(['status'])
            ->addIndex(['author_id'])
            ->create();

        // 添加导入任务外键约束
        $this->table('import_jobs')
            ->addForeignKey('author_id', 'wa_users', 'id', ['delete' => 'SET_NULL'])
            ->update();

        // 创建评论表
        $table = $this->table('comments');
        $table->addColumn('post_id', 'biginteger', ['signed' => false])
            ->addColumn('user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('parent_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('guest_name', 'string', ['null' => true, 'limit' => 255])
            ->addColumn('guest_email', 'string', ['null' => true, 'limit' => 255])
            ->addColumn('content', 'text')
            ->addColumn('status', 'string', ['limit' => 10, 'default' => 'pending']) // 替换enum为string
            ->addColumn('ip_address', 'string', ['null' => true, 'limit' => 45])
            ->addColumn('user_agent', 'string', ['null' => true, 'limit' => 255])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->addIndex(['post_id'])
            ->addIndex(['user_id'])
            ->addIndex(['parent_id'])
            ->addIndex(['status'])
            ->addIndex(['deleted_at'])
            ->create();

        // 添加评论外键约束
        $this->table('comments')
            ->addForeignKey('post_id', 'posts', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('user_id', 'wa_users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('parent_id', 'comments', 'id', ['delete' => 'SET_NULL'])
            ->update();

        // 创建标签表
        $table = $this->table('tags');
        $table->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('slug', 'string', ['limit' => 255])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
            ->addIndex(['deleted_at'])
            ->create();

        // 创建文章-标签关联表
        $table = $this->table('post_tag');
        $table->addColumn('post_id', 'biginteger', ['signed' => false])
            ->addColumn('tag_id', 'biginteger', ['signed' => false])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['post_id'])
            ->addIndex(['tag_id'])
            ->create();

        // 添加文章-标签外键约束
        $this->table('post_tag')
            ->addForeignKey('post_id', 'posts', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('tag_id', 'tags', 'id', ['delete' => 'CASCADE'])
            ->update();

        // 添加分类自关联外键约束
        $this->table('categories')
            ->addForeignKey('parent_id', 'categories', 'id', ['delete' => 'SET_NULL'])
            ->update();
            
        // 创建管理员角色表
        $table = $this->table('wa_admin_roles');
        $table->addColumn('role_id', 'integer')
            ->addColumn('admin_id', 'integer')
            ->addIndex(['role_id', 'admin_id'], ['unique' => true])
            ->create();

        // 创建管理员表
        $table = $this->table('wa_admins');
        $table->addColumn('username', 'string', ['limit' => 32])
            ->addColumn('nickname', 'string', ['limit' => 40])
            ->addColumn('password', 'string', ['limit' => 255])
            ->addColumn('avatar', 'string', ['default' => '/app/admin/avatar.png', 'limit' => 255, 'null' => true])
            ->addColumn('email', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('mobile', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addColumn('login_at', 'datetime', ['null' => true])
            ->addColumn('status', 'integer', ['null' => true])
            ->addIndex(['username'], ['unique' => true])
            ->create();

        // 创建选项表
        $table = $this->table('wa_options');
        $table->addColumn('name', 'string', ['limit' => 128])
            ->addColumn('value', 'text')
            ->addColumn('created_at', 'datetime', ['default' => '2022-08-15 00:00:00'])
            ->addColumn('updated_at', 'datetime', ['default' => '2022-08-15 00:00:00'])
            ->addIndex(['name'], ['unique' => true])
            ->create();

        // 创建管理员角色表
        $table = $this->table('wa_roles');
        $table->addColumn('name', 'string', ['limit' => 80])
            ->addColumn('rules', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addColumn('pid', 'integer', ['null' => true, 'signed' => false])
            ->create();

        // 创建权限规则表
        $table = $this->table('wa_rules');
        $table->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('icon', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('key', 'string', ['limit' => 255])
            ->addColumn('pid', 'integer', ['default' => 0, 'signed' => false])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addColumn('href', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('type', 'integer', ['default' => 1])
            ->addColumn('weight', 'integer', ['default' => 0, 'null' => true])
            ->create();

        // 创建附件表
        $table = $this->table('wa_uploads');
        $table->addColumn('name', 'string', ['limit' => 128])
            ->addColumn('url', 'string', ['limit' => 255])
            ->addColumn('admin_id', 'integer', ['null' => true])
            ->addColumn('file_size', 'integer')
            ->addColumn('mime_type', 'string', ['limit' => 255])
            ->addColumn('image_width', 'integer', ['null' => true])
            ->addColumn('image_height', 'integer', ['null' => true])
            ->addColumn('ext', 'string', ['limit' => 128])
            ->addColumn('storage', 'string', ['limit' => 255, 'default' => 'local'])
            ->addColumn('created_at', 'date', ['null' => true])
            ->addColumn('category', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('updated_at', 'date', ['null' => true])
            ->addIndex(['category'])
            ->addIndex(['admin_id'])
            ->addIndex(['name'])
            ->addIndex(['ext'])
            ->create();
    }
}