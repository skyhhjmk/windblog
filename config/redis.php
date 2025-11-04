<?php

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

// 统一使用单一Redis DB，通过key前缀区分不同用途
// 支持Redis单机和集群模式

$redisDb = (int) (getenv('REDIS_DB') ?: 0);
$redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
$redisPort = (int) (getenv('REDIS_PORT') ?: 6379);
$redisPassword = getenv('REDIS_PASSWORD') ?: null;

// 检测是否启用集群模式
$redisCluster = filter_var(getenv('REDIS_CLUSTER') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$redisClusterNodes = getenv('REDIS_CLUSTER_NODES') ? explode(',', getenv('REDIS_CLUSTER_NODES')) : [];

if ($redisCluster && !empty($redisClusterNodes)) {
    // Redis集群配置
    $clusters = [];
    foreach ($redisClusterNodes as $node) {
        [$host, $port] = array_pad(explode(':', $node), 2, 6379);
        $clusters[] = [
            'host' => trim($host),
            'port' => (int) $port,
            'password' => $redisPassword,
            'database' => $redisDb,
        ];
    }

    $config = [
        'clusters' => [
            'default' => $clusters,
        ],
        'options' => [
            'cluster' => 'redis', // 使用Redis原生集群
        ],
        'pool' => [
            'max_connections' => 10,
            'min_connections' => 2,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
    ];

    return [
        'default' => $config,
        'cache' => $config,
    ];
} else {
    // 单机Redis配置
    $config = [
        'host' => $redisHost,
        'port' => $redisPort,
        'password' => $redisPassword,
        'database' => $redisDb,
        'pool' => [
            'max_connections' => 10,
            'min_connections' => 2,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
    ];

    return [
        'default' => $config,
        'cache' => $config,
    ];
}
