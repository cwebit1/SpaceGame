<?php

declare(strict_types=1);

namespace SpaceGame;

/**
 * ConfigSchema - Validazione schema per game_config.json.
 */
final class ConfigSchema
{
    /** Chiavi di primo livello richieste */
    private const REQUIRED_TOP_LEVEL = [
        'settings', 'galaxy', 'resources', 'buildings', 'moon_buildings',
        'research', 'ships', 'defenses', 'missiles', 'upgrades',
        'combat', 'fleet', 'moon', 'power', 'cpu', 'pirates',
        'gauntlet', 'admiral', 'mining', 'market', 'diplomacy',
        'events', 'log', 'format', 'realtime', 'formula'
    ];

    /** Campi richiesti per ogni edificio */
    private const REQUIRED_BUILDING_FIELDS = ['cost', 'growth', 'requires', 'energy', 'effect', 'fields'];

    /** Campi richiesti per ogni unità (nave) */
    private const REQUIRED_SHIP_FIELDS = ['cost', 'attack', 'shield', 'hull', 'speed', 'cargo', 'fuel', 'initiative', 'weight', 'drive', 'requires', 'ability', 'matrix'];

    /** Campi richiesti per ogni difesa */
    private const REQUIRED_DEFENSE_FIELDS = ['cost', 'against', 'attack', 'shield', 'hull', 'initiative', 'ability', 'weak_against', 'requires'];

    /** Campi richiesti per ogni missile */
    private const REQUIRED_MISSILE_FIELDS = ['cost', 'damage', 'speed', 'range_base', 'range_per_impulse', 'range_per_missile_factory', 'requires'];

    /** Tipi di energia validi */
    private const ENERGY_TYPES = ['flat_per_level', 'a', 'b'];

    /**
     * Valida la configurazione completa.
     * Lancia ConfigError se la validazione fallisce.
     */
    public static function validate(array $config): void
    {
        // 1. Chiavi di primo livello
        foreach (self::REQUIRED_TOP_LEVEL as $key) {
            if (!array_key_exists($key, $config)) {
                throw new ConfigError("Chiave di primo livello mancante: {$key}");
            }
        }

        // 2. Edifici
        self::validateBuildings($config['buildings'], 'buildings');
        self::validateBuildings($config['moon_buildings'], 'moon_buildings');

        // 3. Navi
        self::validateShips($config['ships']);

        // 4. Difese
        self::validateDefenses($config['defenses']);

        // 5. Missili
        self::validateMissiles($config['missiles']);

        // 6. Ricerche
        self::validateResearch($config['research']);

        // 7. Potenziamenti
        self::validateUpgrades($config['upgrades']);

        // 8. Risorse - verifica costi base non negativi
        self::validateResourceCosts($config);

        // 9. Combat
        self::validateCombat($config['combat']);

        // 10. Fleet
        self::validateFleet($config['fleet']);

        // 11. Moon
        self::validateMoon($config['moon']);

        // 12. Power
        self::validatePower($config['power']);

        // 13. CPU
        self::validateCpu($config['cpu']);

        // 14. Pirates
        self::validatePirates($config['pirates']);

        // 15. Gauntlet
        self::validateGauntlet($config['gauntlet']);

        // 16. Admiral
        self::validateAdmiral($config['admiral']);

        // 17. Mining
        self::validateMining($config['mining']);

        // 18. Market
        self::validateMarket($config['market']);

        // 19. Diplomacy
        self::validateDiplomacy($config['diplomacy']);

        // 20. Events
        self::validateEvents($config['events']);

        // 21. Log
        self::validateLog($config['log']);

        // 22. Format
        self::validateFormat($config['format']);

        // 23. Realtime
        self::validateRealtime($config['realtime']);

        // 24. Formula
        self::validateFormula($config['formula']);

        // 25. Settings
        self::validateSettings($config['settings']);

        // 25. Galaxy
        self::validateGalaxy($config['galaxy']);
    }

    private static function validateBuildings(array $buildings, string $section): void
    {
        foreach ($buildings as $code => $building) {
            if (!is_array($building)) {
                throw new ConfigError("Edificio {$code} in {$section} deve essere un array");
            }

            foreach (self::REQUIRED_BUILDING_FIELDS as $field) {
                if (!array_key_exists($field, $building)) {
                    throw new ConfigError("Edificio {$code} in {$section}: campo mancante '{$field}'");
                }
            }

            // Cost validation
            self::validateCost($building['cost'], "{$section}.{$code}.cost");

            // Growth > 0
            if (!is_numeric($building['growth']) || $building['growth'] <= 0) {
                throw new ConfigError("Edificio {$code} in {$section}: growth deve essere > 0");
            }

            // Requires - array
            if (!is_array($building['requires'])) {
                throw new ConfigError("Edificio {$code} in {$section}: requires deve essere un array");
            }

            // Energy - deve avere flat_per_level OPPURE a+b
            $energy = $building['energy'];
            if (!is_array($energy)) {
                throw new ConfigError("Edificio {$code} in {$section}: energy deve essere un array");
            }
            $hasFlat = array_key_exists('flat_per_level', $energy);
            $hasAB = array_key_exists('a', $energy) && array_key_exists('b', $energy);
            if (!($hasFlat || $hasAB)) {
                throw new ConfigError("Edificio {$code} in {$section}: energy deve avere 'flat_per_level' oppure 'a' e 'b'");
            }
            if ($hasFlat && (!is_numeric($energy['flat_per_level']) || $energy['flat_per_level'] < 0)) {
                throw new ConfigError("Edificio {$code} in {$section}: flat_per_level deve essere >= 0");
            }
            if ($hasAB && (!is_numeric($energy['a']) || !is_numeric($energy['b']))) {
                throw new ConfigError("Edificio {$code} in {$section}: a e b devono essere numerici");
            }

            // Effect - array
            if (!is_array($building['effect'])) {
                throw new ConfigError("Edificio {$code} in {$section}: effect deve essere un array");
            }

            // Fields - 0 o 1 (0 per Terraformer/Nanoforge)
            if (!in_array($building['fields'], [0, 1], true)) {
                throw new ConfigError("Edificio {$code} in {$section}: fields deve essere 0 o 1");
            }
        }
    }

    private static function validateShips(array $ships): void
    {
        foreach ($ships as $code => $ship) {
            if (!is_array($ship)) {
                throw new ConfigError("Nave {$code}: deve essere un array");
            }

            foreach (self::REQUIRED_SHIP_FIELDS as $field) {
                if (!array_key_exists($field, $ship)) {
                    throw new ConfigError("Nave {$code}: campo mancante '{$field}'");
                }
            }

            self::validateCost($ship['cost'], "ships.{$code}.cost");

            // Numerici >= 0
            foreach (['attack', 'shield', 'hull', 'speed', 'cargo', 'fuel', 'initiative', 'weight'] as $field) {
                if (!is_numeric($ship[$field]) || $ship[$field] < 0) {
                    throw new ConfigError("Nave {$code}: {$field} deve essere >= 0");
                }
            }

            // Drive
            if (!is_string($ship['drive'])) {
                throw new ConfigError("Nave {$code}: drive deve essere stringa");
            }

            // Requires
            if (!is_array($ship['requires'])) {
                throw new ConfigError("Nave {$code}: requires deve essere array");
            }

            // Ability
            if (!is_array($ship['ability'])) {
                throw new ConfigError("Nave {$code}: ability deve essere array");
            }

            // Matrix
            if (!is_array($ship['matrix'])) {
                throw new ConfigError("Nave {$code}: matrix deve essere array");
            }
        }
    }

    private static function validateDefenses(array $defenses): void
    {
        foreach ($defenses as $code => $defense) {
            if (!is_array($defense)) {
                throw new ConfigError("Difesa {$code}: deve essere un array");
            }

            foreach (self::REQUIRED_DEFENSE_FIELDS as $field) {
                if (!array_key_exists($field, $defense)) {
                    throw new ConfigError("Difesa {$code}: campo mancante '{$field}'");
                }
            }

            self::validateCost($defense['cost'], "defenses.{$code}.cost");

            // Numerici >= 0
            foreach (['attack', 'shield', 'hull', 'initiative'] as $field) {
                if (!is_numeric($defense[$field]) || $defense[$field] < 0) {
                    throw new ConfigError("Difesa {$code}: {$field} deve essere >= 0");
                }
            }

            // Against
            if (!is_string($defense['against'])) {
                throw new ConfigError("Difesa {$code}: against deve essere stringa");
            }

            // Ability
            if (!is_array($defense['ability'])) {
                throw new ConfigError("Difesa {$code}: ability deve essere array");
            }

            // Weak against
            if (!is_array($defense['weak_against'])) {
                throw new ConfigError("Difesa {$code}: weak_against deve essere array");
            }

            // Requires
            if (!is_array($defense['requires'])) {
                throw new ConfigError("Difesa {$code}: requires deve essere array");
            }
        }
    }

    private static function validateMissiles(array $missiles): void
    {
        foreach ($missiles as $code => $missile) {
            if (!is_array($missile)) {
                throw new ConfigError("Missile {$code}: deve essere un array");
            }

            foreach (self::REQUIRED_MISSILE_FIELDS as $field) {
                if (!array_key_exists($field, $missile)) {
                    throw new ConfigError("Missile {$code}: campo mancante '{$field}'");
                }
            }

            self::validateCost($missile['cost'], "missiles.{$code}.cost");

            if (!is_numeric($missile['damage']) || $missile['damage'] < 0) {
                throw new ConfigError("Missile {$code}: damage deve essere >= 0");
            }
            if (!is_numeric($missile['speed']) || $missile['speed'] <= 0) {
                throw new ConfigError("Missile {$code}: speed deve essere > 0");
            }
            if (!is_numeric($missile['range_base']) || $missile['range_base'] < 0) {
                throw new ConfigError("Missile {$code}: range_base deve essere >= 0");
            }
            if (!is_numeric($missile['range_per_impulse']) || $missile['range_per_impulse'] < 0) {
                throw new ConfigError("Missile {$code}: range_per_impulse deve essere >= 0");
            }
            if (!is_numeric($missile['range_per_missile_factory']) || $missile['range_per_missile_factory'] < 0) {
                throw new ConfigError("Missile {$code}: range_per_missile_factory deve essere >= 0");
            }

            if (!is_array($missile['requires'])) {
                throw new ConfigError("Missile {$code}: requires deve essere array");
            }
        }
    }

    private static function validateResearch(array $research): void
    {
        foreach ($research as $code => $r) {
            if (!is_array($r)) {
                throw new ConfigError("Ricerca {$code}: deve essere un array");
            }
            if (!array_key_exists('cost', $r) || !array_key_exists('growth', $r) || !array_key_exists('requires', $r) || !array_key_exists('effect', $r)) {
                throw new ConfigError("Ricerca {$code}: campi mancanti (cost, growth, requires, effect)");
            }
            self::validateCost($r['cost'], "research.{$code}.cost");
            if (!is_numeric($r['growth']) || $r['growth'] <= 0) {
                throw new ConfigError("Ricerca {$code}: growth deve essere > 0");
            }
            if (!is_array($r['requires'])) {
                throw new ConfigError("Ricerca {$code}: requires deve essere array");
            }
            if (!is_array($r['effect'])) {
                throw new ConfigError("Ricerca {$code}: effect deve essere array");
            }
        }
    }

    private static function validateUpgrades(array $upgrades): void
    {
        if (!array_key_exists('branches', $upgrades)) {
            throw new ConfigError("Upgrades: manca 'branches'");
        }
        if (!array_key_exists('cost_multiplier', $upgrades)) {
            throw new ConfigError("Upgrades: manca 'cost_multiplier'");
        }
        if (!is_numeric($upgrades['cost_multiplier']) || $upgrades['cost_multiplier'] <= 0) {
            throw new ConfigError("Upgrades: cost_multiplier deve essere > 0");
        }
        if (!array_key_exists('lunarite_from_level', $upgrades)) {
            throw new ConfigError("Upgrades: manca 'lunarite_from_level'");
        }
        if (!is_int($upgrades['lunarite_from_level']) || $upgrades['lunarite_from_level'] < 1) {
            throw new ConfigError("Upgrades: lunarite_from_level deve essere intero >= 1");
        }
        if (!array_key_exists('lunarite_base', $upgrades)) {
            throw new ConfigError("Upgrades: manca 'lunarite_base'");
        }
        if (!is_numeric($upgrades['lunarite_base']) || $upgrades['lunarite_base'] < 0) {
            throw new ConfigError("Upgrades: lunarite_base deve essere >= 0");
        }
        if (!array_key_exists('lunarite_growth', $upgrades)) {
            throw new ConfigError("Upgrades: manca 'lunarite_growth'");
        }
        if (!is_numeric($upgrades['lunarite_growth']) || $upgrades['lunarite_growth'] <= 0) {
            throw new ConfigError("Upgrades: lunarite_growth deve essere > 0");
        }
    }

    private static function validateResourceCosts(array $config): void
    {
        // Controlla che tutti i costi base non siano negativi
        $sections = ['buildings', 'moon_buildings', 'ships', 'defenses', 'missiles', 'research'];
        foreach ($sections as $section) {
            if (isset($config[$section])) {
                foreach ($config[$section] as $code => $item) {
                    if (isset($item['cost'])) {
                        self::validateCost($item['cost'], "{$section}.{$code}.cost");
                    }
                }
            }
        }
    }

    private static function validateCost(array $cost, string $path): void
    {
        $resources = ['m', 'c', 'd', 'lu'];
        foreach ($resources as $res) {
            if (!array_key_exists($res, $cost)) {
                throw new ConfigError("{$path}: manca risorsa '{$res}'");
            }
            if (!is_numeric($cost[$res]) || $cost[$res] < 0) {
                throw new ConfigError("{$path}.{$res}: deve essere >= 0");
            }
        }
    }

    private static function validateCombat(array $combat): void
    {
        $required = ['max_rounds', 'initiative_group', 'shield_bounce_threshold', 'explosion_threshold', 'max_dodge', 'debris_ship_pct', 'debris_defense_pct', 'debris_decay_per_day'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $combat)) {
                throw new ConfigError("Combat: campo mancante '{$field}'");
            }
        }
    }

    private static function validateFleet(array $fleet): void
    {
        $required = ['base_fleets', 'computer_per_fleet', 'fleet_command_per_fleet', 'admiral_command_per_fleet', 'distance_same_system_base', 'distance_same_system_per_pos', 'distance_diff_system', 'distance_diff_galaxy', 'time_factor', 'fuel_distance_divisor', 'fuel_speed_exponent', 'hold_seconds', 'anti_brush_limit', 'anti_brush_hours'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $fleet)) {
                throw new ConfigError("Fleet: campo mancante '{$field}'");
            }
        }
    }

    private static function validateMoon(array $moon): void
    {
        $required = ['base_fields', 'fields_per_nanoforge_level', 'resistance_base_metal', 'resistance_base_crystal', 'resistance_base_deut', 'resistance_bulwark_per_level', 'resistance_armor_per_level', 'repair_per_hour', 'repair_orbital_dock_per_level', 'repair_lunar_shipyard_per_level', 'emergency_repair_hours', 'destroyed_cooldown_hours', 'debris_from_destroy_pct', 'move_speed_base', 'move_speed_per_death_star_level', 'galaxy_jump_systems', 'recharge_hours', 'nanoforge_requires'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $moon)) {
                throw new ConfigError("Moon: campo mancante '{$field}'");
            }
        }
    }

    private static function validatePower(array $power): void
    {
        $required = ['offense_weight', 'defense_weight', 'shield_weight', 'dodge_weight', 'structure_weight_research', 'structure_weight_building', 'admiral_medal_weight', 'missile_in_silo', 'coalition_threshold'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $power)) {
                throw new ConfigError("Power: campo mancante '{$field}'");
            }
        }
    }

    private static function validateCpu(array $cpu): void
    {
        $required = ['max_live_empires', 'live_share_pct', 'resource_multiplier_max', 'research_multiplier_max', 'difficulty_levels', 'personalities', 'simple_tiers'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $cpu)) {
                throw new ConfigError("CPU: campo mancante '{$field}'");
            }
        }

        // live_share_pct deve essere percentuale intera 0-100
        $liveSharePct = $cpu['live_share_pct'];
        if (!is_numeric($liveSharePct) || $liveSharePct < 0 || $liveSharePct > 100) {
            throw new ConfigError("CPU.live_share_pct deve essere una percentuale intera tra 0 e 100 (valore: {$liveSharePct})");
        }

        // max_live_empires deve essere >= 1
        $maxLiveEmpires = $cpu['max_live_empires'];
        if (!is_int($maxLiveEmpires) || $maxLiveEmpires < 1) {
            throw new ConfigError("CPU.max_live_empires deve essere un intero >= 1 (valore: {$maxLiveEmpires})");
        }

        // resource_multiplier_max e research_multiplier_max devono essere > 0
        foreach (['resource_multiplier_max', 'research_multiplier_max'] as $field) {
            $val = $cpu[$field];
            if (!is_numeric($val) || $val <= 0) {
                throw new ConfigError("CPU.{$field} deve essere > 0 (valore: {$val})");
            }
        }
    }

    private static function validatePirates(array $pirates): void
    {
        $required = ['base_power', 'power_growth', 'rift_per_day', 'rift_duration_hours', 'rift_power_pct_min', 'rift_power_pct_max', 'warp_position'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $pirates)) {
                throw new ConfigError("Pirates: campo mancante '{$field}'");
            }
        }
    }

    private static function validateGauntlet(array $gauntlet): void
    {
        $required = ['base_power', 'power_growth', 'medal_base', 'medal_growth', 'requires_moon', 'retreat_max_wave', 'retreat_max_loss_pct', 'retreat_min_rel_power'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $gauntlet)) {
                throw new ConfigError("Gauntlet: campo mancante '{$field}'");
            }
        }
    }

    private static function validateAdmiral(array $admiral): void
    {
        $required = ['base_cost', 'cost_growth', 'skills'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $admiral)) {
                throw new ConfigError("Admiral: campo mancante '{$field}'");
            }
        }
    }

    private static function validateMining(array $mining): void
    {
        $required = ['base_reserve', 'reserve_growth', 'lunarite_fraction', 'base_extraction_per_hour', 'extraction_upgrade_pct', 'mining_tech_pct', 'mining_control_strata_per_level', 'depth_base_strata', 'soft_asteroid_extra_strata', 'soft_asteroid_speed_bonus', 'contested_loss_cargo_pct', 'contested_loss_ships_pct', 'field_per_day', 'field_duration_hours'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $mining)) {
                throw new ConfigError("Mining: campo mancante '{$field}'");
            }
        }
    }

    private static function validateMarket(array $market): void
    {
        $required = ['base_rates', 'rate_recovery_per_hour', 'liquidity', 'trade_center_rate_bonus_per_level', 'galactic_economy_rate_bonus_per_level'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $market)) {
                throw new ConfigError("Market: campo mancante '{$field}'");
            }
        }
    }

    private static function validateDiplomacy(array $diplomacy): void
    {
        $required = ['status_thresholds', 'pact_types', 'coalition_power_threshold'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $diplomacy)) {
                throw new ConfigError("Diplomacy: campo mancante '{$field}'");
            }
        }
    }

    private static function validateEvents(array $events): void
    {
        $required = ['enabled', 'probabilities', 'weather_control_reduction_per_level', 'climatology_reduction_per_level'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $events)) {
                throw new ConfigError("Events: campo mancante '{$field}'");
            }
        }
    }

    private static function validateLog(array $log): void
    {
        $required = ['retention_events_days', 'retention_reports_days'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $log)) {
                throw new ConfigError("Log: campo mancante '{$field}'");
            }
        }
    }

    private static function validateFormat(array $format): void
    {
        $required = ['scale', 'decimal_separator', 'thousands_separator', 'decimals'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $format)) {
                throw new ConfigError("Format: campo mancante '{$field}'");
            }
        }
    }

    private static function validateRealtime(array $realtime): void
    {
        $required = ['moon_destroyed_cooldown_hours', 'moon_move_recharge_hours', 'mothership_rebuild_days', 'asteroid_reset_timezone', 'asteroid_reset_hour', 'rift_per_day', 'field_per_day'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $realtime)) {
                throw new ConfigError("Realtime: campo mancante '{$field}'");
            }
        }
    }

    private static function validateSettings(array $settings): void
    {
        $required = ['player_speed_min', 'player_speed_max', 'cpu_resource_mult_min', 'cpu_resource_mult_max', 'cpu_research_mult_min', 'cpu_research_mult_max', 'galaxy_count_min', 'galaxy_count_max', 'system_count_min', 'system_count_max', 'cpu_density_min', 'cpu_density_max', 'cpu_planet_size_min', 'cpu_planet_size_max'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $settings)) {
                throw new ConfigError("Settings: campo mancante '{$field}'");
            }
        }
    }

    private static function validateGalaxy(array $galaxy): void
    {
        $required = ['positions_per_system', 'planet_positions', 'warp_position', 'asteroid_positions', 'planet_types', 'position_temp_modifiers', 'planet_type_modifiers', 'base_fields_planet'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $galaxy)) {
                throw new ConfigError("Galaxy: campo mancante '{$field}'");
            }
        }
    }

    private static function validateFormula(array $formula): void
    {
        $required = ['construction_cost_divisor'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $formula)) {
                throw new ConfigError("Formula: campo mancante '{$field}'");
            }
        }

        $divisor = $formula['construction_cost_divisor'];
        if (!is_numeric($divisor) || $divisor <= 0) {
            throw new ConfigError("Formula.construction_cost_divisor deve essere > 0 (valore: {$divisor})");
        }
    }
}