<?php

declare(strict_types=1);

namespace SpaceGame\Api;

use SpaceGame\Auth;
use SpaceGame\Db;
use SpaceGame\Actions\Build;
use SpaceGame\Queue;
use SpaceGame\Config;
use SpaceGame\GameContext;
use PDO;

/**
 * BuildController - Endpoint API per costruzione edifici.
 */
final class BuildController
{
    /**
     * POST /api/games/{gameId}/build
     * Body: {body_id: int, code: string}
     * Risposta: {ok: true, data: {queue_id, status, eta}} | {ok: false, error: string}
     */
    public static function queue(): void
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

        // Verifica proprietà partita
        $userId = Auth::requireUser();
        if (!self::isGameOwner($gameId, $userId)) {
            self::json(['ok' => false, 'error' => 'Non autorizzato'], 403);
            return;
        }

        // Ottieni impero player per questa partita
        $empireId = self::getPlayerEmpireId($gameId);
        if ($empireId === null) {
            self::json(['ok' => false, 'error' => 'Impero player non trovato per questa partita'], 404);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $bodyId = (int)($input['body_id'] ?? 0);
        $code = trim($input['code'] ?? '');

        if ($bodyId <= 0) {
            self::json(['ok' => false, 'error' => 'body_id richiesto'], 400);
            return;
        }
        if ($code === '') {
            self::json(['ok' => false, 'error' => 'code richiesto'], 400);
            return;
        }

        // Verifica che il corpo appartenga all'impero
        if (!self::bodyBelongsToEmpire($bodyId, $empireId)) {
            self::json(['ok' => false, 'error' => 'Corpo non trovato o non appartiene all\'impero'], 404);
            return;
        }

        try {
            $queueId = Build::queue($empireId, $bodyId, $code);

            // Recupera info coda per risposta
            $pdo = Db::pdo();
            $stmt = $pdo->prepare("SELECT `status`, `eta` FROM `queues` WHERE `id` = ?");
            $stmt->execute([$queueId]);
            $queue = $stmt->fetch(PDO::FETCH_ASSOC);

            self::json([
                'ok' => true,
                'data' => [
                    'queue_id' => $queueId,
                    'status' => $queue['status'] ?? 'waiting',
                    'eta' => $queue['eta'] ?? null,
                ],
            ]);
        } catch (\RuntimeException $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * DELETE /api/games/{gameId}/build/{queueId}
     * Risposta: {ok: true} | {ok: false, error: string}
     */
    public static function cancel(): void
    {
        self::requireDelete();
        self::requireAuth();
        self::requireCsrf();

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

        // Estrai queueId dal path
        $queueId = self::getQueueIdFromPath();
        if ($queueId === null) {
            self::json(['ok' => false, 'error' => 'ID coda non valido'], 400);
            return;
        }

        try {
            Build::cancel($empireId, $queueId);
            self::json(['ok' => true]);
        } catch (\RuntimeException $e) {
            $status = strpos($e->getMessage(), 'non trovato') !== false ? 404 : 400;
            self::json(['ok' => false, 'error' => $e->getMessage()], $status);
        }
    }

    /**
     * POST /api/games/{gameId}/build/reorder
     * Body: {body_id: int, order: array<int>} (array di queue_id nell'ordine desiderato)
     * Risposta: {ok: true} | {ok: false, error: string}
     */
    public static function reorder(): void
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

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $bodyId = (int)($input['body_id'] ?? 0);
        $order = $input['order'] ?? [];

        if ($bodyId <= 0) {
            self::json(['ok' => false, 'error' => 'body_id richiesto'], 400);
            return;
        }
        if (!is_array($order)) {
            self::json(['ok' => false, 'error' => 'order deve essere un array'], 400);
            return;
        }

        // Verifica che il corpo appartenga all'impero
        if (!self::bodyBelongsToEmpire($bodyId, $empireId)) {
            self::json(['ok' => false, 'error' => 'Corpo non trovato o non appartiene all\'impero'], 404);
            return;
        }

        try {
            Build::reorder($empireId, $bodyId, $order);
            self::json(['ok' => true]);
        } catch (\RuntimeException $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/games/{gameId}/build/queue?body_id=X
     * Risposta: {ok: true, data: [{id, kind, code, status, progress, eta, slot}, ...]}
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

        $bodyId = (int)($_GET['body_id'] ?? 0);
        if ($bodyId <= 0) {
            self::json(['ok' => false, 'error' => 'body_id richiesto'], 400);
            return;
        }

        // Verifica che il corpo appartenga all'impero
        if (!self::bodyBelongsToEmpire($bodyId, $empireId)) {
            self::json(['ok' => false, 'error' => 'Corpo non trovato o non appartiene all\'impero'], 404);
            return;
        }

        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT `id`, `kind`, `code`, `status`, `progress`, `eta`, `slot`, `started_at`, `created_at`
            FROM `queues`
            WHERE `body_id` = ? AND `kind` = 'building'
            ORDER BY `slot` ASC, `created_at` ASC
        ");
        $stmt->execute([$bodyId]);
        $queues = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::json(['ok' => true, 'data' => $queues]);
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

    private static function getQueueIdFromPath(): ?int
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '';

        if (preg_match('#/api/games/\d+/build/(\d+)#', $path, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    private static function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'error' => 'Metodo non consentito'], 405);
            exit;
        }
    }

    private static function requireGet(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($token === '') {
            self::json(['ok' => false, 'error' => 'Token CSRF mancante'], 400);
            exit;
        }
        Auth::checkCsrf($token);
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