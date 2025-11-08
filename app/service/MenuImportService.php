<?php

declare(strict_types=1);

namespace app\service;

use function base_path;
use function config;

use Exception;
use PDO;
use support\Db;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 菜单导入服务
 * 提供清空与导入 Admin 菜单的能力
 */
class MenuImportService
{
    /**
     * 重新初始化（清空并导入）菜单
     */
    public static function reinitialize(): bool
    {
        $output = new NullOutput();
        try {
            $connection = Db::connection();
            $pdo = $connection->getPdo();
            $dbType = (string) config('database.default', 'pgsql');

            self::clearMenuTable($pdo, $dbType);
            self::importMenu($pdo, $dbType);

            return true;
        } catch (Exception $_) {
            return false;
        }
    }

    private static function clearMenuTable(PDO $pdo, string $type): void
    {
        $quoteChar = match ($type) {
            'sqlite', 'pgsql' => '"',
            default => '`',
        };
        $table = "{$quoteChar}wa_rules{$quoteChar}";
        $pdo->exec("DELETE FROM $table");
        try {
            match ($type) {
                'mysql' => $pdo->exec("ALTER TABLE $table AUTO_INCREMENT = 1"),
                'pgsql' => $pdo->exec('ALTER SEQUENCE wa_rules_id_seq RESTART WITH 1'),
                'sqlite' => $pdo->exec("DELETE FROM sqlite_sequence WHERE name='wa_rules'"),
                default => null,
            };
        } catch (Exception $_) {
            // ignore
        }
    }

    private static function importMenu(PDO $pdo, string $type): void
    {
        $menuFile = base_path('plugin/admin/config/menu.php');
        if (!file_exists($menuFile)) {
            return;
        }
        $menu = include $menuFile;
        self::importMenuRecursive($menu, $pdo, $type, 0);
    }

    private static function importMenuRecursive(array $tree, PDO $pdo, string $type, int $parentId): void
    {
        if (empty($tree)) {
            return;
        }
        if (is_numeric(key($tree)) && !isset($tree['key'])) {
            foreach ($tree as $item) {
                self::importMenuRecursive($item, $pdo, $type, $parentId);
            }

            return;
        }
        $children = $tree['children'] ?? [];
        unset($tree['children']);
        $tree['pid'] = $parentId;
        $pid = self::addMenu($tree, $pdo, $type);
        foreach ($children as $child) {
            self::importMenuRecursive($child, $pdo, $type, $pid);
        }
    }

    private static function addMenu(array $menu, PDO $pdo, string $type): int
    {
        $allow = ['title', 'key', 'icon', 'href', 'pid', 'weight', 'type'];
        $data = [];
        foreach ($allow as $column) {
            if (array_key_exists($column, $menu)) {
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

        $quoteChar = match ($type) {
            'sqlite', 'pgsql' => '"',
            default => '`',
        };
        $table = "{$quoteChar}wa_rules{$quoteChar}";
        $sql = "INSERT INTO $table (" . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')';
        $smt = $pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $smt->bindValue($key, $value);
        }
        $smt->execute();

        return (int) $pdo->lastInsertId();
    }
}
