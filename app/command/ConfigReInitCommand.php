<?php

namespace app\command;

use Exception;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class ConfigReInitCommand extends Command
{
    protected static $defaultName = 'config:re-init';

    protected static $defaultDescription = 'Re-initialize database configuration interactively';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $output->writeln('<info>数据库配置重新初始化工具</info>');
        $output->writeln('<comment>此工具将重新配置您的数据库连接设置</comment>');
        $output->writeln('');

        // 选择数据库类型
        $dbTypeQuestion = new ChoiceQuestion(
            '请选择数据库类型',
            ['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'sqlite' => 'SQLite'],
            'pgsql'
        );
        $dbType = $helper->ask($input, $output, $dbTypeQuestion);
        $dbTypeName = $dbType === 'pgsql' ? 'PostgreSQL' : ($dbType === 'mysql' ? 'MySQL' : 'SQLite');
        $output->writeln('您选择了: ' . $dbTypeName);
        $output->writeln('');

        // 根据数据库类型获取配置
        $config = [];
        switch ($dbType) {
            case 'sqlite':
                $config = $this->configureSqlite($input, $output, $helper);
                break;
            case 'mysql':
            case 'pgsql':
            default:
                $config = $this->configureHostDatabase($input, $output, $helper, $dbType);
                break;
        }

        // 测试数据库连接
        $output->writeln('<comment>正在测试数据库连接...</comment>');
        if ($this->testDatabaseConnection($dbType, $config, $output)) {
            $output->writeln('<info>✓ 数据库连接测试成功</info>');

            // 询问是否初始化数据库
            $initQuestion = new ChoiceQuestion(
                '是否初始化数据库？这将导入初始数据结构和内容',
                ['是', '否'],
                '否'
            );
            $shouldInit = $helper->ask($input, $output, $initQuestion);

            if ($shouldInit === '是') {
                $this->initializeDatabase($dbType, $config, $output);
            }
        } else {
            $output->writeln('<error>✗ 数据库连接测试失败</error>');

            // 询问是否强制应用配置
            $forceQuestion = new ChoiceQuestion(
                '是否强制应用配置？（即使连接测试失败）',
                ['是', '否'],
                '否'
            );
            $forceApply = $helper->ask($input, $output, $forceQuestion);

            if ($forceApply !== '是') {
                $output->writeln('<comment>操作已取消</comment>');

                return self::FAILURE;
            }
        }

        // 生成配置文件
        $this->generateConfigFiles($dbType, $config);

        $output->writeln('');
        $output->writeln('<info>数据库配置已完成！</info>');
        $output->writeln('<comment>请重启应用使配置生效</comment>');

        return self::SUCCESS;
    }

    /**
     * 配置 SQLite 数据库
     */
    private function configureSqlite(InputInterface $input, OutputInterface $output, QuestionHelper $helper): array
    {
        $output->writeln('<info>配置 SQLite 数据库</info>');

        // 获取数据库文件路径
        $defaultPath = runtime_path('windblog.db');
        $dbPathQuestion = new Question("数据库文件路径 (默认: $defaultPath): ", $defaultPath);
        $dbPath = $helper->ask($input, $output, $dbPathQuestion);

        return [
            'database' => $dbPath,
        ];
    }

    /**
     * 配置基于主机的数据库 (PostgreSQL/MySQL)
     */
    private function configureHostDatabase(InputInterface $input, OutputInterface $output, QuestionHelper $helper, string $dbType): array
    {
        $dbTypeName = $dbType === 'pgsql' ? 'PostgreSQL' : 'MySQL';
        $output->writeln("<info>配置 $dbTypeName 数据库</info>");

        // 获取主机地址
        $hostQuestion = new Question('数据库主机地址 (默认: localhost): ', 'localhost');
        $host = $helper->ask($input, $output, $hostQuestion);

        // 获取端口
        $defaultPort = $dbType === 'pgsql' ? '5432' : '3306';
        $portQuestion = new Question("数据库端口 (默认: $defaultPort): ", $defaultPort);
        $port = $helper->ask($input, $output, $portQuestion);

        // 获取数据库名
        $dbNameQuestion = new Question('数据库名称 (默认: windblog): ', 'windblog');
        $dbName = $helper->ask($input, $output, $dbNameQuestion);

        // 获取用户名
        $usernameQuestion = new Question('数据库用户名 (默认: root): ', 'root');
        $username = $helper->ask($input, $output, $usernameQuestion);

        // 获取密码
        $passwordQuestion = new Question('数据库密码 (默认: root): ', 'root');
        $passwordQuestion->setHidden(true);
        $passwordQuestion->setHiddenFallback(false);
        $password = $helper->ask($input, $output, $passwordQuestion);

        return [
            'host' => $host,
            'port' => $port,
            'database' => $dbName,
            'username' => $username,
            'password' => $password,
        ];
    }

    /**
     * 测试数据库连接
     */
    private function testDatabaseConnection(string $dbType, array $config, OutputInterface $output): bool
    {
        try {
            $pdo = null;

            switch ($dbType) {
                case 'sqlite':
                    $dbPath = $config['database'];
                    if ($dbPath !== ':memory:' && !file_exists($dbPath)) {
                        // 确保目录存在
                        $dir = dirname($dbPath);
                        if (!is_dir($dir)) {
                            mkdir($dir, 0o777, true);
                        }
                    }
                    $pdo = new PDO('sqlite:' . $dbPath);
                    break;

                case 'mysql':
                    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
                    $pdo = new PDO($dsn, $config['username'], $config['password'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 5,
                    ]);
                    break;

                case 'pgsql':
                    $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
                    $pdo = new PDO($dsn, $config['username'], $config['password'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 5,
                    ]);
                    break;
            }

            // 执行一个简单的查询来测试连接
            $stmt = $pdo->query('SELECT 1');
            $result = $stmt->fetch();

            $pdo = null; // 关闭连接

            return true;
        } catch (Exception $e) {
            $output->writeln('<error>连接失败: ' . $e->getMessage() . '</error>');

            return false;
        }
    }

    /**
     * 初始化数据库
     */
    private function initializeDatabase(string $dbType, array $config, OutputInterface $output): void
    {
        try {
            $output->writeln('<comment>正在初始化数据库...</comment>');

            // 创建 PDO 连接
            $pdo = null;
            switch ($dbType) {
                case 'sqlite':
                    $dbPath = $config['database'];
                    if ($dbPath !== ':memory:' && !file_exists($dbPath)) {
                        $dir = dirname($dbPath);
                        if (!is_dir($dir)) {
                            mkdir($dir, 0o777, true);
                        }
                    }
                    $pdo = new PDO('sqlite:' . $dbPath);
                    // 为 SQLite 启用外键约束
                    $pdo->exec('PRAGMA foreign_keys = ON');
                    break;

                case 'mysql':
                    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
                    $pdo = new PDO($dsn, $config['username'], $config['password'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ]);
                    // 设置字符集
                    $pdo->exec('SET NAMES utf8mb4');
                    break;

                case 'pgsql':
                    $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
                    $pdo = new PDO($dsn, $config['username'], $config['password'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ]);
                    break;
            }

            // 根据数据库类型选择对应的SQL文件
            $sqlFile = match ($dbType) {
                'mysql' => base_path('app/install/mysql.sql'),
                'sqlite' => base_path('app/install/sqlite.sql'),
                default => base_path('app/install/postgresql.sql')
            };

            if (!file_exists($sqlFile)) {
                throw new Exception("SQL 文件不存在: $sqlFile");
            }

            // 读取并执行SQL文件
            $sql = file_get_contents($sqlFile);

            // 对 SQLite 特殊处理保留关键字问题
            if ($dbType === 'sqlite') {
                // 将 "group" 替换为 "[group]" 以避免 SQLite 保留关键字冲突
                $sql = str_replace('(group)', '([group])', $sql);
                $sql = str_replace(' group ', ' [group] ', $sql);
            }

            // 移除注释
            $sql = preg_replace("/(\n--[^\n]*)/", '', $sql);

            // 分割SQL语句
            $statements = $this->splitSqlFile($sql, ';');

            $successCount = 0;
            $errorCount = 0;

            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    try {
                        $pdo->exec($statement);
                        $successCount++;
                    } catch (Exception $e) {
                        $errorCount++;
                        $output->writeln('<error>执行SQL语句失败: ' . $e->getMessage() . '</error>');
                        $output->writeln('<comment>语句: ' . substr($statement, 0, 100) . '...</comment>');
                    }
                }
            }

            // 导入菜单
            $this->importMenu($pdo, $dbType, $output);

            $pdo = null; // 关闭连接
            $output->writeln("<info>✓ 数据库初始化完成，成功执行 {$successCount} 条语句");
            if ($errorCount > 0) {
                $output->writeln("<comment>有 {$errorCount} 条语句执行失败</comment>");
            }
        } catch (Exception $e) {
            $output->writeln('<error>数据库初始化失败: ' . $e->getMessage() . '</error>');
        }
    }

    /**
     * 分割sql文件
     *
     * @param $sql
     * @param $delimiter
     *
     * @return array
     */
    private function splitSqlFile($sql, $delimiter): array
    {
        $tokens = explode($delimiter, $sql);
        $output = [];
        $matches = [];
        $token_count = count($tokens);
        for ($i = 0; $i < $token_count; $i++) {
            if (($i != ($token_count - 1)) || (strlen($tokens[$i] > 0))) {
                $total_quotes = preg_match_all("/'/", $tokens[$i], $matches);
                $escaped_quotes = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $tokens[$i], $matches);
                $unescaped_quotes = $total_quotes - $escaped_quotes;

                if (($unescaped_quotes % 2) == 0) {
                    $output[] = $tokens[$i];
                    $tokens[$i] = '';
                } else {
                    $temp = $tokens[$i] . $delimiter;
                    $tokens[$i] = '';

                    $complete_stmt = false;
                    for ($j = $i + 1; (!$complete_stmt && ($j < $token_count)); $j++) {
                        $total_quotes = preg_match_all("/'/", $tokens[$j], $matches);
                        $escaped_quotes = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $tokens[$j], $matches);
                        $unescaped_quotes = $total_quotes - $escaped_quotes;
                        if (($unescaped_quotes % 2) == 1) {
                            $output[] = $temp . $tokens[$j];
                            $tokens[$j] = '';
                            $temp = '';
                            $complete_stmt = true;
                            $i = $j;
                        } else {
                            $temp .= $tokens[$j] . $delimiter;
                            $tokens[$j] = '';
                        }

                    }
                }
            }
        }

        return $output;
    }

    /**
     * 生成配置文件
     */
    private function generateConfigFiles(string $dbType, array $config): void
    {
        // 生成 database.php 配置
        $databaseConfig = <<<EOF
            <?php
            return [
                // 默认数据库
                'default' => getenv('DB_DEFAULT') ?: '$dbType',
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

        // 写入 database.php 配置文件
        file_put_contents(config_path('database.php'), $databaseConfig);

        // 生成 .env 配置
        $envConfig = "# 实例部署类型\n";
        $envConfig .= "DEPLOYMENT_TYPE=datacenter\n";
        $envConfig .= "DB_DEFAULT=$dbType\n\n";

        switch ($dbType) {
            case 'sqlite':
                $envConfig .= "# 数据库配置 - SQLite\n";
                $envConfig .= 'DB_SQLITE_DATABASE=' . ($config['database'] ?? runtime_path('windblog.db')) . "\n";
                break;

            case 'mysql':
                $envConfig .= "# 数据库配置 - MySQL\n";
                $envConfig .= 'DB_MYSQL_HOST=' . ($config['host'] ?? 'localhost') . "\n";
                $envConfig .= 'DB_MYSQL_PORT=' . ($config['port'] ?? '3306') . "\n";
                $envConfig .= 'DB_MYSQL_DATABASE=' . ($config['database'] ?? 'windblog') . "\n";
                $envConfig .= 'DB_MYSQL_USERNAME=' . ($config['username'] ?? 'root') . "\n";
                $envConfig .= 'DB_MYSQL_PASSWORD=' . ($config['password'] ?? 'root') . "\n";
                break;

            case 'pgsql':
            default:
                $envConfig .= "# 数据库配置 - PostgreSQL\n";
                $envConfig .= 'DB_PGSQL_HOST=' . ($config['host'] ?? 'localhost') . "\n";
                $envConfig .= 'DB_PGSQL_PORT=' . ($config['port'] ?? '5432') . "\n";
                $envConfig .= 'DB_PGSQL_DATABASE=' . ($config['database'] ?? 'windblog') . "\n";
                $envConfig .= 'DB_PGSQL_USERNAME=' . ($config['username'] ?? 'root') . "\n";
                $envConfig .= 'DB_PGSQL_PASSWORD=' . ($config['password'] ?? 'root') . "\n";
                break;
        }

        $envConfig .= "\n# 缓存配置\n";
        $envConfig .= "# CACHE_DRIVER 可选：none | memory | array | apcu | memcached | redis\n";
        $envConfig .= "# - none: 完全禁用缓存\n";
        $envConfig .= "# - memory/array: 进程内内存缓存（仅当前PHP进程/worker内有效，适合开发/单机）\n";
        $envConfig .= "# - apcu: 需开启 APCu 扩展；如在 CLI/常驻进程下需确保 php.ini 中 apc.enable_cli=1\n";
        $envConfig .= "# - memcached: 需安装 Memcached 扩展，使用 MEMCACHED_HOST/MEMCACHED_PORT\n";
        $envConfig .= "# - redis: 使用 REDIS_* 配置\n";
        $envConfig .= "CACHE_DRIVER=null\n\n";
        $envConfig .= "# 缓存键前缀，用于避免缓存键冲突，可为空或自定义字符串\n";
        $envConfig .= "CACHE_PREFIX=\n\n";
        $envConfig .= "# 默认缓存过期时间（秒），默认为86400秒（24小时）\n";
        $envConfig .= "CACHE_DEFAULT_TTL=86400\n\n";
        $envConfig .= "# 负面缓存过期时间（秒），用于缓存不存在的数据结果，防止缓存穿透，默认30秒\n";
        $envConfig .= "CACHE_NEGATIVE_TTL=30\n\n";
        $envConfig .= "# 缓存抖动时间（秒），用于给缓存过期时间添加随机值，防止缓存雪崩，默认0秒\n";
        $envConfig .= "CACHE_JITTER_SECONDS=0\n\n";
        $envConfig .= "# 缓存忙等待时间（毫秒），当缓存正在重建时，其他请求等待的时间，默认50毫秒\n";
        $envConfig .= "CACHE_BUSY_WAIT_MS=50\n\n";
        $envConfig .= "# 缓存忙等待最大重试次数，当缓存正在重建时，最多重试次数，默认3次\n";
        $envConfig .= "CACHE_BUSY_MAX_RETRIES=3\n\n";
        $envConfig .= "# 缓存锁过期时间（毫秒），用于防止缓存击穿，默认3000毫秒（3秒）\n";
        $envConfig .= "CACHE_LOCK_TTL_MS=3000\n\n";
        $envConfig .= "# Redis服务器主机地址\n";
        $envConfig .= "REDIS_HOST=127.0.0.1\n\n";
        $envConfig .= "# Redis服务器端口\n";
        $envConfig .= "REDIS_PORT=6379\n\n";
        $envConfig .= "# Redis密码，如果未设置密码则留空\n";
        $envConfig .= "REDIS_PASSWORD=\n\n";
        $envConfig .= "# Redis数据库索引，项目使用多库架构（DB0+DB1），此选项不可用\n";
        $envConfig .= "#REDIS_DATABASE=1\n\n";
        $envConfig .= "# Memcached服务器主机地址\n";
        $envConfig .= "MEMCACHED_HOST=127.0.0.1\n\n";
        $envConfig .= "# Memcached服务器端口\n";
        $envConfig .= "MEMCACHED_PORT=11211\n\n";
        $envConfig .= "# 缓存严格模式，设为true时会抛出异常而非尝试切换缓存器自愈\n";
        $envConfig .= "CACHE_STRICT_MODE=false\n\n";
        $envConfig .= "# APP_DEBUG=false\n";
        $envConfig .= "# TWIG_CACHE_ENABLE=true\n";
        $envConfig .= "# TWIG_CACHE_PATH=runtime/twig_cache\n";
        $envConfig .= "# TWIG_AUTO_RELOAD=false\n";
        $envConfig .= "#\n";
        $envConfig .= "# 默认策略说明：\n";
        $envConfig .= "# - 当 APP_DEBUG=false（生产环境），默认：启用缓存、禁用 debug、禁用 auto_reload\n";
        $envConfig .= "# - 当 APP_DEBUG=true（开发环境），默认：关闭缓存、启用 debug、启用 auto_reload\n";
        $envConfig .= "# - 若设置 TWIG_CACHE_ENABLE，则优先生效（true 启用缓存，false 关闭缓存）\n";
        $envConfig .= "# - 若设置 TWIG_AUTO_RELOAD，则优先生效（true 开启自动重载，false 关闭）\n";
        $envConfig .= "# - 缓存目录默认为 runtime/twig_cache，可用 TWIG_CACHE_PATH 覆盖\n";

        // 写入 .env 配置文件
        file_put_contents(base_path('.env'), $envConfig);
    }

    /**
     * 添加菜单
     *
     * @param PDO   $pdo
     * @param string $type 数据库类型
     * @param OutputInterface $output
     *
     * @return int
     */
    private function addMenu(array $menu, PDO $pdo, string $type, OutputInterface $output): int
    {
        $allow_columns = ['title', 'key', 'icon', 'href', 'pid', 'weight', 'type'];
        $data = [];
        foreach ($allow_columns as $column) {
            if (isset($menu[$column])) {
                $data[$column] = $menu[$column];
            }
        }
        $time = utc_now_string('Y-m-d H:i:s');
        $data['created_at'] = $data['updated_at'] = $time;
        $values = [];
        foreach ($data as $k => $v) {
            $values[] = ":$k";
        }
        $columns = array_keys($data);

        // 根据数据库类型确定表名引用方式
        $quoteChar = match ($type) {
            'sqlite', 'pgsql' => '"',
            default => '`'
        };
        $table_name = "{$quoteChar}wa_rules{$quoteChar}";

        $sql = "insert into $table_name (" . implode(',', $columns) . ') values (' . implode(',', $values) . ')';
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
     * @param PDO   $pdo
     * @param string $type 数据库类型
     * @param OutputInterface $output
     *
     * @return void
     */
    private function importMenu(PDO $pdo, string $type, OutputInterface $output): void
    {
        $output->writeln('<comment>正在导入菜单...</comment>');

        // 获取菜单配置
        $menuFile = base_path('plugin/admin/config/menu.php');
        if (!file_exists($menuFile)) {
            $output->writeln('<error>菜单配置文件不存在: ' . $menuFile . '</error>');

            return;
        }

        $menu_tree = include $menuFile;
        $this->importMenuRecursive($menu_tree, $pdo, $type, 0, $output);

        $output->writeln('<info>✓ 菜单导入完成</info>');
    }

    /**
     * 递归导入菜单
     *
     * @param array  $menu_tree
     * @param PDO   $pdo
     * @param string $type
     * @param int    $parent_id
     * @param OutputInterface $output
     *
     * @return void
     */
    private function importMenuRecursive(array $menu_tree, PDO $pdo, string $type, int $parent_id, OutputInterface $output): void
    {
        if (empty($menu_tree)) {
            return;
        }

        // 如果是索引数组且没有key字段，则遍历每个元素
        if (is_numeric(key($menu_tree)) && !isset($menu_tree['key'])) {
            foreach ($menu_tree as $item) {
                $this->importMenuRecursive($item, $pdo, $type, $parent_id, $output);
            }

            return;
        }

        $children = $menu_tree['children'] ?? [];
        unset($menu_tree['children']);

        // 设置父ID
        $menu_tree['pid'] = $parent_id;

        // 根据数据库类型确定表名和字段引用方式
        $quoteChar = match ($type) {
            'sqlite', 'pgsql' => '"',
            default => '`'
        };
        $table_name = "{$quoteChar}wa_rules{$quoteChar}";

        // 检查菜单是否已存在
        $stmt = $pdo->prepare("SELECT * FROM $table_name WHERE {$quoteChar}key{$quoteChar}=:key LIMIT 1");
        $stmt->execute(['key' => $menu_tree['key']]);
        $old_menu = $stmt->fetch();

        if ($old_menu) {
            // 更新现有菜单
            $pid = $old_menu['id'];
            $params = [
                'title' => $menu_tree['title'],
                'icon' => $menu_tree['icon'] ?? '',
                'key' => $menu_tree['key'],
            ];
            $sql = "UPDATE $table_name SET title=:title, icon=:icon WHERE {$quoteChar}key{$quoteChar}=:key";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            // 创建新菜单
            $pid = $this->addMenu($menu_tree, $pdo, $type, $output);
        }

        // 递归处理子菜单
        foreach ($children as $menu) {
            $this->importMenuRecursive($menu, $pdo, $type, $pid, $output);
        }
    }
}
