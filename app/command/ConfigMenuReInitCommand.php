<?php

namespace app\command;

use Exception;
use PDO;
use support\Db;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigMenuReInitCommand extends Command
{
    protected static $defaultName = 'config:menu:re-init';

    protected static $defaultDescription = 'Re-initialize menu by deleting existing wa_rules and importing menu';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln('<info>菜单重新初始化工具</info>');
            $output->writeln('<comment>此工具将删除现有菜单并重新导入</comment>');
            $output->writeln('');

            // 获取数据库连接
            $connection = Db::connection();
            $pdo = $connection->getPdo();
            $dbType = config('database.default', 'pgsql');

            // 删除 wa_rules 表中的所有记录
            $output->writeln('<comment>正在删除现有菜单数据...</comment>');
            $this->clearMenuTable($pdo, $dbType, $output);
            $output->writeln('<info>✓ 现有菜单数据已删除</info>');
            $output->writeln('');

            // 导入菜单
            $this->importMenu($pdo, $dbType, $output);

            $output->writeln('');
            $output->writeln('<info>菜单重新初始化完成！</info>');

            return self::SUCCESS;
        } catch (Exception $e) {
            $output->writeln('<error>菜单重新初始化失败: ' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }
    }

    /**
     * 清空菜单表
     *
     * @param PDO             $pdo
     * @param string          $type 数据库类型
     * @param OutputInterface $output
     *
     * @return void
     */
    private function clearMenuTable(PDO $pdo, string $type, OutputInterface $output): void
    {
        // 根据数据库类型确定表名引用方式
        $quoteChar = match ($type) {
            'sqlite', 'pgsql' => '"',
            default => '`'
        };
        $table_name = "{$quoteChar}wa_rules{$quoteChar}";

        // 删除所有记录
        $sql = "DELETE FROM $table_name";
        $pdo->exec($sql);

        // 重置自增ID（不同数据库有不同的方式）
        try {
            match ($type) {
                'mysql' => $pdo->exec("ALTER TABLE $table_name AUTO_INCREMENT = 1"),
                'pgsql' => $pdo->exec('ALTER SEQUENCE wa_rules_id_seq RESTART WITH 1'),
                'sqlite' => $pdo->exec("DELETE FROM sqlite_sequence WHERE name='wa_rules'"),
                default => null
            };
        } catch (Exception $e) {
            // 如果重置自增ID失败，仅记录警告，不影响整体流程
            $output->writeln('<comment>警告: 重置自增ID失败 - ' . $e->getMessage() . '</comment>');
        }
    }

    /**
     * 添加菜单
     *
     * @param array           $menu
     * @param PDO             $pdo
     * @param string          $type 数据库类型
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
     * @param PDO             $pdo
     * @param string          $type 数据库类型
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
     * @param array           $menu_tree
     * @param PDO             $pdo
     * @param string          $type
     * @param int             $parent_id
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

        // 创建新菜单
        $pid = $this->addMenu($menu_tree, $pdo, $type, $output);

        // 递归处理子菜单
        foreach ($children as $menu) {
            $this->importMenuRecursive($menu, $pdo, $type, $pid, $output);
        }
    }
}
