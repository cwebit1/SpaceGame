<?php

declare(strict_types=1);

namespace SpaceGame;

use RuntimeException;

/**
 * Energy - Calcolo bilancio energetico.
 * Fonti: posizione, tipo, temperatura, Energetica, satelliti, fusione, antimateria.
 * Priorità carenza: miniere scalate per prime.
 */
final class Energy
{
    /**
     * Calcola il bilancio energetico di un corpo celeste.
     *
     * @param array $body Dati del corpo (buildings, type, temp_class, p, g, s, ecc.)
     * @param array $empireResearch Ricerche dell'impero
     * @param Config $cfg Configurazione di gioco
     * @return array{
     *     produced: float,
     *     required: array<string,float>,
     *     factorByConsumer: array<string,float>
     * }
     */
    public static function balance(array $body, array $empireResearch, Config $cfg): array
    {
        $buildings = $body['buildings'] ?? [];
        $position = (int)($body['p'] ?? 8);
        $planetType = $body['type'] ?? 'standard';
        $tempClass = (int)($body['temp_class'] ?? 3);

        // 1. Calcola produzione energia da tutte le fonti
        $produced = 0.0;
        $energySources = [];

        // Fonti energetiche che PRODUCONO energia
        $energyBuildings = [
            'solar_plant' => ['key' => 'energy', 'pos_key' => 'solar'],
            'fusion_plant' => ['key' => 'energy', 'consumes' => 'deuterium'],
            'geothermal_plant' => ['key' => 'energy', 'pos_key' => 'geothermal'],
            'power_plant' => ['key' => 'energy', 'pos_key' => 'geothermal'], // Power plant usa geotermica
            'nuclear_plant' => ['key' => 'energy', 'pos_key' => 'geothermal'],
            'antimatter_reactor' => ['key' => 'energy', 'consumes' => 'lunarite'],
            'solar_satellite' => ['key' => 'energy_per_unit', 'pos_key' => 'solar'],
        ];

        foreach ($energyBuildings as $code => $info) {
            $level = $buildings[$code]['level'] ?? 0;
            if ($level <= 0) continue;

            $buildingConfig = $cfg->get("buildings.$code");
            $effect = $buildingConfig['effect'][$info['key']] ?? null;
            if (!$effect) continue;

            $baseProduction = 0.0;
            if (is_array($effect)) {
                // Formula a·L·b^L
                if (isset($effect['a']) && isset($effect['b'])) {
                    $baseProduction = Formula::levelCurve((float)$effect['a'], (float)$effect['b'], $level);
                }
            } else {
                // Valore fisso per unità (es. solar_satellite)
                $baseProduction = (float)$effect * $level;
            }

            // Applica modificatori posizione e tipo
            $modifier = 1.0;

            if (isset($info['pos_key'])) {
                $posMod = self::getPositionModifier($position, $info['pos_key'], $cfg);
                $modifier *= $posMod;
            }

            if ($code === 'solar_plant' || $code === 'solar_satellite') {
                $typeMod = self::getTypeModifier($planetType, 'solar', $cfg);
                $modifier *= $typeMod;
            }

            $production = $baseProduction * $modifier;
            $produced += $production;
            $energySources[$code] = $production;
        }

        // 2. Calcola consumo energia da tutti i consumatori
        $consumers = [
            'metal_mine' => 'production_metal',
            'crystal_mine' => 'production_crystal',
            'deuterium_synthesizer' => 'production_deuterium',
            'fusion_plant' => 'energy', // Fusione consuma energia? No, produce ma consuma deuterio
            'antimatter_reactor' => 'energy', // Antimateria consuma energia? Produce ma consuma Lu
        ];

        // In realtà i consumatori di energia sono gli edifici con campo "energy" nel config
        // Calcoliamo il fabbisogno energetico per ogni edificio
        $required = [];
        $totalRequired = 0.0;

        foreach ($buildings as $code => $building) {
            $level = $building['level'] ?? 0;
            if ($level <= 0) continue;

            $buildingConfig = $cfg->get("buildings.$code");
            $energyConfig = $buildingConfig['energy'] ?? null;
            if (!$energyConfig) continue;

            if (isset($energyConfig['flat_per_level'])) {
                // Consumo fisso per livello
                $consumption = (float)$energyConfig['flat_per_level'] * $level;
            } elseif (isset($energyConfig['a']) && isset($energyConfig['b'])) {
                // Formula a·L·b^L
                $consumption = Formula::levelCurve((float)$energyConfig['a'], (float)$energyConfig['b'], $level);
            } else {
                continue;
            }

            $required[$code] = $consumption;
            $totalRequired += $consumption;
        }

        // Fusione consuma deuterio
        if (isset($buildings['fusion_plant']) && ($buildings['fusion_plant']['level'] ?? 0) > 0) {
            $level = $buildings['fusion_plant']['level'];
            $buildingConfig = $cfg->get("buildings.fusion_plant");
            $energyConfig = $buildingConfig['energy'] ?? [];
            if (isset($energyConfig['a']) && isset($energyConfig['b'])) {
                $deutConsumption = Formula::levelCurve((float)$energyConfig['a'], (float)$energyConfig['b'], $level);
                $required['fusion_plant_deut'] = $deutConsumption;
                $totalRequired += $deutConsumption; // Conta come energia equivalente
            }
        }

        // Antimateria consuma lunarite
        if (isset($buildings['antimatter_reactor']) && ($buildings['antimatter_reactor']['level'] ?? 0) > 0) {
            $level = $buildings['antimatter_reactor']['level'];
            $buildingConfig = $cfg->get("buildings.antimatter_reactor");
            $energyConfig = $buildingConfig['energy'] ?? [];
            if (isset($energyConfig['a']) && isset($energyConfig['b'])) {
                $luConsumption = Formula::levelCurve((float)$energyConfig['a'], (float)$energyConfig['b'], $level);
                $required['antimatter_reactor_lu'] = $luConsumption;
                $totalRequired += $luConsumption; // Conta come energia equivalente
            }
        }

        // 3. Calcola fattori di carenza
        $factorByConsumer = [];

        if ($produced >= $totalRequired && $totalRequired > 0) {
            // Energia sufficiente
            foreach ($required as $code => $req) {
                $factorByConsumer[$code] = 1.0;
            }
        } elseif ($totalRequired > 0) {
            // Carenza: scala i consumatori in base alla priorità
            // Priorità: 1) miniere (metal, crystal, deuterium), 2) altri
            $mineConsumers = ['metal_mine', 'crystal_mine', 'deuterium_synthesizer'];
            $otherConsumers = array_diff(array_keys($required), $mineConsumers);

            // Prima soddisfa le miniere
            $remainingEnergy = $produced;
            foreach ($mineConsumers as $code) {
                if (!isset($required[$code])) continue;
                $need = $required[$code];
                if ($remainingEnergy >= $need) {
                    $factorByConsumer[$code] = 1.0;
                    $remainingEnergy -= $need;
                } else {
                    $factorByConsumer[$code] = $need > 0 ? $remainingEnergy / $need : 0.0;
                    $remainingEnergy = 0;
                }
            }

            // Poi gli altri
            foreach ($otherConsumers as $code) {
                $need = $required[$code];
                if ($remainingEnergy >= $need) {
                    $factorByConsumer[$code] = 1.0;
                    $remainingEnergy -= $need;
                } else {
                    $factorByConsumer[$code] = $need > 0 ? $remainingEnergy / $need : 0.0;
                    $remainingEnergy = 0;
                }
            }
        } else {
            // Nessun consumo
            foreach ($required as $code => $req) {
                $factorByConsumer[$code] = 1.0;
            }
        }

        return [
            'produced' => $produced,
            'required' => $required,
            'factorByConsumer' => $factorByConsumer,
        ];
    }

    /**
     * Ottiene il modificatore di posizione per un tipo di energia.
     */
    private static function getPositionModifier(int $position, string $energyType, Config $cfg): float
    {
        $modifiers = $cfg->get('galaxy.position_temp_modifiers');

        if ($position >= 1 && $position <= 3) {
            return $modifiers['1-3'][$energyType] ?? 1.0;
        }
        if ($position >= 4 && $position <= 6) {
            return $modifiers['4-6'][$energyType] ?? 1.0;
        }
        if ($position >= 7 && $position <= 9) {
            return $modifiers['7-9'][$energyType] ?? 1.0;
        }
        if ($position >= 10 && $position <= 12) {
            return $modifiers['10-12'][$energyType] ?? 1.0;
        }
        // 13-15
        return $modifiers['13-15'][$energyType] ?? 1.0;
    }

    /**
     * Ottiene il modificatore di posizione per un tipo di risorsa (metodo statico pubblico per Economy).
     */
    public static function getPositionModifierStatic(int $position, Config $cfg): array
    {
        $modifiers = $cfg->get('galaxy.position_temp_modifiers');
        $result = ['m' => 1.0, 'c' => 1.0, 'd' => 1.0];

        if ($position >= 1 && $position <= 3) {
            $result['d'] = $modifiers['1-3']['deuterium'] ?? 1.0;
        } elseif ($position >= 4 && $position <= 6) {
            $result['d'] = $modifiers['4-6']['deuterium'] ?? 1.0;
        } elseif ($position >= 7 && $position <= 9) {
            $result['d'] = $modifiers['7-9']['deuterium'] ?? 1.0;
        } elseif ($position >= 10 && $position <= 12) {
            $result['d'] = $modifiers['10-12']['deuterium'] ?? 1.0;
        } else {
            $result['d'] = $modifiers['13-15']['deuterium'] ?? 1.0;
        }

        return $result;
    }

    /**
     * Ottiene il modificatore di tipo pianeta per una risorsa specifica (metodo privato per uso interno).
     */
    private static function getTypeModifier(string $planetType, string $resourceType, Config $cfg): float
    {
        $modifiers = $cfg->get('galaxy.planet_type_modifiers');

        if (isset($modifiers[$planetType][$resourceType])) {
            return $modifiers[$planetType][$resourceType];
        }

        return 1.0;
    }

    /**
     * Ottiene il modificatore di tipo pianeta per risorse (metodo statico pubblico per Economy).
     */
    public static function getTypeModifierStatic(string $planetType, Config $cfg): array
    {
        $modifiers = $cfg->get('galaxy.planet_type_modifiers');
        $result = ['m' => 1.0, 'c' => 1.0, 'd' => 1.0];

        if (isset($modifiers[$planetType]['metal'])) {
            $result['m'] = $modifiers[$planetType]['metal'];
        }
        if (isset($modifiers[$planetType]['crystal'])) {
            $result['c'] = $modifiers[$planetType]['crystal'];
        }
        if (isset($modifiers[$planetType]['deuterium'])) {
            $result['d'] = $modifiers[$planetType]['deuterium'];
        }

        return $result;
    }
}