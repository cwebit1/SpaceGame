# SpaceGame — Avanzamento

## Milestone A — Fondamenta

### Task 1: Scaffolding, ambiente locale, test runner
- [x] Step 1: `git init`; `.gitignore` con `vendor/`, `config.local.php`, `node_modules/`.
- [x] Step 2: Verifica ambiente: `php -v` (≥ 8.1, estensioni `pdo_mysql`, `json`, `mbstring`), `mysql --version`. Se mancano scrivi in `docs/setup-local.md` come installare XAMPP e fermati chiedendo all'utente.
- [x] Step 3: Test `BootstrapTest::test_autoload_registered`: `assertTrue(\SpaceGame\autoload_registered())` e `assertSame('UTC', date_default_timezone_get())`.
- [x] Step 4: Implementa bootstrap con funzione `SpaceGame\autoload_registered(): bool`.
- [x] Step 5: `composer install` → `vendor/bin/phpunit` PASS. Commit `chore: scaffolding`.

**Commit:** e8df24f
**Stato:** COMPLETATO

---

### Task 2: Config caricato e validato
- [x] Step 1: Test: `test_get_dot_path` (`get('ships.light_fighter.attack') == 45`), `test_missing_key_names_path` (eccezione contiene `ships.nope`), `test_invalid_json_reports_line`, `test_schema_rejects_negative_cost`, `test_schema_requires_every_ship_field`.
- [x] Step 2: FAIL.
- [x] Step 3: Implementa. Scrivi `game_config.json` **completo** copiando **tutte** le tabelle della spec §2-§18 (chiavi in snake_case inglese, es. `light_fighter`, `metal_mine`, `lunar_nanoforge`). Le formule `a·L·b^L` diventano `{"a":40,"b":1.08}`.
- [x] Step 4: PASS + test `test_shipped_config_is_valid` che carica `game_config.json`. Commit.

**Commit:** e928ebd
**Stato:** COMPLETATO

---

### Task 3: Formule pure e formattazione numeri
- [x] Step 1: Test: `levelCurve(40,1.08,10) ≈ 863.57`; `cost(['m'=>80,'c'=>20],1.55,3)` → m=192.2, c=48.05; `buildHours` con robot 0 naniti 0 speed 1 per 3000 risorse = 1.0; naniti 1 → 0.25; `upgradeCost` L11 ha `lu=50`. Format: `short(12450000000)`="12,45 Mld", `short(999)`="999", `short(1.5e15)`="1,50 Bld", `short(1.23e66)`="1,23e66", `parse('2,5Mln')`=2500000, `parse('5k')`=5000, `parse('')`=null, `parse('-3')`=null, `parse('abc')`=null, `short(NAN)`="0".
- [x] Step 2-4: FAIL → implementa (sigle da `format.scale` in config, passate al JS via API `/api/config/format`) → PASS (`vendor/bin/phpunit`, `node tests/js/format.test.mjs`). Commit.

**Commit:** 2eb9c4b
**Stato:** COMPLETATO

---

### Task 4: Schema DB, installazione, connessione, lock
- [x] Step 1: Test (DB di test `spacegame_test` da `config.local.php`): `test_install_creates_tables`, `test_lock_excludes_second_holder` (seconda connessione riceve `LockBusy`), `test_tx_rolls_back_on_exception`.
- [x] Step 2-4: FAIL → implementa → PASS. Commit.

**Commit:** 991b1dd
**Stato:** COMPLETATO

---

### Task 5: Login, partite, generazione galassia
- [x] Step 1: Test: `test_density_matches_setting` (±2%), `test_live_share_70_percent` (±3%), `test_live_empires_capped`, `test_cpu_planet_fields_scaled` (al 50% = 1000), `test_player_home_has_2000_fields`, `test_every_system_has_warp_and_4_asteroids`, `test_settings_out_of_range_rejected` (speed 101, cpu mult > speed), `test_config_copied_into_game`.
- [x] Step 2-4: FAIL → implementa → PASS. Commit.

**Commit:** 80869e0
**Stato:** COMPLETATO

---

**Milestone A COMPLETATA**
- Tutti i task (1-5) completati con test verdi
- Tag `milestone-A` creato al commit 24c4943
- Push su GitHub effettuato con tag

---

## Milestone B — Tempo, economia, code

### Task 6: Clock ed EventBus
- [x] Step 1: Test: `test_events_run_in_time_order`, `test_chunked_equals_single_pass` (stessa partita clonata: un `catchUp` vs 50 chiamate con budget minimo → stato DB identico), `test_event_processed_once` (due catchUp concorrenti simulati → handler chiamato 1 volta), `test_returns_false_when_budget_exhausted`.
- [x] Step 2-4: FAIL → implementa → PASS. Commit.

**Commit:** 8b45131
**Stato:** COMPLETATO

## Milestone C — Unità

### Task 9: Cantiere, difese, silo, potenziamenti
- [ ] ...

## Milestone D — Flotte

### Task 10: Volo, missioni base, spionaggio
- [ ] ...

## Milestone E — Battaglia

### Task 12: Simulatore di battaglia
- [ ] ...

## Milestone F — Luna

### Task 14: Luna completa
- [ ] ...

## Milestone G — CPU, diplomazia, mercato

### Task 15: CPU semplificate e CPU vive
- [ ] ...

## Milestone H — Contenuti PvE

### Task 17: Pirati, Rift, Gauntlet, Ammiraglio
- [ ] ...

## Milestone I — Interfaccia

### Task 20: Shell PWA, login, partite, barra alta/bassa
- [ ] ...

## Milestone J — Pubblicazione

### Task 24: Guida Aruba
- [ ] ...