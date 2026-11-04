<?php

declare(strict_types=1);

namespace SpaceGame\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use SpaceGame\Db;
use SpaceGame\LockBusyException;

/**
 * Test per Db.php - Connessione, transazioni, lock.
 * Usa database di test 'spacegame_test' da config.local.php.
 */
class DbTest extends TestCase
{
    private static bool $tablesCreated = false;

    protected function setUp(): void
    {
        // Reset connessione per usare db_test
        Db::close();

        // Verifica che il DB di test esista
        try {
            $pdo = Db::pdo(true);
            $pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped("Database di test non disponibile: " . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        Db::close();
    }

    /**
     * Test che l'installazione crei tutte le tabelle previste.
     */
    public function test_install_creates_tables(): void
    {
        if (self::$tablesCreated) {
            $this->assertTrue(true, 'Tabelle già create in un test precedente');
            return;
        }

        $pdo = Db::pdo(true);

        // Esegui schema.sql
        $schemaPath = __DIR__ . '/../../install/schema.sql';
        $this->assertFileExists($schemaPath, 'schema.sql non trovato');

        $sql = file_get_contents($schemaPath);
        $this->assertNotFalse($sql, 'Impossibile leggere schema.sql');

        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => $s !== '' && !str_starts_with($s, '--')
        );

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($statements as $stmt) {
            if (trim($stmt) === '') continue;
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                if ($e->getCode() !== '42S01') {
                    throw $e;
                }
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Verifica che le tabelle principali esistano
        $expectedTables = [
            'users', 'games', 'empires', 'bodies', 'buildings',
            'research', 'upgrades', 'units', 'queues', 'fleets',
            'events', 'reports', 'log', 'debris', 'asteroid_progress',
            'market', 'offers', 'relations', 'pacts', 'pirate_levels',
            'admiral', 'scores'
        ];

        foreach ($expectedTables as $table) {
            // MySQL non permette placeholder per nomi tabella in SHOW TABLES LIKE
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            $this->assertNotFalse($stmt->fetch(), "Tabella '$table' non creata");
        }

        self::$tablesCreated = true;
    }

    /**
     * Test che un secondo holder non possa acquisire lo stesso lock (LockBusyException).
     */
    public function test_lock_excludes_second_holder(): void
    {
        // Assicura che le tabelle esistano
        $this->test_install_creates_tables();

        $pdo = Db::pdo(true);
        $gameId = 999999; // ID di test non usato

        // Crea un game di test per il lock
        $pdo->exec("INSERT IGNORE INTO `games` (`id`, `user_id`, `name`, `speed`, `cpu_res_mult`, `cpu_research_mult`, `settings_json`, `config_json`)
                    VALUES (999999, 1, 'test_lock', 1, 1, 1, '{}', '{}')");

        $lockAcquired = false;
        $exceptionThrown = false;
        $secondHolderAttempted = false;

        // Primo holder acquisisce il lock e lo mantiene finché il secondo non tenta
        $result1 = Db::withGameLock($gameId, function () use (&$lockAcquired, &$secondHolderAttempted) {
            $lockAcquired = true;

            // In un thread separato (simulato), il secondo holder tenta di acquisire
            // Poiché non possiamo fare vero threading in PHP, usiamo un approccio diverso:
            // Acquisiamo il lock manualmente, facciamo tentare il secondo, poi rilasciamo
            return 'holder1';
        }, 5);

        $this->assertTrue($lockAcquired, 'Primo holder dovrebbe acquisire il lock');
        $this->assertEquals('holder1', $result1);

        // Ora il lock è stato rilasciato (finally block).
        // Per testare la concorrenza, dobbiamo tenere il lock mentre il secondo tenta.
        // Usiamo una connessione separata per il primo holder.

        // Crea una nuova connessione per tenere il lock
        $pdoHolder1 = new PDO(
            "mysql:host=localhost;port=3306;dbname=spacegame_test;charset=utf8mb4",
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $lockName = "spacegame_game_{$gameId}";
        $stmt = $pdoHolder1->prepare("SELECT GET_LOCK(?, ?) AS acquired");
        $stmt->execute([$lockName, 10]);
        $row = $stmt->fetch();
        $this->assertEquals(1, (int)$row['acquired'], 'Holder1 dovrebbe acquisire il lock sulla nuova connessione');

        // Ora il lock è tenuto da pdoHolder1. Tentiamo con la connessione principale (Db::pdo())
        try {
            Db::withGameLock($gameId, function () {
                return 'holder2';
            }, 1); // timeout 1 secondo
        } catch (LockBusyException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('non acquisito', $e->getMessage());
        } catch (\Throwable $e) {
            $this->fail('Eccezione inaspettata: ' . get_class($e) . ' - ' . $e->getMessage());
        }

        // Rilascia il lock dalla connessione holder1
        $pdoHolder1->prepare("SELECT RELEASE_LOCK(?)")->execute([$lockName]);
        $pdoHolder1 = null;

        $this->assertTrue($exceptionThrown, 'Secondo holder dovrebbe ricevere LockBusyException');

        // Cleanup
        $pdo->exec("DELETE FROM `games` WHERE `id` = 999999");
    }

    /**
     * Test che PDO restituisca connessioni diverse per test e produzione (E16).
     */
    public function test_pdo_returns_different_connections_for_test_and_prod(): void
    {
        Db::close();

        // Ottieni connessione di test
        $pdoTest = Db::pdo(true);
        $testDbName = $pdoTest->query("SELECT DATABASE()")->fetchColumn();

        // Ottieni connessione di produzione (dovrebbe essere database diverso)
        $pdoProd = Db::pdo(false);
        $prodDbName = $pdoProd->query("SELECT DATABASE()")->fetchColumn();

        $this->assertNotEquals($testDbName, $prodDbName, 'Le connessioni test e produzione dovrebbero usare database diversi');
        $this->assertEquals('spacegame_test', $testDbName, 'Connessione test dovrebbe usare spacegame_test');
        $this->assertEquals('spacegame', $prodDbName, 'Connessione produzione dovrebbe usare spacegame');

        // Verifica che chiamate successive con stesso flag restituiscano la stessa connessione
        $pdoTest2 = Db::pdo(true);
        $this->assertSame($pdoTest, $pdoTest2, 'Chiamate successive con test=true dovrebbero restituire la stessa connessione');

        $pdoProd2 = Db::pdo(false);
        $this->assertSame($pdoProd, $pdoProd2, 'Chiamate successive con test=false dovrebbero restituire la stessa connessione');

        Db::close();
    }

    /**
     * Test che la transazione faccia rollback su eccezione.
     */
    public function test_tx_rolls_back_on_exception(): void
    {
        // Assicura che le tabelle esistano
        $this->test_install_creates_tables();

        $pdo = Db::pdo(true);

        // Cleanup eventuali utenti di test precedenti
        $pdo->exec("DELETE FROM `users` WHERE `username` IN ('test_tx', 'tx_commit_test', 'tx_rollback_test')");

        // Crea un utente di test
        $pdo->exec("INSERT IGNORE INTO `users` (`id`, `username`, `pass_hash`) VALUES (999998, 'test_tx', 'hash')");

        // Test 1: Transazione che ha successo - commit
        $userId = Db::tx(function () use ($pdo) {
            $stmt = $pdo->prepare("INSERT INTO `users` (`username`, `pass_hash`) VALUES (?, ?)");
            $stmt->execute(['tx_commit_test', 'hash']);
            return (int)$pdo->lastInsertId();
        }, true);

        $this->assertIsInt($userId);
        $this->assertGreaterThan(0, $userId);

        // Verifica che l'utente sia stato inserito
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `users` WHERE `id` = ?");
        $stmt->execute([$userId]);
        $this->assertEquals(1, (int)$stmt->fetchColumn());

        // Cleanup utente commit
        $pdo->exec("DELETE FROM `users` WHERE `id` = $userId");

        // Test 2: Transazione che lancia eccezione - rollback
        $exceptionCaught = false;
        try {
            Db::tx(function () use ($pdo) {
                $stmt = $pdo->prepare("INSERT INTO `users` (`username`, `pass_hash`) VALUES (?, ?)");
                $stmt->execute(['tx_rollback_test', 'hash']);
                // Forza un'eccezione
                throw new \RuntimeException('Errore intenzionale per test rollback');
            }, true);
        } catch (\RuntimeException $e) {
            $exceptionCaught = true;
            $this->assertEquals('Errore intenzionale per test rollback', $e->getMessage());
        }

        $this->assertTrue($exceptionCaught, 'Eccezione dovrebbe essere rilanciata');

        // Verifica che l'utente NON sia stato inserito (rollback)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `users` WHERE `username` = 'tx_rollback_test'");
        $stmt->execute();
        $this->assertEquals(0, (int)$stmt->fetchColumn(), 'Rollback dovrebbe annullare l\'inserimento');

        // Cleanup utente di test iniziale
        $pdo->exec("DELETE FROM `users` WHERE `id` = 999998");
    }
}