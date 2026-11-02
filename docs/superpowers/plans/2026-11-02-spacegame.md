# SpaceGame Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gioco di strategia spaziale persistente single-player contro CPU, PHP+MySQL, interfaccia PWA mobile-first.

**Architecture:** API JSON in PHP 8 senza framework; simulazione a eventi (`events` ordinati per orario) recuperata a blocchi a ogni richiesta; tutte le regole e i numeri letti da `game_config.json`; frontend JS vanilla.

**Tech Stack:** PHP ≥ 8.1, PDO MySQL (MySQL ≥ 5.7 / MariaDB ≥ 10.3), PHPUnit 10 (solo sviluppo, via Composer), JS ES2020 vanilla, Node ≥ 18 solo per i test JS.

**Spec:** `docs/superpowers/specs/2026-11-02-spacegame-design.md` — **leggila prima di ogni task**: i numeri citati come "§N" vanno copiati da lì in `game_config.json`.

## Global Constraints

- Nessun numero di gioco nel codice PHP/JS: solo letture da `Config` (eccezioni: 0, 1, indici).
- CPU e player usano **le stesse** funzioni d'azione (`Actions\*`); nessun parametro "isCpu" che salti controlli.
- Quantità di gioco in `DOUBLE` (DB) / `float` (PHP). Tempi in secondi Unix `float` (DB `DOUBLE`).
- Tempo di gioco = tempo reale × `games.speed`. Sono **reali** (non accelerati): attesa luna 48 h, ricarica sposta-luna 24 h, Rift 2/giorno durata 6 h, campi asteroidi 3/giorno durata 12 h, sosta flotta 30 s, reset asteroidi mezzanotte `Europe/Rome`, ricostruzione Mothership 7 giorni, conservazione log 30/90 giorni.
- Nessuna dipendenza runtime: in produzione si caricano solo `public/`, `core/`, `install/`, `cron.php`, `game_config.json`.
- Namespace `SpaceGame\`, autoload PSR-4 da `core/` tramite `core/bootstrap.php` (niente Composer in produzione).
- UI in italiano; nomi navi/difese/strutture in inglese come da spec.
- Mobile-first: target tocco ≥ 48 px, nessuna interazione solo-hover.
- Ogni task termina con test verdi (`vendor/bin/phpunit`) e un commit.

## Review Focus

1. **Recupero lungo offline** (x100, giorni di assenza): deve completarsi su più richieste senza timeout e dare lo **stesso stato** che in un'unica passata → test `ClockTest::test_chunked_equals_single_pass` (Task 6).
2. **Due richieste contemporanee** (due schede) che recuperano la stessa partita: non devono elaborare due volte lo stesso evento → lock `GET_LOCK('game_<id>')`, test `ClockTest::test_event_processed_once` (Task 6).
3. **Coda in attesa di risorse offline**: deve partire all'istante esatto in cui le risorse bastano, non alla richiesta successiva → `QueueTest::test_waiting_item_starts_at_exact_time` (Task 8).
4. **Limiti magazzino**: produzione ferma al cap; bottini/rimborsi/trasporti possono arrivare fino a +100% del cap e non oltre → `EconomyTest::test_overflow_capped_at_double` (Task 7).
5. **Numeri estremi e input utente**: 1e70, `2,5Mln`, `5k`, stringhe vuote, negativi, NaN → `FormatTest` PHP e `format.test.mjs` (Task 3) rifiutano o formattano senza eccezioni.

## Dove vive ogni effetto di struttura

Ogni effetto di §5 va implementato nel modulo indicato, con **almeno un test** nel task di quel modulo:

| Modulo (task) | Strutture / effetti |
|---|---|
| Economy/Energy (7) | miniere, tutte le fonti di energia, Energy Storage, magazzini, Vault, Planetary/Lunar Bunker, Deep Core Drill, Asteroid Processing Plant (al rientro Miner, Task 18) |
| Queue (8) | Robot, Naniti, Lab, Rete di ricerca, Construction Hub, Quantum Archive, Logistica, Terraformer, Nanoforge (spazi) |
| Shipyard/Upgrades (9) | Cantiere, Assembly Line, War Academy, Silo, Missile Factory, Upgrade Forge, Lunar Shipyard |
| Fleet (10) | Fleet Command, Orbital Elevator, Hyperspace Relay, Warp Beacon, Intelligence Agency (rilevazione), Colony Hub |
| Sensors (11) | Teleporter, Sensor Phalanx, Deep Space Radar, Jamming Tower, Lunar Observatory |
| Attack (13) | Repair Bay, Orbital Dock, Recycling Plant |
| Moon (14) | Bulwark, Cryo Hangar, Interdiction Field, Decoy Generator, Mass Driver, Antimatter (solo luna/pianeta) |
| Market/Diplomacy (16) | Trade Center, Resource Converter, Embassy |
| Pirates/Gauntlet (17) | Pirate Bounty Office, Admiral's Academy |
| Mining/Events (18) | Mining Control, Weather Control |

---

## Mappa file

```
composer.json                (solo require-dev phpunit)
phpunit.xml
game_config.json             tutti i parametri (§1-§18)
config.local.example.php     DB host/nome/utente/password, cron key
cron.php
install/install.php, install/schema.sql
core/bootstrap.php           autoload, error handler, timezone
core/Config.php              load + validate + get(dot.path)
core/ConfigSchema.php        regole di validazione
core/Db.php                  PDO, transazioni, lock
core/Formula.php             funzioni pure delle formule
core/Format.php              notazione numeri
core/Auth.php
core/Games.php               crea/elenca/elimina, copia config
core/Galaxy.php              generazione galassia
core/Model/*.php             Body, Empire, UnitStack, FleetRow (array tipizzati)
core/Repo/*.php              accesso DB per entità
core/Economy.php, core/Energy.php
core/Clock.php, core/EventBus.php
core/Queue.php
core/Actions/*.php           azioni condivise player/CPU
core/Fleet.php, core/Espionage.php
core/Combat.php, core/Loot.php, core/Debris.php, core/Missiles.php
core/Moon.php
core/Power.php
core/Pirates.php, core/Gauntlet.php, core/Admiral.php, core/Mining.php
core/Market.php, core/Diplomacy.php, core/RandomEvents.php
core/Cpu/Simple.php, core/Cpu/Brain.php, core/Cpu/Personality.php
core/Log.php
public/index.html, public/manifest.webmanifest, public/sw.js
public/api/index.php         router → core/Api/*Controller.php
public/js/*.js, public/css/app.css
tests/php/*Test.php, tests/js/*.test.mjs
docs/deploy-aruba.md
```

---

## Milestone A — Fondamenta

### Task 1: Scaffolding, ambiente locale, test runner

**Files:** Create `composer.json`, `phpunit.xml`, `core/bootstrap.php`, `config.local.example.php`, `.gitignore`, `tests/php/BootstrapTest.php`, `docs/setup-local.md`

**Interfaces:**
- Produces: `require 'core/bootstrap.php'` registra autoload `SpaceGame\X\Y` → `core/X/Y.php`, imposta `date_default_timezone_set('UTC')`, converte warning in `ErrorException`.

- [ ] **Step 1:** `git init`; `.gitignore` con `vendor/`, `config.local.php`, `node_modules/`.
- [ ] **Step 2:** Verifica ambiente: `php -v` (≥ 8.1, estensioni `pdo_mysql`, `json`, `mbstring`), `mysql --version`. Se mancano scrivi in `docs/setup-local.md` come installare XAMPP e fermati chiedendo all'utente.
- [ ] **Step 3:** Test `BootstrapTest::test_autoload_registered`: `assertTrue(\SpaceGame\autoload_registered())` e `assertSame('UTC', date_default_timezone_get())`.
- [ ] **Step 4:** Implementa bootstrap con funzione `SpaceGame\autoload_registered(): bool`.
- [ ] **Step 5:** `composer install` → `vendor/bin/phpunit` PASS. Commit `chore: scaffolding`.

### Task 2: Config caricato e validato

**Files:** Create `core/Config.php`, `core/ConfigSchema.php`, `game_config.json`, `tests/php/ConfigTest.php`

**Interfaces:**
- Produces: `Config::fromFile(string $path): Config` · `Config::fromArray(array $data): Config` · `$cfg->get(string $dotPath): mixed` (lancia `ConfigError` con il percorso se manca) · `$cfg->toJson(): string` · `ConfigError extends \RuntimeException`.
- Struttura JSON (chiavi di primo livello): `settings`, `galaxy`, `resources`, `buildings`, `moon_buildings`, `research`, `ships`, `defenses`, `missiles`, `upgrades`, `combat`, `fleet`, `moon`, `power`, `cpu`, `pirates`, `gauntlet`, `admiral`, `mining`, `market`, `diplomacy`, `events`, `log`, `format`, `realtime`.
- Ogni struttura: `{ "cost": {"m":..,"c":..,"d":..,"lu":..}, "growth": x, "requires": {"building.robot":2,"research.ioni":2}, "energy": {"a":..,"b":..} | {"flat_per_level":..}, "effect": {...}, "fields": 1 }`.
- Ogni unità: `cost, attack, shield, hull, speed, cargo, fuel, initiative, weight, drive, requires, ability{...}, matrix{target:mult}`.

- [ ] **Step 1:** Test: `test_get_dot_path` (`get('ships.light_fighter.attack') == 45`), `test_missing_key_names_path` (eccezione contiene `ships.nope`), `test_invalid_json_reports_line`, `test_schema_rejects_negative_cost`, `test_schema_requires_every_ship_field`.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** Implementa. Scrivi `game_config.json` **completo** copiando **tutte** le tabelle della spec §2-§18 (chiavi in snake_case inglese, es. `light_fighter`, `metal_mine`, `lunar_nanoforge`). Le formule `a·L·b^L` diventano `{"a":40,"b":1.08}`.
- [ ] **Step 4:** PASS + test `test_shipped_config_is_valid` che carica `game_config.json`. Commit.

### Task 3: Formule pure e formattazione numeri

**Files:** Create `core/Formula.php`, `core/Format.php`, `public/js/format.js`, `tests/php/FormulaTest.php`, `tests/php/FormatTest.php`, `tests/js/format.test.mjs`

**Interfaces:**
- `Formula::levelCurve(float $a, float $b, int $L): float` = a·L·b^L
- `Formula::cost(array $base, float $growth, int $L): array` costo del livello L (L≥1) = base × growth^(L−1)
- `Formula::buildHours(array $cost, float $speedDivisor, int $nanite, float $gameSpeed, float $naniteFactor): float` = (m+c+d)/3000 ÷ divisor × naniteFactor^nanite ÷ gameSpeed (3000 e 0,25 da config)
- `Formula::upgradeCost(array $unitCost, int $L, array $cfg): array` (incl. Lu da L11)
- `Format::short(float $x): string` ("12,45 Mld"), `Format::long(float $x): string`, `Format::parse(string $s): ?float`
- JS: `formatShort(x)`, `formatLong(x)`, `parseAmount(s)` stessi risultati.

- [ ] **Step 1:** Test: `levelCurve(40,1.08,10) ≈ 863.57`; `cost(['m'=>80,'c'=>20],1.55,3)` → m=192.2, c=48.05; `buildHours` con robot 0 naniti 0 speed 1 per 3000 risorse = 1.0; naniti 1 → 0.25; `upgradeCost` L11 ha `lu=50`. Format: `short(12450000000)`="12,45 Mld", `short(999)`="999", `short(1.5e15)`="1,50 Bld", `short(1.23e66)`="1,23e66", `parse('2,5Mln')`=2500000, `parse('5k')`=5000, `parse('')`=null, `parse('-3')`=null, `parse('abc')`=null, `short(NAN)`="0".
- [ ] **Step 2-4:** FAIL → implementa (sigle da `format.scale` in config, passate al JS via API `/api/config/format`) → PASS (`vendor/bin/phpunit`, `node tests/js/format.test.mjs`). Commit.

### Task 4: Schema DB, installazione, connessione, lock

**Files:** Create `install/schema.sql`, `install/install.php`, `core/Db.php`, `tests/php/DbTest.php`

**Interfaces:**
- `Db::fromLocalConfig(): Db`, `$db->pdo(): \PDO`, `$db->tx(callable $fn): mixed`, `$db->withGameLock(int $gameId, callable $fn, int $timeoutSec = 5): mixed` (usa `GET_LOCK`/`RELEASE_LOCK`, lancia `LockBusy`).
- Tabelle (InnoDB, utf8mb4) con colonne minime:
  - `users(id, username UNIQUE, pass_hash)`
  - `games(id, user_id, name, speed DOUBLE, cpu_res_mult DOUBLE, cpu_research_mult DOUBLE, settings_json, config_json, processed_until DOUBLE, created_at)`
  - `empires(id, game_id, kind ENUM('player','cpu','simple','pirate'), name, personality, settings_json)`
  - `bodies(id, game_id, empire_id NULL, g, s, p, is_moon, moon_of NULL, type, temp_class, fields_base, fields_bonus, res_m, res_c, res_d, res_lu, energy_store, resistance, last_update DOUBLE, destroyed_until DOUBLE NULL, move_json NULL)` UNIQUE(game_id,g,s,p,is_moon)
  - `buildings(body_id, code, level, completed_at DOUBLE)` PK(body_id,code)
  - `research(empire_id, code, level)` · `upgrades(empire_id, unit_code, branch, level)`
  - `units(body_id, code, qty DOUBLE, damage DOUBLE)` · `queues(id, game_id, body_id, empire_id, kind, slot, code, qty, position, state, started_at, ends_at)`
  - `fleets(id, game_id, empire_id, mission, origin_id, target_g, target_s, target_p, target_moon, units_json, cargo_json, speed_pct, depart_at, arrive_at, return_at, hold_until, state, extra_json)`
  - `events(id, game_id, at DOUBLE, type, ref_id, payload_json, done TINYINT)` INDEX(game_id, done, at)
  - `reports, log, debris(game_id,g,s,p,m,c,lu,updated_at), asteroid_progress(empire_id,g,s,p,stratum,remaining,day), market(game_id,res,rate), offers, relations(game_id,a,b,score), pacts, pirate_levels(empire_id,level), admiral(empire_id,skill,level,medals), scores`
- [ ] **Step 1:** Test (DB di test `spacegame_test` da `config.local.php`): `test_install_creates_tables`, `test_lock_excludes_second_holder` (seconda connessione riceve `LockBusy`), `test_tx_rolls_back_on_exception`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

### Task 5: Login, partite, generazione galassia

**Files:** Create `core/Auth.php`, `core/Games.php`, `core/Galaxy.php`, `core/Api/AuthController.php`, `core/Api/GamesController.php`, `public/api/index.php`, `tests/php/GalaxyTest.php`, `tests/php/GamesTest.php`

**Interfaces:**
- `Auth::login(string $u, string $p): bool`, `Auth::requireUser(): int`, `Auth::csrfToken(): string`, `Auth::checkCsrf(string $t): void`; `install.php` crea l'utente con `password_hash`.
- `Games::create(int $userId, array $settings): int` valida range §2, salva `config_json` = copia del file; `Games::refreshConfig(int $gameId): void`; `Games::delete(int $gameId)`; `Games::list(int $userId): array`.
- `Galaxy::generate(int $gameId, array $settings, Config $cfg, int $seed): void` crea pianeti CPU vive (raggruppati in imperi 1–3 pianeti, tetto `cpu.max_live_empires`), CPU semplificate, pianeta madre player, Warp (pos 16), asteroidi (17–20). Dimensione pianeti CPU = base × `cpu_planet_size%`.
- Router: `POST /api/login`, `POST /api/logout`, `GET/POST/DELETE /api/games`, risposta `{ok, data|error}`; tutte le POST richiedono CSRF.
- [ ] **Step 1:** Test: `test_density_matches_setting` (±2%), `test_live_share_70_percent` (±3%), `test_live_empires_capped`, `test_cpu_planet_fields_scaled` (al 50% = 1000), `test_player_home_has_2000_fields`, `test_every_system_has_warp_and_4_asteroids`, `test_settings_out_of_range_rejected` (speed 101, cpu mult > speed), `test_config_copied_into_game`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

---

## Milestone B — Tempo, economia, code

### Task 6: Clock ed EventBus

**Files:** Create `core/Clock.php`, `core/EventBus.php`, `tests/php/ClockTest.php`

**Interfaces:**
- `EventBus::schedule(int $gameId, float $at, string $type, int $refId, array $payload): int` · `EventBus::cancel(int $eventId)` · `EventBus::register(string $type, callable $handler)` con handler `fn(array $event, GameContext $ctx): void`.
- `Clock::catchUp(int $gameId, float $now, float $budgetSec): bool` — dentro `withGameLock`; elabora eventi `done=0 AND at<=now` in ordine `(at, id)`, prima di ognuno porta l'economia dei corpi coinvolti a `at`; si ferma a `budgetSec` e ritorna `false` se rimane lavoro; aggiorna `games.processed_until`.
- `GameContext` (stesso file): `game`, `config`, `db`, `now` (istante dell'evento).
- [ ] **Step 1:** Test: `test_events_run_in_time_order`, `test_chunked_equals_single_pass` (stessa partita clonata: un `catchUp` vs 50 chiamate con budget minimo → stato DB identico), `test_event_processed_once` (due catchUp concorrenti simulati → handler chiamato 1 volta), `test_returns_false_when_budget_exhausted`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

### Task 7: Economia ed energia

**Files:** Create `core/Economy.php`, `core/Energy.php`, `tests/php/EconomyTest.php`, `tests/php/EnergyTest.php`

**Interfaces:**
- `Energy::balance(array $body, array $empireResearch, Config $cfg): array{produced: float, required: array<string,float>, factorByConsumer: array<string,float>}` — fonti §4.2 (posizione, tipo, temperatura, Energetica, satelliti), priorità di carenza §4, Energy Storage.
- `Economy::ratesPerHour(array $body, array $research, array $empireMods, Config $cfg): array{m,c,d,lu}` incl. produzione base, bonus tipo/posizione, Plasma, Geologia, Deep Core Drill, moltiplicatore CPU.
- `Economy::advance(array &$body, float $toTime, ...): void` esatto, con cap magazzino (§4.3), Energy Storage che si carica/scarica, consumo D della fusione e Lu dell'antimateria.
- `Economy::credit(array &$body, array $res, Config $cfg): void` — aggiunge fino a 2× capacità.
- `Economy::protectedAmount(array $body, string $res, Config $cfg): float`.
- [ ] **Step 1:** Test: `test_metal_mine_level_10_rate` (= 863.57 + base 20, speed 1), `test_speed_multiplies`, `test_production_stops_at_cap`, `test_overflow_capped_at_double`, `test_energy_deficit_scales_mines` (80% → 80%), `test_storage_discharges_before_deficit`, `test_desert_solar_bonus_20`, `test_position_1_solar_plus_30_deut_minus_30`, `test_geothermal_position_13_minus_50`, `test_protected_10_percent_plus_bunker`, `test_cpu_multiplier_applies_only_to_cpu`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

### Task 8: Code (edifici, ricerca) con prerequisiti e spazi

**Files:** Create `core/Queue.php`, `core/Requirements.php`, `core/Actions/Build.php`, `core/Actions/Research.php`, `core/Api/BuildController.php`, `tests/php/QueueTest.php`, `tests/php/RequirementsTest.php`

**Interfaces:**
- `Requirements::met(array $requires, array $bodyBuildings, array $research, ?array $moonOfBody): bool` e `::missing(...): array`.
- `Queue::enqueue(int $bodyId, string $kind /* building|research|shipyard|upgrade|silo */, string $code, float $qty, int $slot = 0): int` (illimitata)
- `Queue::tryStart(int $queueId, GameContext $ctx): bool` — scala risorse all'avvio, se mancano stato `waiting` e programma evento `queue_retry` all'istante esatto calcolato con `Economy` (tempo in cui le risorse bastano; se mai, nessun evento e retry al prossimo cambio economico).
- `Queue::reorder`, `Queue::cancel` (rimborso 100% se avviato), slot paralleli edifici = 1 + floor(ConstructionHub/5).
- Spazi: `Fields::total(array $body, Config)`, `Fields::used(array $body)`; costruzione rifiutata se `used >= total`.
- `Actions\Build::queue(int $empireId, int $bodyId, string $code)` e `Actions\Research::queue(...)` — usate da API e CPU.
- [ ] **Step 1:** Test: `test_cost_scaled_at_start_not_enqueue`, `test_waiting_item_starts_at_exact_time`, `test_parallel_slots_from_construction_hub`, `test_requirements_block_queue`, `test_fields_full_blocks_build`, `test_terraformer_adds_200_without_using_field`, `test_research_uses_lab_and_nanite_of_start_body`, `test_nanite_level_2_divides_by_16`, `test_cancel_refunds_running_item`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

---

## Milestone C — Unità

### Task 9: Cantiere, difese, silo, potenziamenti

**Files:** Create `core/Shipyard.php`, `core/Upgrades.php`, `core/Actions/Shipyard.php`, `core/Actions/Upgrade.php`, `core/Api/ShipyardController.php`, `tests/php/ShipyardTest.php`, `tests/php/UpgradesTest.php`

**Interfaces:**
- `Shipyard::slots(array $body): int` = 1 + floor(AssemblyLine/5).
- `Shipyard::maxAffordable(array $unitCost, array $freeRes): float`.
- `Shipyard::distribute(array $items, int $slots, string $mode /* units|resources|time */): array<int,array>` — ripartizione per slot.
- `Actions\Shipyard::start(int $bodyId, array $plan)` lotti per slot (unità prodotte una alla volta: ogni completamento è un evento `unit_done`, le risorse del lotto si scalano all'avvio del lotto).
- `Actions\Shipyard::stop(int $queueId)` → tiene unità completate, rimborsa intero il resto (`Economy::credit`).
- Silo: capacità = 6 + 3·(L−1), 1 slot per missile; coda `silo`.
- `Upgrades::level(int $empireId, string $unit, string $branch): int`, `Upgrades::effect(...)`, rami §9; `Actions\Upgrade::queue(...)`.
- `Stats::effective(string $unitCode, int $empireId, array $ctx): array{attack,shield,hull,speed,cargo,fuel,initiative,dodge,hangar}` in `core/Stats.php` — somma base × tecnologie × potenziamenti × Ammiraglio (Task 17 lo estende).
- [ ] **Step 1:** Test: `test_max_affordable_uses_last_unit`, `test_distribute_equal_units_900_over_3`, `test_distribute_equal_time_finish_together` (scarto ≤ un'unità più lenta), `test_distribute_equal_resources`, `test_stop_keeps_done_refunds_rest`, `test_silo_capacity_l1_6_l5_18`, `test_upgrade_cost_growth_1_25`, `test_upgrade_l11_needs_lunarite`, `test_mothership_max_one`, `test_mothership_only_on_moon`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

---

## Milestone D — Flotte

### Task 10: Volo, missioni base, spionaggio

**Files:** Create `core/Fleet.php`, `core/Espionage.php`, `core/Actions/SendFleet.php`, `core/Api/FleetController.php`, `tests/php/FleetTest.php`, `tests/php/EspionageTest.php`

**Interfaces:**
- `Fleet::distance(array $a, array $b, Config): float` (§10), `Fleet::flightSeconds(float $dist, float $fleetSpeed, int $pct, float $gameSpeed): float`, `Fleet::fuel(array $units, float $dist, int $pct, ...): float`, `Fleet::slowestSpeed(array $units, int $empireId): float` (navi in hangar escluse; capacità hangar verificata con i pesi).
- `Actions\SendFleet::send(int $empireId, int $originBodyId, array $units, array $target, string $mission, int $speedPct, array $cargo, array $extra): int` — controlla flotte contemporanee, sosta (`hold_until`), carburante, colonie max, anti-spazzolatura; programma eventi `fleet_arrive` e `fleet_return`.
- `Actions\SendFleet::recall(int $fleetId)`.
- Missioni qui: transport, station, colonize, spy (le altre si aggiungono negli handler dei task successivi tramite `Fleet::registerMission(string $code, callable $onArrive)`).
- Arrivo: imposta `hold_until = now_real + 30`.
- `Espionage::report(array $attacker, array $target, int $probes): array` e `::detected(...): bool` (§10).
- [ ] **Step 1:** Test: `test_distance_same_system`, `test_flight_time_light_fighter_one_system` (1000/13000 h a x1), `test_speed_pct_50_doubles_time`, `test_fuel_quadratic_speed`, `test_hangar_ships_no_fuel`, `test_hangar_over_capacity_rejected`, `test_hold_30_real_seconds_blocks_departure`, `test_recall_returns_after_elapsed`, `test_colony_limit_astrophysics`, `test_spy_levels_by_score`, `test_spy_detection_clamped_95`, `test_same_attacker_max_3_per_24h`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

### Task 11: Teleporter, Phalanx, Radar

**Files:** Create `core/Actions/Teleport.php`, `core/Sensors.php`, `tests/php/SensorsTest.php`

**Interfaces:**
- `Actions\Teleport::jump(int $empireId, int $fromBodyId, int $toBodyId, array $units)` — capacità 1M peso × L, costo 1 D/10 peso, ricarica 2 h di gioco ÷ L su entrambi, rispetta `hold_until`, consuma energia (blocco se energia insufficiente).
- `Sensors::phalanxScan(int $bodyId, array $target): array` gittata 2^(L−1) sistemi, 5000 D, attesa 10 min di gioco, bloccata da Jamming Tower (probabilità).
- `Sensors::radarWarnings(int $empireId): array` flotte ostili entro L sistemi.
- [ ] **Step 1:** Test: `test_teleport_capacity_by_weight`, `test_teleport_cooldown_both_ends`, `test_phalanx_range_doubles`, `test_phalanx_blocked_by_jamming_with_seed`, `test_radar_only_hostile_in_range`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

---

## Milestone E — Battaglia

### Task 12: Simulatore di battaglia

**Files:** Create `core/Combat.php`, `core/Power.php`, `tests/php/CombatTest.php`, `tests/php/PowerTest.php`

**Interfaces:**
- `Combat::simulate(array $attackers, array $defenders, Config $cfg, int $seed): BattleResult` — input: liste `['code'=>..., 'qty'=>..., 'stats'=>Stats::effective(...)]`, difese con flag `is_defense`, `planetary_shield`.
- `BattleResult` (stesso file, readonly): `winner ('attacker'|'defender'|'draw')`, `rounds array`, `attackerLosses`, `defenderLosses`, `survivorsA`, `survivorsD`.
- Algoritmo (§11): pre-battaglia Torpedo → lancio hangar → 6 round; per round: scudi pieni, ordina per iniziativa, gruppi di `combat.initiative_group` (10), fuoco simultaneo dentro il gruppo, danni a fine gruppo; bersaglio casuale (seed); matrice ++/−−; abilità; rimbalzo < 1% scudo; esplosione sotto 30%; schivata (max 40%); Planetary Shield assorbe prima delle difese. Per performance raggruppa unità identiche in **stack** con danno aggregato (non istanze singole).
- `Power::unitPower(array $stats, Config): float`, `Power::fleetPower(array $stacks): float`, `Power::relative(array $mine, array $theirs): float`, `Power::empire(int $empireId): array{total,fleet,defense,structures,research,admiral}`.
- [ ] **Step 1:** Test: `test_same_seed_same_result`, `test_cruisers_beat_light_fighters_equal_cost`, `test_heavy_fighters_beat_battleships_equal_cost`, `test_initiative_dead_units_dont_fire`, `test_battlecruiser_fires_first`, `test_draw_after_6_rounds`, `test_shield_bounce_under_1_percent`, `test_torpedo_kills_carrier_before_launch_contents_lost`, `test_planetary_shield_absorbs_first`, `test_power_lf_about_170` (±15%), `test_power_relative_matrix`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Inoltre test di prestazione: 1M unità per lato in < 1 s (`@group perf`). Commit.

### Task 13: Attacco, saccheggio, detriti, spazi, riparazioni, missili

**Files:** Create `core/Loot.php`, `core/Debris.php`, `core/Missiles.php`, `core/Missions/Attack.php`, `core/Missions/Recycle.php`, `core/Missions/Missile.php`, `tests/php/AttackTest.php`, `tests/php/MissilesTest.php`

**Interfaces:**
- `Missions\Attack::onArrive(array $fleet, GameContext $ctx): void` → combat, report, loot, debris, `Fields::applyBattle(int $bodyId, string $outcome /* def_win|att_win|draw|bomb_win|bomb_fail */)` (+50/−50/0/−100/+50, mai sotto la base), riparazione 70% difese, Repair Bay/Orbital Dock al rientro, Mothership 20%.
- `Loot::take(array $body, float $cargoCapacity, array $priority, Config): array` 100% oltre quota protetta.
- `Debris::add(...)`, `Debris::decay(...)` 5%/giorno di gioco, `Missions\Recycle`.
- `Missiles::launch(int $empireId, int $siloBodyId, array $targetPlanet, int $count)` velocità 80M, gittata §11, solo pianeti.
- `Missiles::impact(...)`: per ogni missile un ABM (se c'è) con 10% di fallimento; i passanti fanno 1.000.000 × bonus su navi a terra e difese a caso, ignorando scudi; difese distrutte 50% perse / 50% relitti riparabili (50% costo, 50% tempo); danneggiate +10%/h.
- [ ] **Step 1:** Test: `test_loot_respects_protected_and_cargo`, `test_debris_30_ships_10_defenses`, `test_no_debris_from_pirates`, `test_fields_def_win_plus_50`, `test_fields_never_below_base`, `test_defense_repair_70`, `test_abm_fail_rate_10_percent_seeded`, `test_missile_ignores_shield`, `test_missile_wrecks_half_repairable`, `test_missile_cannot_target_moon`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

---

## Milestone F — Luna

### Task 14: Luna completa

**Files:** Create `core/Moon.php`, `core/Missions/Bombard.php`, `core/Missions/MoveMoon.php`, `core/Actions/MassDriver.php`, `tests/php/MoonTest.php`

**Interfaces:**
- `Moon::create(int $planetId)` all'avvio NF1 (fallisce se pianeta ha già luna o `destroyed_until > now_real`).
- `Moon::maxResistance(array $moon, array $research, Config): float`, `Moon::state(float $pct): string` (integra/danneggiata/critica/collasso), `Moon::applyStateModifiers(...)`, riparazione automatica in `Economy::advance` per lune, `Actions\Moon::emergencyRepair(int $moonId)` (ogni 6 h di gioco).
- `Missions\Bombard::onArrive` (solo con Dreadnought/Mothership/Bomber; danno = attacco sopravvissuto × 3 ridotto da Corazza), distruzione → `Moon::destroy` (perdite §5.2, detriti 20%, `destroyed_until = now_real + 48h`).
- `Missions\MoveMoon` (ricerca Death Star): 10 sistemi/h di gioco +5%/livello, salto galassia = 200 sistemi, solo verso pianeti senza luna, ricarica 24 h reali, durante il viaggio coordinate correnti interpolate (attaccabile lì).
- `Actions\MassDriver::send(int $moonId, array $res)` 100k×L/h verso il pianeta d'origine (e viceversa), ignora distanza se la luna si è spostata.
- Strutture lunari di §5.2 lette da `moon_buildings`; Cryo Hangar nasconde navi da spy/phalanx; Interdiction Field rallenta flotte nemiche in arrivo; Decoy gonfia i report.
- [ ] **Step 1:** Test: `test_moon_starts_1000_fields_plus_200`, `test_one_moon_per_planet`, `test_resistance_thresholds`, `test_only_heavy_ships_damage_moon`, `test_destroy_sets_48h_real_cooldown`, `test_move_moon_speed_and_targets`, `test_moved_moon_blocks_owner_moon`, `test_mass_driver_any_distance_after_move`, `test_cryo_hangar_hidden_from_spy`, `test_interdiction_slows_enemy_only`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

---

## Milestone G — CPU, diplomazia, mercato

### Task 15: CPU semplificate e CPU vive

**Files:** Create `core/Cpu/Simple.php`, `core/Cpu/Brain.php`, `core/Cpu/Personality.php`, `tests/php/CpuTest.php`

**Interfaces:**
- `Cpu\Simple::stateAt(array $body, float $gameHours, string $tier, Config): array` (formula §13, nessuna simulazione passo-passo).
- `Cpu\Brain::tick(int $empireId, GameContext $ctx): void` — evento `cpu_tick` ricorrente ogni 30–60 min di gioco (jitter con seed); sequenza §13; usa **solo** `Actions\*`.
- `Cpu\Personality::weights(string $p): array` (tabella §13), `Cpu\Brain::shouldAttack(float $relPower, string $personality, string $difficulty): bool` (soglia 1,3 modificata).
- [ ] **Step 1:** Test: `test_simple_cpu_grows_with_sqrt_time`, `test_brain_cannot_exceed_player_rules` (azioni fallite non vengono aggirate), `test_cpu_multiplier_capped_by_player_speed`, `test_aggressive_attacks_weaker_target`, `test_defensive_saves_fleet_when_radar_warns`, `test_tick_uses_only_actions` (mock di `Actions` conta le chiamate, nessuna scrittura diretta su `bodies`).
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

### Task 16: Mercato e diplomazia

**Files:** Create `core/Market.php`, `core/Diplomacy.php`, `core/Actions/Trade.php`, `core/Actions/Pact.php`, `tests/php/MarketTest.php`, `tests/php/DiplomacyTest.php`

**Interfaces:**
- `Market::rate(string $from, string $to): float`, `Actions\Trade::exchange(int $bodyId, string $from, string $to, float $amount)` senza commissione, tasso aggiornato col volume, ritorno 2%/h verso la base; Trade Center ed Economia galattica migliorano il tasso.
- Offerte CPU: `Market::postOffer`, `Actions\Trade::acceptOffer` → missione `deliver_offer` con pagamento all'arrivo.
- `Diplomacy::score(int $a, int $b): float`, `Diplomacy::adjust(...)`, `Diplomacy::status(float $score): string`, patti (non aggressione, alleanza da Embassy 3, tributo da Embassy 5), coalizioni quando Power player > 3× media CPU vive (se attive).
- [ ] **Step 1:** Test: `test_no_commission`, `test_selling_metal_lowers_metal_rate`, `test_rate_recovers_towards_base`, `test_status_thresholds`, `test_attack_lowers_score`, `test_breaking_pact_penalty_all_cpu`, `test_coalition_triggers_over_3x`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

---

## Milestone H — Contenuti PvE

### Task 17: Pirati, Rift, Gauntlet, Ammiraglio

**Files:** Create `core/Pirates.php`, `core/Gauntlet.php`, `core/Admiral.php`, `core/Missions/Pirates.php`, `core/Missions/GauntletRun.php`, `tests/php/PiratesTest.php`, `tests/php/GauntletTest.php`

**Interfaces:**
- `Pirates::fleetForLevel(int $level, int $seed): array` PF = 50.000 × 1,18^level; `Pirates::level(int $empireId)` unico per impero; vittoria = cattura tutte le navi + livello +1 + bottino; sconfitta = navi perse.
- `Pirates::spawnRift(GameContext)` (evento 2/giorno reale, durata 6 h, PF 15–30% della PF media delle flotte del player), primo arrivato vince, non cambia livello.
- `Gauntlet::waveFleet(int $wave, int $seed)` PF = 30.000 × 1,12^wave; `Missions\GauntletRun` ondate continue con scudi rigenerati e perdite permanenti; Medaglie 1,1^wave subito accreditate; regole di ritiro `{maxWave, maxLossPct, minRelPower}`; una corsa per impero; parte solo da luna.
- `Admiral::buy(int $empireId, string $skill)` costo 10 × 1,15^L; `Admiral::modifiers(int $empireId): array` integrato in `Stats::effective`.
- [ ] **Step 1:** Test: `test_pirate_level_shared_across_warps`, `test_pirate_win_captures_all`, `test_pirate_loss_keeps_level`, `test_rift_power_range`, `test_gauntlet_rewards_each_wave`, `test_gauntlet_retreat_rules`, `test_gauntlet_only_from_moon`, `test_admiral_bonus_all_fleets`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

### Task 18: Miner, asteroidi, eventi casuali, spedizioni

**Files:** Create `core/Mining.php`, `core/Missions/Mine.php`, `core/RandomEvents.php`, `core/Missions/Expedition.php`, `tests/php/MiningTest.php`, `tests/php/RandomEventsTest.php`

**Interfaces:**
- `Mining::stratumReserve(int $s, string $res): float` 100k × 1,2^s (Lu × 0,1); `Mining::accessibleStrata(int $empireId, array $asteroid, string $day): int` = 1 + Profondità + Mining Control/5 + (3 se Morbido); `Mining::softTypeOf(string $dateRome): string` (deterministico per partita + data); reset a mezzanotte `Europe/Rome` (attenzione ora legale: usa `DateTimeImmutable` con fuso).
- `Missions\Mine::onArrive` estrae nel tempo (2.000/h × fattori), contesa §14.
- `RandomEvents::roll(GameContext)` evento ricorrente; tabella §15 con probabilità da config ridotte da Weather Control/Climatologia; campi asteroidi 3/giorno reale 12 h.
- `Missions\Expedition` esito casuale per dimensione flotta.
- [ ] **Step 1:** Test: `test_reserve_growth`, `test_depth_skill_unlocks_strata`, `test_soft_asteroid_plus_3_strata_plus_50`, `test_reset_at_rome_midnight_dst` (date 2026-03-29 e 2026-10-25), `test_progress_per_empire`, `test_unescorted_miners_lose_cargo_and_half`, `test_weather_control_reduces_negative_events`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

### Task 19: Classifica, registro, notifiche, pulizia, cron

**Files:** Create `core/Log.php`, `core/Scores.php`, `core/Api/InfoController.php`, `cron.php`, `tests/php/LogTest.php`

**Interfaces:**
- `Log::add(int $empireId, string $category, array $data)`, `Log::list(int $empireId, array $filters, int $page)`, `Log::sinceLastVisit(int $empireId): array` (riepilogo "mentre eri via"), `Log::purge()` 30/90 giorni reali.
- `Scores::recalc(int $gameId)` dopo ogni catchUp completo (totale, flotte, difese, strutture, ricerca, Gauntlet).
- `cron.php?key=...` chiama `Clock::catchUp` per tutte le partite con budget 20 s.
- API: `GET /api/state` (stato completo del corpo attivo + flotte + code + eventi del giorno + `serverTime`), `GET /api/log`, `GET /api/scores`.
- [ ] **Step 1:** Test: `test_purge_keeps_90_days_reports`, `test_since_last_visit`, `test_scores_categories`, `test_cron_rejects_wrong_key`.
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

---

## Milestone I — Interfaccia

Ogni task UI: verifica manuale con viewport 375×812 (DevTools Android) + test JS delle funzioni pure. Nessuna logica di gioco nel JS: il JS chiama le API e anima i timer da `serverTime`.

### Task 20: Shell PWA, login, partite, barra alta/bassa

**Files:** Create `public/index.html`, `public/css/app.css`, `public/js/app.js`, `public/js/api.js`, `public/js/timers.js`, `public/manifest.webmanifest`, `public/sw.js`, `tests/js/timers.test.mjs`
- `api.js`: `api(method, path, body)` con CSRF e gestione `{ok,error}`.
- `timers.js`: `remaining(endsAtServer, serverOffset): number`, `formatDuration(sec): string` ("2g 03:14:07").
- Token colori in `:root` (tema scuro), target ≥ 48 px, barra alta (corpo attivo con swipe, risorse/h, energia, Power), barra bassa 5 tab.
- [ ] Test `formatDuration(93247)` = "1g 01:54:07"; offset server applicato. Verifica manuale installazione PWA su Android. Commit.

### Task 21: Dash con flotte in volo

**Files:** Create `public/js/views/dash.js`, `tests/js/dash.test.mjs`
- `fleetProgress(fleet, now): {phase: 'outbound'|'hold'|'return', pct: number}`; ordinamento: ostili prima, poi per arrivo.
- Card per missione (colori da token), countdown al secondo, barra andata/sosta/ritorno, anello sosta 30 s, vibrazione `navigator.vibrate` per ostili nuovi, filtri, tocco → dettagli + Richiama; code attive; eventi del giorno; ultimi report; riepilogo "mentre eri via" all'apertura; notifiche browser (`Notification`) a gioco aperto per attacco in arrivo, Rift, coda ferma.
- [ ] Test `fleetProgress` per le tre fasi e ordinamento ostili. Commit.

### Task 22: Costruisci + Cantiere

**Files:** Create `public/js/views/build.js`, `public/js/views/shipyard.js`, `public/js/shipyard-math.js`, `tests/js/shipyard-math.test.mjs`
- `shipyard-math.js` replica in JS (per l'anteprima) `maxAffordable` e `distribute` del Task 9 con gli stessi casi di test; la conferma usa sempre l'API.
- Slider 0–100% dinamico, campo numerico con `parseAmount`, −1/+1/Max, tocco ciclico sugli slot (S1…Sn → disattiva), pulsanti Unità/Risorse/Tempi uguali, anteprima barre con trascinamento, Stop per slot, preset (salvati via API in `empires.settings_json`).
- [ ] Test JS: stessi casi di `ShipyardTest` → stessi numeri. Commit.

### Task 23: Flotte, mappa, altro

**Files:** Create `public/js/views/fleet.js`, `public/js/views/map.js`, `public/js/views/more.js`
- Invio flotta in 3 passi con anteprima consumo/tempo/PF relativa (da `GET /api/fleet/preview`), simulatore (`POST /api/combat/simulate`), regole Gauntlet.
- Mappa galassia (griglia sistemi con icone) e sistema (posizioni 1–20), pinch-zoom (pointer events), swipe tra sistemi.
- Altro: impero, classifica, diplomazia, mercato, registro, impostazioni partita (solo moltiplicatori CPU modificabili), "aggiorna parametri", cambio partita, logout.
- [ ] Verifica manuale end-to-end: crea partita x100, costruisci miniera, invia sonda, attacca un pianeta semplificato, controlla report. Commit.

---

## Milestone J — Pubblicazione

### Task 24: Guida Aruba

**Files:** Create `docs/deploy-aruba.md`
- Passi: creare DB MySQL dal pannello Aruba; caricare via FTP `public/` come root web (o `public/*` nella root e `core/`, `game_config.json` sopra la root se il piano lo consente, altrimenti protetti con `.htaccess` `Require all denied`); copiare `config.local.example.php` in `config.local.php` e far compilare le credenziali **all'utente**; aprire `install/install.php` una volta, poi cancellare `install/`; opzionale: cron Aruba o servizio esterno su `cron.php?key=`.
- [ ] Verifica: checklist eseguita su un'installazione locale pulita (copia della cartella "come su Aruba") senza errori. Commit.
