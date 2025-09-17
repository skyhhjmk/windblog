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
    protected $noNeedLogin = ['step1', 'step2'];

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
            if (stripos($e->getMessage(), 'password authentication failed')) {
                return $this->json(1, '数据库用户名或密码错误');
            }
            if (stripos($e->getMessage(), 'Connection refused')) {
                return $this->json(1, 'Connection refused. 请确认数据库IP端口是否正确，数据库已经启动');
            }
            if (stripos($e->getMessage(), 'timed out')) {
                return $this->json(1, '数据库连接超时，请确认数据库IP端口是否正确，安全组及防火墙已经放行端口');
            }
            throw $e;
        }

        $tables_to_install = [
            'phinxlog',
            'wa_admins',
            'wa_admin_roles',
            'wa_roles',
            'wa_rules',
            'wa_options',
            'wa_users',
            'wa_uploads',
            'posts',
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

# 数据库配置 - MySQL
#DB_MYSQL_HOST=$host
#DB_MYSQL_PORT=$port
#DB_MYSQL_DATABASE=$database
#DB_MYSQL_USERNAME=$user
#DB_MYSQL_PASSWORD=$password

# 数据库配置 - PostgreSQL
DB_PGSQL_HOST=$host
DB_PGSQL_PORT=$port
DB_PGSQL_DATABASE=$database
DB_PGSQL_USERNAME=$user
DB_PGSQL_PASSWORD=$password
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

        $request->session()->flush();
        return $this->json(0);
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