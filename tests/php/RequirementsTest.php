<?php

declare(strict_types=1);

namespace SpaceGame\Tests;

use PHPUnit\Framework\TestCase;
use SpaceGame\Config;
use SpaceGame\Requirements;
use SpaceGame\Db;

/**
 * Test per prerequisiti (Task 8).
 * Usa database di test 'spacegame_test' da config.local.php.
 */
class RequirementsTest extends TestCase
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
     * Test: prerequisiti edificio soddisfatti.
     */
    public function test_building_requirements_met(): void
    {
        $bodyBuildings = [
            'robot_factory' => ['level' => 2],
            'nanite_factory' => ['level' => 1],
        ];
        $research = ['computer_tech' => 10];

        // Nanoforge richiede Robot 10, Computer 10
        // Qui Robot 2 < 10, quindi NON soddisfatto
        $requires = ['building.robot_factory' => 10, 'research.computer_tech' => 10];
        $met = Requirements::met($requires, $bodyBuildings, $research, null);
        $this->assertFalse($met);

        // Con Robot 10
        $bodyBuildings['robot_factory'] = ['level' => 10];
        $met = Requirements::met($requires, $bodyBuildings, $research, null);
        $this->assertTrue($met);
    }

    /**
     * Test: prerequisiti ricerca soddisfatti.
     */
    public function test_research_requirements_met(): void
    {
        $bodyBuildings = [
            'research_lab' => ['level' => 5],
        ];
        $research = ['energy_tech' => 3];

        // Plasma richiede Ioni 5, Energetica 8
        $requires = ['research.ion_tech' => 5, 'research.energy_tech' => 8];
        $met = Requirements::met($requires, $bodyBuildings, $research, null);
        $this->assertFalse($met);

        $research['ion_tech'] = 5;
        $research['energy_tech'] = 8;
        $met = Requirements::met($requires, $bodyBuildings, $research, null);
        $this->assertTrue($met);
    }

    /**
     * Test: prerequisiti luna (moon_of_body).
     */
    public function test_moon_requirements(): void
    {
        // Lunar Nanoforge richiede Ingegneria lunare 1, Naniti 1 (pianeta)
        $bodyBuildings = []; // Luna vuota
        $research = ['lunar_engineering' => 1];
        $moonOfBody = [
            'buildings' => ['nanite_factory' => ['level' => 1]],
        ];

        $requires = ['research.lunar_engineering' => 1, 'building.nanite_factory' => 1];
        $met = Requirements::met($requires, $bodyBuildings, $research, $moonOfBody);
        // building.nanite_factory si controlla sul pianeta (moonOfBody)
        $this->assertTrue($met);
    }

    /**
     * Test: missing restituisce elenco prerequisiti mancanti.
     */
    public function test_missing_returns_list(): void
    {
        $bodyBuildings = ['robot_factory' => ['level' => 5]];
        $research = ['computer_tech' => 5];

        $requires = ['building.robot_factory' => 10, 'research.computer_tech' => 10];
        $missing = Requirements::missing($requires, $bodyBuildings, $research, null);

        $this->assertArrayHasKey('building.robot_factory', $missing);
        $this->assertEquals(10, $missing['building.robot_factory']);
        $this->assertArrayHasKey('research.computer_tech', $missing);
        $this->assertEquals(10, $missing['research.computer_tech']);
    }

    /**
     * Test: prerequisiti vuoti sempre soddisfatti.
     */
    public function test_empty_requirements_always_met(): void
    {
        $met = Requirements::met([], [], [], null);
        $this->assertTrue($met);

        $missing = Requirements::missing([], [], [], null);
        $this->assertEmpty($missing);
    }

    /**
     * Test: livello 0 = non costruito.
     */
    public function test_level_zero_not_built(): void
    {
        $bodyBuildings = ['robot_factory' => ['level' => 0]];
        $research = [];

        $requires = ['building.robot_factory' => 1];
        $met = Requirements::met($requires, $bodyBuildings, $research, null);
        $this->assertFalse($met);

        $missing = Requirements::missing($requires, $bodyBuildings, $research, null);
        $this->assertArrayHasKey('building.robot_factory', $missing);
    }

    /**
     * Test: edificio assente = livello 0.
     */
    public function test_missing_building_is_level_zero(): void
    {
        $bodyBuildings = [];
        $research = [];

        $requires = ['building.robot_factory' => 1];
        $met = Requirements::met($requires, $bodyBuildings, $research, null);
        $this->assertFalse($met);

        $missing = Requirements::missing($requires, $bodyBuildings, $research, null);
        $this->assertArrayHasKey('building.robot_factory', $missing);
    }

    /**
     * Test: ricerca assente = livello 0.
     */
    public function test_missing_research_is_level_zero(): void
    {
        $bodyBuildings = [];
        $research = [];

        $requires = ['research.energy_tech' => 1];
        $met = Requirements::met($requires, $bodyBuildings, $research, null);
        $this->assertFalse($met);

        $missing = Requirements::missing($requires, $bodyBuildings, $research, null);
        $this->assertArrayHasKey('research.energy_tech', $missing);
    }
}