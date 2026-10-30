<?php

namespace App\Database\Connectors;

use Illuminate\Database\Connectors\PostgresConnector as LaravelPostgresConnector;
use PDO;

class PostgresConnector extends LaravelPostgresConnector
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<int, mixed>
     */
    public function getOptions(array $config)
    {
        $options = parent::getOptions($config);

        if (! array_key_exists('connect_timeout', $config) || $config['connect_timeout'] === null || $config['connect_timeout'] === '') {
            return $options;
        }

        $timeout = max(1, (int) $config['connect_timeout']);
        $options[PDO::ATTR_TIMEOUT] = $timeout;

        return $options;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function getDsn(array $config): string
    {
        $dsn = parent::getDsn($config);

        if (! array_key_exists('connect_timeout', $config) || $config['connect_timeout'] === null || $config['connect_timeout'] === '') {
            return $dsn;
        }

        return $dsn.';connect_timeout='.max(1, (int) $config['connect_timeout']);
    }
}
