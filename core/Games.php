<?php

declare(strict_types=1);

namespace SpaceGame;

use PDO;
use RuntimeException;

/**
 * Games - Gestione partite (creazione, elenco, eliminazione, refresh config).
 */
final class Games
{
    /**
     * Crea una nuova partita per l'utente.
     *
     * @param int $userId ID dell'utente proprietario
     * @param array $settings Impostazioni della partita
     * @param Config|null $config Configurazione di gioco (opzionale, per test usa test_game_config.json)
     * @return int ID della partita creata
     * @throws RuntimeException se le impostazioni non sono valide
     */
    public static function create(int $userId, array $settings, ?Config $config = null): int
    {
        if ($config === null) {
            $config = Config::fromFile(__DIR__ . '/../game_config.json');
        }
        $settingsConfig = $config->get('settings');

        // Applica default dal config per valori mancanti
        $defaults = [
            'speed' => 1,
            'cpu_res_mult' => 1,
            'cpu_research_mult' => 1,
            'galaxies' => $settingsConfig['galaxy_count_min'] ?? 1,
            'systems_per_galaxy' => $settingsConfig['system_count_min'] ?? 1,
            'cpu_density' => $settingsConfig['cpu_density_min'] ?? 0,
            'cpu_live_share' => $config->get('cpu.live_share_pct') ?? 70,
            'max_live_empires' => $config->get('cpu.max_live_empires') ?? 150,
            'cpu_planet_size' => $settingsConfig['cpu_planet_size_min'] ?? 10,
            'cpu_difficulty' => 'normal',
            'anti_player_coalitions' => false,
        ];

        $finalSettings = array_merge($defaults, $settings);

        // Valida impostazioni (stessa logica di Galaxy::validateSettings)
        self::validateSettings($finalSettings, $config);

        $pdo = Db::pdo();

        // Crea la partita
        $stmt = $pdo->prepare("
            INSERT INTO `games`
                (`user_id`, `name`, `speed`, `cpu_res_mult`, `cpu_research_mult`, `settings_json`, `config_json`)
            VALUES
                (?, ?, ?, ?, ?, ?, ?)
        ");

        $name = $finalSettings['name'] ?? 'Test Game';
        $speed = (float)$finalSettings['speed'];
        $cpuResMult = (float)$finalSettings['cpu_res_mult'];
        $cpuResearchMult = (float)$finalSettings['cpu_research_mult'];

        $stmt->execute([
            $userId,
            $name,
            $speed,
            $cpuResMult,
            $cpuResearchMult,
            json_encode($finalSettings, JSON_THROW_ON_ERROR),
            $config->toJson(),
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Valida le impostazioni della partita secondo i range in config.
     * Logica duplicata da Galaxy per validazione anticipata.
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
        $galaxies = (int)($settings['galaxies'] ?? 1);
        if ($galaxies < $settingsConfig['galaxy_count_min'] || $galaxies > $settingsConfig['galaxy_count_max']) {
            throw new RuntimeException("galaxies deve essere tra {$settingsConfig['galaxy_count_min']} e {$settingsConfig['galaxy_count_max']}");
        }

        // Sistemi per galassia
        $systemsPerGalaxy = (int)($settings['systems_per_galaxy'] ?? 1);
        if ($systemsPerGalaxy < $settingsConfig['system_count_min'] || $systemsPerGalaxy > $settingsConfig['system_count_max']) {
            throw new RuntimeException("systems_per_galaxy deve essere tra {$settingsConfig['system_count_min']} e {$settingsConfig['system_count_max']}");
        }

        // Densità CPU
        $cpuDensity = (float)($settings['cpu_density'] ?? 0);
        if ($cpuDensity < $settingsConfig['cpu_density_min'] || $cpuDensity > $settingsConfig['cpu_density_max']) {
            throw new RuntimeException("cpu_density deve essere tra {$settingsConfig['cpu_density_min']} e {$settingsConfig['cpu_density_max']}");
        }

        // CPU planet size
        $cpuPlanetSize = (float)($settings['cpu_planet_size'] ?? 10);
        if ($cpuPlanetSize < $settingsConfig['cpu_planet_size_min'] || $cpuPlanetSize > $settingsConfig['cpu_planet_size_max']) {
            throw new RuntimeException("cpu_planet_size deve essere tra {$settingsConfig['cpu_planet_size_min']} e {$settingsConfig['cpu_planet_size_max']}");
        }
    }

    /**
     * Restituisce l'elenco delle partite dell'utente.
     *
     * @param int $userId ID dell'utente
     * @return array Lista di partite con id, name, speed, created_at
     */
    public static function list(int $userId): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT `id`, `name`, `speed`, `created_at`
            FROM `games`
            WHERE `user_id` = ?
            ORDER BY `created_at` DESC
        ");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Elimina una partita (solo se l'utente è il proprietario).
     * Grazie a ON DELETE CASCADE, elimina anche tutti i dati correlati.
     *
     * @param int $gameId ID della partita
     * @throws RuntimeException se la partita non esiste o l'utente non è il proprietario
     */
    public static function delete(int $gameId): void
    {
        $userId = Auth::requireUser();

        $pdo = Db::pdo();

        // Verifica proprietà
        $stmt = $pdo->prepare("SELECT `user_id` FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();

        if (!$game) {
            throw new RuntimeException("Partita $gameId non trovata");
        }

        if ((int)$game['user_id'] !== $userId) {
            throw new RuntimeException("Non autorizzato a eliminare questa partita");
        }

        // Elimina (cascade rimuove empires, bodies, ecc.)
        $stmt = $pdo->prepare("DELETE FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
    }

    /**
     * Aggiorna il config_json della partita con la configurazione corrente dal file.
     * Utile dopo aggiornamenti del gioco.
     *
     * @param int $gameId ID della partita
     * @throws RuntimeException se la partita non esiste
     */
    public static function refreshConfig(int $gameId): void
    {
        $config = Config::fromFile(__DIR__ . '/../game_config.json');

        $pdo = Db::pdo();

        // Verifica che la partita esista
        $stmt = $pdo->prepare("SELECT `id` FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        if (!$stmt->fetch()) {
            throw new RuntimeException("Partita $gameId non trovata");
        }

        // Aggiorna config_json
        $stmt = $pdo->prepare("UPDATE `games` SET `config_json` = ? WHERE `id` = ?");
        $stmt->execute([$config->toJson(), $gameId]);
    }

    /**
     * Recupera i dettagli completi di una partita (per verifica proprietà).
     *
     * @param int $gameId ID della partita
     * @return array|null Dati della partita o null se non esiste
     */
    public static function get(int $gameId): ?array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("SELECT * FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Verifica che l'utente sia proprietario della partita.
     *
     * @param int $gameId ID della partita
     * @param int $userId ID dell'utente
     * @return bool True se l'utente è il proprietario
     */
    public static function isOwner(int $gameId, int $userId): bool
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("SELECT `user_id` FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();

        return $game && (int)$game['user_id'] === $userId;
    }
}