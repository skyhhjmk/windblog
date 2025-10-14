<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigDatabase extends Command
{
    protected static $defaultName = 'config:db';

    protected static $defaultDescription = 'Show database config';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Using database connection: ' . config('database.default'));
        $config = config('database');
        $headers = ['name', 'default', 'driver', 'host', 'port', 'database', 'username', 'password', 'unix_socket', 'charset', 'collation', 'prefix', 'strict', 'engine', 'schema', 'sslmode'];
        $rows = [];
        foreach ($config['connections'] as $name => $db_config) {
            $row = [];
            foreach ($headers as $key) {
                $row[] = match ($key) {
                    'name' => $name,
                    'default' => $config['default'] == $name ? 'true' : 'false',
                    default => $db_config[$key] ?? '',
                };
            }
            if ($config['default'] == $name) {
                array_unshift($rows, $row);
            } else {
                $rows[] = $row;
            }
        }
        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();

        return self::SUCCESS;
    }
}
