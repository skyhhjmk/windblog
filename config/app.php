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

use support\Request;

$__deployment = strtolower(trim((string) env('DEPLOYMENT_TYPE', 'datacenter')));

return [
    'debug' => getenv('APP_DEBUG'),
    'error_reporting' => E_ALL,
    'default_timezone' => 'UTC',
    'request_class' => Request::class,
    'public_path' => base_path() . DIRECTORY_SEPARATOR . 'public',
    'runtime_path' => base_path(false) . DIRECTORY_SEPARATOR . 'runtime',
    'controller_suffix' => 'Controller',
    'controller_reuse' => false,
    'deployment_type' => $__deployment,
    'is_edge_mode' => $__deployment === 'edge',
    'datacenter_url' => getenv('EDGE_DATACENTER_URL') ?: '',
    'edge_sync_interval' => (int) (getenv('EDGE_SYNC_INTERVAL') ?: 300),
    'edge_degrade_enabled' => filter_var(getenv('EDGE_DEGRADE_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
];
