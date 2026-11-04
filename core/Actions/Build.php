<?php

declare(strict_types=1);

namespace SpaceGame\Actions;

use SpaceGame\Config;
use SpaceGame\Queue;
use SpaceGame\Requirements;
use SpaceGame\Fields;
use SpaceGame\Economy;
use SpaceGame\EventBus;
use SpaceGame\Db;
use SpaceGame\GameContext;

/**
 * Actions\Build - Azioni di costruzione edifici (player e CPU usano le stesse funzioni).
 */
final class Build
{
    /**
     * Accoda la costruzione di un edificio.
     *
     * @param int $empireId ID impero
     * @param int $bodyId ID corpo
     * @param string $code Codice edificio (es. 'metal_mine')
     * @return int ID elemento in coda
     * @throws \RuntimeException se prerequisiti non soddisfatti, spazi insufficienti, ecc.
     */
    public static function queue(int $empireId, int $bodyId, string $code): int
    {
        $pdo = Db::pdo();

        // Verifica proprietà
        $stmt = $pdo->prepare("SELECT `game_id`, `empire_id`, `is_moon`, `fields_base`, `fields_bonus` FROM `bodies` WHERE `id` = ?");
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

        // Carica edifici
        $stmt = $pdo->prepare("SELECT `code`, `level` FROM `buildings` WHERE `body_id` = ?");
        $stmt->execute([$bodyId]);
        $buildings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $buildings[$row['code']] = ['level' => (int)$row['level']];
        }

        // Carica ricerche
        $stmt = $pdo->prepare("SELECT `code`, `level` FROM `research` WHERE `empire_id` = ?");
        $stmt->execute([$empireId]);
        $research = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $research[$row['code']] = (int)$row['level'];
        }

        // Carica edificio madre per prerequisiti luna
        $moonOfBody = null;
        if ((bool)$body['is_moon'] && $body['moon_of']) {
            $stmt = $pdo->prepare("SELECT `id` FROM `bodies` WHERE `id` = ?");
            $stmt->execute([$body['moon_of']]);
            $parentBody = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($parentBody) {
                $stmt = $pdo->prepare("SELECT `code`, `level` FROM `buildings` WHERE `body_id` = ?");
                $stmt->execute([$parentBody['id']]);
                $parentBuildings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $parentBuildings[$row['code']] = ['level' => (int)$row['level']];
                }
                $moonOfBody = ['buildings' => $parentBuildings];
            }
        }

        // Prepara array body per controlli
        $bodyArray = [
            'id' => $bodyId,
            'game_id' => $body['game_id'],
            'empire_id' => $empireId,
            'is_moon' => $body['is_moon'],
            'fields_base' => $body['fields_base'],
            'fields_bonus' => $body['fields_bonus'],
            'buildings' => $buildings,
        ];

        // Verifica prerequisiti
        $buildingConfig = $config->get("buildings.$code");
        $requires = $buildingConfig['requires'] ?? [];
        if (!Requirements::met($requires, $buildings, $research, $moonOfBody)) {
            $missing = Requirements::missing($requires, $buildings, $research, $moonOfBody);
            $missingStr = implode(', ', array_map(
                fn($k, $v) => "$k (livello $v)",
                array_keys($missing),
                array_values($missing)
            ));
            throw new RuntimeException("Prerequisiti mancanti per $code: $missingStr");
        }

        // Verifica spazi
        if (!Fields::canBuild($bodyArray, $code, $config)) {
            throw new RuntimeException("Spazi insufficienti per $code");
        }

        // Verifica se già al livello massimo (se definito)
        $currentLevel = $buildings[$code]['level'] ?? 0;
        $maxLevel = $buildingConfig['max_level'] ?? 999999;
        if ($currentLevel >= $maxLevel) {
            throw new RuntimeException("Edificio $code già al livello massimo ($maxLevel)");
        }

        // Accoda (lo slot viene assegnato automaticamente)
        $queueId = Queue::enqueue($bodyId, 'building', $code, 1.0, 0);

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
     * Annulla costruzione in coda.
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

        if ($queue['kind'] !== 'building') {
            throw new RuntimeException("Elemento coda non è un edificio");
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
     * Riordina code edifici.
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

        Queue::reorder((int)$body['game_id'], $bodyId, 'building', $newOrder);
    }
}