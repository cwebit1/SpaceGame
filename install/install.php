<?php

declare(strict_types=1);

namespace SpaceGame\Install;

use PDO;
use PDOException;

/**
 * Esegue lo schema SQL per creare le tabelle del database.
 * Uso: php install/install.php [--drop]
 */

require_once __DIR__ . '/../core/Db.php';

use SpaceGame\Db;

$dropTables = in_array('--drop', $argv);

try {
    // Carica configurazione direttamente (sezione 'db' per installazione)
    $configPath = __DIR__ . '/../config.local.php';
    if (!file_exists($configPath)) {
        throw new \RuntimeException("File config.local.php non trovato. Copia config.local.php.example e modifica i parametri.");
    }

    $fullConfig = require $configPath;
    if (!is_array($fullConfig) || !isset($fullConfig['db'])) {
        throw new \RuntimeException("Sezione 'db' mancante in config.local.php");
    }

    $config = $fullConfig['db'];
    $required = ['host', 'port', 'name', 'user', 'password'];
    foreach ($required as $key) {
        if (!isset($config[$key])) {
            throw new \RuntimeException("Chiave mancante in config.local.php[db]: $key");
        }
    }

    // Connessione senza selezionare DB (per creare il DB se non esiste)
    $dsnNoDb = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
    $pdoNoDb = new PDO($dsnNoDb, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Crea database se non esiste
    // Escape database name to prevent backtick injection
    $dbName = str_replace('`', '``', $config['name']);
    $pdoNoDb->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database '$dbName' pronto.\n";

    // Connessione al DB specifico
    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Leggi schema.sql
    $schemaPath = __DIR__ . '/schema.sql';
    if (!file_exists($schemaPath)) {
        throw new \RuntimeException("File schema.sql non trovato in $schemaPath");
    }

    $sql = file_get_contents($schemaPath);
    if ($sql === false) {
        throw new \RuntimeException("Impossibile leggere $schemaPath");
    }

    // Separa le statement (delimitate da ;)
    // Nota: CREATE TRIGGER e stored procedures usano ; internamente, ma qui non ne abbiamo
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== '' && !str_starts_with($s, '--')
    );

    if ($dropTables) {
        echo "Eliminazione tabelle esistenti...\n";
        // Disabilita foreign key checks per drop
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        // Ottieni lista tabelle
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
            echo "  Dropped: $table\n";
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    echo "Creazione tabelle...\n";
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($statements as $stmt) {
        if (trim($stmt) === '') continue;
        try {
            $pdo->exec($stmt);
            // Estrae nome tabella per log
            if (preg_match('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?[`"]?(\w+)/i', $stmt, $m)) {
                echo "  Created: {$m[1]}\n";
            }
        } catch (PDOException $e) {
            // Ignora errori "already exists" se non in modalità --drop
            if ($e->getCode() !== '42S01') {
                throw $e;
            }
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    echo "\n✓ Installazione completata con successo!\n";
    echo "Tabelle create nel database '$dbName'.\n";

} catch (PDOException $e) {
    echo "✗ Errore database: " . $e->getMessage() . "\n";
    exit(1);
} catch (\Throwable $e) {
    echo "✗ Errore: " . $e->getMessage() . "\n";
    exit(1);
}