<?php

namespace plugin\admin\app\controller;

use Exception;
use Illuminate\Database\Capsule\Manager;
use plugin\admin\app\common\Util;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Throwable;
use Webman\Captcha\CaptchaBuilder;
use Phinx\Console\PhinxApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use support\Log;

/**
 * 安装
 */
class InstallController extends Base
{
    /**
     * 不需要登录的方法
     *
     * @var string[]
     */
    protected $noNeedLogin = ['step0', 'step1', 'step2'];

    public function step0(Request $request): Response
    {
        $checks = [];
        $missing = [];

        $add = function(string $name, bool $ok, string $message = '') use (&$checks, &$missing) {
            $checks[] = ['name' => $name, 'ok' => $ok, 'message' => $message];
            if (!$ok) { $missing[] = $name; }
        };

        // PHP 版本
        $php_ok = version_compare(PHP_VERSION, '8.2.0', '>=');
        $add('php>=8.2', $php_ok, $php_ok ? '' : ('当前PHP版本为 ' . PHP_VERSION));

        // 扩展（必需）
        $exts = ['pdo','pdo_pgsql','openssl','json','mbstring','curl','fileinfo','gd','xmlreader','dom','libxml'];
        foreach ($exts as $ext) {
            $ok = extension_loaded($ext);
            $add($ext, $ok, $ok ? '' : '未启用/未安装');
        }

        // 扩展（可选）：从 composer.json 动态解析 ext-*
        $optional_exts = ['redis'];
        $composer_file = base_path() . '/composer.json';
        if (is_file($composer_file)) {
            $json = @json_decode(@file_get_contents($composer_file), true);
            if (is_array($json)) {
                $addExts = function(array $arr) use (&$optional_exts) {
                    foreach ($arr as $k => $_) {
                        if (is_string($k) && str_starts_with($k, 'ext-')) {
                            $optional_exts[] = substr($k, 4);
                        }
                    }
                };
                if (isset($json['suggest']) && is_array($json['suggest'])) {
                    $addExts($json['suggest']);
                }
                if (isset($json['require-dev']) && is_array($json['require-dev'])) {
                    $addExts($json['require-dev']);
                }
                if (isset($json['extra']['optional-extensions']) && is_array($json['extra']['optional-extensions'])) {
                    foreach ($json['extra']['optional-extensions'] as $e) {
                        if (is_string($e)) {
                            $optional_exts[] = ltrim($e, 'ext-');
                        }
                    }
                }
            }
        }
        $optional_exts = array_values(array_unique($optional_exts));
        foreach ($optional_exts as $ext) {
            $ok = extension_loaded($ext);
            $add($ext, $ok, $ok ? '' : '未启用/未安装（可选）');
        }

        // 关键函数
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        foreach (['proc_open','exec'] as $fn) {
            $ok = function_exists($fn) && !in_array($fn, $disabled, true);
            $add($fn, $ok, $ok ? '' : '函数不可用（可能被禁用）');
        }

        // 目录写权限
        $upload_dir = base_path() . '/public/uploads';
        $ok = is_dir($upload_dir) ? is_writable($upload_dir) : is_writable(dirname($upload_dir));
        $add('public/uploads writable', $ok, $ok ? '' : ('目录不可写：' . $upload_dir));

        // 追加目录权限检查：runtime、logs、public/assets
        $dirs = [
            'runtime' => base_path() . '/runtime',
            'logs' => base_path() . '/runtime/logs',
            'public/assets' => base_path() . '/public/assets',
        ];
        foreach ($dirs as $label => $dir) {
            $ok = is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir));
            $add($label . ' writable', $ok, $ok ? '' : ('目录不可写：' . $dir));
        }

        // 汇总
        $ok_all = true;
        foreach ($checks as $c) { if (!$c['ok']) { $ok_all = false; break; } }

        $driverTip = '';
        if (!extension_loaded('pdo') || !extension_loaded('pdo_pgsql')) {
            $driverTip = '检测到数据库驱动未启用（pdo/pdo_pgsql），这会导致“could not find driver”。请安装并启用后重试。';
        }

        return $this->json($ok_all ? 0 : 1, $driverTip ?: ($ok_all ? '' : '存在未满足的依赖项'), [
            'ok' => $ok_all,
            'checks' => $checks,
            'missing' => $missing,
            'php_version' => PHP_VERSION,
            'optional_exts' => $optional_exts,
        ]);
    }

    /**
     * 设置数据库
     *
     * @param Request $request
     *
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function step1(Request $request): Response
    {
        if (!(extension_loaded('pdo') && extension_loaded('pdo_pgsql'))) {
            return $this->json(1, '缺少数据库驱动（pdo/pdo_pgsql）。请安装并启用后重试安装。');
        }
        $database_config_file = base_path() . '/.env';
        clearstatcache();
        if (is_file($database_config_file)) {
            return $this->json(1, '管理后台已经安装！如需重新安装，请删除该插件数据库配置文件并重启');
        }

        if (!class_exists(CaptchaBuilder::class) || !class_exists(Manager::class)) {
            return $this->json(1, '请运行 composer require -W illuminate/database 安装illuminate/database组件并重启');
        }

        $user = $request->post('user');
        $password = $request->post('password');
        $database = $request->post('database');
        $host = $request->post('host');
        $port = (int)$request->post('port') ?: 5432;
        $overwrite = $request->post('overwrite');

        try {
            $db = $this->getPdo($host, $user, $password, $port);
            // PostgreSQL中检查数据库是否存在
            $smt = $db->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
            $smt->execute([$database]);
            if (empty($smt->fetchAll())) {
                $db->exec("CREATE DATABASE $database");
            }
            // PostgreSQL中切换数据库需要重新连接
            $db = $this->getPdo($host, $user, $password, $port, $database);
            // 获取所有表名
            $smt = $db->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
            $tables = $smt->fetchAll();
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // 兼容多语言/本地化错误信息
            if (
                stripos($msg, 'password authentication failed') !== false
                || stripos($msg, 'authentication failed') !== false
                || stripos($msg, '验证失败') !== false
                || stripos($msg, '密码') !== false && stripos($msg, '失败') !== false
                || stripos($msg, 'Password') !== false && stripos($msg, 'failed') !== false
            ) {
                return $this->json(1, '数据库用户名或密码错误');
            }
            if (stripos($msg, 'Connection refused') !== false) {
                return $this->json(1, 'Connection refused. 请确认数据库IP端口是否正确，数据库已经启动');
            }
            if (stripos($msg, 'timed out') !== false) {
                return $this->json(1, '数据库连接超时，请确认数据库IP端口是否正确，安全组及防火墙已经放行端口');
            }
            return $this->json(1, $msg);
        }

        $tables_to_install = [
            'wa_admins',
            'wa_admin_roles',
            'wa_roles',
            'wa_rules',
            'wa_options',
            'wa_users',
            'wa_uploads',
            'posts',
            'post_ext',
            'categories',
            'tags',
            'post_author',
            'post_category',
            'post_tag',
            'links',
            'pages',
            'settings',
            'media',
            'import_jobs',
            'comments'
        ];

        $tables_exist = [];
        foreach ($tables as $table) {
            $tables_exist[] = current($table);
        }
        $tables_conflict = array_intersect($tables_to_install, $tables_exist);
        if (!$overwrite) {
            if ($tables_conflict) {
                return $this->json(1, '以下表' . implode(',', $tables_conflict) . '已经存在，如需覆盖请选择强制覆盖');
            }
        } else {
            // 按照外键依赖顺序删除表，使用CASCADE方式
            $dropOrder = [
                'post_author',
                'post_category', 
                'post_tag',
                'post_ext',
                'comments',
                'import_jobs',
                'media',
                'wa_admin_roles',
                'wa_uploads',
                'posts',
                'categories',
                'tags',
                'wa_users',
                'links',
                'pages',
                'settings',
                'wa_options',
                'wa_admins',
                'wa_rules',
                'wa_roles'
            ];
            
            foreach ($dropOrder as $table) {
                if (in_array($table, $tables_exist)) {
                    $db->exec("DROP TABLE IF EXISTS \"$table\" CASCADE");
                }
            }
        }

        $sql_file = base_path() . '/app/install/postgresql.sql';
        if (!is_file($sql_file)) {
            return $this->json(1, '数据库SQL文件不存在');
        }

        $sql_query = file_get_contents($sql_file);
        $sql_query = $this->removeComments($sql_query);
        $sql_query = $this->splitSqlFile($sql_query, ';');
        foreach ($sql_query as $sql) {
            $db->exec($sql);
        }

        // 导入菜单
        $menus = include base_path() . '/plugin/admin/config/menu.php';
        // 重新获取数据库连接，因为迁移可能已经改变了数据库状态
        $db = $this->getPdo($host, $user, $password, $port, $database);
        // 安装过程中没有数据库配置，无法使用api\Menu::import()方法
        $this->importMenu($menus, $db);

        $config_content = <<<EOF
<?php
return [
    // 默认数据库
    'default' => getenv('DB_DEFAULT') ?: 'pgsql',
    // 各种数据库配置
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => getenv('DB_PGSQL_HOST') ?: 'localhost',
            'port' => getenv('DB_PGSQL_PORT') ?: '5432',
            'database' => getenv('DB_PGSQL_DATABASE') ?: 'windblog',
            'username' => getenv('DB_PGSQL_USERNAME') ?: 'root',
            'password' => getenv('DB_PGSQL_PASSWORD') ?: 'root',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
            'options' => [
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        ],
    ]
];
EOF;

        file_put_contents($database_config_file, $config_content);

        $env_config = <<<EOF
# 实例部署类型
DEPLOYMENT_TYPE=datacenter
DB_DEFAULT=pgsql

# 数据库配置 - PostgreSQL
DB_PGSQL_HOST=$host
DB_PGSQL_PORT=$port
DB_PGSQL_DATABASE=$database
DB_PGSQL_USERNAME=$user
DB_PGSQL_PASSWORD=$password

# 缓存配置
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
#REDIS_DATABASE=1
MEMCACHED_HOST=127.0.0.1
MEMCACHED_PORT=11211
CACHE_STRICT_MODE=false

# APP_DEBUG=false
# TWIG_CACHE_ENABLE=true
# TWIG_CACHE_PATH=runtime/twig_cache
# TWIG_AUTO_RELOAD=false
#
# 默认策略说明：
# - 当 APP_DEBUG=false（生产环境），默认：启用缓存、禁用 debug、禁用 auto_reload
# - 当 APP_DEBUG=true（开发环境），默认：关闭缓存、启用 debug、启用 auto_reload
# - 若设置 TWIG_CACHE_ENABLE，则优先生效（true 启用缓存，false 关闭缓存）
# - 若设置 TWIG_AUTO_RELOAD，则优先生效（true 开启自动重载，false 关闭）
# - 缓存目录默认为 runtime/twig_cache，可用 TWIG_CACHE_PATH 覆盖
EOF;
        file_put_contents(base_path() . '/.env', $env_config);


        // 尝试reload
        if (function_exists('posix_kill')) {
            set_error_handler(function () {
            });
            posix_kill(posix_getppid(), SIGUSR1);
            restore_error_handler();
        }

        return $this->json(0);
    }

    /**
     * 设置管理员
     *
     * @param Request $request
     *
     * @return Response
     * @throws BusinessException|Exception
     */
    public function step2(Request $request): Response
    {
        try {
            $username = $request->post('username');
            $password = $request->post('password');
            $password_confirm = $request->post('password_confirm');
            if ($password != $password_confirm) {
                return $this->json(1, '两次密码不一致');
            }
            if (!is_file($config_file = base_path() . '/config/database.php')) {
                return $this->json(1, '请先完成第一步数据库配置');
            }
            $config = include $config_file;
            $connection = $config['connections']['pgsql'];
            $pdo = $this->getPdo($connection['host'], $connection['username'], $connection['password'], $connection['port'], $connection['database']);

            // 前置校验：确保必需表已存在
            $existsStmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
            $existingTables = array_map(static function($row){ return current($row); }, $existsStmt->fetchAll());
            $requiredTables = ['wa_admins', 'wa_admin_roles', 'wa_rules', 'wa_users'];
            $missing = array_diff($requiredTables, $existingTables);
            if (!empty($missing)) {
                return $this->json(1, '请先完成第一步（SQL未导入成功或安装未完成），缺少表：' . implode(',', $missing));
            }

            if ($pdo->query('select * from wa_admins')->fetchAll()) {
                return $this->json(1, '后台已经安装完毕，无法通过此页面创建管理员');
            }

        $smt = $pdo->prepare("insert into wa_admins (username, password, nickname, created_at, updated_at) values (:username, :password, :nickname, :created_at, :updated_at)");
        $time = date('Y-m-d H:i:s');
        $data = [
            'username' => $username,
            'password' => Util::passwordHash($password),
            'nickname' => '超级管理员',
            'created_at' => $time,
            'updated_at' => $time
        ];
        foreach ($data as $key => $value) {
            $smt->bindValue($key, $value);
        }
        $smt->execute();
        $admin_id = $pdo->lastInsertId();

        $smt = $pdo->prepare("insert into wa_admin_roles (role_id, admin_id) values (:role_id, :admin_id)");
        $smt->bindValue('role_id', 1);
        $smt->bindValue('admin_id', $admin_id);
        $smt->execute();

        $smt = $pdo->prepare("insert into wa_users (username, password, nickname, created_at, updated_at) values (:username, :password, :nickname, :created_at, :updated_at)");
        $time = date('Y-m-d H:i:s');
        $data = [
            'username' => $username,
            'password' => Util::passwordHash($password),
            'nickname' => '超级管理员',
            'created_at' => $time,
            'updated_at' => $time
        ];
        foreach ($data as $key => $value) {
            $smt->bindValue($key, $value);
        }
        $smt->execute();

        $request->session()->flush();
        return $this->json(0);
        } catch (\Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }

    /**
     * 添加菜单
     *
     * @param array $menu
     * @param \PDO  $pdo
     *
     * @return int
     */
    protected function addMenu(array $menu, \PDO $pdo): int
    {
        $allow_columns = ['title', 'key', 'icon', 'href', 'pid', 'weight', 'type'];
        $data = [];
        foreach ($allow_columns as $column) {
            if (isset($menu[$column])) {
                $data[$column] = $menu[$column];
            }
        }
        $time = date('Y-m-d H:i:s');
        $data['created_at'] = $data['updated_at'] = $time;
        $values = [];
        foreach ($data as $k => $v) {
            $values[] = ":$k";
        }
        $columns = array_keys($data);
        $sql = "insert into wa_rules (" . implode(',', $columns) . ") values (" . implode(',', $values) . ")";
        $smt = $pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $smt->bindValue($key, $value);
        }
        $smt->execute();
        return $pdo->lastInsertId();
    }

    /**
     * 导入菜单
     *
     * @param array $menu_tree
     * @param \PDO  $pdo
     *
     * @return void
     */
    protected function importMenu(array $menu_tree, \PDO $pdo)
    {
        if (is_numeric(key($menu_tree)) && !isset($menu_tree['key'])) {
            foreach ($menu_tree as $item) {
                $this->importMenu($item, $pdo);
            }
            return;
        }
        $children = $menu_tree['children'] ?? [];
        unset($menu_tree['children']);
        $smt = $pdo->prepare("select * from wa_rules where key=:key limit 1");
        $smt->execute(['key' => $menu_tree['key']]);
        $old_menu = $smt->fetch();
        if ($old_menu) {
            $pid = $old_menu['id'];
            $params = [
                'title' => $menu_tree['title'],
                'icon' => $menu_tree['icon'] ?? '',
                'key' => $menu_tree['key'],
            ];
            $sql = "update wa_rules set title=:title, icon=:icon where key=:key";
            $smt = $pdo->prepare($sql);
            $smt->execute($params);
        } else {
            $pid = $this->addMenu($menu_tree, $pdo);
        }
        foreach ($children as $menu) {
            $menu['pid'] = $pid;
            $this->importMenu($menu, $pdo);
        }
    }

    /**
     * 去除sql文件中的注释
     * @param $sql
     * @return string
     */
    protected function removeComments($sql): string
    {
        return preg_replace("/(\n--[^\n]*)/","", $sql);
    }

    /**
     * 分割sql文件
     * @param $sql
     * @param $delimiter
     * @return array
     */
    function splitSqlFile($sql, $delimiter): array
    {
        $tokens = explode($delimiter, $sql);
        $output = array();
        $matches = array();
        $token_count = count($tokens);
        for ($i = 0; $i < $token_count; $i++) {
            if (($i != ($token_count - 1)) || (strlen($tokens[$i] > 0))) {
                $total_quotes = preg_match_all("/'/", $tokens[$i], $matches);
                $escaped_quotes = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $tokens[$i], $matches);
                $unescaped_quotes = $total_quotes - $escaped_quotes;

                if (($unescaped_quotes % 2) == 0) {
                    $output[] = $tokens[$i];
                    $tokens[$i] = "";
                } else {
                    $temp = $tokens[$i] . $delimiter;
                    $tokens[$i] = "";

                    $complete_stmt = false;
                    for ($j = $i + 1; (!$complete_stmt && ($j < $token_count)); $j++) {
                        $total_quotes = preg_match_all("/'/", $tokens[$j], $matches);
                        $escaped_quotes = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $tokens[$j], $matches);
                        $unescaped_quotes = $total_quotes - $escaped_quotes;
                        if (($unescaped_quotes % 2) == 1) {
                            $output[] = $temp . $tokens[$j];
                            $tokens[$j] = "";
                            $temp = "";
                            $complete_stmt = true;
                            $i = $j;
                        } else {
                            $temp .= $tokens[$j] . $delimiter;
                            $tokens[$j] = "";
                        }

                    }
                }
            }
        }

        return $output;
    }

    /**
     * 获取pdo连接
     *
     * @param $host
     * @param $username
     * @param $password
     * @param $port
     * @param $database
     *
     * @return \PDO
     */
    protected function getPdo($host, $username, $password, $port, $database = null): \PDO
    {
        $dsn = "pgsql:host=$host;port=$port;";
        if ($database) {
            $dsn .= "dbname=$database";
        }
        $params = [
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 5,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];
        return new \PDO($dsn, $username, $password, $params);
    }

}