<?php

declare(strict_types=1);

namespace SpaceGame;

use PDO;
use RuntimeException;

/**
 * Auth - Gestione autenticazione utente.
 * Session-based con CSRF token.
 */
final class Auth
{
    private const SESSION_USER_ID = 'spacegame_user_id';
    private const SESSION_CSRF_TOKEN = 'spacegame_csrf_token';

    /**
     * Esegue il login verificando username e password.
     *
     * @param string $username Username
     * @param string $password Password in chiaro
     * @param bool $test Usa database di test
     * @return bool True se login riuscito
     */
    public static function login(string $username, string $password, bool $test = false): bool
    {
        $pdo = Db::pdo($test);

        $stmt = $pdo->prepare("SELECT `id`, `pass_hash` FROM `users` WHERE `username` = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            return false;
        }

        if (!password_verify($password, $user['pass_hash'])) {
            return false;
        }

        // Login riuscito: imposta sessione
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Rigenera ID sessione per sicurezza
        session_regenerate_id(true);

        $_SESSION[self::SESSION_USER_ID] = (int)$user['id'];
        $_SESSION[self::SESSION_CSRF_TOKEN] = bin2hex(random_bytes(32));

        return true;
    }

    /**
     * Esegue il logout distruggendo la sessione.
     */
    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    /**
     * Restituisce l'ID dell'utente loggato.
     * Lancia eccezione se non autenticato.
     *
     * @return int User ID
     * @throws RuntimeException se non autenticato
     */
    public static function requireUser(): int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION[self::SESSION_USER_ID])) {
            throw new RuntimeException('Non autenticato');
        }

        return (int)$_SESSION[self::SESSION_USER_ID];
    }

    /**
     * Verifica se l'utente è loggato (senza lanciare eccezione).
     *
     * @return int|null User ID se loggato, null altrimenti
     */
    public static function getUserId(): ?int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return $_SESSION[self::SESSION_USER_ID] ?? null;
    }

    /**
     * Genera e restituisce un token CSRF per la sessione corrente.
     * Se non esiste, ne crea uno nuovo.
     *
     * @return string Token CSRF
     * @throws RuntimeException se non autenticato
     */
    public static function csrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION[self::SESSION_USER_ID])) {
            throw new RuntimeException('Non autenticato');
        }

        if (!isset($_SESSION[self::SESSION_CSRF_TOKEN])) {
            $_SESSION[self::SESSION_CSRF_TOKEN] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_CSRF_TOKEN];
    }

    /**
     * Verifica che il token CSRF corrisponda a quello in sessione.
     *
     * @param string $token Token da verificare
     * @throws RuntimeException se token non valido o mancante
     */
    public static function checkCsrf(string $token): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $storedToken = $_SESSION[self::SESSION_CSRF_TOKEN] ?? null;

        if (!$storedToken || !hash_equals($storedToken, $token)) {
            throw new RuntimeException('Token CSRF non valido');
        }
    }
}