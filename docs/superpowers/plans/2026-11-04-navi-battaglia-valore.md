# SpaceGame Implementation Plan — Navi, Battaglia, Valore (2026-11-04)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementare il nuovo design basato su "valore" per navi, ricerche, potenziamenti e battaglia, come definito in `docs/design-modifiche.md` e nella specifica aggiornata `docs/superpowers/specs/2026-11-04-navi-battaglia-valore-design.md`.

**Architecture:** API JSON in PHP 8 senza framework; simulazione a eventi; tutte le regole e i numeri letti da `game_config.json`; frontend JS vanilla.

**Tech Stack:** PHP ≥ 8.1, PDO MySQL (MySQL ≥ 5.7 / MariaDB ≥ 10.3), PHPUnit 10 (solo sviluppo, via Composer), JS ES2020 vanilla, Node ≥ 18 solo per i test JS.

**Spec:** `docs/superpowers/specs/2026-11-04-navi-battaglia-valore-design.md` — **leggila prima di ogni task**: i numeri citati come "§N" vanno copiati da lì in `game_config.json`.

## Global Constraints

- Nessun numero di gioco nel codice PHP/JS: solo letture da `Config` (eccezioni: 0, 1, indici).
- CPU e player usano **le stesse** funzioni d'azione (`Actions\*`); nessun parametro "isCpu" che salti controlli.
- Quantità di gioco in `DOUBLE` (DB) / `float` (PHP). Tempi in secondi Unix `float` (DB `DOUBLE`).
- Tempo di gioco = tempo reale × `games.speed`. Sono **reali** (non accelerati).
- Nessuna dipendenza runtime: in produzione si caricano solo `public/`, `core/`, `install/`, `cron.php`, `game_config.json`.
- Namespace `SpaceGame\`, autoload PSR-4 da `core/` tramite `core/bootstrap.php` (niente Composer in produzione).
- UI in italiano; nomi navi/difese/strutture in inglese come da spec.
- Mobile-first: target tocco ≥ 48 px, nessuna interazione solo-hover.
- Ogni task termina con test verdi (`vendor/bin/phpunit`) e un commit.

## Review Focus (specifico per questo piano)

1. **Valore coerente ovunque**: il valore di navi, ricerche, potenziamenti deve essere calcolato con gli stessi pesi (M×1, C×1.5, D×2, Lu×5) e confrontabile — test `StatsTest::testShipValueMatchesConfig`, `PowerTest::testResearchValueMatchesCost`, `UpgradesTest::testUpgradeValueMatchesCost`.
2. **Proporzionalità ricerca (e=0.25)**: ogni raddoppio della spesa dà +19% — test `FormulaTest::testResearchMultiplierGrowthRate`.
3. **Fuoco rapido calcolato da formula**: niente matrice hardcoded — test `CombatTest::testRapidFireCalculatedFromFormula`.
4. **Battaglia per stack (OPBE)**: scala a milioni di navi — test `CombatTest::testMillionUnitsPerSideUnder1s` (`@group perf`).
5. **Conveyor produce valore/s costante per classe**: Light +10 unità/s livello; Medium/Heavy stesso valore/s — test `ShipyardTest::testConveyorValuePerSecondMatchesAcrossClasses`.

## Cambiamenti al codice esistente (Milestone A/B già completate)

Questi file vanno aggiornati per supportare il nuovo design. I task esistenti (1-8b) rimangono validi ma i loro output cambiano.

### Files da modificare

| File | Cambiamenti principali |
|------|------------------------|
| `game_config.json` | Nuova struttura completa: `scale` gioco (x1-x10, x1-x3000), `cpu` 50% player, `formula.research_exponent=0.25`, `formula.upgrade_exponent=0.25`, tabella navi con `value`, `class`, `requires` (tecnologie fuoco+difesa), `rapid_fire` formula per tecnologia, `conveyor` per classe, `combat` parametri stack, `power` pesi valore |
| `core/ConfigSchema.php` | Validazione nuova struttura: `scale.min/max`, `cpu.share_pct=50`, `formula.research_exponent`, `formula.upgrade_exponent`, `ships.*.class`, `ships.*.value`, `ships.*.requires` (min 1 fuoco + 1 difesa), `conveyor` tre classi, `rapid_fire` per tecnologia |
| `core/Formula.php` | Nuove funzioni: `researchMultiplier(float $baseGrowth, int $levelsExtra, float $exponent)`, `upgradeMultiplier(int $levelsExtra, float $exponent)`, `rapidFireFactor(float $attack, float $targetShieldHull, string $tech)`, `shipValue(array $cost): float` (pesi M,C,D,Lu) |
| `core/Format.php` | Scala estesa per valori fino a 10^63+ (già gestito), test parità PHP/JS per nuovi range |
| `core/Games.php` | `validateSettings()`: speed 1-10, res_mult 1-3000, cpu_res_mult = res_mult × 0.5, cpu_research_mult = res_mult × 0.5; default settings da config |
| `core/Galaxy.php` | CPU planet size = base × (cpu_res_mult / speed) × 0.5; live/simple empires usando nuovo `cpu.live_share_pct` (default 70) |
| `core/Queue.php` | `Fields::total()` legge `galaxy.base_fields_planet` e `moon.base_fields`; `applyBattle()` usa nuovi delta campi da config |
| `core/Economy.php` | Produzione risorse moltiplicata per `games.res_mult` (1-3000) invece di `games.speed`; CPU usa `cpu_res_mult` |
| `core/Energy.php` | Nessun cambiamento logico, ma valori da config aggiornato |
| `core/Clock.php` / `core/EventBus.php` | Invariati |
| `core/Actions/Build.php` / `core/Actions/Research.php` | Usano `Formula::cost()` con growth da config; costi ricerca usano growth ricerca |
| `core/Stats.php` (nuovo o esteso) | `Stats::effective()` calcola attacco/scudo/struttura/velocità con: base × ricerca^exponent × potenziamenti^exponent × Ammiraglio; `Stats::shipValue()` per singola nave inclusi potenziamenti |

### Test da aggiornare/aggiungere nei task A/B esistenti

- `ConfigTest::testNewConfigStructureValid` — valida tutta la nuova struttura
- `FormulaTest::testResearchMultiplierExponent025` — verifica regola e=0.25
- `FormulaTest::testUpgradeMultiplierExponent025` — verifica regola e=0.25 per potenziamenti
- `FormulaTest::testShipValueCalculation` — valore = M + 1.5C + 2D + 5Lu
- `GamesTest::testSettingsValidationNewScale` — speed 1-10, res_mult 1-3000, CPU = 50%
- `GalaxyTest::testCpuPlanetSizeScaledByResourceMult` — CPU pianeti scalano con res_mult
- `EconomyTest::testResourceProductionUsesResMult` — produzione × res_mult non × speed
- `QueueTest::testConveyorSlotsFromConfig` — slot conveyor da config per classe

---

## Milestone C — Unità (Navi, Difese, Conveyor, Potenziamenti) — AGGIORNATA

### Task 9: Cantiere, Difese, Silo, Potenziamenti — **RISCRITTO per nuovo design**

**Files:** Modifica `core/Shipyard.php`, `core/Upgrades.php`, `core/Actions/Shipyard.php`, `core/Actions/Upgrade.php`, `core/Api/ShipyardController.php`, `core/Stats.php` (nuovo), `core/Conveyor.php` (nuovo), `tests/php/ShipyardTest.php`, `tests/php/UpgradesTest.php`, `tests/php/ConveyorTest.php`, `tests/php/StatsTest.php`

**Interfaces:**

- `Stats::shipValue(array $unitCost, array $upgradeLevels, int $empireId): float` — valore nave = costo pesato × moltiplicatori ricerca^0.25 × potenziamenti^0.25; `Stats::effective(string $unitCode, int $empireId, array $ctx): array{attack,shield,hull,speed,cargo,fuel,initiative,dodge,hangar,rapidFire}` — statistiche complete per battaglia
- `Conveyor::slots(array $body, string $class): int` = 1 + floor(ConveyorLevel/5) per classe; `Conveyor::unitsPerSecond(int $level, string $class): float` — Light: 10 × level; Medium/Heavy: calcolati per stesso valore/s
- `Conveyor::produce(int $bodyId, string $class, array $plan): void` — produzione continua per classe; risorse scalate all'avvio lotti; eventi `unit_done` per completamento
- `Upgrades::level(int $empireId, string $unit, string $branch): int`, `Upgrades::multiplier(int $levelsExtra): float` = 1.25^(0.25 × levelsExtra); `Actions\Upgrade::queue(...)` — potenziamenti per ramo §9 (attacco, scudo, corazza, motore, stiva, carburante, iniziativa, schivata, hangar)
- `Shipyard::slots(array $body): int` = 1 + floor(AssemblyLine/5) (invariato per navi non-conveyor)
- `Actions\Shipyard::start(int $bodyId, array $plan)` — per navi non-conveyor (civili, speciali); `Actions\Shipyard::stop(int $queueId)` rimborso
- Silo: invariato

- [ ] **Step 1:** Test:
  - `test_ship_value_matches_weighted_cost` — valore = M + 1.5C + 2D + 5Lu
  - `test_effective_stats_include_research_upgrades` — attacco = valore_base × classe_mult × ricerca^0.25 × upgrade^0.25
  - `test_light_conveyor_10_units_per_second_per_level` — L1=10/s, L5=50/s
  - `test_medium_heavy_conveyor_same_value_per_second` — valore/s uguale tra classi a parità di livello
  - `test_conveyor_production_continuous` — lotti sequenziali, risorse all'avvio
  - `test_upgrade_multiplier_exponent_025` — 1.25^(0.25×L) per livelli extra
  - `test_upgrade_l11_requires_lunarite` — invariato
  - `test_no_max_limit_any_ship` — rimossi limiti "1/impero" e "1/pianeta"
  - `test_rapid_fire_formula_per_tech` — Plasma vs corazze/difese, Laser vs agili, Ioni vs scudi
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

### Task 9b: Tabella navi completa in `game_config.json`

**Files:** Modifica `game_config.json` (sezione `ships`, `defenses`, `conveyor`), `tests/php/ShipTableTest.php`

**Contenuto:** Tutte le navi con: `cost`, `value` (calcolato), `class` (light/medium/heavy), `attack_multiplier` (1.0 / ~1.3 / ~6.0), `requires` (min 1 tech fuoco + 1 difesa), `rapid_fire` (formula per tecnologia), `drive`, `cargo`, `fuel`, `speed`, `initiative`, `weight`, `ability[]` (configurabili), `matrix` **RIMOSSA**

- [ ] **Step 1:** Definire tabella completa navi (es. 15-20 navi combattimento + civili) e difese
- [ ] **Step 2:** Calcolare `value` per ciascuna, assegnare `class`, definire `attack_multiplier` per classe
- [ ] **Step 3:** Definire `requires` per ogni nave (tecnologie da `research`)
- [ ] **Step 4:** Definire `rapid_fire` formula per tecnologia (k_Plasma, k_Laser, k_Ioni)
- [ ] **Step 5:** Definire `conveyor` per classe: costi, growth, unità/s base
- [ ] **Step 6:** Test `ShipTableTest::testAllShipsHaveValidConfig` — validazione completa
- [ ] **Step 7:** Commit.

---

## Milestone D — Flotte (INVARIATA, usa nuovi Stats)

### Task 10: Volo, missioni base, spionaggio — **INVARIATA**

Usa `Stats::effective()` per velocità, cargo, fuel, iniziativa. Nessun cambiamento logico.

### Task 11: Teleporter, Phalanx, Radar — **INVARIATA**

---

## Milestone E — Battaglia — **RISCRITTA per nuovo design**

### Task 12: Simulatore di battaglia — **RISCRITTO per motore per stack (OPBE)**

**Files:** Modifica `core/Combat.php`, `core/Power.php`, `core/Stats.php`, `tests/php/CombatTest.php`, `tests/php/PowerTest.php`

**Interfaces:**

- `Combat::simulate(array $attackers, array $defenders, Config $cfg, int $seed): BattleResult` — **nuovo algoritmo per stack**:
  - Input: stack `['code'=>..., 'qty'=>DOUBLE, 'stats'=>Stats::effective(...)]` per navi e difese (`is_defense` flag), `planetary_shield` valore
  - Pre-battaglia: Torpedo (se presenti) → lancio hangar (fighter/bomber da `hangar` stat)
  - 6 round max; per round:
    - Scudi pieni all'inizio round
    - Ordina stack per iniziativa (decrescente)
    - Gruppi di `combat.initiative_group` (default 10) iniziativa
    - Fuoco **simultaneo** dentro il gruppo: ogni stack spara contro bersaglio casuale (peso = qty × iniziativa target)
    - Danni applicati a fine gruppo
    - **Fuoco rapido calcolato**: per ogni colpo, se attaccante ha FR > 1 contro tipo bersaglio, prob (FR-1)/FR di sparare di nuovo nello stesso round (max `combat.max_rapid_fire_chain` default 5)
    - **Niente matrice ++/−−**, **niente abilità superate** (Raffica, Fuoco area, Corazza pesante, Perforante, Carico pesante)
    - **Schivata**: formula `dodge = min(0.4, base_dodge × research^0.25 × upgrade^0.25)` (max 40%)
    - **Esplosione**: se struttura < 30% max, prob esplosione = (1 - struttura/max) × 0.5
    - **Rimbalzo**: colpo < 1% scudo → nessun danno
    - **Planetary Shield**: assorbe prima delle difese planetarie
  - Aggregazione: stack identiche (stesso code, stats, damage%) raggruppate per performance
  - Output: `BattleResult` con `winner`, `rounds[]` (dettaglio per round: colpi, danni, perdite per stack), `survivorsA`, `survivorsD`, `debris`, `loot`

- `Power::unitPower(array $stats, Config): float` — potenza singola unità = f(attacco, scudo, struttura, velocità, iniziativa, FR medio)
- `Power::fleetPower(array $stacks): float` — Σ unitPower × qty
- `Power::relative(array $mine, array $theirs): float` — myPower / theirPower
- `Power::empire(int $empireId): array{total,fleet,defense,structures,research,admiral,upgrades}` — **include valore ricerche e potenziamenti**

- [ ] **Step 1:** Test:
  - `test_same_seed_same_result` — determinismo
  - `test_stack_based_combat_matches_expected_outcomes` — casi noti
  - `test_rapid_fire_calculated_from_formula` — FR = k × attacco / (scudo+struttura bersaglio)
  - `test_no_hardcoded_matrix` — verifica assenza matrice
  - `test_dodge_max_40_percent` — tetto schivata
  - `test_shield_bounce_under_1_percent` — rimbalzo
  - `test_explosion_under_30_percent_hull` — esplosione
  - `test_planetary_shield_absorbs_first` — scudo planetario
  - `test_power_includes_research_and_upgrades_value` — Power conta valore ricerche/upgrade
  - `test_heavy_class_economy_of_scale` — nave pesante batte equivalente costo navi leggere
  - `test_mixed_fleet_always_better` — FR e tech rendono flotta mista conveniente
  - `test_performance_1m_units_under_1s` — `@group perf` 1M unità/lato < 1s
- [ ] **Step 2-4:** FAIL → implementa → PASS. Inoltre test prestazione. Commit.

### Task 13: Attacco, saccheggio, detriti, spazi, riparazioni, missili — **AGGIORNATA**

**Files:** Modifica `core/Loot.php`, `core/Debris.php`, `core/Missiles.php`, `core/Missions/Attack.php`, `core/Missions/Recycle.php`, `core/Missions/Missile.php`, `tests/php/AttackTest.php`, `tests/php/MissilesTest.php`

**Cambiamenti:** Usa nuovo `Combat::simulate()`; `Fields::applyBattle()` usa nuovi delta da config; missili ignorano scudi (invariato); detriti 30% navi 10% difese (invariato).

- [ ] **Step 1:** Test esistenti + `test_attack_uses_new_combat_simulate`
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

---

## Milestone F — Luna (INVARIATA)

### Task 14: Luna completa — **INVARIATA**

---

## Milestone G — CPU, Diplomazia, Mercato (AGGIORNATA per nuovo Power)

### Task 15: CPU semplificate e CPU vive — **AGGIORNATA**

**Files:** Modifica `core/Cpu/Brain.php`, `core/Cpu/Personality.php`, `tests/php/CpuTest.php`

**Cambiamenti:** `Cpu\Brain::shouldAttack()` usa `Power::relative()` che ora include valore ricerche e potenziamenti; personalità usano soglie su Power relativo.

- [ ] **Step 1:** Test: `test_cpu_uses_full_power_for_decisions`, `test_aggressive_attacks_weaker_by_power`
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

### Task 16: Mercato e diplomazia — **INVARIATA**

---

## Milestone H — Contenuti PvE (INVARIATA, usa nuovi valori)

### Task 17: Pirati, Rift, Gauntlet, Ammiraglio — **AGGIORNATA**

**Files:** Modifica `core/Pirates.php`, `core/Gauntlet.php`, `core/Admiral.php`, `tests/php/PiratesTest.php`, `tests/php/GauntletTest.php`

**Cambiamenti:** PF pirati/Gauntlet calcolata con `Power::fleetPower()` (include ricerche/upgrade); Ammiraglio bonus integrati in `Stats::effective()`.

- [ ] **Step 1:** Test: `test_pirate_power_uses_full_power_calculation`, `test_gauntlet_wave_power_scales_with_research`
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

### Task 18: Miner, asteroidi, eventi casuali, spedizioni — **INVARIATA**

### Task 19: Classifica, registro, notifiche, pulizia, cron — **AGGIORNATA**

**Files:** Modifica `core/Scores.php`, `tests/php/ScoresTest.php`

**Cambiamenti:** `Scores::recalc()` usa `Power::empire()` che include valore ricerche e potenziamenti.

- [ ] **Step 1:** Test: `test_scores_include_research_and_upgrade_value`
- [ ] **Step 2-4:** FAIL → implementa → PASS. Commit.

---

## Milestone I — Interfaccia (AGGIORNATA per nuovi dati)

### Task 20: Shell PWA, login, partite, barra alta/bassa — **AGGIORNATA**

**Files:** Modifica `public/js/app.js`, `public/js/api.js`, `public/css/app.css`

**Cambiamenti:** Barra alta mostra `Power` totale (flotta + difesa + strutture + ricerche + upgrade + ammiraglio); scala gioco x1-x10, risorse x1-x3000 in impostazioni partita.

- [ ] Test manuale: verifica barra alta mostra Power corretto
- [ ] Commit.

### Task 21: Dash con flotte in volo — **INVARIATA**

### Task 22: Costruisci + Cantiere — **AGGIORNATA per Conveyor**

**Files:** Modifica `public/js/views/build.js`, `public/js/views/shipyard.js`, `public/js/shipyard-math.js`, `tests/js/shipyard-math.test.mjs`

**Cambiamenti:** UI separata per Conveyor (Light/Medium/Heavy) con slider unità/s; anteprima valore/s; `shipyard-math.js` replica `Conveyor::unitsPerSecond()` e distribuzione.

- [ ] Test JS: stessi casi `ConveyorTest` → stessi numeri
- [ ] Commit.

### Task 23: Flotte, mappa, altro — **AGGIORNATA per Simulatore**

**Files:** Modifica `public/js/views/fleet.js`, `public/js/views/more.js`

**Cambiamenti:** Simulatore (`POST /api/combat/simulate`) esegue `Combat::simulate` con `combat.simulator_runs: 5` seed diversi; restituisce % vittoria/pareggio/sconfitta, perdite medie/min-max per tipo; grafico barra sopravvissuta/persa con % persa per tipo. Report spionaggio: pulsante **Simula** apre simulatore con difensori dal report.

- [ ] Verifica manuale end-to-end: crea partita x100 (speed) × 3000 (res), costruisci miniera, invia sonda, attacca CPU, controlla report e simulatore
- [ ] Commit.

---

## Milestone J — Pubblicazione (INVARIATA)

### Task 24: Guida Aruba — **INVARIATA**

---

## Mappa file aggiornata (delta rispetto al piano base)

```
core/Formula.php               + researchMultiplier, upgradeMultiplier, rapidFireFactor, shipValue
core/Stats.php                 NUOVO — statistiche efficaci navi + valore
core/Conveyor.php              NUOVO — produzione per classe (Light/Medium/Heavy)
core/Combat.php                RISCRITTO — motore per stack OPBE, FR calcolato, niente matrice
core/Power.php                 AGGIORNATO — include valore ricerche/upgrade
core/Shipyard.php              AGGIORNATO — usa Conveyor per navi classe
core/Upgrades.php              AGGIORNATO — moltiplicatore 1.25^(0.25×L)
core/Games.php                 AGGIORNATO — validazione scale x1-10 / x1-3000, CPU 50%
core/Galaxy.php                AGGIORNATO — CPU planet size scala con res_mult
core/Economy.php               AGGIORNATO — produzione × res_mult
core/ConfigSchema.php          AGGIORNATO — validazione nuova struttura config
game_config.json               RISCRITTO — struttura completa nuovo design
tests/php/StatsTest.php        NUOVO
tests/php/ConveyorTest.php     NUOVO
tests/php/ShipTableTest.php    NUOVO
tests/php/CombatTest.php       RISCRITTO — test nuovo motore
tests/php/PowerTest.php        AGGIORNATO — test Power con ricerche/upgrade
tests/php/CpuTest.php          AGGIORNATO — CPU usa Power completo
tests/php/ScoresTest.php       AGGIORNATO — classifiche includono ricerche/upgrade
tests/js/shipyard-math.test.mjs AGGIORNATO — test Conveyor JS
public/js/views/shipyard.js    AGGIORNATO — UI Conveyor
public/js/views/fleet.js       AGGIORNATO — Simulatore 5 run
```

---

## Ordine di esecuzione consigliato

1. **Aggiorna `game_config.json` e `ConfigSchema.php`** — base per tutto
2. **Aggiorna `Formula.php`** — funzioni pure condivise
3. **Crea `Stats.php`** — cuore calcolo statistiche e valore
4. **Crea `Conveyor.php` + aggiorna `Shipyard.php`/`Upgrades.php`** — Milestone C
5. **Riscrivi `Combat.php` + aggiorna `Power.php`** — Milestone E
6. **Aggiorna moduli dipendenti** (CPU, Scores, Pirates/Gauntlet/Admiral)
7. **Aggiorna UI** (shipyard, fleet simulator, dash Power)
8. **Test integrazione end-to-end**

---

## Note per l'agente

- **NON toccare codice** in questo piano: serve solo come riferimento per l'implementazione futura
- La specifica `docs/superpowers/specs/2026-11-04-navi-battaglia-valore-design.md` va creata/aggiornata PRIMA di iniziare i task, copiando tutti i numeri da `docs/design-modifiche.md`
- `game_config.json` va riscritto completamente con la nuova struttura prima del Task 9
- I test esistenti (Milestone A/B) vanno aggiornati per passare con la nuova config prima di procedere
- Ogni task deve terminare con `vendor/bin/phpunit` PASS e commit