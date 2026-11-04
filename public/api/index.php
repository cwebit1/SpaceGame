<?php

declare(strict_types=1);

/**
 * API Router - SpaceGame
 *
 * Endpoint disponibili:
 * POST   /api/login              - Login utente
 * POST   /api/logout             - Logout utente
 * GET    /api/auth/status        - Stato autenticazione
 *
 * GET    /api/games              - Lista partite utente
 * POST   /api/games              - Crea nuova partita (richiede CSRF)
 * DELETE /api/games/{id}         - Elimina partita (richiede CSRF)
 * POST   /api/games/{id}/refresh-config  - Aggiorna config partita (richiede CSRF)
 * POST   /api/games/{id}/generate-galaxy - Genera galassia (richiede CSRF)
 */

require_once __DIR__ . '/../../core/bootstrap.php';

use SpaceGame\Api\AuthController;
use SpaceGame\Api\GamesController;

// CORS headers per sviluppo (in produzione limitare agli origin consentiti)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

// Gestione preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH) ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Normalizza path (rimuovi query string)
$path = preg_replace('#/api/?$#', '/api', $path);

// Routing
try {
    // Auth endpoints
    if ($path === '/api/login' && $method === 'POST') {
        AuthController::login();
    } elseif ($path === '/api/logout' && $method === 'POST') {
        AuthController::logout();
    } elseif ($path === '/api/auth/status' && $method === 'GET') {
        AuthController::status();
    }
    // Games endpoints
    elseif ($path === '/api/games' && $method === 'GET') {
        GamesController::list();
    } elseif ($path === '/api/games' && $method === 'POST') {
        GamesController::create();
    } elseif (preg_match('#^/api/games/(\d+)$#', $path, $matches) && $method === 'DELETE') {
        // Imposta l'ID per il controller
        $_SERVER['GAME_ID'] = (int)$matches[1];
        GamesController::delete();
    } elseif (preg_match('#^/api/games/(\d+)/refresh-config$#', $path, $matches) && $method === 'POST') {
        $_SERVER['GAME_ID'] = (int)$matches[1];
        GamesController::refreshConfig();
    } elseif (preg_match('#^/api/games/(\d+)/generate-galaxy$#', $path, $matches) && $method === 'POST') {
        $_SERVER['GAME_ID'] = (int)$matches[1];
        GamesController::generateGalaxy();
    } else {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Endpoint non trovato'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
} catch (\Throwable $e) {
    // Error handler generico
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log("API Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode([
        'ok' => false,
        'error' => 'Errore interno del server'
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}