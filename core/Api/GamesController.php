<?php

declare(strict_types=1);

namespace SpaceGame\Api;

use SpaceGame\Auth;
use SpaceGame\Games;
use SpaceGame\Config;
use SpaceGame\Db;
use SpaceGame\Galaxy;
use PDO;

/**
 * GamesController - Endpoint API per gestione partite.
 */
final class GamesController
{
    /**
     * GET /api/games
     * Risposta: {ok: true, data: [{id, name, speed, created_at}, ...]}
     */
    public static function list(): void
    {
        self::requireGet();
        self::requireAuth();

        $userId = Auth::requireUser();
        $games = Games::list($userId);

        self::json(['ok' => true, 'data' => $games]);
    }

    /**
     * POST /api/games
     * Body: {settings: {...}}
     * Risposta: {ok: true, data: {game_id}} | {ok: false, error: string}
     */
    public static function create(): void
    {
        self::requirePost();
        self::requireJson();
        self::requireAuth();
        self::requireCsrf();

        // Use cached parsed input if available (from requireCsrf)
        $input = $_SERVER['PARSED_JSON_INPUT'] ?? json_decode(file_get_contents('php://input'), true) ?? [];
        $settings = $input['settings'] ?? [];

        if (!is_array($settings)) {
            self::json(['ok' => false, 'error' => 'settings deve essere un oggetto'], 400);
            return;
        }

        try {
            $userId = Auth::requireUser();
            $gameId = Games::create($userId, $settings);

            self::json(['ok' => true, 'data' => ['game_id' => $gameId]]);
        } catch (\RuntimeException $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * DELETE /api/games/{id}
     * Risposta: {ok: true} | {ok: false, error: string}
     */
    public static function delete(): void
    {
        self::requireDelete();
        self::requireAuth();
        self::requireCsrf();

        $gameId = self::getGameIdFromPath();

        if ($gameId === null) {
            self::json(['ok' => false, 'error' => 'ID partita non valido'], 400);
            return;
        }

        try {
            Games::delete($gameId);
            self::json(['ok' => true]);
        } catch (\RuntimeException $e) {
            $status = strpos($e->getMessage(), 'non trovata') !== false ? 404 : 403;
            self::json(['ok' => false, 'error' => $e->getMessage()], $status);
        }
    }

    /**
     * POST /api/games/{id}/refresh-config
     * Risposta: {ok: true} | {ok: false, error: string}
     */
    public static function refreshConfig(): void
    {
        self::requirePost();
        self::requireAuth();
        self::requireCsrf();

        $gameId = self::getGameIdFromPath();

        if ($gameId === null) {
            self::json(['ok' => false, 'error' => 'ID partita non valido'], 400);
            return;
        }

        // Verifica proprietà
        $userId = Auth::requireUser();
        if (!Games::isOwner($gameId, $userId)) {
            self::json(['ok' => false, 'error' => 'Non autorizzato'], 403);
            return;
        }

        try {
            Games::refreshConfig($gameId);
            self::json(['ok' => true]);
        } catch (\RuntimeException $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    /**
     * POST /api/games/{id}/generate-galaxy
     * Genera la galassia per una partita esistente.
     * Body: {seed?: int}
     * Risposta: {ok: true} | {ok: false, error: string}
     */
    public static function generateGalaxy(): void
    {
        self::requirePost();
        self::requireJson();
        self::requireAuth();
        self::requireCsrf();

        $gameId = self::getGameIdFromPath();

        if ($gameId === null) {
            self::json(['ok' => false, 'error' => 'ID partita non valido'], 400);
            return;
        }

        // Verifica proprietà
        $userId = Auth::requireUser();
        if (!Games::isOwner($gameId, $userId)) {
            self::json(['ok' => false, 'error' => 'Non autorizzato'], 403);
            return;
        }

        $input = $_SERVER['PARSED_JSON_INPUT'] ?? json_decode(file_get_contents('php://input'), true) ?? [];
        $seed = $input['seed'] ?? null;

        try {
            $config = Config::fromFile(__DIR__ . '/../../game_config.json');
            Galaxy::generate($gameId, $config, false, $seed ?? mt_rand());
            self::json(['ok' => true]);
        } catch (\RuntimeException $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    // ========== Helpers ==========

    private static function requireGet(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::json(['ok' => false, 'error' => 'Metodo non consentito'], 405);
            exit;
        }
    }

    private static function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'error' => 'Metodo non consentito'], 405);
            exit;
        }
    }

    private static function requireDelete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            self::json(['ok' => false, 'error' => 'Metodo non consentito'], 405);
            exit;
        }
    }

    private static function requireJson(): void
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') === false) {
            self::json(['ok' => false, 'error' => 'Content-Type deve essere application/json'], 400);
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

    private static function requireCsrf(): void
    {
        // Check header first (for AJAX)
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        // Check form field (for traditional forms)
        if ($token === '') {
            $token = $_POST['csrf_token'] ?? '';
        }

        // Check JSON body (for JSON API)
        if ($token === '') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (is_array($input)) {
                $token = $input['csrf_token'] ?? '';
                // Store parsed input for reuse by calling method
                $_SERVER['PARSED_JSON_INPUT'] = $input;
            }
        }

        if ($token === '') {
            self::json(['ok' => false, 'error' => 'Token CSRF mancante'], 400);
            exit;
        }
        Auth::checkCsrf($token);
    }

    private static function getGameIdFromPath(): ?int
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '';

        // Pattern: /api/games/{id} or /api/games/{id}/action
        if (preg_match('#/api/games/(\d+)#', $path, $matches)) {
            return (int)$matches[1];
        }

        return null;
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