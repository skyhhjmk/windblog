<?php

use Phinx\Migration\AbstractMigration;

/**
 * 基础设置迁移文件
 * 用于配置迁移期间的锁表和外键检查
 */
class BaseSetup extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        // 关闭外键检查以提高性能
        switch ($adapterType) {
            case 'mysql':
                $this->execute('SET FOREIGN_KEY_CHECKS = 0');
                break;
            case 'pgsql':
                $this->execute('SET session_replication_role = replica');
                break;
            case 'sqlite':
                $this->execute('PRAGMA foreign_keys = OFF');
                break;
        }

        // 锁表配置（根据需要添加）
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        // 恢复外键检查
        switch ($adapterType) {
            case 'mysql':
                $this->execute('SET FOREIGN_KEY_CHECKS = 1');
                break;
            case 'pgsql':
                $this->execute('SET session_replication_role = origin');
                break;
            case 'sqlite':
                $this->execute('PRAGMA foreign_keys = ON');
                break;
        }
    }
}
