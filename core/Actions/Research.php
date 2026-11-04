<?php

declare(strict_types=1);

namespace SpaceGame\Actions;

use SpaceGame\Config;
use SpaceGame\Queue;
use SpaceGame\Requirements;
use SpaceGame\Fields;
use SpaceGame\Economy;
use SpaceGame\Formula;
use SpaceGame\Db;
use SpaceGame\GameContext;

/**
 * Actions\Research - Azioni di ricerca (player e CPU usano le stesse funzioni).
 */
final class Research
{
    /**
     * Accoda una ricerca.
     *
     * @param int $empireId ID impero
     * @param int $bodyId ID corpo dove avviene la ricerca (usa Lab e Naniti di questo corpo)
     * @param string $code Codice ricerca (es. 'geology')
     * @return int ID elemento in coda
     * @throws \RuntimeException se prerequisiti non soddisfatti, ecc.
     */
    public static function queue(int $empireId, int $bodyId, string $code): int
    {
        $pdo = Db::pdo();

        // Verifica proprietà
        $stmt = $pdo->prepare("SELECT `game_id`, `empire_id` FROM `bodies` WHERE `id` = ?");
        $stmt->execute([$bodyId]);
        $body = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$body) {
            throw new RuntimeException("Corpo $bodyId non trovato");
        }

        if ((int)$body['empire_id'] !== $empireId) {
            throw new RuntimeException("Il corpo non appartiene all'impero");
        }

        // Carica configurazione
        $stmt = $pdo->prepare("SELECT `config_json` FROM `games` WHERE `id` = ?");
        $stmt->execute([$body['game_id']]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);
        $config = Config::fromJson($game['config_json']);

        // Carica ricerche dell'impero
        $stmt = $pdo->prepare("SELECT `code`, `level` FROM `research` WHERE `empire_id` = ?");
        $stmt->execute([$empireId]);
        $research = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $research[$row['code']] = (int)$row['level'];
        }

        // Carica edifici del corpo di partenza (per Lab e Naniti)
        $stmt = $pdo->prepare("SELECT `code`, `level` FROM `buildings` WHERE `body_id` = ?");
        $stmt->execute([$bodyId]);
        $buildings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $buildings[$row['code']] = ['level' => (int)$row['level']];
        }

        // Verifica prerequisiti
        $researchConfig = $config->get("research.$code");
        $requires = $researchConfig['requires'] ?? [];
        if (!Requirements::met($requires, $buildings, $research, null)) {
            $missing = Requirements::missing($requires, $buildings, $research, null);
            $missingStr = implode(', ', array_map(
                fn($k, $v) => "$k (livello $v)",
                array_keys($missing),
                array_values($missing)
            ));
            throw new RuntimeException("Prerequisiti mancanti per ricerca $code: $missingStr");
        }

        // Verifica se già al livello massimo (se definito)
        $currentLevel = $research[$code] ?? 0;
        $maxLevel = $researchConfig['max_level'] ?? 999999;
        if ($currentLevel >= $maxLevel) {
            throw new RuntimeException("Ricerca $code già al livello massimo ($maxLevel)");
        }

        // Accoda (lo slot viene assegnato automaticamente)
        // Per le ricerche: il body_id è il pianeta dove avviene la ricerca
        $queueId = Queue::enqueue($bodyId, 'research', $code, 1.0, 0);

        // Tenta di avviare subito
        $ctx = new GameContext(
            game: ['id' => $body['game_id']],
            config: $config,
            db: $pdo,
            now: microtime(true)
        );
        Queue::tryStart($queueId, $ctx);

        return $queueId;
    }

    /**
     * Annulla ricerca in coda.
     */
    public static function cancel(int $empireId, int $queueId): void
    {
        $pdo = Db::pdo();

        // Verifica proprietà
        $stmt = $pdo->prepare("
            SELECT q.*, b.`empire_id` as body_empire_id
            FROM `queues` q
            JOIN `bodies` b ON b.`id` = q.`body_id`
            WHERE q.`id` = ?
        ");
        $stmt->execute([$queueId]);
        $queue = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$queue) {
            throw new RuntimeException("Elemento coda $queueId non trovato");
        }

        if ((int)$queue['body_empire_id'] !== $empireId) {
            throw new RuntimeException("L'elemento coda non appartiene all'impero");
        }

        if ($queue['kind'] !== 'research') {
            throw new RuntimeException("Elemento coda non è una ricerca");
        }

        // Carica config
        $stmt = $pdo->prepare("SELECT `config_json` FROM `games` WHERE `id` = ?");
        $stmt->execute([$queue['game_id']]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);
        $config = Config::fromJson($game['config_json']);

        $ctx = new GameContext(
            game: ['id' => $queue['game_id']],
            config: $config,
            db: $pdo,
            now: microtime(true)
        );

        Queue::cancel($queueId, $ctx);
    }

    /**
     * Riordina code ricerche.
     */
    public static function reorder(int $empireId, int $bodyId, array $newOrder): void
    {
        $pdo = Db::pdo();

        // Verifica proprietà
        $stmt = $pdo->prepare("SELECT `game_id`, `empire_id` FROM `bodies` WHERE `id` = ?");
        $stmt->execute([$bodyId]);
        $body = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$body || (int)$body['empire_id'] !== $empireId) {
            throw new RuntimeException("Corpo non trovato o non appartiene all'impero");
        }

        Queue::reorder((int)$body['game_id'], $bodyId, 'research', $newOrder);
    }
}