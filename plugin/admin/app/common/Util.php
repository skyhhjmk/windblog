<?php

namespace plugin\admin\app\common;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use PDO;
use plugin\admin\app\model\Setting;
use support\Db;
use support\exception\BusinessException;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

class Util
{
    /**
     * 密码哈希
     *
     * @param        $password
     * @param string $algo
     *
     * @return false|string|null
     */
    public static function passwordHash($password, string $algo = PASSWORD_DEFAULT)
    {
        return password_hash($password, $algo);
    }

    /**
     * 验证密码哈希
     *
     * @param string $password
     * @param string $hash
     *
     * @return bool
     */
    public static function passwordVerify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * 获取webman-admin数据库连接
     *
     * @return Connection
     */
    public static function db(): Connection
    {
        $defaultConnection = config('database.default');

        // 如果是 SQLite 数据库且文件不存在，则先创建一个空文件
        if ($defaultConnection === 'sqlite') {
            $dbPath = config('database.connections.sqlite.database');
            if ($dbPath !== ':memory:' && !file_exists($dbPath)) {
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0o777, true);
                }
                // 创建空的 SQLite 数据库文件
                $pdo = new PDO('sqlite:' . $dbPath);
                $pdo = null; // 关闭连接
            }
        }

        return Db::connection($defaultConnection);
    }

    /**
     * 获取SchemaBuilder
     *
     * @return Builder
     */
    public static function schema(): Builder
    {
        $defaultConnection = config('database.default', 'pgsql');

        return Db::schema($defaultConnection);
    }

    /**
     * 获取语义化时间
     *
     * @param $time
     *
     * @return false|string
     */
    public static function humanDate($time)
    {
        $timestamp = is_numeric($time) ? $time : strtotime($time);
        $dur = time() - $timestamp;
        if ($dur < 0) {
            return date('Y-m-d', $timestamp);
        } else {
            if ($dur < 60) {
                return $dur . '秒前';
            } else {
                if ($dur < 3600) {
                    return floor($dur / 60) . '分钟前';
                } else {
                    if ($dur < 86400) {
                        return floor($dur / 3600) . '小时前';
                    } else {
                        if ($dur < 2592000) { // 30天内
                            return floor($dur / 86400) . '天前';
                        } else {
                            return date('Y-m-d', $timestamp);
                        }
                    }
                }
            }
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * 格式化文件大小
     *
     * @param $file_size
     *
     * @return string
     */
    public static function formatBytes($file_size): string
    {
        $size = sprintf('%u', $file_size);
        if ($size == 0) {
            return '0 Bytes';
        }
        $size_name = [' Bytes', ' KB', ' MB', ' GB', ' TB', ' PB', ' EB', ' ZB', ' YB'];

        return round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . $size_name[$i];
    }

    /**
     * 数据库字符串转义
     *
     * @param $var
     *
     * @return false|string
     */
    public static function pdoQuote($var)
    {
        return Util::db()->getPdo()->quote($var);
    }

    /**
     * 检查表名是否合法
     *
     * @param string $table
     *
     * @return string
     * @throws BusinessException
     */
    public static function checkTableName(string $table): string
    {
        if (!preg_match('/^[a-zA-Z_0-9]+$/', $table)) {
            throw new BusinessException('表名不合法');
        }

        return $table;
    }

    /**
     * 变量或数组中的元素只能是字母数字下划线组合
     *
     * @param $var
     *
     * @return mixed
     * @throws BusinessException
     */
    public static function filterAlphaNum($var)
    {
        $vars = (array) $var;
        array_walk_recursive($vars, function ($item) {
            if (is_string($item) && !preg_match('/^[a-zA-Z_0-9]+$/', $item)) {
                throw new BusinessException('参数不合法');
            }
        });

        return $var;
    }

    /**
     * 变量或数组中的元素只能是字母数字
     *
     * @param $var
     *
     * @return mixed
     * @throws BusinessException
     */
    public static function filterNum($var)
    {
        $vars = (array) $var;
        array_walk_recursive($vars, function ($item) {
            if (is_string($item) && !preg_match('/^[0-9]+$/', $item)) {
                throw new BusinessException('参数不合法');
            }
        });

        return $var;
    }

    /**
     * @desc 检测是否是合法URL Path
     *
     * @param $var
     *
     * @return string
     * @throws BusinessException
     */
    public static function filterUrlPath($var): string
    {
        if (!is_string($var)) {
            throw new BusinessException('参数不合法，地址必须是一个字符串！');
        }

        if (strpos($var, 'https://') === 0 || strpos($var, 'http://') === 0) {
            if (!filter_var($var, FILTER_VALIDATE_URL)) {
                throw new BusinessException('参数不合法，不是合法的URL地址！');
            }
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\/&?.]+$/', $var)) {
            throw new BusinessException('参数不合法，不是合法的Path！');
        }

        return $var;
    }

    /**
     * 检测是否是合法Path
     *
     * @param $var
     *
     * @return string
     * @throws BusinessException
     */
    public static function filterPath($var): string
    {
        if (!is_string($var) || !preg_match('/^[a-zA-Z0-9_\-\/]+$/', $var)) {
            throw new BusinessException('参数不合法');
        }

        return $var;
    }

    /**
     * 类转换为url path
     *
     * @param $controller_class
     *
     * @return false|string
     */
    public static function controllerToUrlPath($controller_class)
    {
        $key = strtolower($controller_class);
        $action = '';
        if (strpos($key, '@')) {
            [$key, $action] = explode('@', $key, 2);
        }
        $prefix = 'plugin';
        $paths = explode('\\', $key);
        if (count($paths) < 2) {
            return false;
        }
        $base = '';
        if (strpos($key, "$prefix\\") === 0) {
            if (count($paths) < 4) {
                return false;
            }
            array_shift($paths);
            $plugin = array_shift($paths);
            $base = "/app/$plugin/";
        }
        array_shift($paths);
        foreach ($paths as $index => $path) {
            if ($path === 'controller') {
                unset($paths[$index]);
            }
        }
        $suffix = 'controller';
        $code = $base . implode('/', $paths);
        if (substr($code, -strlen($suffix)) === $suffix) {
            $code = substr($code, 0, -strlen($suffix));
        }

        return $action ? "$code/$action" : $code;
    }

    /**
     * 转换为驼峰
     *
     * @param string $value
     *
     * @return string
     */
    public static function camel(string $value): string
    {
        static $cache = [];
        $key = $value;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return $cache[$key] = str_replace(' ', '', $value);
    }

    /**
     * 转换为小驼峰
     *
     * @param $value
     *
     * @return string
     */
    public static function smCamel($value): string
    {
        return lcfirst(static::camel($value));
    }

    /**
     * 获取注释中第一行
     *
     * @param $comment
     *
     * @return false|mixed|string
     */
    public static function getCommentFirstLine($comment)
    {
        if ($comment === false) {
            return false;
        }
        foreach (explode("\n", $comment) as $str) {
            if ($s = trim($str, "*/\ \t\n\r\0\x0B")) {
                return $s;
            }
        }

        return $comment;
    }

    /**
     * 表单类型到插件的映射
     *
     * @return string[][]
     */
    public static function methodControlMap(): array
    {
        return [
            //method=>[控件]
            'integer' => ['InputNumber'],
            'string' => ['Input'],
            'text' => ['TextArea'],
            'date' => ['DatePicker'],
            'enum' => ['Select'],
            'float' => ['Input'],

            'tinyInteger' => ['InputNumber'],
            'smallInteger' => ['InputNumber'],
            'mediumInteger' => ['InputNumber'],
            'bigInteger' => ['InputNumber'],

            'unsignedInteger' => ['InputNumber'],
            'unsignedTinyInteger' => ['InputNumber'],
            'unsignedSmallInteger' => ['InputNumber'],
            'unsignedMediumInteger' => ['InputNumber'],
            'unsignedBigInteger' => ['InputNumber'],

            'decimal' => ['Input'],
            'double' => ['Input'],

            'mediumText' => ['TextArea'],
            'longText' => ['TextArea'],

            'dateTime' => ['DateTimePicker'],

            'time' => ['DateTimePicker'],
            'timestamp' => ['DateTimePicker'],
            'timestamptz' => ['DateTimePicker'],

            'char' => ['Input'],
            'varchar' => ['Input'],
            'boolean' => ['Switch'],
            'bool' => ['Switch'],

            'binary' => ['Input'],

            'json' => ['input'],
        ];
    }

    /**
     * 数据库类型到插件的转换
     *
     * @param $type
     *
     * @return string
     */
    public static function typeToControl($type): string
    {
        if (stripos($type, 'int') !== false) {
            return 'inputNumber';
        }
        if (stripos($type, 'time') !== false || stripos($type, 'date') !== false) {
            return 'dateTimePicker';
        }
        if (stripos($type, 'text') !== false) {
            return 'textArea';
        }
        if ($type === 'enum') {
            return 'select';
        }

        return 'input';
    }

    /**
     * 数据库类型到表单类型的转换
     *
     * @param $type
     * @param $unsigned
     *
     * @return string
     */
    public static function typeToMethod($type, $unsigned = false)
    {
        if (stripos($type, 'int') !== false) {
            $type = str_replace('int', 'Integer', $type);

            return $unsigned ? 'unsigned' . ucfirst($type) : lcfirst($type);
        }
        $map = [
            'int' => 'integer',
            'varchar' => 'string',
            'mediumtext' => 'mediumText',
            'longtext' => 'longText',
            'datetime' => 'dateTime',
        ];

        return $map[$type] ?? $type;
    }

    /**
     * 按表获取摘要
     *
     * @param      $table
     * @param null $section
     *
     * @return array|mixed
     * @throws BusinessException
     */
    public static function getSchema($table, $section = null)
    {
        Util::checkTableName($table);
        $database = config('database.connections')[config('database.default')]['database'] ?? config('database.connections')['pgsql']['database'];
        $driver = config('database.default');

        $forms = [];
        $columns = [];

        if ($driver === 'pgsql') {
            // PostgreSQL查询
            $schema_raw = $section !== 'table' ? Util::db()->select("SELECT * FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position", [$table]) : [];

            foreach ($schema_raw as $item) {
                $field = $item->column_name;
                $columns[$field] = [
                    'field' => $field,
                    'type' => Util::typeToMethod($item->data_type),
                    'comment' => $field, // PostgreSQL中列注释需要特殊查询
                    'default' => $item->column_default,
                    'length' => static::getLengthValuePgsql($item),
                    'nullable' => $item->is_nullable !== 'NO',
                    'primary_key' => false, // PostgreSQL主键需要特殊查询
                    'auto_increment' => false, // PostgreSQL自增需要特殊处理
                ];

                $forms[$field] = [
                    'field' => $field,
                    'comment' => $field,
                    'control' => static::typeToControl($item->data_type),
                    'form_show' => true,
                    'list_show' => true,
                    'enable_sort' => false,
                    'searchable' => false,
                    'search_type' => 'normal',
                    'control_args' => '',
                ];
            }

            // 查询主键信息
            if ($section !== 'table' && $columns) {
                $primary_keys_result = Util::db()->select(
                    'SELECT a.attname FROM pg_index i ' .
                    'JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) ' .
                    'WHERE i.indrelid = ?::regclass AND i.indisprimary',
                    [$table]
                );

                foreach ($primary_keys_result as $pk) {
                    $pk_name = $pk->attname;
                    if (isset($columns[$pk_name])) {
                        $columns[$pk_name]['primary_key'] = true;
                        $forms[$pk_name]['form_show'] = false;
                    }
                }
            }

            // 查询表注释
            $table_schema = $section == 'table' || !$section ? Util::db()->select(
                'SELECT description AS table_comment FROM pg_description ' .
                'WHERE objoid = (SELECT oid FROM pg_class WHERE relname = ?) AND objsubid = 0',
                [$table]
            ) : [];

            // 查询索引信息
            $keys = [];
            $primary_key = [];
            if (!$section || in_array($section, ['keys', 'table'])) {
                $indexes = Util::db()->select(
                    'SELECT ' .
                    'ic.relname AS index_name, ' .
                    'a.attname AS column_name, ' .
                    'i.indisunique AS is_unique ' .
                    'FROM pg_class bc ' .
                    'JOIN pg_index i ON bc.oid = i.indrelid ' .
                    'JOIN pg_class ic ON ic.oid = i.indexrelid ' .
                    'JOIN pg_attribute a ON a.attrelid = bc.oid AND a.attnum = ANY(i.indkey) ' .
                    'WHERE bc.relname = ?',
                    [$table]
                );

                foreach ($indexes as $index) {
                    $key_name = $index->index_name;
                    if (strpos($key_name, '_pkey') !== false) {
                        $primary_key[] = $index->column_name;
                        continue;
                    }
                    if (!isset($keys[$key_name])) {
                        $keys[$key_name] = [
                            'name' => $key_name,
                            'columns' => [],
                            'type' => $index->is_unique ? 'unique' : 'normal',
                        ];
                    }
                    $keys[$key_name]['columns'][] = $index->column_name;
                }
            }
        } elseif ($driver === 'mysql') {
            // MySQL查询
            $schema_raw = $section !== 'table' ? Util::db()->select('SELECT * FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position', [$database, $table]) : [];

            foreach ($schema_raw as $item) {
                $field = $item->COLUMN_NAME;
                $columns[$field] = [
                    'field' => $field,
                    'type' => Util::typeToMethod($item->DATA_TYPE),
                    'comment' => $item->COLUMN_COMMENT ?: $field,
                    'default' => $item->COLUMN_DEFAULT,
                    'length' => static::getLengthValue($item),
                    'nullable' => $item->IS_NULLABLE !== 'NO',
                    'primary_key' => $item->COLUMN_KEY === 'PRI',
                    'auto_increment' => $item->EXTRA === 'auto_increment',
                ];

                $forms[$field] = [
                    'field' => $field,
                    'comment' => $item->COLUMN_COMMENT ?: $field,
                    'control' => static::typeToControl($item->DATA_TYPE),
                    'form_show' => true,
                    'list_show' => true,
                    'enable_sort' => false,
                    'searchable' => false,
                    'search_type' => 'normal',
                    'control_args' => '',
                ];
            }

            // 查询表注释
            $table_schema = $section == 'table' || !$section ? [] : [];

            if ($section == 'table' || !$section) {
                // 根据数据库类型使用不同的查询方式
                $driver = config('database.default');
                if ($driver === 'mysql') {
                    $table_schema = Util::db()->select(
                        'SELECT TABLE_COMMENT AS table_comment FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                        [$database, $table]
                    );
                } elseif ($driver === 'pgsql') {
                    $table_schema = Util::db()->select(
                        'SELECT description AS table_comment FROM pg_description ' .
                        'WHERE objoid = (SELECT oid FROM pg_class WHERE relname = ?) AND objsubid = 0',
                        [$table]
                    );
                } else {
                    // SQLite不支持表注释，返回空数组
                    $table_schema = [];
                }
            }

            // 查询索引信息
            $keys = [];
            $primary_key = [];
            if (!$section || in_array($section, ['keys', 'table'])) {
                // 根据数据库类型使用不同的查询方式
                $driver = config('database.default');
                if ($driver === 'mysql') {
                    $indexes = Util::db()->select(
                        'SELECT INDEX_NAME as index_name, COLUMN_NAME as column_name, NON_UNIQUE as is_unique ' .
                        'FROM information_schema.statistics ' .
                        'WHERE table_schema = ? AND table_name = ?',
                        [$database, $table]
                    );
                } elseif ($driver === 'pgsql') {
                    $indexes = Util::db()->select(
                        'SELECT ix.relname as index_name, a.attname as column_name, ' .
                        'CASE WHEN ix.indisunique THEN 0 ELSE 1 END as is_unique ' .
                        'FROM pg_class t, pg_class ix, pg_index i, pg_attribute a ' .
                        'WHERE t.oid = i.indrelid and ix.oid = i.indexrelid ' .
                        'AND a.attrelid = t.oid AND a.attnum = ANY(i.indkey) ' .
                        'AND t.relname = ?',
                        [$table]
                    );
                } else {
                    // SQLite查询索引信息
                    $indexes = Util::db()->select(
                        "SELECT name as index_name, sql as column_name, '1' as is_unique " .
                        "FROM sqlite_master WHERE type = 'index' AND tbl_name = ?",
                        [$table]
                    );
                }

                foreach ($indexes as $index) {
                    $key_name = $index->index_name;
                    if ($key_name === 'PRIMARY' || strpos($key_name, '_pkey') !== false) {
                        $primary_key[] = $index->column_name;
                        continue;
                    }
                    if (!isset($keys[$key_name])) {
                        $keys[$key_name] = [
                            'name' => $key_name,
                            'columns' => [],
                            'type' => !$index->is_unique ? 'unique' : 'normal',
                        ];
                    }
                    $keys[$key_name]['columns'][] = $index->column_name;
                }
            }
        }

        $data = [
            'table' => ['name' => $table, 'comment' => $table_schema[0]->table_comment ?? ($table_schema[0]->TABLE_COMMENT ?? ''), 'primary_key' => $primary_key],
            'columns' => $columns,
            'forms' => $forms,
            'keys' => array_reverse($keys, true),
        ];

        $schema = Setting::where('key', "table_form_schema_$table")->value('value');
        $form_schema_map = $schema ? json_decode($schema, true) : [];

        foreach ($data['forms'] as $field => $item) {
            if (isset($form_schema_map[$field])) {
                $data['forms'][$field] = $form_schema_map[$field];
            }
        }

        return $section ? $data[$section] : $data;
    }

    /**
     * 获取字段长度或默认值
     *
     * @param $schema
     *
     * @return mixed|string
     */
    public static function getLengthValue($schema)
    {
        $type = $schema->DATA_TYPE;
        if (in_array($type, ['float', 'decimal', 'double'])) {
            return "{$schema->NUMERIC_PRECISION},{$schema->NUMERIC_SCALE}";
        }
        if ($type === 'enum') {
            return implode(',', array_map(function ($item) {
                return trim($item, "'");
            }, explode(',', substr($schema->COLUMN_TYPE, 5, -1))));
        }
        if (in_array($type, ['varchar', 'text', 'char'])) {
            return $schema->CHARACTER_MAXIMUM_LENGTH;
        }
        if (in_array($type, ['time', 'datetime', 'timestamp'])) {
            return $schema->CHARACTER_MAXIMUM_LENGTH;
        }

        return '';
    }

    /**
     * 获取字段长度或默认值 (PostgreSQL版本)
     *
     * @param $schema
     *
     * @return mixed|string
     */
    public static function getLengthValuePgsql($schema)
    {
        $type = $schema->data_type;
        if (in_array($type, ['numeric', 'decimal'])) {
            // PostgreSQL中使用numeric_precision和numeric_scale
            return "{$schema->numeric_precision},{$schema->numeric_scale}";
        }
        if ($type === 'character varying' || $type === 'varchar') {
            return $schema->character_maximum_length;
        }
        if (in_array($type, ['character', 'char'])) {
            return $schema->character_maximum_length;
        }
        if (in_array($type, ['time', 'timestamp', 'timestamptz'])) {
            return '';
        }

        return '';
    }

    /**
     * 获取控件参数
     *
     * @param $control
     * @param $control_args
     *
     * @return array
     */
    public static function getControlProps($control, $control_args): array
    {
        if (!$control_args) {
            return [];
        }
        $control = strtolower($control);
        $props = [];
        $split = explode(';', $control_args);
        foreach ($split as $item) {
            $pos = strpos($item, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($item, 0, $pos));
            $values = trim(substr($item, $pos + 1));
            // values = a:v,c:d
            $pos = strpos($values, ':');
            if ($pos !== false && strpos($values, '#') !== 0) {
                $options = explode(',', $values);
                $values = [];
                foreach ($options as $option) {
                    [$v, $n] = explode(':', $option);
                    if (in_array($control, ['select', 'selectmulti', 'treeselect', 'treemultiselect']) && $name == 'data') {
                        $values[] = ['value' => $v, 'name' => $n];
                    } else {
                        $values[$v] = $n;
                    }
                }
            }
            $props[$name] = $values;
        }

        return $props;

    }

    /**
     * 获取某个composer包的版本
     *
     * @param string $package
     *
     * @return mixed|string
     */
    public static function getPackageVersion(string $package)
    {
        $installed_php = base_path('vendor/composer/installed.php');
        if (is_file($installed_php)) {
            $packages = include $installed_php;
        }

        return substr($packages['versions'][$package]['version'] ?? 'unknown  ', 0, -2);
    }

    /**
     * Reload webman
     *
     * @return bool
     */
    public static function reloadWebman()
    {
        if (function_exists('posix_kill')) {
            try {
                posix_kill(posix_getppid(), SIGUSR1);

                return true;
            } catch (Throwable $e) {
            }
        } else {
            Timer::add(1, function () {
                Worker::stopAll();
            });
        }

        return false;
    }

    /**
     * Pause file monitor
     *
     * @return void
     */
    public static function pauseFileMonitor()
    {
        if (class_exists('Webman\FileMonitor') && method_exists('Webman\FileMonitor', 'pause')) {
            call_user_func(['Webman\FileMonitor', 'pause']);
        }
    }

    /**
     * Resume file monitor
     *
     * @return void
     */
    public static function resumeFileMonitor()
    {
        if (class_exists('Webman\FileMonitor') && method_exists('Webman\FileMonitor', 'resume')) {
            call_user_func(['Webman\FileMonitor', 'resume']);
        }
    }
}
