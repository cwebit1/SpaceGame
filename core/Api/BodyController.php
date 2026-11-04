<?php

declare(strict_types=1);

namespace SpaceGame\Api;

use SpaceGame\Auth;
use SpaceGame\Db;
use SpaceGame\Config;
use SpaceGame\Economy;
use SpaceGame\Energy;
use SpaceGame\Fields;
use SpaceGame\Formula;
use SpaceGame\Queue;
use SpaceGame\GameContext;
use PDO;

/**
 * BodyController - Endpoint API per info corpi celesti (pianeti, lune, asteroidi).
 */
final class BodyController
{
    /**
     * GET /api/games/{gameId}/bodies
     * Risposta: {ok: true, data: [{id, g, s, p, is_moon, type, temp_class, fields_base, fields_bonus, buildings: {...}}, ...]}
     */
    public static function list(): void
    {
        self::requireGet();
        self::requireAuth();

        $gameId = self::getGameIdFromPath();

        if ($gameId === null) {
            self::json(['ok' => false, 'error' => 'ID partita non valido'], 400);
            return;
        }

        // Verifica proprietà partita
        $userId = Auth::requireUser();
        if (!self::isGameOwner($gameId, $userId)) {
            self::json(['ok' => false, 'error' => 'Non autorizzato'], 403);
            return;
        }

        // Ottieni impero player
        $empireId = self::getPlayerEmpireId($gameId);
        if ($empireId === null) {
            self::json(['ok' => false, 'error' => 'Impero player non trovato'], 404);
            return;
        }

        $pdo = Db::pdo();

        // Carica corpi dell'impero
        $stmt = $pdo->prepare("
            SELECT `id`, `g`, `s`, `p`, `is_moon`, `type`, `temp_class`, `fields_base`, `fields_bonus`,
                   `res_m`, `res_c`, `res_d`, `res_lu`, `energy_store`, `resistance`, `last_update`,
                   `destroyed_until`, `moon_of`
            FROM `bodies`
            WHERE `empire_id` = ? AND `is_moon` = 0
            ORDER BY `g`, `s`, `p`
        ");
        $stmt->execute([$empireId]);
        $bodies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Carica config
        $stmt = $pdo->prepare("SELECT `config_json` FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();
        $config = Config::fromJson($game['config_json']);

        // Carica ricerche
        $stmt = $pdo->prepare("SELECT `code`, `level` FROM `research` WHERE `empire_id` = ?");
        $stmt->execute([$empireId]);
        $research = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $research[$row['code']] = (int)$row['level'];
        }

        // Carica edifici per ogni corpo
        $result = [];
        foreach ($bodies as $body) {
            $bodyId = (int)$body['id'];

            // Edifici
            $stmt = $pdo->prepare("SELECT `code`, `level`, `completed_at` FROM `buildings` WHERE `body_id` = ?");
            $stmt->execute([$bodyId]);
            $buildings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $buildings[$row['code']] = [
                    'level' => (int)$row['level'],
                    'completed_at' => (int)$row['completed_at'],
                ];
            }

            // Campi usati/liberi
            $totalFields = Fields::total($body, $config);
            $usedFields = Fields::used($buildings, $config);

            // Code di costruzione
            $stmt = $pdo->prepare("
                SELECT `id`, `kind`, `code`, `status`, `progress`, `eta`, `slot`, `started_at`
                FROM `queues`
                WHERE `body_id` = ? AND `kind` = 'building'
                ORDER BY `slot` ASC, `created_at` ASC
            ");
            $stmt->execute([$bodyId]);
            $buildQueue = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Code di ricerca (se laboratorio presente)
            $stmt = $pdo->prepare("
                SELECT `id`, `kind`, `code`, `status`, `progress`, `eta`, `slot`, `started_at`
                FROM `queues`
                WHERE `body_id` = ? AND `kind` = 'research'
                ORDER BY `slot` ASC, `created_at` ASC
            ");
            $stmt->execute([$bodyId]);
            $researchQueue = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Tassi produzione (per anteprima costi)
            $empireMods = ['speed' => 1.0]; // TODO: empire mods from settings
            $rates = Economy::ratesPerHour($body, $research, $empireMods, $config);

            $result[] = [
                'id' => $bodyId,
                'g' => (int)$body['g'],
                's' => (int)$body['s'],
                'p' => (int)$body['p'],
                'is_moon' => (bool)$body['is_moon'],
                'type' => $body['type'],
                'temp_class' => (int)$body['temp_class'],
                'fields_base' => (int)$body['fields_base'],
                'fields_bonus' => (int)$body['fields_bonus'],
                'fields_total' => $totalFields,
                'fields_used' => $usedFields,
                'fields_free' => max(0, $totalFields - $usedFields),
                'resources' => [
                    'm' => (int)$body['res_m'],
                    'c' => (int)$body['res_c'],
                    'd' => (int)$body['res_d'],
                    'lu' => (int)$body['res_lu'],
                ],
                'energy_store' => (int)$body['energy_store'],
                'resistance' => (int)$body['resistance'],
                'last_update' => (int)$body['last_update'],
                'destroyed_until' => $body['destroyed_until'] ? (int)$body['destroyed_until'] : null,
                'moon_of' => $body['moon_of'] ? (int)$body['moon_of'] : null,
                'buildings' => $buildings,
                'build_queue' => $buildQueue,
                'research_queue' => $researchQueue,
                'production_rates' => $rates,
            ];
        }

        self::json(['ok' => true, 'data' => $result]);
    }

    /**
     * GET /api/games/{gameId}/bodies/{bodyId}
     * Risposta dettagliata per un singolo corpo.
     */
    public static function get(): void
    {
        self::requireGet();
        self::requireAuth();

        $gameId = self::getGameIdFromPath();

        if ($gameId === null) {
            self::json(['ok' => false, 'error' => 'ID partita non valido'], 400);
            return;
        }

        // Verifica proprietà partita
        $userId = Auth::requireUser();
        if (!self::isGameOwner($gameId, $userId)) {
            self::json(['ok' => false, 'error' => 'Non autorizzato'], 403);
            return;
        }

        // Ottieni impero player
        $empireId = self::getPlayerEmpireId($gameId);
        if ($empireId === null) {
            self::json(['ok' => false, 'error' => 'Impero player non trovato'], 404);
            return;
        }

        $bodyId = self::getBodyIdFromPath();
        if ($bodyId === null) {
            self::json(['ok' => false, 'error' => 'ID corpo non valido'], 400);
            return;
        }

        // Verifica che il corpo appartenga all'impero
        if (!self::bodyBelongsToEmpire($bodyId, $empireId)) {
            self::json(['ok' => false, 'error' => 'Corpo non trovato o non appartiene all\'impero'], 404);
            return;
        }

        $pdo = Db::pdo();

        // Carica corpo
        $stmt = $pdo->prepare("
            SELECT `id`, `g`, `s`, `p`, `is_moon`, `type`, `temp_class`, `fields_base`, `fields_bonus`,
                   `res_m`, `res_c`, `res_d`, `res_lu`, `energy_store`, `resistance`, `last_update`,
                   `destroyed_until`, `moon_of`, `move_json`
            FROM `bodies`
            WHERE `id` = ?
        ");
        $stmt->execute([$bodyId]);
        $body = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$body) {
            self::json(['ok' => false, 'error' => 'Corpo non trovato'], 404);
            return;
        }

        // Carica config
        $stmt = $pdo->prepare("SELECT `config_json` FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();
        $config = Config::fromJson($game['config_json']);

        // Carica ricerche
        $stmt = $pdo->prepare("SELECT `code`, `level` FROM `research` WHERE `empire_id` = ?");
        $stmt->execute([$empireId]);
        $research = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $research[$row['code']] = (int)$row['level'];
        }

        // Carica edifici
        $stmt = $pdo->prepare("SELECT `code`, `level`, `completed_at` FROM `buildings` WHERE `body_id` = ?");
        $stmt->execute([$bodyId]);
        $buildings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $buildings[$row['code']] = [
                'level' => (int)$row['level'],
                'completed_at' => (int)$row['completed_at'],
            ];
        }

        // Campi usati/liberi
        $totalFields = Fields::total($body, $config);
        $usedFields = Fields::used($buildings, $config);

        // Code di costruzione
        $stmt = $pdo->prepare("
            SELECT `id`, `kind`, `code`, `status`, `progress`, `eta`, `slot`, `started_at`, `created_at`, `cost_json`, `duration_sec`
            FROM `queues`
            WHERE `body_id` = ? AND `kind` = 'building'
            ORDER BY `slot` ASC, `created_at` ASC
        ");
        $stmt->execute([$bodyId]);
        $buildQueue = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Code di ricerca
        $stmt = $pdo->prepare("
            SELECT `id`, `kind`, `code`, `status`, `progress`, `eta`, `slot`, `started_at`, `created_at`, `cost_json`, `duration_sec`
            FROM `queues`
            WHERE `body_id` = ? AND `kind` = 'research'
            ORDER BY `slot` ASC, `created_at` ASC
        ");
        $stmt->execute([$bodyId]);
        $researchQueue = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Tassi produzione
        $empireMods = ['speed' => 1.0];
        $rates = Economy::ratesPerHour($body, $research, $empireMods, $config);

        // Edifici costruibili (con costi e durate per prossimo livello)
        $buildable = [];
        foreach ($config->get('buildings') as $code => $bConfig) {
            // Skip special buildings not in main list or already at max
            $currentLevel = $buildings[$code]['level'] ?? 0;
            $maxLevel = $bConfig['max_level'] ?? 999999;
            if ($currentLevel >= $maxLevel) continue;

            // Prerequisiti
            $requires = $bConfig['requires'] ?? [];
            $moonOfBody = null;
            if ((bool)$body['is_moon'] && $body['moon_of']) {
                // Carica edifici pianeta madre
                $stmt = $pdo->prepare("SELECT `code`, `level` FROM `buildings` WHERE `body_id` = ?");
                $stmt->execute([$body['moon_of']]);
                $parentBuildings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $parentBuildings[$row['code']] = ['level' => (int)$row['level']];
                }
                $moonOfBody = ['buildings' => $parentBuildings];
            }

            $canBuild = \SpaceGame\Requirements::met($requires, $buildings, $research, $moonOfBody)
                && Fields::canBuild($body, $code, $config);

            $nextLevel = $currentLevel + 1;
            $cost = Formula::cost($bConfig['cost'], $bConfig['growth'], $nextLevel);
            $durationHours = Formula::buildHours($cost, 1.0, $buildings['nanite_factory']['level'] ?? 0, 1.0, 0.25, $config);

            $buildable[$code] = [
                'name' => $bConfig['name'] ?? $code,
                'current_level' => $currentLevel,
                'next_level' => $nextLevel,
                'max_level' => $maxLevel,
                'can_build' => $canBuild,
                'requires' => $requires,
                'cost' => $cost,
                'duration_hours' => round($durationHours, 4),
            ];
        }

        // Ricerche ricercabili
        $researchable = [];
        foreach ($config->get('research') as $code => $rConfig) {
            $currentLevel = $research[$code] ?? 0;
            $maxLevel = $rConfig['max_level'] ?? 999999;
            if ($currentLevel >= $maxLevel) continue;

            $requires = $rConfig['requires'] ?? [];
            $canResearch = \SpaceGame\Requirements::met($requires, $buildings, $research, null);

            $nextLevel = $currentLevel + 1;
            $cost = Formula::cost($rConfig['cost'], $rConfig['growth'], $nextLevel);
            // Durata ricerca: cost / (lab_level * research_speed)
            $labLevel = $buildings['research_lab']['level'] ?? 0;
            $naniteLevel = $buildings['nanite_factory']['level'] ?? 0;
            $researchSpeed = $rConfig['speed_factor'] ?? 1.0;
            $durationHours = 0;
            if ($labLevel > 0) {
                $naniteFactor = pow(0.25, $naniteLevel);
                $durationHours = ($cost['m'] + $cost['c']) / (1000 * $labLevel * $researchSpeed) * $naniteFactor;
            }

            $researchable[$code] = [
                'name' => $rConfig['name'] ?? $code,
                'current_level' => $currentLevel,
                'next_level' => $nextLevel,
                'max_level' => $maxLevel,
                'can_research' => $canResearch && $labLevel > 0,
                'requires' => $requires,
                'cost' => $cost,
                'duration_hours' => round($durationHours, 4),
                'lab_level' => $labLevel,
            ];
        }

        $result = [
            'id' => $bodyId,
            'g' => (int)$body['g'],
            's' => (int)$body['s'],
            'p' => (int)$body['p'],
            'is_moon' => (bool)$body['is_moon'],
            'type' => $body['type'],
            'temp_class' => (int)$body['temp_class'],
            'fields_base' => (int)$body['fields_base'],
            'fields_bonus' => (int)$body['fields_bonus'],
            'fields_total' => $totalFields,
            'fields_used' => $usedFields,
            'fields_free' => max(0, $totalFields - $usedFields),
            'resources' => [
                'm' => (int)$body['res_m'],
                'c' => (int)$body['res_c'],
                'd' => (int)$body['res_d'],
                'lu' => (int)$body['res_lu'],
            ],
            'energy_store' => (int)$body['energy_store'],
            'resistance' => (int)$body['resistance'],
            'last_update' => (int)$body['last_update'],
            'destroyed_until' => $body['destroyed_until'] ? (int)$body['destroyed_until'] : null,
            'moon_of' => $body['moon_of'] ? (int)$body['moon_of'] : null,
            'move_json' => $body['move_json'] ? json_decode($body['move_json'], true) : null,
            'buildings' => $buildings,
            'build_queue' => $buildQueue,
            'research_queue' => $researchQueue,
            'production_rates' => $rates,
            'buildable' => $buildable,
            'researchable' => $researchable,
        ];

        self::json(['ok' => true, 'data' => $result]);
    }

    // ========== Helpers ==========

    private static function isGameOwner(int $gameId, int $userId): bool
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT `user_id` FROM `games` WHERE `id` = ?");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();
        return $game && (int)$game['user_id'] === $userId;
    }

    private static function getPlayerEmpireId(int $gameId): ?int
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT `id` FROM `empires` WHERE `game_id` = ? AND `kind` = 'player' LIMIT 1");
        $stmt->execute([$gameId]);
        return (int)$stmt->fetchColumn() ?: null;
    }

    private static function bodyBelongsToEmpire(int $bodyId, int $empireId): bool
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT 1 FROM `bodies` WHERE `id` = ? AND `empire_id` = ?");
        $stmt->execute([$bodyId, $empireId]);
        return (bool)$stmt->fetch();
    }

    private static function getGameIdFromPath(): ?int
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '';

        if (preg_match('#/api/games/(\d+)#', $path, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    private static function getBodyIdFromPath(): ?int
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '';

        if (preg_match('#/api/games/\d+/bodies/(\d+)#', $path, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    private static function requireGet(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::json(['ok' => false, 'error' => 'Metodo non consentito'], 405);
            exit;
        }
    }

    private static function requireAuth(): void
    {
        if (Auth::getUserId() === null) {
            self::json(['ok' => false, 'error' => 'Non autenticato'], 401);
            exit;
        }
    }

    private static function json(array $data, int $status = 200): void
    {
        http_response_code($status);

        // CORS headers (E22)
        $config = Config::fromFile(__DIR__ . '/../../game_config.json');
        $corsOrigin = $config->get('security.cors_allowed_origin') ?? '*';
        header('Access-Control-Allow-Origin: ' . $corsOrigin);
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}