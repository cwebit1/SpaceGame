<?php

declare(strict_types=1);

namespace SpaceGame;

/**
 * Formula - Funzioni pure per i calcoli di gioco.
 * Nessuna dipendenza da DB o stato esterno.
 */
final class Formula
{
    /**
     * Curva di livello: a * L * b^L
     * Usata per produzione miniere, energia, ecc.
     */
    public static function levelCurve(float $a, float $b, int $L): float
    {
        if ($L <= 0) {
            return 0.0;
        }
        return $a * $L * pow($b, $L);
    }

    /**
     * Costo del livello L: base * growth^(L-1)
     * Per L=1 restituisce il costo base.
     */
    public static function cost(array $base, float $growth, int $L): array
    {
        if ($L <= 0) {
            return ['m' => 0.0, 'c' => 0.0, 'd' => 0.0, 'lu' => 0.0];
        }

        $factor = pow($growth, $L - 1);
        return [
            'm' => $base['m'] * $factor,
            'c' => $base['c'] * $factor,
            'd' => $base['d'] * $factor,
            'lu' => ($base['lu'] ?? 0.0) * $factor,
        ];
    }

    /**
     * Ore di costruzione: (m+c+d)/3000 / speedDivisor * naniteFactor^nanite / gameSpeed
     * - cost: array con m, c, d, lu
     * - speedDivisor: 1 + 0.15*Robot (edifici) o 1 + 0.2*Shipyard (navi) ecc.
     * - nanite: livello Fabbrica di naniti
     * - gameSpeed: velocità della partita
     * - naniteFactor: 0.25 (da config)
     */
    public static function buildHours(
        array $cost,
        float $speedDivisor,
        int $nanite,
        float $gameSpeed,
        float $naniteFactor
    ): float {
        $totalCost = ($cost['m'] ?? 0) + ($cost['c'] ?? 0) + ($cost['d'] ?? 0);
        if ($totalCost <= 0) {
            return 0.0;
        }

        $naniteMultiplier = pow($naniteFactor, $nanite);
        return ($totalCost / 3000.0) / $speedDivisor * $naniteMultiplier / $gameSpeed;
    }

    /**
     * Costo potenziamento livello L (incluso Lunarite da L11).
     * $cfg deve contenere: upgrades.cost_multiplier, upgrades.lunarite_from_level,
     * upgrades.lunarite_base, upgrades.lunarite_growth
     */
    public static function upgradeCost(array $unitCost, int $L, array $cfg): array
    {
        if ($L <= 0) {
            return ['m' => 0.0, 'c' => 0.0, 'd' => 0.0, 'lu' => 0.0];
        }

        $growth = $cfg['upgrades']['cost_multiplier'] ?? 1.25;
        $luFromLevel = (int)($cfg['upgrades']['lunarite_from_level'] ?? 11);
        $luBase = (float)($cfg['upgrades']['lunarite_base'] ?? 50);
        $luGrowth = (float)($cfg['upgrades']['lunarite_growth'] ?? 1.25);

        $cost = self::cost($unitCost, $growth, $L);

        // Aggiunge Lunarite da L11 in poi
        if ($L >= $luFromLevel) {
            $luLevel = $L - $luFromLevel + 1;
            $cost['lu'] = $luBase * pow($luGrowth, $luLevel - 1);
        } else {
            $cost['lu'] = 0.0;
        }

        return $cost;
    }
}