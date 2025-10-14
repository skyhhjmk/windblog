<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigRedis extends Command
{
    protected static $defaultName = 'config:redis';

    protected static $defaultDescription = 'Show Redis config';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Redis配置信息如下：');
        $config = config('redis');
        $headers = ['name', 'host', 'port', 'database', 'password', 'max_connections', 'min_connections', 'wait_timeout', 'idle_timeout', 'heartbeat_interval'];
        $rows = [];

        foreach ($config as $name => $redis_config) {
            $row = [];
            foreach ($headers as $key) {
                switch ($key) {
                    case 'name':
                        $row[] = $name;
                        break;
                    case 'max_connections':
                        $row[] = $redis_config['pool']['max_connections'] ?? '';
                        break;
                    case 'min_connections':
                        $row[] = $redis_config['pool']['min_connections'] ?? '';
                        break;
                    case 'wait_timeout':
                        $row[] = $redis_config['pool']['wait_timeout'] ?? '';
                        break;
                    case 'idle_timeout':
                        $row[] = $redis_config['pool']['idle_timeout'] ?? '';
                        break;
                    case 'heartbeat_interval':
                        $row[] = $redis_config['pool']['heartbeat_interval'] ?? '';
                        break;
                    default:
                        $row[] = $redis_config[$key] ?? '';
                }
            }
            $rows[] = $row;
        }

        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();

        return self::SUCCESS;
    }
}
