<?php

/**
 * 全局辅助函数 - 在配置文件加载前可用
 * 这个文件通过 composer.json autoload.files 自动加载
 */
if (!function_exists('is_installed')) {
    /**
     * 检测系统是否已安装
     * 生产环境优先检查环境变量（如 DB_DEFAULT），使用 install.lock 作为后备方案
     *
     * @return bool
     */
    function is_installed(): bool
    {
        // 优先检查环境变量：如果设置了 DB_DEFAULT 或其他关键数据库配置，视为已安装
        $dbDefault = getenv('DB_DEFAULT');
        if ($dbDefault !== false && !empty($dbDefault)) {
            // 检查对应数据库的关键配置是否存在
            switch ($dbDefault) {
                case 'mysql':
                    $dbHost = getenv('DB_MYSQL_HOST');
                    if ($dbHost !== false && !empty($dbHost)) {
                        return true;
                    }
                    break;
                case 'pgsql':
                    $dbHost = getenv('DB_PGSQL_HOST');
                    if ($dbHost !== false && !empty($dbHost)) {
                        return true;
                    }
                    break;
                case 'sqlite':
                    $dbPath = getenv('DB_SQLITE_DATABASE');
                    if ($dbPath !== false && !empty($dbPath)) {
                        return true;
                    }
                    break;
            }
        }

        // 后备方案：检查 install.lock 文件
        $lockFile = base_path() . '/runtime/install.lock';
        if (file_exists($lockFile)) {
            return true;
        }

        return false;
    }
}
