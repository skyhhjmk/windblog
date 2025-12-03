<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加外键约束迁移文件
 * 负责配置所有表之间的外键关系
 */
class AddForeignKeys extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        // 配置外键约束
        $foreignKeys = [
            // user_oauth_bindings 表
            [
                'table' => 'user_oauth_bindings',
                'columns' => ['user_id'],
                'referenced_table' => 'wa_users',
                'referenced_columns' => ['id'],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],

            // categories 表
            [
                'table' => 'categories',
                'columns' => ['parent_id'],
                'referenced_table' => 'categories',
                'referenced_columns' => ['id'],
                'on_delete' => 'SET NULL',
                'on_update' => 'RESTRICT',
            ],

            // post_category 表
            [
                'table' => 'post_category',
                'columns' => ['post_id'],
                'referenced_table' => 'posts',
                'referenced_columns' => ['id'],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],
            [
                'table' => 'post_category',
                'columns' => ['category_id'],
                'referenced_table' => 'categories',
                'referenced_columns' => ['id'],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],

            // post_author 表
            [
                'table' => 'post_author',
                'columns' => ['post_id'],
                'referenced_table' => 'posts',
                'referenced_columns' => ['id'],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],
            [
                'table' => 'post_author',
                'columns' => ['author_id'],
                'referenced_table' => 'wa_users',
                'referenced_columns' => ['id'],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],

            // comments 表
            [
                'table' => 'comments',
                'columns' => ['post_id'],
                'referenced_table' => 'posts',
                'referenced_columns' => ['id'],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],
            [
                'table' => 'comments',
                'columns' => ['user_id'],
                'referenced_table' => 'wa_users',
                'referenced_columns' => ['id'],
                'on_delete' => 'SET NULL',
                'on_update' => 'RESTRICT',
            ],
            [
                'table' => 'comments',
                'columns' => ['parent_id'],
                'referenced_table' => 'comments',
                'referenced_columns' => ['id'],
                'on_delete' => 'SET NULL',
                'on_update' => 'RESTRICT',
            ],

            // post_tag 表
            [
                'table' => 'post_tag',
                'columns' => ['post_id'],
                'referenced_table' => 'posts',
                'referenced_columns' => ['id'],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],
            [
                'table' => 'post_tag',
                'columns' => ['tag_id'],
                'referenced_table' => 'tags',
                'referenced_columns' => ['id'],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],

            // post_ext 表
            [
                'table' => 'post_ext',
                'columns' => ['post_id'],
                'referenced_table' => 'posts',
                'referenced_columns' => ['id'],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],

            // ai_polling_group_providers 表
            [
                'table' => 'ai_polling_group_providers',
                'columns' => ['group_id'],
                'referenced_table' => 'ai_polling_groups',
                'referenced_columns' => ['id'],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],

            // import_jobs 表
            [
                'table' => 'import_jobs',
                'columns' => ['author_id'],
                'referenced_table' => 'wa_users',
                'referenced_columns' => ['id'],
                'on_delete' => 'SET NULL',
                'on_update' => 'RESTRICT',
            ],
        ];

        // 添加外键约束
        foreach ($foreignKeys as $foreignKey) {
            try {
                $constraintName = sprintf('fk_%s_%s', $foreignKey['table'], implode('_', $foreignKey['columns']));

                // 对于每个外键操作，创建一个新的表对象，确保操作独立性
                $table = $this->table($foreignKey['table']);

                // 先尝试添加约束，不先删除，避免事务问题
                $table->addForeignKey(
                    $foreignKey['columns'],
                    $foreignKey['referenced_table'],
                    $foreignKey['referenced_columns'],
                    [
                        'delete' => $foreignKey['on_delete'],
                        'update' => $foreignKey['on_update'],
                        'constraint' => $constraintName,
                    ]
                )->save();
            } catch (\Exception $e) {
                // 记录错误并继续处理下一个外键
                echo '添加外键约束失败: ' . $e->getMessage() . "\n";

                // 重置事务状态，确保后续操作能正常进行
                $adapterType = $this->getAdapter()->getAdapterType();
                if ($adapterType === 'pgsql') {
                    try {
                        $this->execute('ROLLBACK');
                    } catch (\Exception $rollbackException) {
                        // 忽略回滚失败的错误
                    }
                    $this->execute('BEGIN');
                } elseif ($adapterType === 'mysql') {
                    // MySQL 自动回滚失败的语句，不需要手动处理
                    $this->execute('SET FOREIGN_KEY_CHECKS=1');
                } elseif ($adapterType === 'sqlite') {
                    // SQLite 自动回滚失败的语句
                }
            }
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

        // 删除外键约束
        $foreignKeys = [
            // user_oauth_bindings 表
            ['table' => 'user_oauth_bindings', 'columns' => ['user_id']],

            // categories 表
            ['table' => 'categories', 'columns' => ['parent_id']],

            // post_category 表
            ['table' => 'post_category', 'columns' => ['post_id']],
            ['table' => 'post_category', 'columns' => ['category_id']],

            // post_author 表
            ['table' => 'post_author', 'columns' => ['post_id']],
            ['table' => 'post_author', 'columns' => ['author_id']],

            // comments 表
            ['table' => 'comments', 'columns' => ['post_id']],
            ['table' => 'comments', 'columns' => ['user_id']],
            ['table' => 'comments', 'columns' => ['parent_id']],

            // post_tag 表
            ['table' => 'post_tag', 'columns' => ['post_id']],
            ['table' => 'post_tag', 'columns' => ['tag_id']],

            // post_ext 表
            ['table' => 'post_ext', 'columns' => ['post_id']],

            // ai_polling_group_providers 表
            ['table' => 'ai_polling_group_providers', 'columns' => ['group_id']],

            // import_jobs 表
            ['table' => 'import_jobs', 'columns' => ['author_id']],
        ];

        foreach ($foreignKeys as $foreignKey) {
            $table = $this->table($foreignKey['table']);
            $constraintName = sprintf('fk_%s_%s', $foreignKey['table'], implode('_', $foreignKey['columns']));
            $table->dropForeignKey($foreignKey['columns'], $constraintName)->save();
        }
    }
}
