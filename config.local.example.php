<?php

declare(strict_types=1);

/**
 * Configuration locale per SpaceGame.
 * Copia questo file in config.local.php e compila i valori.
 * NON VERSIONARE config.local.php (è in .gitignore).
 */

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'spacegame',
        'user' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'db_test' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'spacegame_test',
        'user' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'cron' => [
        // Chiave segreta per cron.php (genera una stringa casuale lunga)
        'key' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
    ],
];