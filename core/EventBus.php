<?php

declare(strict_types=1);

namespace SpaceGame;

use PDO;
use RuntimeException;

/**
 * EventBus - Sistema di eventi per la simulazione a tempo.
 * Gli eventi vengono memorizzati nella tabella `events` e processati da Clock::catchUp.
 */
final class EventBus
{
    /** @var array<string, callable> Handlers registrati per tipo di evento */
    private static array $handlers = [];

    /**
     * Programma un nuovo evento.
     *
     * @param int $gameId ID della partita
     * @param float $at Timestamp Unix (secondi, float) quando l'evento deve eseguirsi
     * @param string $type Tipo di evento (es. 'fleet_arrive', 'queue_retry', 'cpu_tick')
     * @param int $refId ID di riferimento (body_id, fleet_id, queue_id, empire_id, ecc.)
     * @param array $payload Dati aggiuntivi per l'handler
     * @return int ID dell'evento creato
     */
    public static function schedule(int $gameId, float $at, string $type, int $refId, array $payload): int
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            INSERT INTO `events` (`game_id`, `at`, `type`, `ref_id`, `payload_json`, `done`)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([$gameId, $at, $type, $refId, json_encode($payload)]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Cancella un evento programmato.
     */
    public static function cancel(int $eventId): void
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("DELETE FROM `events` WHERE `id` = ? AND `done` = 0");
        $stmt->execute([$eventId]);
    }

    /**
     * Registra un handler per un tipo di evento.
     * L'handler riceve: fn(array $event, GameContext $ctx): void
     */
    public static function register(string $type, callable $handler): void
    {
        self::$handlers[$type] = $handler;
    }

    /**
     * Esegue un singolo evento.
     * Restituisce true se l'evento è stato eseguito, false se non esiste o già elaborato.
     */
    public static function execute(int $eventId, GameContext $ctx): bool
    {
        $pdo = $ctx->db;

        // Ottieni l'evento e marca come elaborato atomicamente
        // Usa una transazione per evitare race conditions
        $stmt = $pdo->prepare("
            SELECT `id`, `game_id`, `at`, `type`, `ref_id`, `payload_json`
            FROM `events`
            WHERE `id` = ? AND `done` = 0
            FOR UPDATE
        ");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            return false;
        }

        // Marca come elaborato PRIMA di eseguire l'handler (per idempotenza)
        $stmt = $pdo->prepare("UPDATE `events` SET `done` = 1 WHERE `id` = ?");
        $stmt->execute([$eventId]);

        // Decodifica payload
        $event['payload'] = json_decode($event['payload_json'], true) ?? [];

        // Esegui handler se registrato
        $type = $event['type'];
        if (isset(self::$handlers[$type])) {
            $handler = self::$handlers[$type];
            $handler($event, $ctx);
        }

        return true;
    }

    /**
     * Resetta gli handler registrati (solo per test).
     */
    public static function resetHandlers(): void
    {
        self::$handlers = [];
    }
}