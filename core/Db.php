<?php

declare(strict_types=1);

namespace SpaceGame;

use PDO;
use PDOException;

/**
 * Db - Wrapper per connessione PDO e utility transazionali / lock.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static ?array $config = null;

    /**
     * Carica la configurazione dal file config.local.php.
     * Usa la sezione 'db_test' per i test, 'db' per produzione.
     */
    private static function loadConfig(bool $test = false): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $configPath = __DIR__ . '/../config.local.php';
        if (!file_exists($configPath)) {
            throw new \RuntimeException(
                "File config.local.php non trovato. Copia config.local.php.example e modifica i parametri."
            );
        }

        $fullConfig = require $configPath;
        if (!is_array($fullConfig)) {
            throw new \RuntimeException("config.local.php deve restituire un array.");
        }

        $section = $test ? 'db_test' : 'db';
        if (!isset($fullConfig[$section])) {
            throw new \RuntimeException("Sezione mancante in config.local.php: $section");
        }

        $config = $fullConfig[$section];
        $required = ['host', 'port', 'name', 'user', 'password'];
        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new \RuntimeException("Chiave mancante in config.local.php[$section]: $key");
            }
        }

        // Normalizza chiavi per compatibilità
        self::$config = [
            'db_host' => $config['host'],
            'db_port' => $config['port'],
            'db_name' => $config['name'],
            'db_user' => $config['user'],
            'db_pass' => $config['password'],
        ];
        return self::$config;
    }

    /**
     * Restituisce l'istanza PDO singleton (connessione lazy).
     * @param bool $test Usa database di test (db_test) invece di produzione (db)
     */
    public static function pdo(bool $test = false): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $cfg = self::loadConfig($test);

        $dsn = "mysql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$cfg['db_name']};charset=utf8mb4";

        self::$pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]);

        return self::$pdo;
    }

    /**
     * Chiude la connessione e resetta la config (utile per test).
     */
    public static function close(): void
    {
        self::$pdo = null;
        self::$config = null;
    }

    /**
     * Esegue un callback dentro una transazione.
     * Se il callback lancia un'eccezione, fa rollback e rilancia.
     * Altrimenti fa commit.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function tx(callable $callback)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Esegue un callback con un lock advisory su game_id (GET_LOCK).
     * Se il lock non è acquisibile (già preso), lancia LockBusyException.
     * Il lock viene rilasciato automaticamente a fine callback (RELEASE_LOCK).
     *
     * @template T
     * @param int $gameId
     * @param callable(): T $callback
     * @param int $timeoutSecondi Timeout in secondi per acquisire il lock (default 10)
     * @return T
     * @throws LockBusyException se il lock è già detenuto da un altro processo
     */
    public static function withGameLock(int $gameId, callable $callback, int $timeoutSecondi = 10)
    {
        $lockName = "spacegame_game_{$gameId}";
        $pdo = self::pdo();

        // Tenta di acquisire il lock
        $stmt = $pdo->prepare("SELECT GET_LOCK(?, ?) AS acquired");
        $stmt->execute([$lockName, $timeoutSecondi]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['acquired'] !== 1) {
            throw new LockBusyException("Lock su game_id=$gameId non acquisito (timeout {$timeoutSecondi}s)");
        }

        try {
            return $callback();
        } finally {
            // Rilascia sempre il lock
            $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$lockName]);
        }
    }

    /**
     * Verifica se esiste un lock su game_id (senza acquisirlo).
     */
    public static function isGameLocked(int $gameId): bool
    {
        $lockName = "spacegame_game_{$gameId}";
        $pdo = self::pdo();
        $stmt = $pdo->prepare("SELECT IS_FREE_LOCK(?) AS free");
        $stmt->execute([$lockName]);
        $row = $stmt->fetch();
        return $row && (int)$row['free'] === 0;
    }
}

/**
 * Eccezione lanciata quando il lock di gioco è già occupato.
 */
class LockBusyException extends \RuntimeException
{
}