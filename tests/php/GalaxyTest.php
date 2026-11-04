<?php

declare(strict_types=1);

namespace SpaceGame\Tests;

use PHPUnit\Framework\TestCase;
use SpaceGame\Config;
use SpaceGame\Galaxy;
use SpaceGame\Db;
use PDO;

/**
 * Test per la generazione galassia (Task 5).
 * Usa database di test 'spacegame_test' da config.local.php.
 */
class GalaxyTest extends TestCase
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

            // Pulisce le tabelle per isolamento tra test
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $pdo->exec("TRUNCATE TABLE `empires`");
            $pdo->exec("TRUNCATE TABLE `bodies`");
            $pdo->exec("TRUNCATE TABLE `games`");
            $pdo->exec("TRUNCATE TABLE `users`");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
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
     * Crea un utente e partita di test.
     */
    private function createTestGame(array $overrides = []): int
    {
        $pdo = Db::pdo(true);

        // Crea utente
        $pdo->exec("DELETE FROM `users` WHERE `username` = 'test_galaxy'");
        $pdo->exec("INSERT INTO `users` (`username`, `pass_hash`) VALUES ('test_galaxy', 'hash')");
        $userId = (int)$pdo->lastInsertId();

        // Impostazioni default per il test
        $settings = array_merge([
            'speed' => 1,
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 1,
            'galaxies' => 3,
            'systems_per_galaxy' => 100,
            'cpu_density' => 40,
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
            'Test Galaxy',
            $settings['speed'],
            $settings['cpu_res_mult'],
            $settings['cpu_research_mult'],
            json_encode($settings),
            $this->config->toJson(),
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Test: densità CPU corrisponde all'impostazione (±2%).
     */
    public function test_density_matches_setting(): void
    {
        $gameId = $this->createTestGame([
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
            'cpu_density' => 40,
        ]);

        Galaxy::generate($gameId, $this->config, true);

        $pdo = Db::pdo(true);
        // Total possible positions = galaxies * systems * 15 planet positions
        $totalPositions = 2 * 10 * 15;
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as occupied_positions
            FROM `bodies`
            WHERE `game_id` = ? AND `is_moon` = 0 AND `p` BETWEEN 1 AND 15 AND `empire_id` IS NOT NULL
        ");
        $stmt->execute([$gameId]);
        $occupiedPositions = (int)$stmt->fetchColumn();

        $actualDensity = $totalPositions > 0 ? ($occupiedPositions / $totalPositions) * 100 : 0;

        $this->assertEqualsWithDelta(40, $actualDensity, 2, "Densità CPU: {$actualDensity}% (atteso 40% ±2%)");
    }

    /**
     * Test: quota CPU vive è 70% (±3%).
     */
    public function test_live_share_70_percent(): void
    {
        $gameId = $this->createTestGame([
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
            'cpu_density' => 40,
            'cpu_live_share' => 70,
        ]);

        Galaxy::generate($gameId, $this->config, true);

        $pdo = Db::pdo(true);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_cpu,
                   SUM(CASE WHEN kind = 'cpu' THEN 1 ELSE 0 END) as live_cpu
            FROM `empires`
            WHERE `game_id` = ? AND `kind` IN ('cpu', 'simple')
        ");
        $stmt->execute([$gameId]);
        $row = $stmt->fetch();

        $totalCpu = (int)$row['total_cpu'];
        $liveCpu = (int)$row['live_cpu'];
        $actualShare = $totalCpu > 0 ? ($liveCpu / $totalCpu) * 100 : 0;

        $this->assertEqualsWithDelta(70, $actualShare, 3, "Quota CPU vive: {$actualShare}% (atteso 70% ±3%)");
    }

    /**
     * Test: imperi vivi non superano il tetto (default 150).
     */
    public function test_live_empires_capped(): void
    {
        $gameId = $this->createTestGame([
            'galaxies' => 2,
            'systems_per_galaxy' => 20,
            'cpu_density' => 100,
            'cpu_live_share' => 100,
            'max_live_empires' => 10, // Tetto basso per test
        ]);

        Galaxy::generate($gameId, $this->config, true);

        $pdo = Db::pdo(true);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `empires` WHERE `game_id` = ? AND `kind` = 'cpu'");
        $stmt->execute([$gameId]);
        $liveCpuCount = (int)$stmt->fetchColumn();

        $this->assertLessThanOrEqual(10, $liveCpuCount, "CPU vivi: {$liveCpuCount} (massimo 10)");
    }

    /**
     * Test: pianeti CPU hanno dimensioni scalate (al 50% = 1000 campi base).
     */
    public function test_cpu_planet_fields_scaled(): void
    {
        $gameId = $this->createTestGame([
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
            'cpu_density' => 40,
            'cpu_planet_size' => 50, // 50% della base
        ]);

        Galaxy::generate($gameId, $this->config, true);

        $pdo = Db::pdo(true);
        $stmt = $pdo->prepare("
            SELECT b.`fields_base` FROM `bodies` b
            JOIN `empires` e ON b.`empire_id` = e.`id`
            WHERE b.`game_id` = ? AND e.`kind` IN ('cpu', 'simple') AND b.`is_moon` = 0
            LIMIT 10
        ");
        $stmt->execute([$gameId]);
        $fields = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($fields as $fieldBase) {
            // Base planet fields = 2000, at 50% = 1000
            $this->assertEquals(1000, (int)$fieldBase, "Pianeta CPU ha campi base: {$fieldBase} (atteso 1000)");
        }
    }

    /**
     * Test: pianeta madre del player ha 2000 campi base.
     */
    public function test_player_home_has_2000_fields(): void
    {
        $gameId = $this->createTestGame([
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
            'cpu_density' => 40,
        ]);

        Galaxy::generate($gameId, $this->config, true);

        $pdo = Db::pdo(true);
        $stmt = $pdo->prepare("
            SELECT `fields_base` FROM `bodies`
            WHERE `game_id` = ? AND `empire_id` IN (
                SELECT `id` FROM `empires` WHERE `game_id` = ? AND `kind` = 'player'
            ) AND `is_moon` = 0
        ");
        $stmt->execute([$gameId, $gameId]);
        $fields = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $this->assertNotEmpty($fields, 'Player deve avere un pianeta madre');
        foreach ($fields as $fieldBase) {
            $this->assertEquals(2000, (int)$fieldBase, "Pianeta madre player ha campi base: {$fieldBase} (atteso 2000)");
        }
    }

    /**
     * Test: ogni sistema ha 1 Warp (pos 16) e 4 asteroidi (pos 17-20).
     */
    public function test_every_system_has_warp_and_4_asteroids(): void
    {
        $gameId = $this->createTestGame([
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
            'cpu_density' => 0, // Nessun pianeta CPU per semplicità
        ]);

        Galaxy::generate($gameId, $this->config, true);

        $pdo = Db::pdo(true);

        // Verifica Warp (posizione 16)
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT CONCAT(`g`, ':', `s`)) as systems_with_warp
            FROM `bodies`
            WHERE `game_id` = ? AND `p` = 16 AND `is_moon` = 0
        ");
        $stmt->execute([$gameId]);
        $systemsWithWarp = (int)$stmt->fetchColumn();

        $expectedSystems = 2 * 10; // 2 galassie × 10 sistemi
        $this->assertEquals($expectedSystems, $systemsWithWarp, "Sistemi con Warp: {$systemsWithWarp} (atteso {$expectedSystems})");

        // Verifica 4 asteroidi per sistema (posizioni 17, 18, 19, 20)
        $stmt = $pdo->prepare("
            SELECT CONCAT(`g`, ':', `s`) as system, COUNT(*) as asteroid_count
            FROM `bodies`
            WHERE `game_id` = ? AND `p` IN (17, 18, 19, 20) AND `is_moon` = 0
            GROUP BY `g`, `s`
        ");
        $stmt->execute([$gameId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertEquals($expectedSystems, count($results), "Sistemi con asteroidi: " . count($results) . " (atteso {$expectedSystems})");

        foreach ($results as $row) {
            $this->assertEquals(4, (int)$row['asteroid_count'], "Sistema {$row['system']} ha {$row['asteroid_count']} asteroidi (atteso 4)");
        }
    }

    /**
     * Test: impostazioni fuori range vengono rifiutate.
     */
    public function test_settings_out_of_range_rejected(): void
    {
        // Test velocità > 100
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('speed');

        $gameId = $this->createTestGame([
            'speed' => 101, // Fuori range (max 100)
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
        ]);

        Galaxy::generate($gameId, $this->config, true);
    }

    /**
     * Test: moltiplicatore CPU risorse > velocità player viene rifiutato.
     */
    public function test_cpu_resource_mult_exceeds_speed_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cpu_res_mult');

        $gameId = $this->createTestGame([
            'speed' => 10,
            'cpu_res_mult' => 15, // > speed
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
        ]);

        Galaxy::generate($gameId, $this->config, true);
    }

    /**
     * Test: moltiplicatore CPU ricerca > velocità player viene rifiutato.
     */
    public function test_cpu_research_mult_exceeds_speed_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cpu_research_mult');

        $gameId = $this->createTestGame([
            'speed' => 10,
            'cpu_research_mult' => 15, // > speed
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
        ]);

        Galaxy::generate($gameId, $this->config, true);
    }

    /**
     * Test: configurazione viene copiata nella partita (config_json).
     */
    public function test_config_copied_into_game(): void
    {
        $gameId = $this->createTestGame([
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
            'cpu_density' => 40,
        ]);

        Galaxy::generate($gameId, $this->config, true);

        $pdo = Db::pdo(true);
        $stmt = $pdo->prepare("SELECT `config_json` FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        $configJson = $stmt->fetchColumn();

        $this->assertNotFalse($configJson);
        $storedConfig = json_decode($configJson, true);
        $this->assertIsArray($storedConfig);
        $this->assertArrayHasKey('ships', $storedConfig);
        $this->assertArrayHasKey('buildings', $storedConfig);
        $this->assertArrayHasKey('resources', $storedConfig);

        // Verifica un valore specifico
        $this->assertEquals(45, $storedConfig['ships']['light_fighter']['attack']);
    }
}