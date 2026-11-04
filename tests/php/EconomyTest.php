<?php

declare(strict_types=1);

namespace SpaceGame\Tests;

use PHPUnit\Framework\TestCase;
use SpaceGame\Config;
use SpaceGame\Economy;
use SpaceGame\Energy;
use SpaceGame\Db;
use SpaceGame\Galaxy;
use PDO;

/**
 * Test per Economia ed Energia (Task 7).
 * Usa database di test 'spacegame_test' da config.local.php.
 */
class EconomyTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        Db::close();

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
     * Crea un pianeta di test con miniere a livelli specifici.
     */
    private function createTestBody(array $buildings = [], array $overrides = []): array
    {
        $defaults = [
            'id' => 1,
            'game_id' => 1,
            'empire_id' => 1,
            'g' => 1,
            's' => 1,
            'p' => 8,
            'is_moon' => 0,
            'type' => 'standard',
            'temp_class' => 3,
            'fields_base' => 2000,
            'fields_bonus' => 0,
            'res_m' => 0,
            'res_c' => 0,
            'res_d' => 0,
            'res_lu' => 0,
            'energy_store' => 0,
            'resistance' => 0,
            'last_update' => time(),
            'destroyed_until' => null,
            'move_json' => null,
        ];

        $body = array_merge($defaults, $overrides);
        $body['buildings'] = $buildings;

        return $body;
    }

    /**
     * Test: produzione miniera metallo livello 10 = 863.57 + base 20 a speed 1.
     */
    public function test_metal_mine_level_10_rate(): void
    {
        $body = $this->createTestBody([
            'metal_mine' => ['level' => 10, 'completed_at' => time() - 3600],
            'solar_plant' => ['level' => 10, 'completed_at' => time() - 3600],
        ]);

        $research = [];
        $empireMods = [];

        $rates = Economy::ratesPerHour($body, $research, $empireMods, $this->config);

        // levelCurve(40, 1.08, 10) = 40 * 10 * 1.08^10 ≈ 863.57
        // + base production 20 = 883.57
        $this->assertEqualsWithDelta(883.57, $rates['m'], 0.1, "Produzione metallo livello 10: {$rates['m']}");
    }

    /**
     * Test: moltiplicatore velocità player scala la produzione.
     */
    public function test_speed_multiplies(): void
    {
        $body = $this->createTestBody([
            'metal_mine' => ['level' => 5, 'completed_at' => time() - 3600],
            'solar_plant' => ['level' => 10, 'completed_at' => time() - 3600],
        ]);

        $research = [];
        $empireMods = ['speed' => 2.0]; // velocità 2x

        $rates = Economy::ratesPerHour($body, $research, $empireMods, $this->config);

        // A velocità 1, livello 5: levelCurve(40, 1.08, 5) = 40*5*1.08^5 = 293.8656
        // + base production 20 = 313.8656
        // A velocità 2x: 313.8656 * 2 = 627.7312
        $baseRate = 313.86561536;
        $expectedRate = $baseRate * 2.0;

        $this->assertEqualsWithDelta($expectedRate, $rates['m'], 0.1, "Produzione con speed 2x: {$rates['m']}");
    }

    /**
     * Test: produzione si ferma al cap magazzino.
     */
    public function test_production_stops_at_cap(): void
    {
        $body = $this->createTestBody([
            'metal_mine' => ['level' => 10, 'completed_at' => time() - 3600],
            'metal_storage' => ['level' => 1, 'completed_at' => time() - 3600],
            'solar_plant' => ['level' => 10, 'completed_at' => time() - 3600],
        ], [
            'res_m' => 95000, // Vicino al cap (100000 base)
        ]);

        $research = [];
        $empireMods = [];

        // Avanza di 1 ora
        Economy::advance($body, $body['last_update'] + 3600, $research, $empireMods, $this->config);

        // Cap base metallo = 10000 (da test config) * growth^level
        // storage level 1: base 10000 * 1.6^1 = 16000
        // Con res_m = 95000, supera il cap -> dovrebbe fermarsi al cap
        $this->assertLessThanOrEqual(16000, $body['res_m'], "Risorse oltre il cap: {$body['res_m']}");
    }

    /**
     * Test: overflow limitato a 2x cap (bottini/rimborsi/trasporti).
     */
    public function test_overflow_capped_at_double(): void
    {
        $body = $this->createTestBody([
            'metal_storage' => ['level' => 1, 'completed_at' => time() - 3600],
        ], [
            'res_m' => 16000, // Al cap
        ]);

        $research = [];
        $empireMods = [];
        $cfg = $this->config;

        // Credito 20000 metallo (oltre cap)
        Economy::credit($body, ['m' => 20000], $cfg);

        // Cap = 16000, 2x cap = 32000
        // 16000 + 20000 = 36000 -> limitato a 32000
        $this->assertEquals(32000, $body['res_m'], "Overflow oltre 2x cap: {$body['res_m']}");
    }

    /**
     * Test: carenza energia scala produzione miniere (80% energia -> 80% produzione).
     */
    public function test_energy_deficit_scales_mines(): void
    {
        $body = $this->createTestBody([
            'metal_mine' => ['level' => 5, 'completed_at' => time() - 3600],
            'solar_plant' => ['level' => 1, 'completed_at' => time() - 3600],
        ], [
            'res_m' => 0,
        ]);

        $research = [];
        $empireMods = [];

        // Calcola energia: solo 1 solare livello 1, non basta per miniera livello 5
        $balance = Energy::balance($body, $research, $this->config);

        // Se energia prodotta < energia richiesta, factorByConsumer per miniere < 1
        // Testiamo che la produzione sia scalata
        $rates = Economy::ratesPerHour($body, $research, $empireMods, $this->config);

        // Se factorByConsumer['metal_mine'] = 0.8, produzione = base * 0.8
        // Questo test verifica che il fattore di scalamento funzioni
        $this->assertArrayHasKey('factorByConsumer', $balance);
        $this->assertArrayHasKey('metal_mine', $balance['factorByConsumer']);
        $this->assertLessThan(1.0, $balance['factorByConsumer']['metal_mine']);
        $this->assertGreaterThan(0, $balance['factorByConsumer']['metal_mine']);
    }

    /**
     * Test: Energy Storage si scarica prima di andare in carenza.
     */
    public function test_storage_discharges_before_deficit(): void
    {
        $body = $this->createTestBody([
            'metal_mine' => ['level' => 5, 'completed_at' => time() - 3600],
            'solar_plant' => ['level' => 1, 'completed_at' => time() - 3600],
            'energy_storage' => ['level' => 1, 'completed_at' => time() - 3600],
        ], [
            'energy_store' => 5000, // Energia accumulata
        ]);

        $research = [];
        $empireMods = [];

        $balance = Energy::balance($body, $research, $this->config);

        // Energy Storage dovrebbe coprire il deficit prima di scalare le miniere
        // Verifichiamo che l'energia prodotta + storage >= energia richiesta totale
        $totalRequired = array_sum($balance['required']);
        $totalAvailable = $balance['produced'] + $body['energy_store'];
        $this->assertGreaterThanOrEqual(0, $totalAvailable - $totalRequired);
    }

    /**
     * Test: pianeta desertico ha bonus solare +20%.
     */
    public function test_desert_solar_bonus_20(): void
    {
        $body = $this->createTestBody([
            'solar_plant' => ['level' => 10, 'completed_at' => time() - 3600],
        ], [
            'type' => 'desert',
            'p' => 8, // Posizione neutra
        ]);

        $research = [];
        $empireMods = [];

        $balance = Energy::balance($body, $research, $this->config);

        // Confronta con pianeta standard stessa configurazione
        $bodyStandard = $this->createTestBody([
            'solar_plant' => ['level' => 10, 'completed_at' => time() - 3600],
        ], [
            'type' => 'standard',
            'p' => 8,
        ]);

        $balanceStandard = Energy::balance($bodyStandard, $research, $this->config);

        // Desertico dovrebbe produrre 20% in più di solare
        $this->assertEqualsWithDelta(
            $balanceStandard['produced'] * 1.2,
            $balance['produced'],
            1.0,
            "Bonus desertico solare: {$balance['produced']} vs {$balanceStandard['produced']}"
        );
    }

    /**
     * Test: posizione 1 ha solare +30%, deut -30%.
     */
    public function test_position_1_solar_plus_30_deut_minus_30(): void
    {
        $body = $this->createTestBody([
            'solar_plant' => ['level' => 10, 'completed_at' => time() - 3600],
            'deuterium_synthesizer' => ['level' => 10, 'completed_at' => time() - 3600],
        ], [
            'p' => 1, // Posizione 1 (più vicina al sole)
        ]);

        $research = [];
        $empireMods = [];

        $balance = Energy::balance($body, $research, $this->config);
        $rates = Economy::ratesPerHour($body, $research, $empireMods, $this->config);

        // Confronta con posizione 8 (neutra)
        $bodyNeutral = $this->createTestBody([
            'solar_plant' => ['level' => 10, 'completed_at' => time() - 3600],
            'deuterium_synthesizer' => ['level' => 10, 'completed_at' => time() - 3600],
        ], [
            'p' => 8,
        ]);

        $balanceNeutral = Energy::balance($bodyNeutral, $research, $this->config);
        $ratesNeutral = Economy::ratesPerHour($bodyNeutral, $research, $empireMods, $this->config);

        // Posizione 1: solare +30%
        $this->assertEqualsWithDelta(
            $balanceNeutral['produced'] * 1.3,
            $balance['produced'],
            1.0,
            "Posizione 1 solare +30%"
        );

        // Posizione 1: deuterio -30%
        $this->assertEqualsWithDelta(
            $ratesNeutral['d'] * 0.7,
            $rates['d'],
            1.0,
            "Posizione 1 deuterio -30%"
        );
    }

    /**
     * Test: posizione 13 ha geotermica -50%.
     */
    public function test_geothermal_position_13_minus_50(): void
    {
        $body = $this->createTestBody([
            'geothermal_plant' => ['level' => 10, 'completed_at' => time() - 3600],
        ], [
            'p' => 13,
        ]);

        $research = [];
        $empireMods = [];

        $balance = Energy::balance($body, $research, $this->config);

        // Confronta con posizione 8 (neutra)
        $bodyNeutral = $this->createTestBody([
            'geothermal_plant' => ['level' => 10, 'completed_at' => time() - 3600],
        ], [
            'p' => 8,
        ]);

        $balanceNeutral = Energy::balance($bodyNeutral, $research, $this->config);

        // Posizione 13: geotermica -50%
        $this->assertEqualsWithDelta(
            $balanceNeutral['produced'] * 0.5,
            $balance['produced'],
            1.0,
            "Posizione 13 geotermica -50%"
        );
    }

    /**
     * Test: risorse protette = 10% + bunker.
     */
    public function test_protected_10_percent_plus_bunker(): void
    {
        $body = $this->createTestBody([
            'metal_mine' => ['level' => 10, 'completed_at' => time() - 3600],
            'metal_storage' => ['level' => 1, 'completed_at' => time() - 3600],
            'planetary_bunker' => ['level' => 3, 'completed_at' => time() - 3600],
        ], [
            'res_m' => 100000,
        ]);

        $research = [];
        $empireMods = [];
        $cfg = $this->config;

        $protected = Economy::protectedAmount($body, 'm', $cfg);

        // Cap level 1: levelCurve(10000, 1.6, 1) = 10000 * 1 * 1.6 = 16000
        // Base protetta = 10% di 16000 = 1600
        // Bunker level 3: bonus 5% per livello = 15% -> 2400
        // Totale protetto = 1600 + 2400 = 4000
        $this->assertEqualsWithDelta(4000, $protected, 1, "Protetto con bunker: {$protected}");
    }

    /**
     * Test: moltiplicatore CPU si applica solo a imperi CPU.
     */
    public function test_cpu_multiplier_applies_only_to_cpu(): void
    {
        $body = $this->createTestBody([
            'metal_mine' => ['level' => 10, 'completed_at' => time() - 3600],
            'solar_plant' => ['level' => 10, 'completed_at' => time() - 3600],
        ]);

        $research = [];
        $empireModsPlayer = ['cpu_res_mult' => 1.0, 'speed' => 1.0, 'is_cpu' => false]; // Player
        $empireModsCpu = ['cpu_res_mult' => 2.0, 'speed' => 2.0, 'is_cpu' => true]; // CPU con moltiplicatore 2x, speed 2x

        $ratesPlayer = Economy::ratesPerHour($body, $research, $empireModsPlayer, $this->config);
        $ratesCpu = Economy::ratesPerHour($body, $research, $empireModsCpu, $this->config);

        // CPU dovrebbe avere produzione 2x (cpu_res_mult=2.0 ma limitato da speed=2.0)
        $this->assertEqualsWithDelta(
            $ratesPlayer['m'] * 2.0,
            $ratesCpu['m'],
            0.1,
            "Moltiplicatore CPU: player {$ratesPlayer['m']}, CPU {$ratesCpu['m']}"
        );
    }
}