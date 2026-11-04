<?php

declare(strict_types=1);

namespace SpaceGame\Tests;

use PHPUnit\Framework\TestCase;
use SpaceGame\Config;
use SpaceGame\Formula;

/**
 * Test per la classe Formula.
 */
final class FormulaTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = Config::fromFile(__DIR__ . '/../../game_config.json');
    }
    public function test_levelCurve(): void
    {
        // levelCurve(40, 1.08, 10) = 40 * 10 * 1.08^10 ≈ 863.57
        $result = Formula::levelCurve(40.0, 1.08, 10);
        $this->assertEqualsWithDelta(863.57, $result, 0.01);

        // L=0 dovrebbe restituire 0
        $this->assertSame(0.0, Formula::levelCurve(40.0, 1.08, 0));
        $this->assertSame(0.0, Formula::levelCurve(40.0, 1.08, -1));
    }

    public function test_cost(): void
    {
        // cost(['m'=>80,'c'=>20], 1.55, 3) -> m=80*1.55^2=192.2, c=20*1.55^2=48.05
        $base = ['m' => 80.0, 'c' => 20.0, 'd' => 0.0, 'lu' => 0.0];
        $result = Formula::cost($base, 1.55, 3);

        $this->assertEqualsWithDelta(192.2, $result['m'], 0.01);
        $this->assertEqualsWithDelta(48.05, $result['c'], 0.01);
        $this->assertSame(0.0, $result['d']);
        $this->assertSame(0.0, $result['lu']);

        // L=1 dovrebbe restituire il costo base
        $result1 = Formula::cost($base, 1.55, 1);
        $this->assertSame(80.0, $result1['m']);
        $this->assertSame(20.0, $result1['c']);

        // L=0 dovrebbe restituire 0
        $result0 = Formula::cost($base, 1.55, 0);
        $this->assertSame(0.0, $result0['m']);
    }

    public function test_buildHours(): void
    {
        // buildHours con 3000 risorse, robot=0, naniti=0, speed=1 -> 1.0
        $cost = ['m' => 1000, 'c' => 1000, 'd' => 1000, 'lu' => 0];
        $hours = Formula::buildHours($cost, 1.0, 0, 1.0, 0.25, $this->config);
        $this->assertEqualsWithDelta(1.0, $hours, 0.001);

        // naniti=1 -> divide per 4 (0.25^1)
        $hoursNanite1 = Formula::buildHours($cost, 1.0, 1, 1.0, 0.25, $this->config);
        $this->assertEqualsWithDelta(0.25, $hoursNanite1, 0.001);

        // naniti=2 -> divide per 16 (0.25^2)
        $hoursNanite2 = Formula::buildHours($cost, 1.0, 2, 1.0, 0.25, $this->config);
        $this->assertEqualsWithDelta(0.0625, $hoursNanite2, 0.001);

        // speed=2 -> dimezza il tempo
        $hoursSpeed2 = Formula::buildHours($cost, 1.0, 0, 2.0, 0.25, $this->config);
        $this->assertEqualsWithDelta(0.5, $hoursSpeed2, 0.001);

        // speedDivisor=2 (es. Robot 1) -> dimezza il tempo
        $hoursDiv2 = Formula::buildHours($cost, 2.0, 0, 1.0, 0.25, $this->config);
        $this->assertEqualsWithDelta(0.5, $hoursDiv2, 0.001);
    }

    public function test_upgradeCost(): void
    {
        $unitCost = ['m' => 1000, 'c' => 500, 'd' => 200, 'lu' => 0];
        $cfg = [
            'upgrades' => [
                'cost_multiplier' => 1.25,
                'lunarite_from_level' => 11,
                'lunarite_base' => 50,
                'lunarite_growth' => 1.25,
            ],
        ];

        // L=1: costo base * 2 * 1.25^0 = 2x base (secondo spec: costo unità × 2 × 1.25^(L-1))
        // Wait, the spec says: "Costo livello L = costo unità × 2 × 1.25^(L−1)"
        // But the Formula::cost function does base * growth^(L-1)
        // So upgradeCost should use unitCost * 2 as base for the cost function
        // Actually, looking at the test expectation: upgradeCost L11 has lu=50
        // Let me check: the test says "upgradeCost L11 ha lu=50"

        $result = Formula::upgradeCost($unitCost, 11, $cfg);
        // L11: lunarite should be 50 * 1.25^0 = 50
        $this->assertEqualsWithDelta(50.0, $result['lu'], 0.01);

        // L=10: no lunarite yet
        $result10 = Formula::upgradeCost($unitCost, 10, $cfg);
        $this->assertSame(0.0, $result10['lu']);

        // L=12: lunarite = 50 * 1.25^1 = 62.5
        $result12 = Formula::upgradeCost($unitCost, 12, $cfg);
        $this->assertEqualsWithDelta(62.5, $result12['lu'], 0.01);
    }
}