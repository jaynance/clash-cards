<?php
declare(strict_types=1);

/*
 * V8.25 production configuration template.
 *
 * Copy this file to config.php and fill in the real values.
 * NEVER put config.php inside public/.
 * NEVER commit config.php to a public repository.
 */

return [
    'app' => [
        // Keep false on the hosted beta.
        'debug' => false,

        // This directory must be writable by PHP.
        'error_log' => __DIR__ . '/storage/logs/php-error.log',
    ],

    'db' => [
        // Use the exact MySQL host shown in Network Solutions Database Manager.
        'host' => 'YOUR_DATABASE_HOST',
        'port' => 3306,
        'name' => 'YOUR_DATABASE_NAME',
        'user' => 'YOUR_DATABASE_USER',
        'pass' => 'YOUR_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    'admin' => [
        /*
         * Store a PASSWORD HASH, not the password itself.
         *
         * Generate one locally:
         * php -r "echo password_hash('CHOOSE_A_STRONG_ADMIN_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
         */
        'password_hash' => 'PASTE_PASSWORD_HASH_HERE',
    ],
];
