<?php

declare(strict_types=1);

namespace SpaceGame\Api;

use SpaceGame\Auth;
use SpaceGame\Config;
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

        $config = Config::fromFile(__DIR__ . '/../../game_config.json');
        $securityConfig = $config->get('security') ?? [
            'login_max_attempts' => 5,
            'login_lockout_minutes' => 15,
        ];

        // Rate limiting per IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!self::checkRateLimit($ip, $securityConfig)) {
            self::json(['ok' => false, 'error' => 'Troppi tentativi di login. Riprova più tardi.'], 429);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $username = trim($input['username'] ?? '');
        $password = (string)($input['password'] ?? '');

        if ($username === '' || $password === '') {
            self::json(['ok' => false, 'error' => 'Username e password richiesti'], 400);
            return;
        }

        $success = Auth::login($username, $password);

        if ($success) {
            // Reset rate limit on successful login
            self::clearRateLimit($ip);
            $userId = Auth::requireUser();
            $csrfToken = Auth::csrfToken();
            self::json(['ok' => true, 'data' => ['user_id' => $userId, 'csrf_token' => $csrfToken]]);
        } else {
            // Increment failed attempts
            self::incrementRateLimit($ip, $securityConfig);
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
        self::requireCsrf();

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

    private static function requireCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($token === '') {
            self::json(['ok' => false, 'error' => 'Token CSRF mancante'], 400);
            exit;
        }
        Auth::checkCsrf($token);
    }

    /**
     * Controlla il rate limit per un IP.
     * Restituisce true se la richiesta è consentita, false se superato il limite.
     */
    private static function checkRateLimit(string $ip, array $securityConfig): bool
    {
        $pdo = Db::pdo(false);
        $maxAttempts = $securityConfig['login_max_attempts'] ?? 5;
        $lockoutMinutes = $securityConfig['login_lockout_minutes'] ?? 15;

        // Crea la tabella se non esiste
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `login_attempts` (
                `ip` VARCHAR(45) NOT NULL PRIMARY KEY,
                `attempts` INT NOT NULL DEFAULT 0,
                `last_attempt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `locked_until` TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $stmt = $pdo->prepare("SELECT `attempts`, `locked_until` FROM `login_attempts` WHERE `ip` = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row) {
            $attempts = (int)$row['attempts'];
            $lockedUntil = $row['locked_until'] ? new \DateTime($row['locked_until']) : null;

            // Controlla se è in lockout
            if ($lockedUntil && $lockedUntil > new \DateTime()) {
                return false;
            }

            // Se il lockout è scaduto, resetta i tentativi
            if ($lockedUntil && $lockedUntil <= new \DateTime()) {
                return true;
            }

            // Controlla se ha superato il limite
            if ($attempts >= $maxAttempts) {
                // Imposta lockout
                $lockoutUntil = (new \DateTime())->modify("+{$lockoutMinutes} minutes")->format('Y-m-d H:i:s');
                $stmt = $pdo->prepare("UPDATE `login_attempts` SET `locked_until` = ? WHERE `ip` = ?");
                $stmt->execute([$lockoutUntil, $ip]);
                return false;
            }
        }

        return true;
    }

    /**
     * Incrementa il contatore di tentativi falliti per un IP.
     */
    private static function incrementRateLimit(string $ip, array $securityConfig): void
    {
        $pdo = Db::pdo(false);
        $maxAttempts = $securityConfig['login_max_attempts'] ?? 5;
        $lockoutMinutes = $securityConfig['login_lockout_minutes'] ?? 15;

        $stmt = $pdo->prepare("
            INSERT INTO `login_attempts` (`ip`, `attempts`, `last_attempt`)
            VALUES (?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                `attempts` = `attempts` + 1,
                `last_attempt` = NOW()
        ");
        $stmt->execute([$ip]);

        // Controlla se ora ha superato il limite
        $stmt = $pdo->prepare("SELECT `attempts` FROM `login_attempts` WHERE `ip` = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row && (int)$row['attempts'] >= $maxAttempts) {
            $lockoutUntil = (new \DateTime())->modify("+{$lockoutMinutes} minutes")->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare("UPDATE `login_attempts` SET `locked_until` = ? WHERE `ip` = ?");
            $stmt->execute([$lockoutUntil, $ip]);
        }
    }

    /**
     * Resetta il rate limit per un IP (login riuscito).
     */
    private static function clearRateLimit(string $ip): void
    {
        $pdo = Db::pdo(false);
        $stmt = $pdo->prepare("DELETE FROM `login_attempts` WHERE `ip` = ?");
        $stmt->execute([$ip]);
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