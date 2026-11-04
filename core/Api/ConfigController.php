<?php

declare(strict_types=1);

namespace SpaceGame\Api;

use SpaceGame\Auth;
use SpaceGame\Config;

/**
 * ConfigController - Endpoint API per configurazione pubblica.
 */
final class ConfigController
{
    /**
     * GET /api/config/format
     * Restituisce la configurazione di formattazione per il frontend JS.
     * Risposta: {ok: true, data: {scale, decimal_separator, thousands_separator, decimals}}
     */
    public static function format(): void
    {
        self::requireGet();

        $config = Config::fromFile(__DIR__ . '/../../game_config.json');
        $formatConfig = $config->get('format') ?? [
            'scale' => ['k', 'Mln', 'Mld', 'Bln', 'Bld', 'Tln', 'Tld', 'Qln', 'Qld', 'Qnl', 'Qnd', 'Sln', 'Sld'],
            'decimal_separator' => ',',
            'thousands_separator' => '.',
            'decimals' => 2,
        ];

        self::json(['ok' => true, 'data' => $formatConfig]);
    }

    /**
     * GET /api/config/public
     * Restituisce le impostazioni pubbliche del gioco (range, default, ecc.).
     * Risposta: {ok: true, data: {...}}
     */
    public static function public(): void
    {
        self::requireGet();

        $config = Config::fromFile(__DIR__ . '/../../game_config.json');

        $publicConfig = [
            'settings' => $config->get('settings') ?? [],
            'galaxy' => $config->get('galaxy') ?? [],
            'formula' => $config->get('formula') ?? [],
            'format' => $config->get('format') ?? [],
        ];

        self::json(['ok' => true, 'data' => $publicConfig]);
    }

    // ========== Helpers ==========

    private static function requireGet(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::json(['ok' => false, 'error' => 'Metodo non consentito'], 405);
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
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}