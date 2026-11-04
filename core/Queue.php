<?php

declare(strict_types=1);

namespace SpaceGame;

use PDO;
use RuntimeException;

/**
 * Queue - Gestione code di costruzione, ricerca, cantiere, potenziamenti, silo.
 */
final class Queue
{
    /**
     * Accoda un elemento.
     *
     * @param int $bodyId ID del corpo
     * @param string $kind Tipo coda: 'building', 'research', 'shipyard', 'upgrade', 'silo'
     * @param string $code Codice elemento (es. 'metal_mine', 'energy_tech')
     * @param float $qty Quantità (per navi/difese/missili/potenziamenti)
     * @param int $slot Numero slot (0 = primo disponibile)
     * @return int ID dell'elemento in coda
     */
    public static function enqueue(
        int $bodyId,
        string $kind,
        string $code,
        float $qty = 1.0,
        int $slot = 0
    ): int {
        $pdo = Db::pdo();

        // Trova il game_id e empire_id dal body
        $stmt = $pdo->prepare("SELECT `game_id`, `empire_id` FROM `bodies` WHERE `id` = ?");
        $stmt->execute([$bodyId]);
        $body = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$body) {
            throw new RuntimeException("Corpo $bodyId non trovato");
        }

        $gameId = (int)$body['game_id'];
        $empireId = (int)$body['empire_id'];

        // Calcola la posizione (ultimo in coda per questo kind/slot)
        $stmt = $pdo->prepare("
            SELECT COALESCE(MAX(`position`), 0) + 1 AS next_pos
            FROM `queues`
            WHERE `game_id` = ? AND `body_id` = ? AND `kind` = ? AND `slot` = ?
        ");
        $stmt->execute([$gameId, $bodyId, $kind, $slot]);
        $row = $stmt->fetch();
        $position = (int)($row['next_pos'] ?? 1);

        // Se slot = 0, trova il primo slot disponibile
        if ($slot === 0) {
            $maxSlots = ($kind === 'building')
                ? self::getBuildingSlotsFromBodyId($bodyId)
                : (($kind === 'shipyard' || $kind === 'upgrade')
                    ? self::getShipyardSlotsFromBodyId($bodyId)
                    : 1);

            for ($s = 1; $s <= $maxSlots; $s++) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS cnt FROM `queues`
                    WHERE `game_id` = ? AND `body_id` = ? AND `kind` = ? AND `slot` = ? AND `state` != 'done'
                ");
                $stmt->execute([$gameId, $bodyId, $kind, $s]);
                $row = $stmt->fetch();
                if ((int)$row['cnt'] === 0) {
                    $slot = $s;
                    $position = 1;
                    break;
                }
            }
            if ($slot === 0) {
                $slot = 1; // Default al primo slot se tutti pieni
            }
        }

        // Inserisci in coda
        $stmt = $pdo->prepare("
            INSERT INTO `queues` (`game_id`, `body_id`, `empire_id`, `kind`, `slot`, `code`, `qty`, `position`, `state`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'waiting')
        ");
        $stmt->execute([$gameId, $bodyId, $empireId, $kind, $slot, $code, $qty, $position]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Ottiene slot edifici per body_id (query diretta).
     */
    private static function getBuildingSlotsFromBodyId(int $bodyId): int
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT `level` FROM `buildings` WHERE `body_id` = ? AND `code` = 'construction_hub'
        ");
        $stmt->execute([$bodyId]);
        $row = $stmt->fetch();
        $level = (int)($row['level'] ?? 0);
        return 1 + (int)floor($level / 5);
    }

    /**
     * Ottiene slot cantiere per body_id (query diretta).
     */
    private static function getShipyardSlotsFromBodyId(int $bodyId): int
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT `level` FROM `buildings` WHERE `body_id` = ? AND `code` = 'assembly_line'
        ");
        $stmt->execute([$bodyId]);
        $row = $stmt->fetch();
        $level = (int)($row['level'] ?? 0);
        return 1 + (int)floor($level / 5);
    }

    /**
     * Tenta di avviare un elemento in coda.
     * Scala le risorse all'avvio; se mancano, imposta stato 'waiting' e programma evento 'queue_retry'.
     *
     * @param int $queueId ID elemento in coda
     * @param GameContext $ctx Contesto di gioco
     * @return bool True se avviato, false se in attesa
     */
    public static function tryStart(int $queueId, GameContext $ctx): bool
    {
        $pdo = $ctx->db;
        $now = $ctx->now;
        $config = $ctx->config;

        // Carica elemento coda
        $stmt = $pdo->prepare("
            SELECT q.*, b.`res_m`, b.`res_c`, b.`res_d`, b.`res_lu`, b.`empire_id`, b.`game_id`
            FROM `queues` q
            JOIN `bodies` b ON b.`id` = q.`body_id`
            WHERE q.`id` = ? AND q.`state` = 'waiting'
        ");
        $stmt->execute([$queueId]);
        $queue = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$queue) {
            return false; // Non esiste o non è in waiting
        }

        $bodyId = (int)$queue['body_id'];
        $gameId = (int)$queue['game_id'];
        $empireId = (int)$queue['empire_id'];
        $kind = $queue['kind'];
        $code = $queue['code'];
        $qty = (float)$queue['qty'];
        $slot = (int)$queue['slot'];

        // Carica body completo con edifici
        $stmt = $pdo->prepare("SELECT * FROM `bodies` WHERE `id` = ?");
        $stmt->execute([$bodyId]);
        $body = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$body) {
            return false;
        }

        // Carica edifici
        $stmt = $pdo->prepare("SELECT `code`, `level` FROM `buildings` WHERE `body_id` = ?");
        $stmt->execute([$bodyId]);
        $buildings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $buildings[$row['code']] = ['level' => (int)$row['level']];
        }
        $body['buildings'] = $buildings;

        // Carica ricerche impero
        $stmt = $pdo->prepare("SELECT `code`, `level` FROM `research` WHERE `empire_id` = ?");
        $stmt->execute([$empireId]);
        $research = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $research[$row['code']] = (int)$row['level'];
        }

        // Calcola costo dell'elemento
        $cost = self::calculateCost($kind, $code, $qty, $body, $research, $config);
        if (!$cost) {
            return false; // Costo non calcolabile
        }

        // Verifica risorse disponibili
        $hasResources = true;
        foreach (['m' => 'res_m', 'c' => 'res_c', 'd' => 'res_d', 'lu' => 'res_lu'] as $resKey => $dbKey) {
            if (($cost[$resKey] ?? 0) > (($body[$dbKey] ?? 0) + 0.0001)) {
                $hasResources = false;
                break;
            }
        }

        if (!$hasResources) {
            // Calcola tempo esatto in cui le risorse basteranno
            $retryTime = self::calculateResourceReadyTime($body, $cost, $empireId, $config);
            if ($retryTime > 0 && $retryTime > $now) {
                // Programma evento queue_retry
                EventBus::schedule($gameId, $retryTime, 'queue_retry', $queueId, []);
            }
            return false;
        }

        // Scala risorse
        foreach (['m' => 'res_m', 'c' => 'res_c', 'd' => 'res_d', 'lu' => 'res_lu'] as $resKey => $dbKey) {
            if (($cost[$resKey] ?? 0) > 0) {
                $stmt = $pdo->prepare("UPDATE `bodies` SET `$dbKey` = `$dbKey` - ? WHERE `id` = ?");
                $stmt->execute([$cost[$resKey], $bodyId]);
            }
        }

        // Calcola tempo di completamento
        $durationHours = self::calculateDuration($kind, $code, $qty, $body, $research, $config);
        $endsAt = $now + $durationHours * 3600.0;

        // Aggiorna coda: running, started_at, ends_at
        $stmt = $pdo->prepare("
            UPDATE `queues`
            SET `state` = 'running', `started_at` = ?, `ends_at` = ?
            WHERE `id` = ?
        ");
        $stmt->execute([$now, $endsAt, $queueId]);

        // Programma evento di completamento
        $eventType = match ($kind) {
            'building' => 'building_done',
            'research' => 'research_done',
            'shipyard' => 'shipyard_done',
            'upgrade' => 'upgrade_done',
            'silo' => 'silo_done',
            default => 'queue_done',
        };
        EventBus::schedule($gameId, $endsAt, $eventType, $queueId, [
            'kind' => $kind,
            'code' => $code,
            'qty' => $qty,
        ]);

        return true;
    }

    /**
     * Calcola il costo di un elemento in coda.
     */
    private static function calculateCost(
        string $kind,
        string $code,
        float $qty,
        array $body,
        array $research,
        Config $config
    ): ?array {
        switch ($kind) {
            case 'building':
                $level = ($body['buildings'][$code]['level'] ?? 0) + 1;
                $buildingConfig = $config->get("buildings.$code");
                return Formula::cost($buildingConfig['cost'], $buildingConfig['growth'], $level);

            case 'research':
                $level = ($research[$code] ?? 0) + 1;
                $researchConfig = $config->get("research.$code");
                return Formula::cost($researchConfig['cost'], $researchConfig['growth'], $level);

            case 'shipyard':
            case 'upgrade':
            case 'silo':
                // Per navi, difese, missili, potenziamenti
                // TODO: Implementare calcolo costo specifico
                return ['m' => 0, 'c' => 0, 'd' => 0, 'lu' => 0];

            default:
                return null;
        }
    }

    /**
     * Calcola la durata in ore di un elemento in coda.
     */
    private static function calculateDuration(
        string $kind,
        string $code,
        float $qty,
        array $body,
        array $research,
        Config $config
    ): float {
        $cost = self::calculateCost($kind, $code, $qty, $body, $research, $config);
        if (!$cost) return 0.0;

        $speedDivisor = 1.0;
        $naniteLevel = $body['buildings']['nanite_factory']['level'] ?? 0;
        $naniteFactor = $config->get('buildings.nanite_factory.effect.time_factor.a') ?? 0.25;
        $gameSpeed = ($body['game_speed'] ?? 1.0); // TODO: da game settings

        switch ($kind) {
            case 'building':
                $robotLevel = $body['buildings']['robot_factory']['level'] ?? 0;
                $speedDivisor = 1.0 + 0.15 * $robotLevel;
                break;
            case 'research':
                $labLevel = $body['buildings']['research_lab']['level'] ?? 0;
                $speedDivisor = 1.0 + 0.25 * $labLevel;
                // Rete di ricerca: somma Lab di più pianeti (TODO)
                break;
            case 'shipyard':
                $shipyardLevel = $body['buildings']['spaceyard']['level'] ?? 0;
                $speedDivisor = 1.0 + 0.2 * $shipyardLevel;
                break;
            case 'upgrade':
                $forgeLevel = $body['buildings']['upgrade_forge']['level'] ?? 0;
                $speedDivisor = 1.0 + 0.1 * $forgeLevel;
                break;
            case 'silo':
                $factoryLevel = $body['buildings']['missile_factory']['level'] ?? 0;
                $speedDivisor = 1.0 - 0.1 * $factoryLevel;
                break;
        }

        // Per quantità > 1 (navi), il tempo è per unità * qty
        $hoursPerUnit = Formula::buildHours($cost, $speedDivisor, $naniteLevel, $gameSpeed, $naniteFactor);
        return $hoursPerUnit * $qty;
    }

    /**
     * Calcola il tempo esatto in cui le risorse saranno sufficienti.
     */
    private static function calculateResourceReadyTime(
        array $body,
        array $cost,
        int $empireId,
        Config $config
    ): float {
        // Calcola tassi di produzione orari
        $research = []; // TODO: caricare ricerche
        $empireMods = ['speed' => 1.0]; // TODO: da game settings
        $rates = Economy::ratesPerHour($body, $research, $empireMods, $config);

        $maxHours = 0.0;
        foreach (['m' => 'res_m', 'c' => 'res_c', 'd' => 'res_d', 'lu' => 'res_lu'] as $resKey => $dbKey) {
            $need = ($cost[$resKey] ?? 0) - ($body[$dbKey] ?? 0);
            if ($need <= 0) continue;
            $rate = $rates[$resKey] ?? 0;
            if ($rate <= 0) return 0.0; // Non produrremo mai questa risorsa
            $hours = $need / $rate;
            if ($hours > $maxHours) $maxHours = $hours;
        }

        if ($maxHours <= 0) return 0.0;

        $now = microtime(true);
        return $now + $maxHours * 3600.0;
    }

    /**
     * Riordina elementi in coda.
     */
    public static function reorder(int $gameId, int $bodyId, string $kind, array $newOrder): void
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT `id` FROM `queues`
            WHERE `game_id` = ? AND `body_id` = ? AND `kind` = ? AND `state` != 'done'
            ORDER BY `position` ASC
        ");
        $stmt->execute([$gameId, $bodyId, $kind]);
        $current = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($current) !== count($newOrder)) {
            throw new RuntimeException("Numero elementi non corrisponde");
        }

        // newOrder contiene i queue_id nel nuovo ordine
        foreach ($newOrder as $position => $queueId) {
            $stmt = $pdo->prepare("
                UPDATE `queues` SET `position` = ? WHERE `id` = ? AND `game_id` = ?
            ");
            $stmt->execute([$position + 1, $queueId, $gameId]);
        }
    }

    /**
     * Annulla un elemento in coda.
     * Se running: rimborso 100% risorse (Economy::credit), tiene unità completate.
     * Se waiting: rimborso 100% (non erano state scalate).
     * Se done: nessun rimborso.
     */
    public static function cancel(int $queueId, GameContext $ctx): void
    {
        $pdo = $ctx->db;
        $config = $ctx->config;

        $stmt = $pdo->prepare("
            SELECT q.*, b.`res_m`, b.`res_c`, b.`res_d`, b.`res_lu`, b.`empire_id`
            FROM `queues` q
            JOIN `bodies` b ON b.`id` = q.`body_id`
            WHERE q.`id` = ?
        ");
        $stmt->execute([$queueId]);
        $queue = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$queue) {
            return;
        }

        $bodyId = (int)$queue['body_id'];
        $state = $queue['state'];
        $kind = $queue['kind'];
        $code = $queue['code'];
        $qty = (float)$queue['qty'];

        // Carica body completo
        $stmt = $pdo->prepare("SELECT * FROM `bodies` WHERE `id` = ?");
        $stmt->execute([$bodyId]);
        $body = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$body) return;

        // Carica edifici e ricerche per calcolo costo
        $stmt = $pdo->prepare("SELECT `code`, `level` FROM `buildings` WHERE `body_id` = ?");
        $stmt->execute([$bodyId]);
        $buildings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $buildings[$row['code']] = ['level' => (int)$row['level']];
        }
        $body['buildings'] = $buildings;

        $stmt = $pdo->prepare("SELECT `code`, `level` FROM `research` WHERE `empire_id` = ?");
        $stmt->execute([$body['empire_id']]);
        $research = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $research[$row['code']] = (int)$row['level'];
        }

        // Calcola costo per rimborso (solo se running)
        if ($state === 'running') {
            $cost = self::calculateCost($kind, $code, $qty, $body, $research, $config);
            if ($cost) {
                Economy::credit($body, $cost, $config);
                // Aggiorna DB con le risorse rimborsate
                $stmt = $pdo->prepare("
                    UPDATE `bodies`
                    SET `res_m` = ?, `res_c` = ?, `res_d` = ?, `res_lu` = ?
                    WHERE `id` = ?
                ");
                $stmt->execute([
                    $body['res_m'], $body['res_c'], $body['res_d'], $body['res_lu'],
                    $bodyId
                ]);
            }
        }

        // Cancella eventuali eventi di completamento programmati
        $stmt = $pdo->prepare("
            DELETE FROM `events`
            WHERE `ref_id` = ? AND `type` IN ('building_done','research_done','shipyard_done','upgrade_done','silo_done','queue_done')
            AND `done` = 0
        ");
        $stmt->execute([$queueId]);

        // Rimuovi dalla coda
        $stmt = $pdo->prepare("DELETE FROM `queues` WHERE `id` = ?");
        $stmt->execute([$queueId]);

        // Ricalcola posizioni degli elementi successivi nello stesso slot
        $stmt = $pdo->prepare("
            UPDATE `queues`
            SET `position` = `position` - 1
            WHERE `game_id` = ? AND `body_id` = ? AND `kind` = ? AND `slot` = ? AND `position` > ?
        ");
        $stmt->execute([$queue['game_id'], $bodyId, $kind, $queue['slot'], $queue['position']]);
    }

    /**
     * Calcola slot paralleli per edifici da Construction Hub.
     * 1 + floor(ConstructionHub / 5)
     */
    public static function getBuildingSlots(array $body, Config $cfg): int
    {
        $level = $body['buildings']['construction_hub']['level'] ?? 0;
        return 1 + (int)floor($level / 5);
    }

    /**
     * Calcola slot paralleli per cantiere da Assembly Line.
     * 1 + floor(AssemblyLine / 5)
     */
    public static function getShipyardSlots(array $body, Config $cfg): int
    {
        $level = $body['buildings']['assembly_line']['level'] ?? 0;
        return 1 + (int)floor($level / 5);
    }
}

/**
 * Fields - Calcolo spazi disponibili e usati.
 */
final class Fields
{
    /**
     * Spazi totali del corpo.
     * Pianeta: 2000 + 200 * Terraformer (Terraformer non occupa spazi)
     * Luna: 1000 + 200 * (Nanoforge - 1) per livelli oltre il 1°
     */
    public static function total(array $body, Config $cfg): int
    {
        $baseFields = (int)($body['fields_base'] ?? 0);
        $bonusFields = (int)($body['fields_bonus'] ?? 0);

        if ($body['is_moon'] ?? false) {
            // Luna: base 1000 + 200 * (NF - 1)
            $nfLevel = $body['buildings']['lunar_nanoforge']['level'] ?? 0;
            if ($nfLevel > 1) {
                $bonusFields += 200 * ($nfLevel - 1);
            }
            return 1000 + $bonusFields;
        }

        // Pianeta: base 2000 + 200 * Terraformer
        $tfLevel = $body['buildings']['terraformer']['level'] ?? 0;
        $bonusFields += 200 * $tfLevel;

        return 2000 + $bonusFields;
    }

    /**
     * Spazi usati dagli edifici.
     * Ogni livello di struttura occupa 1 spazio (eccetto Terraformer e Nanoforge).
     */
    public static function used(array $body, Config $cfg): int
    {
        $used = 0;
        $buildings = $body['buildings'] ?? [];

        foreach ($buildings as $code => $building) {
            $level = $building['level'] ?? 0;
            if ($level <= 0) continue;

            // Terraformer e Nanoforge non occupano spazi
            if ($code === 'terraformer' || $code === 'nanite_factory') {
                continue;
            }

            $buildingConfig = $cfg->get("buildings.$code");
            $fieldsPerLevel = $buildingConfig['fields'] ?? 1;
            $used += $level * $fieldsPerLevel;
        }

        return $used;
    }

    /**
     * Verifica se c'è spazio per un nuovo livello.
     */
    public static function canBuild(array $body, string $buildingCode, Config $cfg): bool
    {
        $total = self::total($body, $cfg);
        $used = self::used($body, $cfg);

        // Spazio richiesto per il nuovo livello
        if ($buildingCode === 'terraformer' || $buildingCode === 'nanite_factory') {
            return true; // Non occupano spazi
        }

        $buildingConfig = $cfg->get("buildings.$buildingCode");
        $fieldsPerLevel = $buildingConfig['fields'] ?? 1;

        return ($used + $fieldsPerLevel) <= $total;
    }

    /**
     * Applica modificatori spazi da battaglia.
     * difensore vince: +50
     * attaccante vince: -50
     * pareggio: 0
     * bombardamento vinto: -100
     * bombardamento fallito: +50
     * Mai sotto la base (pianeta 2000+Terraformer, luna base Nanoforge).
     */
    public static function applyBattle(int $bodyId, string $outcome, Config $cfg): void
    {
        $pdo = Db::pdo();

        $delta = match ($outcome) {
            'def_win' => 50,
            'att_win' => -50,
            'draw' => 0,
            'bomb_win' => -100,
            'bomb_fail' => 50,
            default => 0,
        };

        if ($delta === 0) return;

        // Aggiorna fields_bonus
        $stmt = $pdo->prepare("
            UPDATE `bodies` SET `fields_bonus` = `fields_bonus` + ? WHERE `id` = ?
        ");
        $stmt->execute([$delta, $bodyId]);

        // Verifica non sotto la base
        $stmt = $pdo->prepare("SELECT `is_moon`, `fields_base`, `fields_bonus`, `buildings` FROM `bodies` WHERE `id` = ?");
        $stmt->execute([$bodyId]);
        $body = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$body) return;

        $minFields = ($body['is_moon'] ?? false) ? 1000 : 2000;
        // Aggiungi bonus Terraformer/NF
        if (($body['is_moon'] ?? false)) {
            // Per luna, base è 1000 + 200*(NF-1)
            // Ma fields_base dovrebbe essere 1000
            $minFields = 1000;
        } else {
            $minFields = 2000;
        }

        $currentTotal = (int)$body['fields_base'] + (int)$body['fields_bonus'];
        if ($currentTotal < $minFields) {
            $stmt = $pdo->prepare("UPDATE `bodies` SET `fields_bonus` = ? WHERE `id` = ?");
            $stmt->execute([$minFields - (int)$body['fields_base'], $bodyId]);
        }
    }
}