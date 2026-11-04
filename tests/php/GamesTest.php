<?php

declare(strict_types=1);

namespace SpaceGame\Tests;

use PHPUnit\Framework\TestCase;
use SpaceGame\Auth;
use SpaceGame\Games;
use SpaceGame\Config;
use SpaceGame\Db;
use PDO;

/**
 * Test per Auth e Games (Task 5).
 * Usa database di test 'spacegame_test' da config.local.php.
 */
class GamesTest extends TestCase
{
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

        // Avvia sessione per CSRF
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Pulisci sessione
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        Db::close();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Crea un utente di test e fa login.
     */
    private function createTestUser(string $username = 'testuser', string $password = 'testpass'): int
    {
        $pdo = Db::pdo(true);
        $pdo->exec("DELETE FROM `users` WHERE `username` = '$username'");
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO `users` (`username`, `pass_hash`) VALUES (?, ?)");
        $stmt->execute([$username, $hash]);
        return (int)$pdo->lastInsertId();
    }

    // ========== Auth Tests ==========

    public function test_login_success(): void
    {
        $userId = $this->createTestUser('test_login', 'password123');

        $result = Auth::login('test_login', 'password123');
        $this->assertTrue($result);
        $this->assertEquals($userId, Auth::requireUser());
    }

    public function test_login_wrong_password(): void
    {
        $this->createTestUser('test_wrong_pass', 'password123');

        $result = Auth::login('test_wrong_pass', 'wrongpassword');
        $this->assertFalse($result);
    }

    public function test_login_nonexistent_user(): void
    {
        $result = Auth::login('nonexistent', 'password');
        $this->assertFalse($result);
    }

    public function test_require_user_throws_when_not_logged_in(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Non autenticato');

        Auth::requireUser();
    }

    public function test_csrf_token_generated_and_checked(): void
    {
        $userId = $this->createTestUser('test_csrf', 'password123');
        Auth::login('test_csrf', 'password123');

        $token = Auth::csrfToken();
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Token valido non deve lanciare
        Auth::checkCsrf($token);
    }

    public function test_csrf_check_fails_on_wrong_token(): void
    {
        $this->createTestUser('test_csrf2', 'password123');
        Auth::login('test_csrf2', 'password123');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSRF');

        Auth::checkCsrf('wrong_token');
    }

    public function test_csrf_check_fails_on_missing_token(): void
    {
        $this->createTestUser('test_csrf3', 'password123');
        Auth::login('test_csrf3', 'password123');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSRF');

        Auth::checkCsrf('');
    }

    public function test_logout_clears_session(): void
    {
        $this->createTestUser('test_logout', 'password123');
        Auth::login('test_logout', 'password123');
        $this->assertEquals(1, Auth::requireUser()); // logged in

        Auth::logout();

        $this->expectException(\RuntimeException::class);
        Auth::requireUser(); // should throw now
    }

    // ========== Games Tests ==========

    public function test_create_game_valid_settings(): void
    {
        $userId = $this->createTestUser('test_create', 'password123');
        Auth::login('test_create', 'password123');

        $settings = [
            'speed' => 1,
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 1,
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
            'cpu_density' => 40,
            'cpu_live_share' => 70,
            'max_live_empires' => 150,
            'cpu_planet_size' => 50,
            'cpu_difficulty' => 'normal',
            'anti_player_coalitions' => false,
        ];

        $gameId = Games::create($userId, $settings, $this->config);

        $this->assertIsInt($gameId);
        $this->assertGreaterThan(0, $gameId);

        // Verifica che la partita sia stata creata nel DB
        $pdo = Db::pdo(true);
        $stmt = $pdo->prepare("SELECT * FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();

        $this->assertNotFalse($game);
        $this->assertEquals($userId, (int)$game['user_id']);
        $this->assertEquals('Test Game', $game['name']); // nome default
        $this->assertEquals(1.0, (float)$game['speed']);

        // Verifica che config_json sia stato copiato
        $configJson = json_decode($game['config_json'], true);
        $this->assertIsArray($configJson);
        $this->assertArrayHasKey('ships', $configJson);
        $this->assertArrayHasKey('buildings', $configJson);
        $this->assertEquals(45, $configJson['ships']['light_fighter']['attack']);
    }

    public function test_create_game_rejects_speed_out_of_range(): void
    {
        $userId = $this->createTestUser('test_create_speed', 'password123');
        Auth::login('test_create_speed', 'password123');

        $settings = [
            'speed' => 101, // > max 100
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 1,
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
            'cpu_density' => 40,
            'cpu_live_share' => 70,
            'max_live_empires' => 150,
            'cpu_planet_size' => 50,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('speed');

        Games::create($userId, $settings, $this->config);
    }

    public function test_create_game_rejects_cpu_res_mult_greater_than_speed(): void
    {
        $userId = $this->createTestUser('test_create_cpu_res', 'password123');
        Auth::login('test_create_cpu_res', 'password123');

        $settings = [
            'speed' => 10,
            'cpu_res_mult' => 15, // > speed
            'cpu_research_mult' => 1,
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
            'cpu_density' => 40,
            'cpu_live_share' => 70,
            'max_live_empires' => 150,
            'cpu_planet_size' => 50,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cpu_res_mult');

        Games::create($userId, $settings, $this->config);
    }

    public function test_create_game_rejects_cpu_research_mult_greater_than_speed(): void
    {
        $userId = $this->createTestUser('test_create_cpu_research', 'password123');
        Auth::login('test_create_cpu_research', 'password123');

        $settings = [
            'speed' => 10,
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 15, // > speed
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
            'cpu_density' => 40,
            'cpu_live_share' => 70,
            'max_live_empires' => 150,
            'cpu_planet_size' => 50,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cpu_research_mult');

        Games::create($userId, $settings, $this->config);
    }

    public function test_create_game_rejects_galaxies_out_of_range(): void
    {
        $userId = $this->createTestUser('test_create_galaxies', 'password123');
        Auth::login('test_create_galaxies', 'password123');

        $settings = [
            'speed' => 1,
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 1,
            'galaxies' => 10, // > max 9
            'systems_per_galaxy' => 10,
            'cpu_density' => 40,
            'cpu_live_share' => 70,
            'max_live_empires' => 150,
            'cpu_planet_size' => 50,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('galaxies');

        Games::create($userId, $settings, $this->config);
    }

    public function test_create_game_rejects_systems_per_galaxy_out_of_range(): void
    {
        $userId = $this->createTestUser('test_create_systems', 'password123');
        Auth::login('test_create_systems', 'password123');

        $settings = [
            'speed' => 1,
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 1,
            'galaxies' => 2,
            'systems_per_galaxy' => 500, // > max 499
            'cpu_density' => 40,
            'cpu_live_share' => 70,
            'max_live_empires' => 150,
            'cpu_planet_size' => 50,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('systems_per_galaxy');

        Games::create($userId, $settings, $this->config);
    }

    public function test_create_game_rejects_cpu_density_out_of_range(): void
    {
        $userId = $this->createTestUser('test_create_density', 'password123');
        Auth::login('test_create_density', 'password123');

        $settings = [
            'speed' => 1,
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 1,
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
            'cpu_density' => 101, // > max 100
            'cpu_live_share' => 70,
            'max_live_empires' => 150,
            'cpu_planet_size' => 50,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cpu_density');

        Games::create($userId, $settings, $this->config);
    }

    public function test_create_game_rejects_cpu_planet_size_out_of_range(): void
    {
        $userId = $this->createTestUser('test_create_planet_size', 'password123');
        Auth::login('test_create_planet_size', 'password123');

        $settings = [
            'speed' => 1,
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 1,
            'galaxies' => 2,
            'systems_per_galaxy' => 10,
            'cpu_density' => 40,
            'cpu_live_share' => 70,
            'max_live_empires' => 150,
            'cpu_planet_size' => 101, // > max 100
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cpu_planet_size');

        Games::create($userId, $settings, $this->config);
    }

    public function test_create_game_uses_defaults_for_missing_settings(): void
    {
        $userId = $this->createTestUser('test_create_defaults', 'password123');
        Auth::login('test_create_defaults', 'password123');

        // Solo impostazioni obbligatorie minimali
        $settings = [
            'speed' => 2,
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 1,
        ];

        $gameId = Games::create($userId, $settings, $this->config);

        $pdo = Db::pdo(true);
        $stmt = $pdo->prepare("SELECT `settings_json`, `config_json` FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();

        $storedSettings = json_decode($game['settings_json'], true);
        $this->assertIsArray($storedSettings);

        // Verifica che i default siano stati applicati (usa valori dal test config)
        $this->assertEquals(2, (int)$storedSettings['speed']);
        $this->assertEquals(1, (int)$storedSettings['cpu_res_mult']);
        $this->assertEquals(1, (int)$storedSettings['cpu_research_mult']);
        $this->assertEquals(1, (int)$storedSettings['galaxies']); // default from test config (galaxy_count_min)
        $this->assertEquals(1, (int)$storedSettings['systems_per_galaxy']); // default from test config (system_count_min)
    }

    public function test_list_games_returns_user_games(): void
    {
        $userId = $this->createTestUser('test_list', 'password123');
        Auth::login('test_list', 'password123');

        // Crea 3 partite
        $settings = [
            'speed' => 1,
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 1,
            'galaxies' => 1,
            'systems_per_galaxy' => 5,
            'cpu_density' => 30,
            'cpu_live_share' => 70,
            'max_live_empires' => 150,
            'cpu_planet_size' => 50,
        ];

        $gameId1 = Games::create($userId, $settings, $this->config);
        $gameId2 = Games::create($userId, $settings, $this->config);
        $gameId3 = Games::create($userId, $settings, $this->config);

        $games = Games::list($userId);

        $this->assertCount(3, $games);
        $ids = array_column($games, 'id');
        $this->assertContains($gameId1, $ids);
        $this->assertContains($gameId2, $ids);
        $this->assertContains($gameId3, $ids);

        // Verifica struttura risposta
        foreach ($games as $game) {
            $this->assertArrayHasKey('id', $game);
            $this->assertArrayHasKey('name', $game);
            $this->assertArrayHasKey('speed', $game);
            $this->assertArrayHasKey('created_at', $game);
        }
    }

    public function test_list_games_only_returns_own_games(): void
    {
        $userId1 = $this->createTestUser('test_list_user1', 'password123');
        $userId2 = $this->createTestUser('test_list_user2', 'password123');

        Auth::login('test_list_user1', 'password123');
        $settings = [
            'speed' => 1, 'cpu_res_mult' => 1, 'cpu_research_mult' => 1,
            'galaxies' => 1, 'systems_per_galaxy' => 5, 'cpu_density' => 30,
            'cpu_live_share' => 70, 'max_live_empires' => 150, 'cpu_planet_size' => 50,
        ];
        $gameId1 = Games::create($userId1, $settings, $this->config);

        Auth::logout();
        Auth::login('test_list_user2', 'password123');
        $gameId2 = Games::create($userId2, $settings, $this->config);

        $games = Games::list($userId2);

        $this->assertCount(1, $games);
        $this->assertEquals($gameId2, $games[0]['id']);
    }

    public function test_delete_game_removes_game_and_related_data(): void
    {
        $userId = $this->createTestUser('test_delete', 'password123');
        Auth::login('test_delete', 'password123');

        $settings = [
            'speed' => 1, 'cpu_res_mult' => 1, 'cpu_research_mult' => 1,
            'galaxies' => 1, 'systems_per_galaxy' => 5, 'cpu_density' => 30,
            'cpu_live_share' => 70, 'max_live_empires' => 150, 'cpu_planet_size' => 50,
        ];
        $gameId = Games::create($userId, $settings, $this->config);

        // Genera galassia per avere dati correlati
        \SpaceGame\Galaxy::generate($gameId, $this->config, true);

        // Verifica che i dati esistano
        $pdo = Db::pdo(true);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `empires` WHERE `game_id` = ?");
        $stmt->execute([$gameId]);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn());

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `bodies` WHERE `game_id` = ?");
        $stmt->execute([$gameId]);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn());

        // Elimina
        Games::delete($gameId);

        // Verifica che la partita sia sparita
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        $this->assertEquals(0, (int)$stmt->fetchColumn());

        // Verifica cascade delete (empires, bodies, ecc.)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `empires` WHERE `game_id` = ?");
        $stmt->execute([$gameId]);
        $this->assertEquals(0, (int)$stmt->fetchColumn());

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `bodies` WHERE `game_id` = ?");
        $stmt->execute([$gameId]);
        $this->assertEquals(0, (int)$stmt->fetchColumn());
    }

    public function test_delete_game_only_allows_owner(): void
    {
        $userId1 = $this->createTestUser('test_delete_owner', 'password123');
        $userId2 = $this->createTestUser('test_delete_thief', 'password123');

        Auth::login('test_delete_owner', 'password123');
        $settings = [
            'speed' => 1, 'cpu_res_mult' => 1, 'cpu_research_mult' => 1,
            'galaxies' => 1, 'systems_per_galaxy' => 5, 'cpu_density' => 30,
            'cpu_live_share' => 70, 'max_live_empires' => 150, 'cpu_planet_size' => 50,
        ];
        $gameId = Games::create($userId1, $settings, $this->config);

        Auth::logout();
        Auth::login('test_delete_thief', 'password123');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Non autorizzato a eliminare questa partita');

        Games::delete($gameId);
    }

    public function test_refresh_config_updates_config_json(): void
    {
        $userId = $this->createTestUser('test_refresh', 'password123');
        Auth::login('test_refresh', 'password123');

        $settings = [
            'speed' => 1, 'cpu_res_mult' => 1, 'cpu_research_mult' => 1,
            'galaxies' => 1, 'systems_per_galaxy' => 5, 'cpu_density' => 30,
            'cpu_live_share' => 70, 'max_live_empires' => 150, 'cpu_planet_size' => 50,
        ];
        $gameId = Games::create($userId, $settings, $this->config);

        // Modifica il config.json nel DB (simula versione vecchia)
        $pdo = Db::pdo(true);
        $oldConfig = '{"ships":{"light_fighter":{"attack":999}}}'; // valore sbagliato
        $stmt = $pdo->prepare("UPDATE `games` SET `config_json` = ? WHERE `id` = ?");
        $stmt->execute([$oldConfig, $gameId]);

        // Refresh
        Games::refreshConfig($gameId);

        // Verifica che sia stato aggiornato al config corrente
        $stmt = $pdo->prepare("SELECT `config_json` FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        $configJson = $stmt->fetchColumn();
        $config = json_decode($configJson, true);

        $this->assertEquals(45, $config['ships']['light_fighter']['attack']); // valore corretto
    }
}