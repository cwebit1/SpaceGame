<?php

declare(strict_types=1);

namespace SpaceGame;

use RuntimeException;

/**
 * Economy - Calcolo produzione risorse, avanzamento temporale, cap magazzino, protezione.
 */
final class Economy
{
    /**
     * Calcola i tassi di produzione orari per un corpo celeste.
     *
     * @param array $body Dati del corpo
     * @param array $research Ricerche dell'impero
     * @param array $empireMods Modificatori impero (es. cpu_res_mult, speed)
     * @param Config $cfg Configurazione di gioco
     * @return array{m:float, c:float, d:float, lu:float}
     */
    public static function ratesPerHour(array $body, array $research, array $empireMods, Config $cfg): array
    {
        $buildings = $body['buildings'] ?? [];
        $position = (int)($body['p'] ?? 8);
        $planetType = $body['type'] ?? 'standard';

        // Calcola bilancio energetico
        $energyBalance = Energy::balance($body, $research, $cfg);
        $energyFactors = $energyBalance['factorByConsumer'];

        // Tassi base
        $baseProduction = $cfg->get('resources.base_production');
        $rates = [
            'm' => (float)$baseProduction['metal'],
            'c' => (float)$baseProduction['crystal'],
            'd' => (float)$baseProduction['deuterium'],
            'lu' => (float)$baseProduction['lunarite'],
        ];

        // Applica produzione da edifici
        foreach ($buildings as $code => $building) {
            $level = $building['level'] ?? 0;
            if ($level <= 0) continue;

            $buildingConfig = $cfg->get("buildings.$code");
            $effect = $buildingConfig['effect'] ?? [];

            // Fattore energia per questo edificio
            $energyFactor = $energyFactors[$code] ?? 1.0;

            // Applica ricerca Plasma (per miniere)
            $plasmaLevel = $research['plasma_tech'] ?? 0;
            $plasmaFactor = 1.0;
            if ($plasmaLevel > 0 && (isset($effect['production_metal']) || isset($effect['production_crystal']) || isset($effect['production_deuterium']))) {
                $plasmaFactor = 1.0 + 0.05 * $plasmaLevel; // 5% per livello
            }

            // Applica ricerca Geologia (per miniere)
            $geologyLevel = $research['geology'] ?? 0;
            $geologyFactor = 1.0;
            if ($geologyLevel > 0 && (isset($effect['production_metal']) || isset($effect['production_crystal']) || isset($effect['production_deuterium']))) {
                $geologyFactor = 1.0 + 0.03 * $geologyLevel; // 3% per livello
            }

            // Deep Core Drill bonus
            $deepCoreLevel = $buildings['deep_core_drill']['level'] ?? 0;
            $deepCoreFactor = 1.0;
            if ($deepCoreLevel > 0 && (isset($effect['production_metal']) || isset($effect['production_crystal']) || isset($effect['production_deuterium']))) {
                $deepCoreFactor = 1.0 + 0.03 * $deepCoreLevel; // 3% per livello
            }

            // Modificatori posizione
            $posModifiers = Energy::getPositionModifierStatic($position, $cfg);
            $typeModifiers = Energy::getTypeModifierStatic($planetType, $cfg);

            foreach ($effect as $effectKey => $params) {
                $baseRate = Formula::levelCurve((float)$params['a'], (float)$params['b'], $level);

                // Applica modificatori
                $finalRate = $baseRate * $energyFactor * $plasmaFactor * $geologyFactor * $deepCoreFactor;

                // Posizione
                if ($effectKey === 'production_metal' || $effectKey === 'production_crystal' || $effectKey === 'production_deuterium') {
                    $resource = $effectKey === 'production_metal' ? 'm' : ($effectKey === 'production_crystal' ? 'c' : 'd');
                    if (isset($posModifiers[$resource])) {
                        $finalRate *= $posModifiers[$resource];
                    }
                    if (isset($typeModifiers[$resource])) {
                        $finalRate *= $typeModifiers[$resource];
                    }
                }

                if ($effectKey === 'production_metal') {
                    $rates['m'] += $finalRate;
                } elseif ($effectKey === 'production_crystal') {
                    $rates['c'] += $finalRate;
                } elseif ($effectKey === 'production_deuterium') {
                    $rates['d'] += $finalRate;
                }
            }
        }

        // Applica moltiplicatore velocità player a TUTTA la produzione (base + edifici)
        $speedMult = $empireMods['speed'] ?? 1.0;

        // Per imperi CPU: il moltiplicatore CPU (cpu_res_mult) sostituisce la velocità
        // ed è cap-pato dalla velocità di gioco
        if (isset($empireMods['is_cpu']) && $empireMods['is_cpu'] && isset($empireMods['cpu_res_mult'])) {
            $cpuMult = (float)$empireMods['cpu_res_mult'];
            $speedMult = min($cpuMult, $speedMult);
        }

        if ($speedMult != 1.0) {
            foreach ($rates as $key => $rate) {
                $rates[$key] = $rate * $speedMult;
            }
        }

        return $rates;
    }

    /**
     * Avanza l'economia di un corpo fino a un certo tempo.
     *
     * @param array &$body Riferimento al corpo (viene modificato)
     * @param float $toTime Timestamp Unix di destinazione
     * @param array $research Ricerche dell'impero
     * @param array $empireMods Modificatori impero
     * @param Config $cfg Configurazione di gioco
     */
    public static function advance(array &$body, float $toTime, array $research, array $empireMods, Config $cfg): void
    {
        $lastUpdate = (float)($body['last_update'] ?? $toTime);
        if ($toTime <= $lastUpdate) {
            return;
        }

        $hours = ($toTime - $lastUpdate) / 3600.0;

        // Calcola tassi orari
        $rates = self::ratesPerHour($body, $research, $empireMods, $cfg);

        // Calcola produzione per ogni risorsa
        $production = [
            'm' => $rates['m'] * $hours,
            'c' => $rates['c'] * $hours,
            'd' => $rates['d'] * $hours,
            'lu' => $rates['lu'] * $hours,
        ];

        // Consumo deuterio per fusione
        $fusionConsumption = self::getFusionDeuteriumConsumption($body, $cfg) * $hours;
        $production['d'] -= $fusionConsumption;

        // Consumo lunarite per antimateria
        $antimatterConsumption = self::getAntimatterLunariteConsumption($body, $cfg) * $hours;
        $production['lu'] -= $antimatterConsumption;

        // Applica produzione con cap magazzino
        foreach (['m', 'c', 'd', 'lu'] as $res) {
            $cap = self::getStorageCapacity($body, $res, $cfg);
            $current = (float)($body["res_$res"] ?? 0);
            $newAmount = $current + $production[$res];

            if ($newAmount > $cap) {
                $newAmount = $cap;
            }
            if ($newAmount < 0) {
                $newAmount = 0;
            }

            $body["res_$res"] = $newAmount;
        }

        // Energy Storage: carica/scarica
        self::updateEnergyStorage($body, $hours, $research, $empireMods, $cfg, $energyBalance['produced'] ?? 0, $energyBalance['required'] ?? []);

        $body['last_update'] = $toTime;
    }

    /**
     * Aggiunge risorse a un corpo (bottini, rimborsi, trasporti) fino a 2x cap.
     */
    public static function credit(array &$body, array $res, Config $cfg): void
    {
        $overflowMult = (float)$cfg->get('resources.overflow_multiplier');

        foreach (['m', 'c', 'd', 'lu'] as $resource) {
            if (!isset($res[$resource]) || $res[$resource] <= 0) continue;

            $cap = self::getStorageCapacity($body, $resource, $cfg);
            $maxAllowed = $cap * $overflowMult;
            $current = (float)($body["res_$resource"] ?? 0);
            $newAmount = $current + (float)$res[$resource];

            if ($newAmount > $maxAllowed) {
                $newAmount = $maxAllowed;
            }

            $body["res_$resource"] = $newAmount;
        }
    }

    /**
     * Calcola l'ammontare protetto per una risorsa.
     */
    public static function protectedAmount(array $body, string $res, Config $cfg): float
    {
        $cap = self::getStorageCapacity($body, $res, $cfg);
        $baseProtected = $cap * (float)$cfg->get('resources.protected_percentage');

        // Bonus bunker
        $bunkerLevel = $body['buildings']['planetary_bunker']['level'] ?? 0;
        $bunkerBonus = $bunkerLevel * (float)$cfg->get('resources.bunker_bonus_per_level') * $cap;

        return $baseProtected + $bunkerBonus;
    }

    /**
     * Ottiene la capacità di magazzino per una risorsa.
     */
    private static function getStorageCapacity(array $body, string $resource, Config $cfg): float
    {
        $storageMap = [
            'm' => ['building' => 'metal_storage', 'config' => 'metal'],
            'c' => ['building' => 'crystal_storage', 'config' => 'crystal'],
            'd' => ['building' => 'deuterium_tank', 'config' => 'deuterium'],
            'lu' => ['building' => 'lunarite_vault', 'config' => 'lunarite'],
        ];

        $map = $storageMap[$resource] ?? null;
        if (!$map) return 0.0;

        $buildingCode = $map['building'];
        $configKey = $map['config'];

        $level = $body['buildings'][$buildingCode]['level'] ?? 0;
        if ($level <= 0) {
            // Cap base senza edificio
            $baseCap = $cfg->get("resources.storage.$configKey.base");
            return (float)$baseCap;
        }

        $buildingConfig = $cfg->get("buildings.$buildingCode");
        $effect = $buildingConfig['effect']['capacity'] ?? null;
        if (!$effect) return 0.0;

        $capacity = Formula::levelCurve((float)$effect['a'], (float)$effect['b'], $level);
        return $capacity;
    }

    /**
     * Calcola consumo deuterio fusione.
     */
    private static function getFusionDeuteriumConsumption(array $body, Config $cfg): float
    {
        $level = $body['buildings']['fusion_plant']['level'] ?? 0;
        if ($level <= 0) return 0.0;

        $buildingConfig = $cfg->get("buildings.fusion_plant");
        $energyConfig = $buildingConfig['energy'] ?? [];
        if (!isset($energyConfig['a']) || !isset($energyConfig['b'])) return 0.0;

        return Formula::levelCurve((float)$energyConfig['a'], (float)$energyConfig['b'], $level);
    }

    /**
     * Calcola consumo lunarite antimateria.
     */
    private static function getAntimatterLunariteConsumption(array $body, Config $cfg): float
    {
        $level = $body['buildings']['antimatter_reactor']['level'] ?? 0;
        if ($level <= 0) return 0.0;

        $buildingConfig = $cfg->get("buildings.antimatter_reactor");
        $energyConfig = $buildingConfig['energy'] ?? [];
        if (!isset($energyConfig['a']) || !isset($energyConfig['b'])) return 0.0;

        return Formula::levelCurve((float)$energyConfig['a'], (float)$energyConfig['b'], $level);
    }

    /**
     * Aggiorna Energy Storage (carica/scarica).
     */
    private static function updateEnergyStorage(
        array &$body,
        float $hours,
        array $research,
        array $empireMods,
        Config $cfg,
        float $produced,
        array $required
    ): void {
        $level = $body['buildings']['energy_storage']['level'] ?? 0;
        if ($level <= 0) return;

        $buildingConfig = $cfg->get("buildings.energy_storage");
        $effect = $buildingConfig['effect']['capacity'] ?? null;
        if (!$effect) return;

        $capacity = Formula::levelCurve((float)$effect['a'], (float)$effect['b'], $level);
        $currentStore = (float)($body['energy_store'] ?? 0);

        $totalRequired = array_sum($required);

        if ($produced >= $totalRequired) {
            // Surplus: carica storage
            $surplus = $produced - $totalRequired;
            $charge = $surplus * $hours;
            $newStore = min($capacity, $currentStore + $charge);
            $body['energy_store'] = $newStore;
        } else {
            // Deficit: scarica storage
            $deficit = $totalRequired - $produced;
            $discharge = $deficit * $hours;
            $newStore = max(0.0, $currentStore - $discharge);
            $body['energy_store'] = $newStore;
        }
    }
}