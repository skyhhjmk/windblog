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

if (!function_exists('container_info')) {
    /**
     * 检查当前环境是否在Docker或K8s中运行
     * 优先检查IN_CONTAINER环境变量，再验证系统特征
     *
     * @return array 包含是否在容器、是否在Docker、是否在K8s的结果
     *               'in_container' => bool, 是否在容器环境
     *               'in_docker'    => bool, 是否在Docker环境
     *               'in_k8s'      => bool, 是否在K8s环境
     */
    function container_info()
    {
        // 检查IN_CONTAINER环境变量（默认值为true）
        $inContainerEnv = getenv('IN_CONTAINER');
        if ($inContainerEnv === false) {
            // 环境变量未设置，使用默认值true
            $inContainerEnv = true;
        } else {
            // 转换字符串为布尔值（支持true/false/1/0/yes/no，不区分大小写）
            $lowerEnv = strtolower(trim($inContainerEnv));
            $inContainerEnv = in_array($lowerEnv, ['true', '1', 'yes'], true);
        }

        // 检查Docker特征
        $isDocker = false;
        // 检查/.dockerenv文件
        if (file_exists('/.dockerenv')) {
            $isDocker = true;
        } // 检查/proc/1/cgroup是否包含docker关键词
        elseif (file_exists('/proc/1/cgroup') && str_contains(file_get_contents('/proc/1/cgroup'), 'docker')) {
            $isDocker = true;
        }

        // 检查K8s特征（K8s基于Docker，需先满足Docker特征或环境变量）
        $isK8s = false;
        if (($inContainerEnv || $isDocker) && is_dir('/var/run/secrets/kubernetes.io/serviceaccount/')) {
            // 检查K8s服务账户文件（ca.crt、token、namespace）
            $requiredFiles = ['ca.crt', 'token', 'namespace'];
            $hasAllFiles = true;
            foreach ($requiredFiles as $file) {
                if (!file_exists("/var/run/secrets/kubernetes.io/serviceaccount/{$file}")) {
                    $hasAllFiles = false;
                    break;
                }
            }
            if ($hasAllFiles) {
                $isK8s = true;
            }
        }

        // 综合判断结果
        $result['in_docker'] = $isDocker;
        $result['in_k8s'] = $isK8s;
        // 容器环境：满足环境变量为true，或在Docker/K8s中
        $result['in_container'] = $inContainerEnv || $isDocker || $isK8s;

        return $result;
    }
}

if (!function_exists('is_install_lock_exists')) {
    /**
     * 检查安装锁文件是否存在
     *
     * @return bool
     */
    function is_install_lock_exists(): bool
    {
        return file_exists(base_path() . '/runtime/install.lock');
    }
}
