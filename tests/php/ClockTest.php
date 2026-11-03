<?php

declare(strict_types=1);

namespace SpaceGame\Tests;

use PHPUnit\Framework\TestCase;
use SpaceGame\Config;
use SpaceGame\Clock;
use SpaceGame\EventBus;
use SpaceGame\Db;
use SpaceGame\Economy;
use PDO;

/**
 * Test per Clock ed EventBus (Task 6).
 * Usa database di test 'spacegame_test' da config.local.php.
 */
class ClockTest extends TestCase
{
    private static bool $tablesCreated = false;
    private Config $config;

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

        $this->config = Config::fromFile(__DIR__ . '/test_game_config.json');
    }

    protected function tearDown(): void
    {
        Db::close();
    }

    /**
     * Crea un utente e partita di test con galassia generata.
     */
    private function createTestGame(array $overrides = []): int
    {
        $pdo = Db::pdo(true);

        // Crea utente (una volta per test)
        $pdo->exec("DELETE FROM `users` WHERE `username` = 'test_clock'");
        $pdo->exec("INSERT INTO `users` (`username`, `pass_hash`) VALUES ('test_clock', 'hash')");
        $userId = (int)$pdo->lastInsertId();

        // Impostazioni default per il test
        $settings = array_merge([
            'speed' => 1,
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 1,
            'galaxies' => 1,
            'systems_per_galaxy' => 2,
            'cpu_density' => 0,
            'cpu_live_share' => 70,
            'max_live_empires' => 150,
            'cpu_planet_size' => 50,
            'cpu_difficulty' => 'normal',
            'anti_player_coalitions' => false,
        ], $overrides);

        // Crea partita
        $stmt = $pdo->prepare("
            INSERT INTO `games` (`user_id`, `name`, `speed`, `cpu_res_mult`, `cpu_research_mult`, `settings_json`, `config_json`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            'Test Clock',
            $settings['speed'],
            $settings['cpu_res_mult'],
            $settings['cpu_research_mult'],
            json_encode($settings),
            $this->config->toJson(),
        ]);

        $gameId = (int)$pdo->lastInsertId();

        // Genera galassia
        \SpaceGame\Galaxy::generate($gameId, $this->config, true);

        return $gameId;
    }

    /**
     * Crea una seconda partita per lo stesso utente (per test concorrenti).
     */
    private function createSecondTestGame(array $overrides = []): int
    {
        $pdo = Db::pdo(true);

        // Usa un username diverso per evitare cascade delete
        $pdo->exec("DELETE FROM `users` WHERE `username` = 'test_clock2'");
        $pdo->exec("INSERT INTO `users` (`username`, `pass_hash`) VALUES ('test_clock2', 'hash')");
        $userId = (int)$pdo->lastInsertId();

        // Impostazioni default per il test
        $settings = array_merge([
            'speed' => 1,
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 1,
            'galaxies' => 1,
            'systems_per_galaxy' => 2,
            'cpu_density' => 0,
            'cpu_live_share' => 70,
            'max_live_empires' => 150,
            'cpu_planet_size' => 50,
            'cpu_difficulty' => 'normal',
            'anti_player_coalitions' => false,
        ], $overrides);

        // Crea partita
        $stmt = $pdo->prepare("
            INSERT INTO `games` (`user_id`, `name`, `speed`, `cpu_res_mult`, `cpu_research_mult`, `settings_json`, `config_json`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            'Test Clock 2',
            $settings['speed'],
            $settings['cpu_res_mult'],
            $settings['cpu_research_mult'],
            json_encode($settings),
            $this->config->toJson(),
        ]);

        $gameId = (int)$pdo->lastInsertId();

        // Genera galassia
        \SpaceGame\Galaxy::generate($gameId, $this->config, true);

        return $gameId;
    }

    /**
     * Test: gli eventi vengono eseguiti in ordine temporale.
     */
    public function test_events_run_in_time_order(): void
    {
        $gameId = $this->createTestGame();

        $executionOrder = [];

        // Registra handler che registra l'ordine di esecuzione
        EventBus::register('test_event', function (array $event, $ctx) use (&$executionOrder) {
            $executionOrder[] = $event['payload']['step'];
        });

        // Programma eventi in ordine sparso
        EventBus::schedule($gameId, 100.0, 'test_event', 0, ['step' => 3]);
        EventBus::schedule($gameId, 50.0, 'test_event', 0, ['step' => 1]);
        EventBus::schedule($gameId, 75.0, 'test_event', 0, ['step' => 2]);

        // Esegui catchUp fino a tempo 200
        Clock::catchUp($gameId, 200.0, 10.0);

        // Verifica ordine di esecuzione
        $this->assertEquals([1, 2, 3], $executionOrder, 'Eventi eseguiti fuori ordine temporale');
    }

    /**
     * Test: catchUp a blocchi produce lo stesso risultato di una singola passata.
     */
    public function test_chunked_equals_single_pass(): void
    {
        $gameId1 = $this->createTestGame();
        $gameId2 = $this->createSecondTestGame();

        // Registra handler che modifica l'economia (es. aggiunge risorse)
        EventBus::register('economy_test', function (array $event, $ctx) {
            $pdo = $ctx->db;
            $bodyId = $event['ref_id'];
            $amount = $event['payload']['amount'] ?? 100;

            $stmt = $pdo->prepare("UPDATE `bodies` SET `res_m` = `res_m` + ? WHERE `id` = ?");
            $stmt->execute([$amount, $bodyId]);
        });

        // Programma 10 eventi sparsi nel tempo per entrambe le partite
        for ($i = 0; $i < 10; $i++) {
            $at = 100.0 + $i * 100.0;
            EventBus::schedule($gameId1, $at, 'economy_test', 1, ['amount' => 50]);
            EventBus::schedule($gameId2, $at, 'economy_test', 1, ['amount' => 50]);
        }

        // Partita 1: catchUp singolo
        Clock::catchUp($gameId1, 2000.0, 10.0);

        // Partita 2: catchUp a 50 blocchi con budget minimo
        for ($i = 0; $i < 50; $i++) {
            $now = 40.0 * ($i + 1);
            Clock::catchUp($gameId2, $now, 0.001); // budget minimo per forzare chunking
        }

        // Confronta stato finale
        $pdo = Db::pdo(true);

        $stmt = $pdo->prepare("SELECT `res_m` FROM `bodies` WHERE `game_id` = ? AND `id` = 1");
        $stmt->execute([$gameId1]);
        $resM1 = (float)$stmt->fetchColumn();

        $stmt->execute([$gameId2]);
        $resM2 = (float)$stmt->fetchColumn();

        $this->assertEquals($resM1, $resM2, "Stato DB diverge: single pass res_m=$resM1, chunked res_m=$resM2");
    }

    /**
     * Test: due catchUp concorrenti non elaborano lo stesso evento due volte.
     */
    public function test_event_processed_once(): void
    {
        $gameId = $this->createTestGame();

        $executionCount = 0;

        EventBus::register('concurrent_test', function (array $event, $ctx) use (&$executionCount) {
            $executionCount++;
            // Simula lavoro
            usleep(1000);
        });

        // Programma un singolo evento
        EventBus::schedule($gameId, 100.0, 'concurrent_test', 0, []);

        // Simula due catchUp concorrenti (in realtà sequenziali ma con lock)
        $pdo = Db::pdo(true);

        // Primo catchUp - elabora l'evento
        Clock::catchUp($gameId, 200.0, 10.0);

        // Secondo catchUp - l'evento dovrebbe essere già done=1
        Clock::catchUp($gameId, 300.0, 10.0);

        // Handler deve essere stato chiamato una sola volta
        $this->assertEquals(1, $executionCount, "Handler chiamato $executionCount volte (atteso 1)");
    }

    /**
     * Test: catchUp ritorna false quando il budget è esaurito.
     */
    public function test_returns_false_when_budget_exhausted(): void
    {
        $gameId = $this->createTestGame();

        // Registra handler lento
        EventBus::register('slow_event', function (array $event, $ctx) {
            usleep(50000); // 50ms per evento
        });

        // Programma molti eventi
        for ($i = 0; $i < 20; $i++) {
            EventBus::schedule($gameId, 100.0 + $i * 10.0, 'slow_event', 0, []);
        }

        // catchUp con budget molto basso (1ms) dovrebbe tornare false
        $result = Clock::catchUp($gameId, 500.0, 0.001);

        $this->assertFalse($result, 'catchUp dovrebbe tornare false quando budget esaurito');

        // Verifica che alcuni eventi rimangano non elaborati
        $pdo = Db::pdo(true);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `events` WHERE `game_id` = ? AND `done` = 0 AND `at` <= 500");
        $stmt->execute([$gameId]);
        $remaining = (int)$stmt->fetchColumn();

        $this->assertGreaterThan(0, $remaining, 'Dovrebbero rimanere eventi non elaborati');
    }
}