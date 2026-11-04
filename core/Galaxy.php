<?php

declare(strict_types=1);

namespace SpaceGame;

use PDO;
use RuntimeException;

/**
 * Galaxy - Generazione galassia, sistemi, pianeti, CPU.
 * Secondo spec §3: coordinate galassia:sistema:posizione.
 * Posizioni 1-15 pianeti, 16 Warp, 17-20 asteroidi.
 */
final class Galaxy
{
    /**
     * Genera la galassia completa per una partita.
     *
     * @param int $gameId ID della partita
     * @param Config $config Configurazione di gioco
     * @param bool $test Usa database di test
     * @param int|null $seed Seed opzionale per generazione deterministica
     * @throws RuntimeException se le impostazioni non sono valide
     */
    public static function generate(int $gameId, Config $config, bool $test = false, ?int $seed = null): void
    {
        $pdo = Db::pdo($test);
        $settings = self::getGameSettings($pdo, $gameId);

        // Imposta seed per generazione deterministica
        if ($seed !== null) {
            mt_srand($seed);
        }

        // 1. Valida impostazioni
        self::validateSettings($settings, $config);

        // 2. Crea impero player
        $playerEmpireId = self::createPlayerEmpire($pdo, $gameId);

        // 3. Genera galassie e sistemi
        $galaxyCount = (int)$settings['galaxies'];
        $systemsPerGalaxy = (int)$settings['systems_per_galaxy'];
        $cpuDensity = (float)$settings['cpu_density'];
        $cpuLiveShare = (float)$settings['cpu_live_share'];
        $maxLiveEmpires = (int)$settings['max_live_empires'];
        $cpuPlanetSizePct = (float)$settings['cpu_planet_size'];

        // Crea imperi CPU (vivi e semplificati)
        $cpuEmpires = self::createCpuEmpires(
            $pdo,
            $gameId,
            $galaxyCount,
            $systemsPerGalaxy,
            $cpuDensity,
            $cpuLiveShare,
            $maxLiveEmpires,
            $cpuPlanetSizePct,
            $seed
        );

        // 4. Popola corpi celesti (pianeti, warp, asteroidi)
        self::populateBodies(
            $pdo,
            $gameId,
            $playerEmpireId,
            $cpuEmpires,
            $galaxyCount,
            $systemsPerGalaxy,
            $cpuDensity,
            $cpuPlanetSizePct,
            $config,
            $seed
        );

        // 5. Copia configurazione nella partita (config_json)
        self::copyConfigToGame($pdo, $gameId, $config);
    }

    /**
     * Recupera le impostazioni della partita dal database.
     */
    private static function getGameSettings(PDO $pdo, int $gameId): array
    {
        $stmt = $pdo->prepare("SELECT `settings_json` FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException("Partita $gameId non trovata");
        }

        return json_decode($row['settings_json'], true) ?? [];
    }

    /**
     * Valida le impostazioni della partita secondo i range in config.
     */
    private static function validateSettings(array $settings, Config $config): void
    {
        $settingsConfig = $config->get('settings');

        // Velocità player
        $speed = (float)($settings['speed'] ?? 1);
        if ($speed < $settingsConfig['player_speed_min'] || $speed > $settingsConfig['player_speed_max']) {
            throw new RuntimeException("speed deve essere tra {$settingsConfig['player_speed_min']} e {$settingsConfig['player_speed_max']}");
        }

        // CPU resource multiplier
        $cpuResMult = (float)($settings['cpu_res_mult'] ?? 1);
        if ($cpuResMult < $settingsConfig['cpu_resource_mult_min'] || $cpuResMult > $settingsConfig['cpu_resource_mult_max']) {
            throw new RuntimeException("cpu_res_mult deve essere tra {$settingsConfig['cpu_resource_mult_min']} e {$settingsConfig['cpu_resource_mult_max']}");
        }
        if ($cpuResMult > $speed) {
            throw new RuntimeException("cpu_res_mult non può superare la velocità del player (speed)");
        }

        // CPU research multiplier
        $cpuResearchMult = (float)($settings['cpu_research_mult'] ?? 1);
        if ($cpuResearchMult < $settingsConfig['cpu_research_mult_min'] || $cpuResearchMult > $settingsConfig['cpu_research_mult_max']) {
            throw new RuntimeException("cpu_research_mult deve essere tra {$settingsConfig['cpu_research_mult_min']} e {$settingsConfig['cpu_research_mult_max']}");
        }
        if ($cpuResearchMult > $speed) {
            throw new RuntimeException("cpu_research_mult non può superare la velocità del player (speed)");
        }

        // Galassie
        $galaxies = (int)($settings['galaxies'] ?? 3);
        if ($galaxies < $settingsConfig['galaxy_count_min'] || $galaxies > $settingsConfig['galaxy_count_max']) {
            throw new RuntimeException("galaxies deve essere tra {$settingsConfig['galaxy_count_min']} e {$settingsConfig['galaxy_count_max']}");
        }

        // Sistemi per galassia
        $systemsPerGalaxy = (int)($settings['systems_per_galaxy'] ?? 100);
        if ($systemsPerGalaxy < $settingsConfig['system_count_min'] || $systemsPerGalaxy > $settingsConfig['system_count_max']) {
            throw new RuntimeException("systems_per_galaxy deve essere tra {$settingsConfig['system_count_min']} e {$settingsConfig['system_count_max']}");
        }

        // Densità CPU
        $cpuDensity = (float)($settings['cpu_density'] ?? 40);
        if ($cpuDensity < $settingsConfig['cpu_density_min'] || $cpuDensity > $settingsConfig['cpu_density_max']) {
            throw new RuntimeException("cpu_density deve essere tra {$settingsConfig['cpu_density_min']} e {$settingsConfig['cpu_density_max']}");
        }

        // CPU planet size
        $cpuPlanetSize = (float)($settings['cpu_planet_size'] ?? 50);
        if ($cpuPlanetSize < $settingsConfig['cpu_planet_size_min'] || $cpuPlanetSize > $settingsConfig['cpu_planet_size_max']) {
            throw new RuntimeException("cpu_planet_size deve essere tra {$settingsConfig['cpu_planet_size_min']} e {$settingsConfig['cpu_planet_size_max']}");
        }
    }

    /**
     * Crea l'impero del player e restituisce l'ID.
     */
    private static function createPlayerEmpire(PDO $pdo, int $gameId): int
    {
        // Ottieni user_id della partita
        $stmt = $pdo->prepare("SELECT `user_id` FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        $userId = (int)$stmt->fetchColumn();

        // Crea impero player
        $stmt = $pdo->prepare("
            INSERT INTO `empires` (`game_id`, `kind`, `name`, `personality`, `settings_json`)
            VALUES (?, 'player', 'Player', NULL, '{}')
        ");
        $stmt->execute([$gameId]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Crea gli imperi CPU (vivi e semplificati).
     * Restituisce array con info per il popolamento pianeti.
     */
    private static function createCpuEmpires(
        PDO $pdo,
        int $gameId,
        int $galaxyCount,
        int $systemsPerGalaxy,
        float $cpuDensity,
        float $cpuLiveShare,
        int $maxLiveEmpires,
        float $cpuPlanetSizePct,
        ?int $seed
    ): array {
        // Calcola posizioni pianeta totali disponibili (posizioni 1-15 per sistema)
        $totalPlanetPositions = $galaxyCount * $systemsPerGalaxy * 15;

        // Target pianeti CPU da creare (basato su densità)
        $targetCpuPlanets = (int)round($totalPlanetPositions * $cpuDensity / 100);

        // Ogni impero CPU inizia con 1-3 pianeti (spec §13)
        // Per semplicità: 2 pianeti per impero CPU vivo, 1 per semplificato
        $avgPlanetsPerLive = 2;
        $avgPlanetsPerSimple = 1;

        // Calcola numero imperi live target basato sulla quota di imperi (non pianeti)
        // cpuLiveShare = percentuale di imperi CPU che devono essere 'live' (es. 70%)
        // Sia L = imperi live, S = imperi simple
        // L / (L + S) = cpuLiveShare / 100
        // Pianeti totali = L * 2 + S * 1 = targetCpuPlanets
        // Risolvendo: L = targetCpuPlanets * cpuLiveShare / (cpuLiveShare + 100)
        $targetLiveEmpires = (int)round($targetCpuPlanets * $cpuLiveShare / ($cpuLiveShare + 100));
        $targetLiveEmpires = min($targetLiveEmpires, $maxLiveEmpires);
        $targetLiveEmpires = max($targetLiveEmpires, 1);

        // Calcola imperi semplificati per coprire i pianeti rimanenti
        $remainingPlanets = $targetCpuPlanets - ($targetLiveEmpires * $avgPlanetsPerLive);
        $targetSimpleEmpires = max(0, (int)ceil($remainingPlanets / $avgPlanetsPerSimple));

        $personalities = ['aggressive', 'defensive', 'economic', 'technological', 'opportunistic'];

        $cpuEmpires = [
            'live' => [],
            'simple' => [],
            'planets_per_live' => $avgPlanetsPerLive,
            'planets_per_simple' => $avgPlanetsPerSimple,
        ];

        // Crea imperi CPU vivi
        for ($i = 0; $i < $targetLiveEmpires; $i++) {
            $personality = $personalities[array_rand($personalities)];
            $stmt = $pdo->prepare("
                INSERT INTO `empires` (`game_id`, `kind`, `name`, `personality`, `settings_json`)
                VALUES (?, 'cpu', ?, ?, '{}')
            ");
            $name = "CPU {$personality} " . ($i + 1);
            $stmt->execute([$gameId, $name, $personality]);
            $empireId = (int)$pdo->lastInsertId();

            $cpuEmpires['live'][] = [
                'id' => $empireId,
                'personality' => $personality,
                'planets_assigned' => 0,
                'max_planets' => $avgPlanetsPerLive,
            ];
        }

        // Crea imperi CPU semplificati
        $simpleTiers = ['inactive', 'weak', 'medium'];
        for ($i = 0; $i < $targetSimpleEmpires; $i++) {
            $tier = $simpleTiers[array_rand($simpleTiers)];
            $stmt = $pdo->prepare("
                INSERT INTO `empires` (`game_id`, `kind`, `name`, `personality`, `settings_json`)
                VALUES (?, 'simple', ?, ?, '{}')
            ");
            $name = "CPU Simple {$tier} " . ($i + 1);
            $stmt->execute([$gameId, $name, $tier]);
            $empireId = (int)$pdo->lastInsertId();

            $cpuEmpires['simple'][] = [
                'id' => $empireId,
                'tier' => $tier,
                'planets_assigned' => 0,
                'max_planets' => $avgPlanetsPerSimple,
            ];
        }

        return $cpuEmpires;
    }

    /**
     * Popola la tabella bodies con pianeti, warp, asteroidi.
     */
    private static function populateBodies(
        PDO $pdo,
        int $gameId,
        int $playerEmpireId,
        array $cpuEmpires,
        int $galaxyCount,
        int $systemsPerGalaxy,
        float $cpuDensity,
        float $cpuPlanetSizePct,
        Config $config,
        ?int $seed
    ): void {
        $galaxyConfig = $config->get('galaxy');
        $moonConfig = $config->get('moon');

        $planetPositions = $galaxyConfig['planet_positions']; // [1..15]
        $warpPosition = $galaxyConfig['warp_position']; // 16
        $asteroidPositions = $galaxyConfig['asteroid_positions']; // [17,18,19,20]
        $planetTypes = $galaxyConfig['planet_types'];
        $tempModifiers = $galaxyConfig['position_temp_modifiers'];
        $typeModifiers = $galaxyConfig['planet_type_modifiers'];

        // Campi base pianeta player = 2000 (spec §3)
        $playerBaseFields = 2000;

        // Campi base pianeti CPU = 2000 * (cpuPlanetSizePct / 100)
        $cpuBaseFields = (int)round(2000 * $cpuPlanetSizePct / 100);

        // Prepara lista di tutte le posizioni pianeta (1-15) per tutti i sistemi
        $allPlanetSlots = [];
        for ($g = 1; $g <= $galaxyCount; $g++) {
            for ($s = 1; $s <= $systemsPerGalaxy; $s++) {
                foreach ($planetPositions as $p) {
                    $allPlanetSlots[] = ['g' => $g, 's' => $s, 'p' => $p];
                }
            }
        }

        // Mescola per distribuzione casuale
        shuffle($allPlanetSlots);

        // Calcola quanti slot occupare per CPU
        $totalSlots = count($allPlanetSlots);
        $cpuSlotsToFill = (int)round($totalSlots * $cpuDensity / 100);

        // Assegna pianeti CPU
        // Costruisci una lista piatta di slot impero disponibili (ogni impero appare max_planets volte)
        $empireSlots = [];
        foreach ($cpuEmpires['live'] as $empire) {
            for ($k = 0; $k < $empire['max_planets']; $k++) {
                $empireSlots[] = $empire['id'];
            }
        }
        foreach ($cpuEmpires['simple'] as $empire) {
            for ($k = 0; $k < $empire['max_planets']; $k++) {
                $empireSlots[] = $empire['id'];
            }
        }

        // Mescola per distribuzione casuale
        shuffle($empireSlots);

        // Prendi solo gli slot necessari
        $empireSlots = array_slice($empireSlots, 0, $cpuSlotsToFill);
        $slotIndex = 0;

        foreach ($empireSlots as $empireId) {
            if ($slotIndex >= $totalSlots) break;

            $slot = $allPlanetSlots[$slotIndex++];
            $planetType = self::randomPlanetType($planetTypes);
            $tempClass = self::getTempClass($slot['p'], $tempModifiers);

            self::insertBody($pdo, $gameId, $empireId, $slot['g'], $slot['s'], $slot['p'],
                false, $planetType, $tempClass, $cpuBaseFields, $typeModifiers, $planetTypes);
        }

        // Pianeta madre del player: scegli uno slot libero (preferibilmente posizione 8, temperatura neutra)
        $playerSlot = null;
        foreach ($allPlanetSlots as $slot) {
            // Controlla se è già occupato
            $stmt = $pdo->prepare("SELECT 1 FROM `bodies` WHERE `game_id` = ? AND `g` = ? AND `s` = ? AND `p` = ? AND `is_moon` = 0");
            $stmt->execute([$gameId, $slot['g'], $slot['s'], $slot['p']]);
            if (!$stmt->fetch()) {
                $playerSlot = $slot;
                break;
            }
        }

        if ($playerSlot) {
            $planetType = self::randomPlanetType($planetTypes);
            $tempClass = self::getTempClass($playerSlot['p'], $tempModifiers);
            self::insertBody($pdo, $gameId, $playerEmpireId, $playerSlot['g'], $playerSlot['s'], $playerSlot['p'],
                false, $planetType, $tempClass, $playerBaseFields, $typeModifiers, $planetTypes);
        }

        // Inserisci Warp (posizione 16) in ogni sistema - nessun impero (empire_id = NULL)
        for ($g = 1; $g <= $galaxyCount; $g++) {
            for ($s = 1; $s <= $systemsPerGalaxy; $s++) {
                self::insertBody($pdo, $gameId, null, $g, $s, $warpPosition, false, 'warp', 0, 0, $typeModifiers, $planetTypes);
            }
        }

        // Inserisci asteroidi (posizioni 17-20) in ogni sistema
        $asteroidTypes = ['metal', 'crystal', 'deuterium', 'lunarite'];
        for ($g = 1; $g <= $galaxyCount; $g++) {
            for ($s = 1; $s <= $systemsPerGalaxy; $s++) {
                foreach ($asteroidPositions as $idx => $p) {
                    self::insertBody($pdo, $gameId, null, $g, $s, $p, false, $asteroidTypes[$idx], 0, 0, $typeModifiers, $planetTypes);
                }
            }
        }
    }

    /**
     * Inserisce un corpo celeste nella tabella bodies.
     */
    private static function insertBody(
        PDO $pdo,
        int $gameId,
        ?int $empireId,
        int $g,
        int $s,
        int $p,
        bool $isMoon,
        string $type,
        int $tempClass,
        int $fieldsBase,
        array $typeModifiers,
        array $planetTypes
    ): void {
        $stmt = $pdo->prepare("
            INSERT INTO `bodies` (`game_id`, `empire_id`, `g`, `s`, `p`, `is_moon`, `type`, `temp_class`, `fields_base`, `fields_bonus`, `res_m`, `res_c`, `res_d`, `res_lu`, `energy_store`, `resistance`, `last_update`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 0, 0)
        ");

        $stmt->execute([
            $gameId,
            $empireId,
            $g,
            $s,
            $p,
            $isMoon ? 1 : 0,
            $type,
            $tempClass,
            $fieldsBase,
        ]);
    }

    /**
     * Sceglie un tipo di pianeta casuale pesato.
     */
    private static function randomPlanetType(array $planetTypes): string
    {
        // Distribuzione: 20% per ciascun tipo speciale, 20% standard
        $rand = mt_rand(1, 100);
        if ($rand <= 20) return 'rocky';
        if ($rand <= 40) return 'crystalline';
        if ($rand <= 60) return 'oceanic';
        if ($rand <= 80) return 'desert';
        return 'standard';
    }

    /**
     * Determina la classe di temperatura in base alla posizione (1-15).
     */
    private static function getTempClass(int $position, array $tempModifiers): int
    {
        if ($position >= 1 && $position <= 3) return 1;
        if ($position >= 4 && $position <= 6) return 2;
        if ($position >= 7 && $position <= 9) return 3;
        if ($position >= 10 && $position <= 12) return 4;
        return 5; // 13-15
    }

    /**
     * Copia la configurazione completa nella partita (config_json).
     */
    private static function copyConfigToGame(PDO $pdo, int $gameId, Config $config): void
    {
        $stmt = $pdo->prepare("UPDATE `games` SET `config_json` = ? WHERE `id` = ?");
        $stmt->execute([$config->toJson(), $gameId]);
    }
}