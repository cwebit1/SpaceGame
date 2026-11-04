<?php

declare(strict_types=1);

namespace SpaceGame;

use PDO;
use RuntimeException;

/**
 * GameContext - Contesto passato agli handler degli eventi.
 * Contiene: game, config, db, now (istante dell'evento).
 */
final class GameContext
{
    public function __construct(
        public readonly array $game,
        public readonly Config $config,
        public readonly PDO $db,
        public readonly float $now
    ) {}
}

/**
 * Clock - Motore di recupero tempo (catch-up) per la simulazione a eventi.
 * Processa gli eventi in ordine cronologico con lock per partita e budget temporale.
 */
final class Clock
{
    /**
     * Recupera il tempo di gioco elaborando gli eventi pendenti.
     *
     * @param int $gameId ID della partita
     * @param float $now Timestamp Unix corrente (secondi, float)
     * @param float $budgetSec Budget in secondi reali per questa chiamata (es. 0.05 = 50ms)
     * @param bool $test Usa database di test
     * @return true se tutti gli eventi fino a $now sono stati elaborati, false se il budget è esaurito
     */
    public static function catchUp(int $gameId, float $now, float $budgetSec, bool $test = false): bool
    {
        $startTime = microtime(true);
        $budgetEnd = $startTime + $budgetSec;

        // Usa lock per partita per evitare elaborazione concorrente
        return Db::withGameLock($gameId, function () use ($gameId, $now, $budgetEnd, $startTime, $test): bool {
            $pdo = Db::pdo($test);
            // Carica configurazione e dati partita
            $stmt = $pdo->prepare("SELECT `settings_json`, `config_json`, `processed_until` FROM `games` WHERE `id` = ?");
            $stmt->execute([$gameId]);
            $gameRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$gameRow) {
                throw new RuntimeException("Partita $gameId non trovata");
            }

            $settings = json_decode($gameRow['settings_json'], true) ?? [];
            $config = Config::fromJson($gameRow['config_json']);
            $processedUntil = (float)($gameRow['processed_until'] ?? 0);

            // Se già processato fino a $now o oltre, nulla da fare
            if ($processedUntil >= $now) {
                return true;
            }

            // Se processedUntil è 0, inizializza al momento di creazione partita o a now
            $fromTime = $processedUntil > 0 ? $processedUntil : $now;

            // Query eventi pendenti: done=0 AND at <= now, ordinati per (at, id)
            $stmt = $pdo->prepare("
                SELECT `id`, `at`, `type`, `ref_id`, `payload_json`
                FROM `events`
                WHERE `game_id` = ? AND `done` = 0 AND `at` <= ?
                ORDER BY `at` ASC, `id` ASC
            ");
            $stmt->execute([$gameId, $now]);

            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($events)) {
                // Nessun evento, aggiorna processed_until e esci
                $stmt = $pdo->prepare("UPDATE `games` SET `processed_until` = ? WHERE `id` = ?");
                $stmt->execute([$now, $gameId]);
                return true;
            }

            $allProcessed = true;

            foreach ($events as $event) {
                // Controlla budget
                if (microtime(true) >= $budgetEnd) {
                    $allProcessed = false;
                    break;
                }

                $eventAt = (float)$event['at'];

                // Crea contesto per questo evento
                $ctx = new GameContext(
                    game: $gameRow,
                    config: $config,
                    db: $pdo,
                    now: $eventAt
                );

                // Esegui evento
                EventBus::execute((int)$event['id'], $ctx);
            }

            // Aggiorna processed_until all'ultimo tempo elaborato o a now se completato
            $newProcessedUntil = $allProcessed ? $now : (float)$events[count($events) - 1]['at'];

            // Ma se non abbiamo elaborato tutto, fermati all'ultimo evento elaborato
            // Per ora aggiorniamo a now se completato, altrimenti al tempo dell'ultimo evento elaborato
            if ($allProcessed) {
                $newProcessedUntil = $now;
            } else {
                // Trova l'ultimo evento che abbiamo effettivamente elaborato
                // Per semplicità, usiamo il tempo dell'ultimo evento che abbiamo tentato
                // (l'esecuzione marca done=1, quindi la prossima volta ripartirà da lì)
                $newProcessedUntil = (float)$events[count($events) - 1]['at'];
            }

            $stmt = $pdo->prepare("UPDATE `games` SET `processed_until` = ? WHERE `id` = ?");
            $stmt->execute([$newProcessedUntil, $gameId]);

            return $allProcessed;
        }, 5);
    }
}