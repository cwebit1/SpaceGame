<?php

declare(strict_types=1);

namespace SpaceGame\Tests;

use PHPUnit\Framework\TestCase;
use SpaceGame\Config;
use SpaceGame\ConfigError;
use SpaceGame\ConfigSchema;

/**
 * Test per la classe Config e ConfigSchema.
 */
final class ConfigTest extends TestCase
{
    public function test_get_dot_path(): void
    {
        $config = Config::fromFile(__DIR__ . '/../../game_config.json');
        $this->assertSame(45, $config->get('ships.light_fighter.attack'));
    }

    public function test_missing_key_names_path(): void
    {
        $config = Config::fromFile(__DIR__ . '/../../game_config.json');
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('ships.nope');
        $config->get('ships.nope');
    }

    public function test_invalid_json_reports_line(): void
    {
        $invalidJson = <<<'JSON'
        {
            "ships": {
                "light_fighter": {
                    "attack": 45
                }
            }
        JSON; // Missing closing brace

        $tempFile = sys_get_temp_dir() . '/invalid_config_' . uniqid() . '.json';
        file_put_contents($tempFile, $invalidJson);

        try {
            Config::fromFile($tempFile);
            $this->fail('Expected ConfigError');
        } catch (ConfigError $e) {
            $this->assertStringContainsString('JSON non valido', $e->getMessage());
            $this->assertStringContainsString('posizione', $e->getMessage());
        } finally {
            @unlink($tempFile);
        }
    }

    public function test_schema_rejects_negative_cost(): void
    {
        $config = [
            'settings' => [], 'galaxy' => [], 'resources' => [], 'buildings' => [],
            'moon_buildings' => [], 'research' => [], 'ships' => [], 'defenses' => [],
            'missiles' => [], 'upgrades' => [], 'combat' => [], 'fleet' => [],
            'moon' => [], 'power' => [], 'cpu' => [], 'pirates' => [], 'gauntlet' => [],
            'admiral' => [], 'mining' => [], 'market' => [], 'diplomacy' => [],
            'events' => [], 'log' => [], 'format' => [], 'realtime' => [],
        ];
        $config['buildings']['metal_mine'] = [
            'cost' => ['m' => -10, 'c' => 0, 'd' => 0, 'lu' => 0],
            'growth' => 1.5,
            'requires' => [],
            'energy' => ['flat_per_level' => 0],
            'effect' => [],
            'fields' => 1,
        ];

        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('deve essere >= 0');
        ConfigSchema::validate($config);
    }

    public function test_schema_requires_every_ship_field(): void
    {
        $config = [
            'settings' => [], 'galaxy' => [], 'resources' => [], 'buildings' => [],
            'moon_buildings' => [], 'research' => [], 'ships' => [], 'defenses' => [],
            'missiles' => [], 'upgrades' => [], 'combat' => [], 'fleet' => [],
            'moon' => [], 'power' => [], 'cpu' => [], 'pirates' => [], 'gauntlet' => [],
            'admiral' => [], 'mining' => [], 'market' => [], 'diplomacy' => [],
            'events' => [], 'log' => [], 'format' => [], 'realtime' => [],
        ];
        $config['ships']['light_fighter'] = [
            'cost' => ['m' => 100, 'c' => 50, 'd' => 0, 'lu' => 0],
            // Missing required fields: attack, shield, hull, speed, cargo, fuel, initiative, weight, drive, requires, ability, matrix
        ];

        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('campo mancante');
        ConfigSchema::validate($config);
    }

    public function test_shipped_config_is_valid(): void
    {
        $config = Config::fromFile(__DIR__ . '/../../game_config.json');
        $this->assertInstanceOf(Config::class, $config);
        // Verify it has the expected top-level keys
        $data = $config->toArray();
        $this->assertArrayHasKey('ships', $data);
        $this->assertArrayHasKey('buildings', $data);
        $this->assertArrayHasKey('research', $data);
        // Verify a specific value from the spec
        $this->assertSame(45, $config->get('ships.light_fighter.attack'));
    }

    public function test_storage_buildings_exist_with_correct_values(): void
    {
        $config = Config::fromFile(__DIR__ . '/../../game_config.json');

        // Test metal_storage
        $this->assertArrayHasKey('metal_storage', $config->get('buildings'));
        $metalStorage = $config->get('buildings.metal_storage');
        $this->assertEquals(['m' => 1000, 'c' => 0, 'd' => 0, 'lu' => 0], $metalStorage['cost']);
        $this->assertEquals(1.7, $metalStorage['growth']);
        $this->assertEquals(['flat_per_level' => 0], $metalStorage['energy']);
        $this->assertEquals(['capacity' => ['a' => 10000, 'b' => 1.6]], $metalStorage['effect']);
        $this->assertEquals(1, $metalStorage['fields']);

        // Test crystal_storage
        $this->assertArrayHasKey('crystal_storage', $config->get('buildings'));
        $crystalStorage = $config->get('buildings.crystal_storage');
        $this->assertEquals(['m' => 1000, 'c' => 500, 'd' => 0, 'lu' => 0], $crystalStorage['cost']);
        $this->assertEquals(1.7, $crystalStorage['growth']);
        $this->assertEquals(['flat_per_level' => 0], $crystalStorage['energy']);
        $this->assertEquals(['capacity' => ['a' => 10000, 'b' => 1.6]], $crystalStorage['effect']);
        $this->assertEquals(1, $crystalStorage['fields']);

        // Test deuterium_tank
        $this->assertArrayHasKey('deuterium_tank', $config->get('buildings'));
        $deuteriumTank = $config->get('buildings.deuterium_tank');
        $this->assertEquals(['m' => 1000, 'c' => 1000, 'd' => 0, 'lu' => 0], $deuteriumTank['cost']);
        $this->assertEquals(1.7, $deuteriumTank['growth']);
        $this->assertEquals(['flat_per_level' => 0], $deuteriumTank['energy']);
        $this->assertEquals(['capacity' => ['a' => 10000, 'b' => 1.6]], $deuteriumTank['effect']);
        $this->assertEquals(1, $deuteriumTank['fields']);
    }

    public function test_resources_storage_separates_cost_and_capacity_growth(): void
    {
        $config = Config::fromFile(__DIR__ . '/../../game_config.json');

        $storage = $config->get('resources.storage');
        // Cost growth should be 1.7
        $this->assertEquals(1.7, $storage['cost_growth']);
        // Capacity growth should be 1.6
        $this->assertEquals(1.6, $storage['capacity_growth']);
        // Base capacities
        $this->assertEquals(['base' => 10000], $storage['metal']);
        $this->assertEquals(['base' => 10000], $storage['crystal']);
        $this->assertEquals(['base' => 10000], $storage['deuterium']);
        $this->assertEquals(['base' => 2000], $storage['lunarite']);
        // Old 'growth' key should not exist
        $this->assertArrayNotHasKey('growth', $storage['metal']);
        $this->assertArrayNotHasKey('growth', $storage['crystal']);
        $this->assertArrayNotHasKey('growth', $storage['deuterium']);
        $this->assertArrayNotHasKey('growth', $storage['lunarite']);
    }

    public function test_lunarite_vault_has_correct_cost(): void
    {
        $config = Config::fromFile(__DIR__ . '/../../game_config.json');

        $lunariteVault = $config->get('moon_buildings.lunarite_vault');
        $this->assertEquals(['m' => 5000, 'c' => 5000, 'd' => 0, 'lu' => 0], $lunariteVault['cost']);
        $this->assertEquals(1.7, $lunariteVault['growth']);
        $this->assertEquals(['flat_per_level' => 0], $lunariteVault['energy']);
        $this->assertEquals(['capacity' => ['a' => 2000, 'b' => 1.6]], $lunariteVault['effect']);
        $this->assertEquals(1, $lunariteVault['fields']);
    }
}