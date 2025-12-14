<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建广告表迁移文件 - PostgreSQL优化版
 */
class CreateAdsTable extends AbstractMigration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        if ($adapterType === 'pgsql') {
            $this->execute("
                CREATE TABLE IF NOT EXISTS ads (
                    id BIGSERIAL PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    type VARCHAR(20) NOT NULL DEFAULT 'image',
                    enabled BOOLEAN NOT NULL DEFAULT true,
                    image_url VARCHAR(512),
                    link_url VARCHAR(512),
                    link_target VARCHAR(20) DEFAULT '_blank',
                    html TEXT,
                    google_ad_client VARCHAR(64),
                    google_ad_slot VARCHAR(64),
                    placements JSONB,
                    weight INTEGER DEFAULT 100,
                    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    deleted_at TIMESTAMP WITH TIME ZONE,
                    CONSTRAINT chk_ads_type CHECK (type IN ('image', 'google', 'html'))
                )
            ");

            $this->execute('CREATE INDEX IF NOT EXISTS idx_ads_enabled ON ads (enabled)');
            $this->execute('CREATE INDEX IF NOT EXISTS idx_ads_weight ON ads (weight)');

            $this->execute('CREATE INDEX IF NOT EXISTS idx_ads_placements_gin ON ads USING gin (placements)');

            $this->execute('CREATE INDEX IF NOT EXISTS idx_ads_enabled_weight ON ads (enabled, weight) WHERE enabled = true');

            $this->execute("
                INSERT INTO ads (title, type, enabled, image_url, link_url, link_target, html, google_ad_client, google_ad_slot, placements, weight, created_at, updated_at, deleted_at)
                VALUES (
                    '雨云-高性价比云服务商',
                    'image',
                    true,
                    'https://www.rainyun.com/favicon.ico',
                    'https://www.rainyun.com/github_',
                    '_blank',
                    NULL,
                    NULL,
                    NULL,
                    '{\"positions\": [\"sidebar\"]}'::jsonb,
                    100,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP,
                    NULL
                )
            ");

            return;
        }

        $table = $this->table('ads', [
            'id' => 'id',
            'primary_key' => 'id',
            'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
            'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        ]);

        $table->addColumn('title', 'string', [
            'limit' => 255,
            'null' => false,
            'comment' => '广告标题',
        ]);

        $table->addColumn('type', 'string', [
            'limit' => 20,
            'null' => false,
            'default' => 'image',
            'comment' => '广告类型',
        ]);

        $enabledType = $adapterType === 'sqlite' ? 'integer' : 'boolean';
        $enabledDefault = $adapterType === 'sqlite' ? 1 : true;

        $table->addColumn('enabled', $enabledType, [
            'null' => false,
            'default' => $enabledDefault,
            'comment' => '是否启用',
        ]);

        $table->addColumn('image_url', 'string', [
            'limit' => 512,
            'null' => true,
            'default' => null,
            'comment' => '图片URL',
        ]);

        $table->addColumn('link_url', 'string', [
            'limit' => 512,
            'null' => true,
            'default' => null,
            'comment' => '链接URL',
        ]);

        $table->addColumn('link_target', 'string', [
            'limit' => 20,
            'null' => true,
            'default' => '_blank',
            'comment' => '链接目标',
        ]);

        $table->addColumn('html', 'text', [
            'null' => true,
            'default' => null,
            'comment' => 'HTML内容',
        ]);

        $table->addColumn('google_ad_client', 'string', [
            'limit' => 64,
            'null' => true,
            'default' => null,
            'comment' => 'Google广告客户端',
        ]);

        $table->addColumn('google_ad_slot', 'string', [
            'limit' => 64,
            'null' => true,
            'default' => null,
            'comment' => 'Google广告槽位',
        ]);

        if ($adapterType === 'mysql') {
            $table->addColumn('placements', 'json', [
                'null' => true,
                'default' => null,
                'comment' => '广告位置配置',
            ]);
        } else {
            $table->addColumn('placements', 'text', [
                'null' => true,
                'default' => null,
                'comment' => '广告位置配置',
            ]);
        }

        $table->addColumn('weight', 'integer', [
            'null' => true,
            'default' => 100,
            'comment' => '权重',
        ]);

        $table->addColumn('created_at', 'timestamp', [
            'null' => false,
            'default' => 'CURRENT_TIMESTAMP',
            'comment' => '创建时间',
        ]);

        $table->addColumn('updated_at', 'timestamp', [
            'null' => false,
            'default' => 'CURRENT_TIMESTAMP',
            'update' => 'CURRENT_TIMESTAMP',
            'comment' => '更新时间',
        ]);

        $table->addColumn('deleted_at', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '删除时间',
        ]);

        $table->create();

        $this->table('ads')->addIndex(['enabled'], ['name' => 'idx_ads_enabled'])->save();
        $this->table('ads')->addIndex(['weight'], ['name' => 'idx_ads_weight'])->save();

        // SQLite不支持
        if (in_array($adapterType, ['mysql', 'pgsql'])) {
            $this->execute("ALTER TABLE ads ADD CONSTRAINT chk_ads_type CHECK (type IN ('image', 'google', 'html'))");
        }

        $this->execute("
            INSERT INTO ads (title, type, enabled, image_url, link_url, link_target, html, google_ad_client, google_ad_slot, placements, weight, created_at, updated_at, deleted_at)
            VALUES (
                '雨云-高性价比云服务商',
                'image',
                " . ($adapterType === 'sqlite' ? '1' : 'true') . ",
                'https://www.rainyun.com/favicon.ico',
                'https://www.rainyun.com/github_',
                '_blank',
                NULL,
                NULL,
                NULL,
                '{\"positions\": [\"sidebar\"]}',
                100,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP,
                NULL
            )
        ");
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        $this->table('ads')->drop()->save();
    }
}
