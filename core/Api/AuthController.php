<?php

declare(strict_types=1);

namespace SpaceGame\Api;

use SpaceGame\Auth;
use SpaceGame\Db;
use PDO;

/**
 * AuthController - Endpoint API per autenticazione.
 */
final class AuthController
{
    /**
     * POST /api/login
     * Body: {username, password}
     * Risposta: {ok: true, data: {user_id, csrf_token}} | {ok: false, error: string}
     */
    public static function login(): void
    {
        self::requirePost();
        self::requireJson();

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $username = trim($input['username'] ?? '');
        $password = (string)($input['password'] ?? '');

        if ($username === '' || $password === '') {
            self::json(['ok' => false, 'error' => 'Username e password richiesti'], 400);
            return;
        }

        $success = Auth::login($username, $password);

        if ($success) {
            $userId = Auth::requireUser();
            $csrfToken = Auth::csrfToken();
            self::json(['ok' => true, 'data' => ['user_id' => $userId, 'csrf_token' => $csrfToken]]);
        } else {
            self::json(['ok' => false, 'error' => 'Credenziali non valide'], 401);
        }
    }

    /**
     * POST /api/logout
     * Risposta: {ok: true}
     */
    public static function logout(): void
    {
        self::requirePost();

        Auth::logout();
        self::json(['ok' => true]);
    }

    /**
     * GET /api/auth/status
     * Risposta: {ok: true, data: {authenticated: bool, user_id?: int, csrf_token?: string}}
     */
    public static function status(): void
    {
        self::requireGet();

        $userId = Auth::getUserId();

        if ($userId !== null) {
            $csrfToken = Auth::csrfToken();
            self::json(['ok' => true, 'data' => ['authenticated' => true, 'user_id' => $userId, 'csrf_token' => $csrfToken]]);
        } else {
            self::json(['ok' => true, 'data' => ['authenticated' => false]]);
        }
    }

    // ========== Helpers ==========

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

    private static function requireJson(): void
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') === false) {
            self::json(['ok' => false, 'error' => 'Content-Type deve essere application/json'], 400);
            exit;
        }
    }

    private static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}