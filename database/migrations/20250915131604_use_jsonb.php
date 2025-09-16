<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UseJsonb extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        $adapterType = $this->getAdapter()->getAdapterType();
        $tableName = 'settings';
        $columnName = 'value';

        // 1. 先处理数据：将可反序列化的字段值转为JSON（参数绑定防注入）
        $rows = $this->fetchAll("SELECT id, {$columnName} FROM {$tableName} WHERE {$columnName} IS NOT NULL");
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $originalValue = (string)$row[$columnName];

            if ($this->isUnserializable($originalValue)) {
                try {
                    $unserialized = unserialize($originalValue);
                    $jsonValue = json_encode($unserialized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

                    // 用参数绑定执行更新，避免SQL注入
                    $this->execute(
                        "UPDATE {$tableName} SET {$columnName} = ? WHERE id = ?",
                        [$jsonValue, $id]
                    );
                } catch (\Throwable $e) {
                    $this->output->writeln("警告: 无法转换ID={$id}的记录 - {$e->getMessage()}");
                }
            }
        }

        // 2. PostgreSQL：分两步修改字段（先改类型，再设置可空，符合语法规则）
        if ($adapterType === 'pgsql') {
            // 第一步：修改字段类型为jsonb（必须显式指定USING）
            $this->execute(
                "ALTER TABLE {$tableName} 
                 ALTER COLUMN {$columnName} TYPE jsonb 
                 USING {$columnName}::jsonb"  // 核心：强制类型转换
            );

            // 第二步：设置字段允许NULL（PostgreSQL使用DROP NOT NULL语法）
            $this->execute(
                "ALTER TABLE {$tableName} 
                 ALTER COLUMN {$columnName} DROP NOT NULL"
            );
        } else {
            // MySQL：用Phinx原生方法修改为json类型（兼容性更好）
            $this->table($tableName)
                ->changeColumn($columnName, 'json', ['null' => true])
                ->update();
        }
    }


    /**
     * 判断字符串是否可被PHP反序列化
     */
    private function isUnserializable(string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        try {
            $unserialized = unserialize($value);
            return $unserialized !== false || $value === serialize(false);
        } catch (\Throwable $e) {
            return false;
        }
    }
}