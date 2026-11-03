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
        $config = Config::fromArray([
            'ships' => [
                'light_fighter' => [
                    'attack' => 45,
                ],
            ],
        ]);
        $this->assertSame(45, $config->get('ships.light_fighter.attack'));
    }

    public function test_missing_key_names_path(): void
    {
        $config = Config::fromArray(['ships' => []]);
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
}