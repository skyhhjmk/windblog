<?php

namespace app\command;

use plugin\admin\app\common\Util;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AdsMigrateCommand extends Command
{
    protected static $defaultName = 'ads:migrate';

    protected static $defaultDescription = 'Create ads table and admin form schema if not exists';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $driver = config('database.default', 'pgsql');
        $db = Util::db();

        try {
            switch ($driver) {
                case 'mysql':
                    $db->statement(
                        <<<'SQL'
CREATE TABLE IF NOT EXISTS `ads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL COMMENT '标题',
  `type` varchar(20) NOT NULL DEFAULT 'image' COMMENT '类型:image|google|html',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '启用',
  `image_url` varchar(512) DEFAULT NULL COMMENT '图片URL',
  `link_url` varchar(512) DEFAULT NULL COMMENT '跳转链接',
  `link_target` varchar(20) DEFAULT '_blank' COMMENT '打开方式',
  `html` longtext DEFAULT NULL COMMENT '自定义HTML',
  `google_ad_client` varchar(64) DEFAULT NULL COMMENT 'Ad Client',
  `google_ad_slot` varchar(64) DEFAULT NULL COMMENT 'Ad Slot',
  `placements` json DEFAULT NULL COMMENT '投放设置',
  `weight` int(11) DEFAULT 100 COMMENT '权重',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='广告表';
SQL
                    );
                    $db->statement('CREATE INDEX IF NOT EXISTS idx_ads_enabled ON ads (enabled)');
                    $db->statement('CREATE INDEX IF NOT EXISTS idx_ads_weight ON ads (weight)');
                    break;
                case 'sqlite':
                    $db->statement(
                        <<<'SQL'
CREATE TABLE IF NOT EXISTS ads (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'image',
  enabled INTEGER NOT NULL DEFAULT 1,
  image_url TEXT DEFAULT NULL,
  link_url TEXT DEFAULT NULL,
  link_target TEXT DEFAULT '_blank',
  html TEXT DEFAULT NULL,
  google_ad_client TEXT DEFAULT NULL,
  google_ad_slot TEXT DEFAULT NULL,
  placements TEXT DEFAULT NULL,
  weight INTEGER DEFAULT 100,
  created_at TEXT DEFAULT NULL,
  updated_at TEXT DEFAULT NULL,
  deleted_at TEXT DEFAULT NULL
);
SQL
                    );
                    $db->statement('CREATE INDEX IF NOT EXISTS idx_ads_enabled ON ads (enabled)');
                    $db->statement('CREATE INDEX IF NOT EXISTS idx_ads_weight ON ads (weight)');
                    break;
                case 'pgsql':
                default:
                    $db->statement(
                        <<<'SQL'
CREATE TABLE IF NOT EXISTS ads (
    id              BIGSERIAL PRIMARY KEY,
    title           VARCHAR(255)             NOT NULL,
    type            VARCHAR(20)              NOT NULL DEFAULT 'image',
    enabled         BOOLEAN                  NOT NULL DEFAULT TRUE,
    image_url       VARCHAR(512)                      DEFAULT NULL,
    link_url        VARCHAR(512)                      DEFAULT NULL,
    link_target     VARCHAR(20)                       DEFAULT '_blank',
    html            TEXT                               DEFAULT NULL,
    google_ad_client VARCHAR(64)                      DEFAULT NULL,
    google_ad_slot  VARCHAR(64)                       DEFAULT NULL,
    placements      JSONB                              DEFAULT NULL,
    weight          INTEGER                            DEFAULT 100,
    created_at      TIMESTAMP WITH TIME ZONE           DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP WITH TIME ZONE           DEFAULT CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP WITH TIME ZONE           DEFAULT NULL,
    CONSTRAINT chk_ads_type CHECK (type IN ('image','google','html'))
);
SQL
                    );
                    $db->statement('CREATE INDEX IF NOT EXISTS idx_ads_enabled ON ads (enabled)');
                    $db->statement('CREATE INDEX IF NOT EXISTS idx_ads_weight ON ads (weight)');
                    break;
            }

            // Insert form schema if not exists
            $exists = Util::db()->table('settings')->where('key', 'table_form_schema_ads')->count();
            if ($exists == 0) {
                $schemaJson = json_encode([
                    'id' => ['field' => 'id', 'comment' => '主键', 'control' => 'inputNumber', 'form_show' => false, 'list_show' => true, 'enable_sort' => true],
                    'title' => ['field' => 'title', 'comment' => '标题', 'control' => 'input', 'form_show' => true, 'list_show' => true, 'searchable' => true],
                    'type' => ['field' => 'type', 'comment' => '类型', 'control' => 'select', 'control_args' => 'data:image:自定义图文,google:Google广告,html:自定义HTML', 'form_show' => true, 'list_show' => true],
                    'enabled' => ['field' => 'enabled', 'comment' => '启用', 'control' => 'switch', 'control_args' => 'lay-text:启用|禁用', 'form_show' => true, 'list_show' => true],
                    'image_url' => ['field' => 'image_url', 'comment' => '图片', 'control' => 'uploadImage', 'control_args' => 'url:/app/admin/media/upload', 'form_show' => true, 'list_show' => false],
                    'link_url' => ['field' => 'link_url', 'comment' => '链接', 'control' => 'input', 'form_show' => true, 'list_show' => true],
                    'link_target' => ['field' => 'link_target', 'comment' => '打开方式', 'control' => 'select', 'control_args' => 'data:_blank:新窗口,_self:本窗口', 'form_show' => true, 'list_show' => true],
                    'google_ad_client' => ['field' => 'google_ad_client', 'comment' => 'Ad Client', 'control' => 'input', 'form_show' => true, 'list_show' => false],
                    'google_ad_slot' => ['field' => 'google_ad_slot', 'comment' => 'Ad Slot', 'control' => 'input', 'form_show' => true, 'list_show' => false],
                    'html' => ['field' => 'html', 'comment' => 'HTML代码', 'control' => 'textArea', 'form_show' => true, 'list_show' => false],
                    'placements' => ['field' => 'placements', 'comment' => '投放设置(JSON)', 'control' => 'jsonEditor', 'form_show' => true, 'list_show' => false],
                    'weight' => ['field' => 'weight', 'comment' => '权重', 'control' => 'inputNumber', 'form_show' => true, 'list_show' => true, 'enable_sort' => true],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                Util::db()->table('settings')->insert([
                    'key' => 'table_form_schema_ads',
                    'value' => $schemaJson,
                    'created_at' => utc_now_string('Y-m-d H:i:s'),
                    'updated_at' => utc_now_string('Y-m-d H:i:s'),
                ]);
            }

            $output->writeln('<info>✓ Ads migration completed (or already present)</info>');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>× Ads migration failed: ' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }
    }
}
