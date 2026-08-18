<?php
declare(strict_types=1);

final class Database
{
    public static function connect(array $config): PDO
    {
        foreach (['host', 'name', 'user', 'pass'] as $required) {
            if (!array_key_exists($required, $config)) {
                throw new RuntimeException('Database configuration is incomplete.');
            }
        }

        $host = (string)$config['host'];
        $name = (string)$config['name'];
        $user = (string)$config['user'];
        $pass = (string)$config['pass'];
        $charset = (string)($config['charset'] ?? 'utf8mb4');
        $port = isset($config['port']) ? (int)$config['port'] : null;

        $dsn = 'mysql:host=' . $host .
            ($port ? ';port=' . $port : '') .
            ';dbname=' . $name .
            ';charset=' . $charset;

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
