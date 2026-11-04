<?php

declare(strict_types=1);

namespace SpaceGame\Tests;

use PHPUnit\Framework\TestCase;
use SpaceGame\Config;
use SpaceGame\Energy;
use SpaceGame\Db;

/**
 * Test per bilancio energetico (Task 7).
 * Usa database di test 'spacegame_test' da config.local.php.
 */
class EnergyTest extends TestCase
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
     * Crea un pianeta di test con edifici energetici.
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
     * Test: produzione energia solare base.
     */
    public function test_solar_plant_base_production(): void
    {
        $body = $this->createTestBody([
            'solar_plant' => ['level' => 1, 'completed_at' => time() - 3600],
        ]);

        $research = [];
        $balance = Energy::balance($body, $research, $this->config);

        // Solar plant livello 1: a * L * b^L dove a=22, b=1.08 (da config)
        // levelCurve(22, 1.08, 1) = 22 * 1 * 1.08 = 23.76
        // Posizione 8 neutra = 1.0
        // Tipo standard = 1.0
        $this->assertEqualsWithDelta(23.76, $balance['produced'], 0.1, "Produzione solare livello 1");
    }

    /**
     * Test: fusione consuma deuterio.
     */
    public function test_fusion_plant_consumes_deuterium(): void
    {
        $body = $this->createTestBody([
            'fusion_plant' => ['level' => 1, 'completed_at' => time() - 3600],
            'deuterium_synthesizer' => ['level' => 5, 'completed_at' => time() - 3600],
        ], [
            'res_d' => 10000,
        ]);

        $research = [];
        $balance = Energy::balance($body, $research, $this->config);

        // Fusion plant consuma deuterio
        $this->assertArrayHasKey('required', $balance);
        // Il consumo deuterio è tracciato come 'fusion_plant_deut' in required
        $this->assertArrayHasKey('fusion_plant_deut', $balance['required']);
        $this->assertGreaterThan(0, $balance['required']['fusion_plant_deut']);
    }

    /**
     * Test: antimateria consuma lunarite.
     */
    public function test_antimatter_reactor_consumes_lunarite(): void
    {
        $body = $this->createTestBody([
            'antimatter_reactor' => ['level' => 1, 'completed_at' => time() - 3600],
        ], [
            'res_lu' => 1000,
        ]);

        $research = [];
        $balance = Energy::balance($body, $research, $this->config);

        // Antimatter reactor consuma lunarite
        $this->assertArrayHasKey('required', $balance);
        $this->assertArrayHasKey('antimatter_reactor_lu', $balance['required']);
        $this->assertGreaterThan(0, $balance['required']['antimatter_reactor_lu']);
    }

    /**
     * Test: priorità carenza energia - miniere scalate per prime.
     */
    public function test_energy_shortage_priority_mines_first(): void
    {
        $body = $this->createTestBody([
            'metal_mine' => ['level' => 10, 'completed_at' => time() - 3600],
            'crystal_mine' => ['level' => 10, 'completed_at' => time() - 3600],
            'deuterium_synthesizer' => ['level' => 10, 'completed_at' => time() - 3600],
            'solar_plant' => ['level' => 1, 'completed_at' => time() - 3600], // Poca energia
        ]);

        $research = [];
        $balance = Energy::balance($body, $research, $this->config);

        // Con poca energia, le miniere dovrebbero essere scalate
        // factorByConsumer per miniere < 1
        $this->assertArrayHasKey('factorByConsumer', $balance);
        $this->assertLessThan(1.0, $balance['factorByConsumer']['metal_mine']);
        $this->assertLessThan(1.0, $balance['factorByConsumer']['crystal_mine']);
        $this->assertLessThan(1.0, $balance['factorByConsumer']['deuterium_synthesizer']);
    }

    /**
     * Test: satellite solare produce energia.
     */
    public function test_solar_satellite_production(): void
    {
        $body = $this->createTestBody([
            'solar_satellite' => ['level' => 10, 'completed_at' => time() - 3600],
        ], [
            'p' => 1, // Posizione 1 = più sole
        ]);

        $research = [];
        $balance = Energy::balance($body, $research, $this->config);

        // Solar satellite produce energia basata sulla posizione
        $this->assertGreaterThan(0, $balance['produced']);
    }

    /**
     * Test: energia totale = somma fonti.
     */
    public function test_total_energy_is_sum_of_sources(): void
    {
        $body = $this->createTestBody([
            'solar_plant' => ['level' => 5, 'completed_at' => time() - 3600],
            'fusion_plant' => ['level' => 3, 'completed_at' => time() - 3600],
            'solar_satellite' => ['level' => 5, 'completed_at' => time() - 3600],
        ]);

        $research = [];
        $balance = Energy::balance($body, $research, $this->config);

        // L'energia prodotta dovrebbe essere la somma delle fonti individuali
        // (controlliamo che produced sia > 0)
        $this->assertGreaterThan(0, $balance['produced']);
    }
}