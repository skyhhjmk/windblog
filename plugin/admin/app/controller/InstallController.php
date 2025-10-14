<?php

namespace plugin\admin\app\controller;

use Illuminate\Database\Connection;
use plugin\admin\app\common\Util;
use plugin\admin\app\model\Admin;
use plugin\admin\app\model\Option;
use plugin\admin\app\model\Role;
use plugin\admin\app\model\Rule;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Throwable;
use Webman\Captcha\CaptchaBuilder;
use Webman\Captcha\PhraseBuilder;
use Illuminate\Database\Capsule\Manager;

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
        $critical_missing = []; // 致命错误

        $add = function(string $name, bool $ok, string $message = '', bool $critical = false) use (&$checks, &$missing, &$critical_missing) {
            $checks[] = ['name' => $name, 'ok' => $ok, 'message' => $message];
            if (!$ok) {
                $missing[] = $name;
                if ($critical) {
                    $critical_missing[] = $name;
                }
            }
        };

        // PHP 版本
        $php_ok = version_compare(PHP_VERSION, '8.2.0', '>=');
        $add('php>=8.2', $php_ok, $php_ok ? '当前版本: ' . PHP_VERSION : ('当前PHP版本为 ' . PHP_VERSION . '，需要 >= 8.2'), true);

        // 必需扩展（不包括数据库驱动）
        $required_exts = ['pdo','openssl','json','mbstring','curl','fileinfo','gd','xmlreader','dom','libxml'];
        foreach ($required_exts as $ext) {
            $ok = extension_loaded($ext);
            $add($ext, $ok, $ok ? '' : '未启用/未安装', $ext === 'pdo'); // PDO是致命的
        }

        // 数据库驱动（至少需要一个）
        $db_drivers = ['pdo_pgsql', 'pdo_mysql', 'pdo_sqlite'];
        $has_any_driver = false;
        foreach ($db_drivers as $driver) {
            $ok = extension_loaded($driver);
            if ($ok) {
                $has_any_driver = true;
            }
            $add($driver, $ok, $ok ? '' : '未启用/未安装', false); // 数据库驱动不单独标记为致命
        }

        // 如果没有任何数据库驱动，则标记为致命错误
        if (!$has_any_driver) {
            $critical_missing[] = 'database_driver';
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
            $add($ext, $ok, $ok ? '' : '未启用/未安装（可选）', false);
        }

        // 关键函数
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        foreach (['proc_open','exec'] as $fn) {
            $ok = function_exists($fn) && !in_array($fn, $disabled, true);
            $add($fn, $ok, $ok ? '' : '函数不可用（可能被禁用）', false);
        }

        // 目录写权限
        $upload_dir = base_path() . '/public/uploads';
        $ok = is_dir($upload_dir) ? is_writable($upload_dir) : is_writable(dirname($upload_dir));
        $add('public/uploads writable', $ok, $ok ? '' : ('目录不可写：' . $upload_dir), true);

        // 追加目录权限检查：runtime、logs、public/assets
        $dirs = [
            'runtime' => base_path() . '/runtime',
            'logs' => base_path() . '/runtime/logs',
            'public/assets' => base_path() . '/public/assets',
        ];
        foreach ($dirs as $label => $dir) {
            $ok = is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir));
            $add($label . ' writable', $ok, $ok ? '' : ('目录不可写：' . $dir), true);
        }

        // 汇总：只有致命错误才阻止安装
        $ok_all = empty($critical_missing);

        $message = '';
        if (!$ok_all) {
            if (!extension_loaded('pdo')) {
                $message = '缺少 PDO 扩展，这是必需的数据库扩展。请安装并启用后重试。';
            } elseif (!$has_any_driver) {
                $message = '未检测到任何可用的数据库驱动（pdo_pgsql/pdo_mysql/pdo_sqlite）。请至少安装一个数据库驱动后重试。';
            } elseif (!$php_ok) {
                $message = 'PHP 版本不满足要求，需要 >= 8.2.0，当前版本：' . PHP_VERSION;
            } else {
                $message = '存在未满足的关键依赖项，请检查并修复后重试。';
            }
        }

        return $this->json($ok_all ? 0 : 1, $message, [
            'ok' => $ok_all,
            'checks' => $checks,
            'missing' => $missing,
            'critical_missing' => $critical_missing,
            'has_any_driver' => $has_any_driver,
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
        $type = $request->post('type', 'pgsql');

        // 根据选择的数据库类型验证对应的驱动
        $driver_map = [
            'pgsql' => 'pdo_pgsql',
            'mysql' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite'
        ];

        if (!extension_loaded('pdo')) {
            return $this->json(1, '缺少 PDO 扩展。请安装并启用后重试安装。');
        }

        $required_driver = $driver_map[$type] ?? 'pdo_pgsql';
        if (!extension_loaded($required_driver)) {
            $db_names = [
                'pgsql' => 'PostgreSQL',
                'mysql' => 'MySQL',
                'sqlite' => 'SQLite'
            ];
            $db_name = $db_names[$type] ?? $type;
            return $this->json(1, "缺少 {$db_name} 数据库驱动（{$required_driver}）。请安装并启用后重试安装。");
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
        $port = (int)$request->post('port');
        $overwrite = $request->post('overwrite');

        // 根据数据库类型设置默认端口
        if (!$port) {
            $port = match($type) {
                'mysql' => 3306,
                'pgsql' => 5432,
                default => 5432
            };
        }

        try {
            $db = $this->getPdo($host, $user, $password, $port, null, $type);

            // 检查数据库是否存在，不存在则创建
            switch ($type) {
                case 'pgsql':
                    // PostgreSQL中检查数据库是否存在
                    $smt = $db->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
                    $smt->execute([$database]);
                    if (empty($smt->fetchAll())) {
                        $db->exec("CREATE DATABASE $database");
                    }
                    // PostgreSQL中切换数据库需要重新连接
                    $db = $this->getPdo($host, $user, $password, $port, $database, $type);
                    // 获取所有表名
                    $smt = $db->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                    $tables = $smt->fetchAll();
                    break;

                case 'mysql':
                    // MySQL中检查数据库是否存在
                    $smt = $db->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
                    $smt->execute([$database]);
                    if (empty($smt->fetchAll())) {
                        $db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    }
                    // MySQL中切换数据库需要重新连接
                    $db = $this->getPdo($host, $user, $password, $port, $database, $type);
                    // 获取所有表名
                    $smt = $db->query("SHOW TABLES");
                    $tables = $smt->fetchAll();
                    break;

                case 'sqlite':
                    // SQLite使用文件路径作为数据库名
                    $db = $this->getPdo($host, $user, $password, $port, $database, $type);
                    // SQLite中获取所有表名
                    $smt = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
                    $tables = $smt->fetchAll();
                    break;
            }
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
            // 按照外键依赖顺序删除表
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
                    // 根据数据库类型使用正确的引号语法
                    $dropSql = match($type) {
                        'mysql' => "DROP TABLE IF EXISTS `$table`",
                        'sqlite' => "DROP TABLE IF EXISTS \"$table\"",
                        'pgsql' => "DROP TABLE IF EXISTS \"$table\"",
                        default => "DROP TABLE IF EXISTS \"$table\""
                    };
                    $db->exec($dropSql);
                }
            }
        }

        // 根据数据库类型选择对应的SQL文件
        $sql_file = match($type) {
            'mysql' => base_path() . '/app/install/mysql.sql',
            'sqlite' => base_path() . '/app/install/sqlite.sql',
            default => base_path() . '/app/install/postgresql.sql'
        };

        if (!is_file($sql_file)) {
            return $this->json(1, '数据库SQL文件不存在: ' . $sql_file);
        }

        $sql_query = file_get_contents($sql_file);
        $sql_query = $this->removeComments($sql_query);
        $sql_query = $this->splitSqlFile($sql_query, ';');
        foreach ($sql_query as $sql) {
            if (trim($sql)) {
                $db->exec($sql);
            }
        }

        // 导入菜单
        $menus = include base_path() . '/plugin/admin/config/menu.php';
        // 重新获取数据库连接，因为迁移可能已经改变了数据库状态
        $db = $this->getPdo($host, $user, $password, $port, $database, $type);
        // 安装过程中没有数据库配置，无法使用api\Menu::import()方法
        $this->importMenu($menus, $db, $type);

        // 根据数据库类型生成配置内容
        $config_content = match($type) {
            'mysql' => $this->getMysqlConfigContent(),
            'sqlite' => $this->getSqliteConfigContent(),
            default => $this->getPgsqlConfigContent()
        };

        file_put_contents($database_config_file, $config_content);

        // 生成.env配置
        $env_config = match($type) {
            'mysql' => $this->getMysqlEnvConfig($host, $port, $database, $user, $password),
            'sqlite' => $this->getSqliteEnvConfig($database),
            default => $this->getPgsqlEnvConfig($host, $port, $database, $user, $password)
        };
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
            
            // 获取当前配置的数据库类型
            $db_type = config('database.default', 'pgsql');
            $connection = $config['connections'][$db_type];
            
            $pdo = $this->getPdo(
                $connection['host'] ?? '', 
                $connection['username'] ?? '', 
                $connection['password'] ?? '', 
                $connection['port'] ?? 5432, 
                $connection['database'] ?? '', 
                $db_type
            );

            // 前置校验：确保必需表已存在
            switch ($db_type) {
                case 'pgsql':
                    $existsStmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                    break;
                case 'mysql':
                    $existsStmt = $pdo->query("SHOW TABLES");
                    break;
                case 'sqlite':
                    $existsStmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
                    break;
            }
            
            $existingTables = array_map(static function($row){ return current($row); }, $existsStmt->fetchAll());
            $requiredTables = ['wa_admins', 'wa_admin_roles', 'wa_rules', 'wa_users'];
            $missing = array_diff($requiredTables, $existingTables);
            if (!empty($missing)) {
                return $this->json(1, '请先完成第一步（SQL未导入成功或安装未完成），缺少表：' . implode(',', $missing));
            }

            // 根据数据库类型确定引号字符
            $quoteChar = match($db_type) {
                'mysql' => '`',
                'sqlite' => '"',
                'pgsql' => '"',
                default => '"'
            };

            if ($pdo->query("select * from {$quoteChar}wa_admins{$quoteChar}")->fetchAll()) {
                return $this->json(1, '后台已经安装完毕，无法通过此页面创建管理员');
            }

            $smt = $pdo->prepare("insert into {$quoteChar}wa_admins{$quoteChar} ({$quoteChar}username{$quoteChar}, {$quoteChar}password{$quoteChar}, {$quoteChar}nickname{$quoteChar}, {$quoteChar}created_at{$quoteChar}, {$quoteChar}updated_at{$quoteChar}) values (:username, :password, :nickname, :created_at, :updated_at)");
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

            $smt = $pdo->prepare("insert into {$quoteChar}wa_admin_roles{$quoteChar} ({$quoteChar}role_id{$quoteChar}, {$quoteChar}admin_id{$quoteChar}) values (:role_id, :admin_id)");
            $smt->bindValue('role_id', 1);
            $smt->bindValue('admin_id', $admin_id);
            $smt->execute();

            $smt = $pdo->prepare("insert into {$quoteChar}wa_users{$quoteChar} ({$quoteChar}username{$quoteChar}, {$quoteChar}password{$quoteChar}, {$quoteChar}nickname{$quoteChar}, {$quoteChar}created_at{$quoteChar}, {$quoteChar}updated_at{$quoteChar}) values (:username, :password, :nickname, :created_at, :updated_at)");
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
     * @param string $type 数据库类型
     *
     * @return int
     */
    protected function addMenu(array $menu, \PDO $pdo, string $type = 'pgsql'): int
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
        
        // 根据数据库类型确定表名和字段引用方式
        $quoteChar = match($type) {
            'mysql' => '`',
            'sqlite' => '"',
            'pgsql' => '"',
            default => '"'
        };

        $table_name = "{$quoteChar}wa_rules{$quoteChar}";
        $quoted_columns = array_map(fn($col) => "{$quoteChar}{$col}{$quoteChar}", $columns);

        $sql = "insert into $table_name (" . implode(',', $quoted_columns) . ") values (" . implode(',', $values) . ")";
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
     * @param string $type 数据库类型
     *
     * @return void
     */
    protected function importMenu(array $menu_tree, \PDO $pdo, string $type = 'pgsql')
    {
        if (is_numeric(key($menu_tree)) && !isset($menu_tree['key'])) {
            foreach ($menu_tree as $item) {
                $this->importMenu($item, $pdo, $type);
            }
            return;
        }
        $children = $menu_tree['children'] ?? [];
        unset($menu_tree['children']);
        
        // 根据数据库类型确定表名和字段引用方式
        $quoteChar = match($type) {
            'mysql' => '`',
            'sqlite' => '"',
            'pgsql' => '"',
            default => '"'
        };

        $table_name = "{$quoteChar}wa_rules{$quoteChar}";
        $key_field = "{$quoteChar}key{$quoteChar}";

        $smt = $pdo->prepare("select * from $table_name where $key_field=:key limit 1");
        $smt->execute(['key' => $menu_tree['key']]);
        $old_menu = $smt->fetch();
        if ($old_menu) {
            $pid = $old_menu['id'];
            $params = [
                'title' => $menu_tree['title'],
                'icon' => $menu_tree['icon'] ?? '',
                'key' => $menu_tree['key'],
            ];
            $sql = "update $table_name set title=:title, icon=:icon where $key_field=:key";
            $smt = $pdo->prepare($sql);
            $smt->execute($params);
        } else {
            $pid = $this->addMenu($menu_tree, $pdo, $type);
        }
        foreach ($children as $menu) {
            $menu['pid'] = $pid;
            $this->importMenu($menu, $pdo, $type);
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
     * @param $type
     *
     * @return \PDO
     */
    protected function getPdo($host, $username, $password, $port, $database = null, $type = 'pgsql'): \PDO
    {
        $params = [
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 5,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];
        
        switch ($type) {
            case 'mysql':
                $dsn = "mysql:host=$host;port=$port;";
                if ($database) {
                    $dsn .= "dbname=$database";
                }
                $params[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
                break;
                
            case 'sqlite':
                // SQLite使用文件路径作为数据库名
                $dsn = "sqlite:" . ($database ?: ':memory:');
                // SQLite不需要用户名和密码
                return new \PDO($dsn, null, null, $params);
                
            case 'pgsql':
            default:
                $dsn = "pgsql:host=$host;port=$port;";
                if ($database) {
                    $dsn .= "dbname=$database";
                }
                break;
        }
        
        return new \PDO($dsn, $username, $password, $params);
    }
    
    /**
     * 获取 PostgreSQL 配置内容
     */
    protected function getPgsqlConfigContent(): string
    {
        return <<<EOF
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
        'mysql' => [
            'driver' => 'mysql',
            'host' => getenv('DB_MYSQL_HOST') ?: 'localhost',
            'port' => getenv('DB_MYSQL_PORT') ?: '3306',
            'database' => getenv('DB_MYSQL_DATABASE') ?: 'windblog',
            'username' => getenv('DB_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('DB_MYSQL_PASSWORD') ?: 'root',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'options' => [
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => getenv('DB_SQLITE_DATABASE') ?: runtime_path('windblog.db'),
            'prefix' => '',
            'foreign_key_constraints' => getenv('DB_SQLITE_FOREIGN_KEYS') ?: true,
        ],
    ]
];
EOF;
    }
    
    /**
     * 获取 MySQL 配置内容
     */
    protected function getMysqlConfigContent(): string
    {
        return $this->getPgsqlConfigContent(); // 使用相同的配置内容
    }
    
    /**
     * 获取 SQLite 配置内容
     */
    protected function getSqliteConfigContent(): string
    {
        return $this->getPgsqlConfigContent(); // 使用相同的配置内容
    }
    
    /**
     * 获取 PostgreSQL 环境配置
     */
    protected function getPgsqlEnvConfig($host, $port, $database, $user, $password): string
    {
        return <<<EOF
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
# CACHE_DRIVER 可选：none | memory | array | apcu | memcached | redis
# - none: 完全禁用缓存
# - memory/array: 进程内内存缓存（仅当前PHP进程/worker内有效，适合开发/单机）
# - apcu: 需开启 APCu 扩展；如在 CLI/常驻进程下需确保 php.ini 中 apc.enable_cli=1
# - memcached: 需安装 Memcached 扩展，使用 MEMCACHED_HOST/MEMCACHED_PORT
# - redis: 使用 REDIS_* 配置
CACHE_DRIVER=null

# 缓存键前缀，用于避免缓存键冲突，可为空或自定义字符串
CACHE_PREFIX=

# 默认缓存过期时间（秒），默认为86400秒（24小时）
CACHE_DEFAULT_TTL=86400

# 负面缓存过期时间（秒），用于缓存不存在的数据结果，防止缓存穿透，默认30秒
CACHE_NEGATIVE_TTL=30

# 缓存抖动时间（秒），用于给缓存过期时间添加随机值，防止缓存雪崩，默认0秒
CACHE_JITTER_SECONDS=0

# 缓存忙等待时间（毫秒），当缓存正在重建时，其他请求等待的时间，默认50毫秒
CACHE_BUSY_WAIT_MS=50

# 缓存忙等待最大重试次数，当缓存正在重建时，最多重试次数，默认3次
CACHE_BUSY_MAX_RETRIES=3

# 缓存锁过期时间（毫秒），用于防止缓存击穿，默认3000毫秒（3秒）
CACHE_LOCK_TTL_MS=3000

# Redis服务器主机地址
REDIS_HOST=127.0.0.1

# Redis服务器端口
REDIS_PORT=6379

# Redis密码，如果未设置密码则留空
REDIS_PASSWORD=

# Redis数据库索引，项目使用多库架构（DB0+DB1），此选项不可用
#REDIS_DATABASE=1

# Memcached服务器主机地址
MEMCACHED_HOST=127.0.0.1

# Memcached服务器端口
MEMCACHED_PORT=11211

# 缓存严格模式，设为true时会抛出异常而非尝试切换缓存器自愈
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
    }
    
    /**
     * 获取 MySQL 环境配置
     */
    protected function getMysqlEnvConfig($host, $port, $database, $user, $password): string
    {
        return <<<EOF
# 实例部署类型
DEPLOYMENT_TYPE=datacenter
DB_DEFAULT=mysql

# 数据库配置 - MySQL
DB_MYSQL_HOST=$host
DB_MYSQL_PORT=$port
DB_MYSQL_DATABASE=$database
DB_MYSQL_USERNAME=$user
DB_MYSQL_PASSWORD=$password

# 缓存配置
# CACHE_DRIVER 可选：none | memory | array | apcu | memcached | redis
# - none: 完全禁用缓存
# - memory/array: 进程内内存缓存（仅当前PHP进程/worker内有效，适合开发/单机）
# - apcu: 需开启 APCu 扩展；如在 CLI/常驻进程下需确保 php.ini 中 apc.enable_cli=1
# - memcached: 需安装 Memcached 扩展，使用 MEMCACHED_HOST/MEMCACHED_PORT
# - redis: 使用 REDIS_* 配置
CACHE_DRIVER=null

# 缓存键前缀，用于避免缓存键冲突，可为空或自定义字符串
CACHE_PREFIX=

# 默认缓存过期时间（秒），默认为86400秒（24小时）
CACHE_DEFAULT_TTL=86400

# 负面缓存过期时间（秒），用于缓存不存在的数据结果，防止缓存穿透，默认30秒
CACHE_NEGATIVE_TTL=30

# 缓存抖动时间（秒），用于给缓存过期时间添加随机值，防止缓存雪崩，默认0秒
CACHE_JITTER_SECONDS=0

# 缓存忙等待时间（毫秒），当缓存正在重建时，其他请求等待的时间，默认50毫秒
CACHE_BUSY_WAIT_MS=50

# 缓存忙等待最大重试次数，当缓存正在重建时，最多重试次数，默认3次
CACHE_BUSY_MAX_RETRIES=3

# 缓存锁过期时间（毫秒），用于防止缓存击穿，默认3000毫秒（3秒）
CACHE_LOCK_TTL_MS=3000

# Redis服务器主机地址
REDIS_HOST=127.0.0.1

# Redis服务器端口
REDIS_PORT=6379

# Redis密码，如果未设置密码则留空
REDIS_PASSWORD=

# Redis数据库索引，项目使用多库架构（DB0+DB1），此选项不可用
#REDIS_DATABASE=1

# Memcached服务器主机地址
MEMCACHED_HOST=127.0.0.1

# Memcached服务器端口
MEMCACHED_PORT=11211

# 缓存严格模式，设为true时会抛出异常而非尝试切换缓存器自愈
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
    }
    
    /**
     * 获取 SQLite 环境配置
     */
    protected function getSqliteEnvConfig($database): string
    {
        // 对于SQLite，我们只需要数据库路径
        $sqlitePath = $database;
        if (!str_starts_with($database, '/') && !str_starts_with($database, ':')) {
            // 如果不是绝对路径或特殊路径（如 :memory:），则使用 runtime_path
            $sqlitePath = runtime_path($database);
        }
        
        return <<<EOF
# 实例部署类型
DEPLOYMENT_TYPE=datacenter
DB_DEFAULT=sqlite

# 数据库配置 - SQLite
DB_SQLITE_DATABASE=$sqlitePath

# 缓存配置
# CACHE_DRIVER 可选：none | memory | array | apcu | memcached | redis
# - none: 完全禁用缓存
# - memory/array: 进程内内存缓存（仅当前PHP进程/worker内有效，适合开发/单机）
# - apcu: 需开启 APCu 扩展；如在 CLI/常驻进程下需确保 php.ini 中 apc.enable_cli=1
# - memcached: 需安装 Memcached 扩展，使用 MEMCACHED_HOST/MEMCACHED_PORT
# - redis: 使用 REDIS_* 配置
CACHE_DRIVER=null

# 缓存键前缀，用于避免缓存键冲突，可为空或自定义字符串
CACHE_PREFIX=

# 默认缓存过期时间（秒），默认为86400秒（24小时）
CACHE_DEFAULT_TTL=86400

# 负面缓存过期时间（秒），用于缓存不存在的数据结果，防止缓存穿透，默认30秒
CACHE_NEGATIVE_TTL=30

# 缓存抖动时间（秒），用于给缓存过期时间添加随机值，防止缓存雪崩，默认0秒
CACHE_JITTER_SECONDS=0

# 缓存忙等待时间（毫秒），当缓存正在重建时，其他请求等待的时间，默认50毫秒
CACHE_BUSY_WAIT_MS=50

# 缓存忙等待最大重试次数，当缓存正在重建时，最多重试次数，默认3次
CACHE_BUSY_MAX_RETRIES=3

# 缓存锁过期时间（毫秒），用于防止缓存击穿，默认3000毫秒（3秒）
CACHE_LOCK_TTL_MS=3000

# Redis服务器主机地址
REDIS_HOST=127.0.0.1

# Redis服务器端口
REDIS_PORT=6379

# Redis密码，如果未设置密码则留空
REDIS_PASSWORD=

# Redis数据库索引，项目使用多库架构（DB0+DB1），此选项不可用
#REDIS_DATABASE=1

# Memcached服务器主机地址
MEMCACHED_HOST=127.0.0.1

# Memcached服务器端口
MEMCACHED_PORT=11211

# 缓存严格模式，设为true时会抛出异常而非尝试切换缓存器自愈
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
    }
}