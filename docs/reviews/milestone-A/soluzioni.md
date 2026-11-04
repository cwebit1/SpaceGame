# Soluzioni milestone A

## E1 — Campi base pianeta hard-coded
- File: `core/Galaxy.php`
- Soluzione: Spostare il valore 2000 in `game_config.json` (`galaxy.base_fields_planet`) e leggerlo con fallback esplicito.
- Test da aggiungere: `GalaxyTest::testBaseFieldsFromConfig`
- Rischi/effetti collaterali: Eccezione se la chiave è mancante nelle config esistenti; richiede migrazione test.

## E2 — Divisore tempi costruzione hard-coded
- File: `core/Formula.php`
- Soluzione: Configurare `formula.construction_cost_divisor` (default 3000) in JSON e usarlo nel calcolo.
- Test da aggiungere: `FormulaTest::testConstructionTimeUsesConfigDivisor`
- Rischi/effetti collaterali: Partite salvate senza nuova chiave richiedono fallback o rinormalizzazione.

## E3 — Default silenziosi formule upgrades
- File: `core/Formula.php`
- Soluzione: Rimuovere default impliciti, validare chiavi obbligatorie in `ConfigSchema`, usare accesso diretto ai dati.
- Test da aggiungere: `FormulaTest::testUpgradeCostThrowsOnMissingKeys`
- Rischi/effetti collaterali: Rompe configurazioni parziali oggi funzionanti per errore.

## E4 — Costanti gameplay CPU hard-coded
- File: `core/Galaxy.php`
- Soluzione: Spostare personalità, tier e densità pianeti in `game_config.json` sotto `cpu.*`.
- Test da aggiungere: `GalaxyTest::testCpuPersonalitiesFromConfig`
- Rischi/effetti collaterali: Cambi di config alterano bilanciamento nuove partite; vecchie restano ancorate alla loro copia.

## E5 — Default incoerenti Games::create vs validateSettings
- File: `core/Games.php`
- Soluzione: Unico punto di default via `Games::defaultSettings(Config)` che legge da `settings.defaults.*`.
- Test da aggiungere: `GamesTest::testCreateDefaultsMatchConfiguredDefaults`
- Rischi/effetti collaterali: Nessuna migrazione necessaria, ma impostazioni diverse per partite create prima del fix.

## E6 — Scala percentuale live_share_pct incoerente
- File: `game_config.json`, `core/Games.php`
- Soluzione: Standardizzare su percentuali intere 0–100 (es. 70) e validare range in `ConfigSchema`.
- Test da aggiungere: `ConfigTest::testLiveSharePctRangeValidation`
- Rischi/effetti collaterali: Modifica semantica chiave tocca frontend/documenti; aggiornare tutte le occorrenze.

## E7 — Formula live-share derivata negativa
- File: `core/Galaxy.php`
- Soluzione: Ricalcolo difensivo con `min/max` e clamp a 0 per `remainingPlanets`.
- Test da aggiungere: `GalaxyTest::testLiveEmpireCountNeverNegativeRemaining`
- Rischi/effetti collaterali: Cambia conteggio imperi live in galassie piccole quando `max_live_empires` è tetto.

## E8 — Pianeta madre casuale e query N+1
- File: `core/Galaxy.php`
- Soluzione: Criterio definito per slot player (non primo libero) e batch insert multi-row.
- Test da aggiungere: `GalaxyTest::testPlayerHomeworldPlacement`
- Rischi/effetti collaterali: Batch insert richiede `max_allowed_packet` adeguato; cambio layout seed-dipendenti.

## E9 — mt_srand globale non deterministico
- File: `core/Galaxy.php`
- Soluzione: Introdurre classe `Rng` dedicata con seme locale, eliminando `mt_srand()` globale.
- Test da aggiungere: `GalaxyTest::testSameSeedProducesIdenticalGalaxy`
- Rischi/effetti collaterali: Partite precedenti non riproducibili col nuovo RNG; rewrite utility mescolamento.

## E10 — randomPlanetType ignora config
- File: `core/Galaxy.php`
- Soluzione: Usare pesi definiti in `galaxy.planet_types` con selezione ponderata.
- Test da aggiungere: `GalaxyTest::testPlanetTypeDistributionRespectsWeights`
- Rischi/effetti collaterali: Distribuzione tipi cambia da uniforme a pesata; bilanciare pesi iniziali.

## E11 — short() non sale di scala correttamente
- File: `core/Format.php`
- Soluzione: Aggiungere guard per incrementare indice scala se arrotondamento supera 1000.
- Test da aggiungere: `FormatTest::testShortPromotesScaleAfterRounding`
- Rischi/effetti collaterali: Output cambiato ai bordi di scala; verificare snapshot esistenti.

## E12 — Arrotondamento divergente PHP/JS
- File: `core/Format.php`, `public/js/format.js`
- Soluzione: Regola unica "round-half-away-from-zero" implementata identicamente nei due linguaggi.
- Test da aggiungere: `FormatParityTest` (PHP) + `format.test.mjs`
- Rischi/effetti collaterali: Piccoli shift ultimo decimale UI; nessun effetto sui dati.

## E13 — Notazione scientifica virgola rifiutata in PHP
- File: `core/Format.php`
- Soluzione: Normalizzare separatore decimale prima di parsare esponente nella regex.
- Test da aggiungere: `FormatTest::testParseScientificWithItalianComma`
- Rischi/effetti collaterali: Input ambigui risolti con regola esplicita mantissa decimale.

## E14 — parse() limiti superiori assenti
- File: `core/Format.php`
- Soluzione: Centralizzare controlli e aggiungere guard finale su `$maxValue` derivato da config.
- Test da aggiungere: `FormatTest::testParseRejectsOverflowAndInvalidChars`
- Rischi/effetti collaterali: Input precedentemente accettati (es. segno +) ora nulli; allineare errori frontend.

## E15 — Ambiguità separatore migliaia/decimale
- File: `core/Format.php`, `public/js/format.js`
- Soluzione: Implementare regola deterministica documentata (ultimo separatore è decimale se misto).
- Test da aggiungere: `FormatTest::testNormalizeSeparatorRules`
- Rischi/effetti collaterali: Utenti abituati a interpretazioni errate vedono cambi input; comunicare regola.

## E16 — Singleton PDO ignora flag $test
- File: `core/Db.php`
- Soluzione: Memorizzare connessioni separate per sezione ('db' vs 'db_test') in array statico.
- Test da aggiungere: `DbTest::testPdoReturnsDifferentConnectionsForTestAndProd`
- Rischi/effetti collaterali: Chiamate `close()` devono passare flag corretto; due connessioni aperte in dev/test.

## E17 — Lock ricorsivo e timeout disallineato
- File: `core/Db.php`
- Soluzione: Stack profondità per lock non rientrante o divieto ricorsione esplicito; timeout default 5s.
- Test da aggiungere: `DbTest::testNestedWithGameLockDoesNotReleaseEarly`
- Rischi/effetti collaterali: Code path ricorsivi andranno in errore esplicito da correggere.

## E18 — PK asteroid_progress incompleta
- File: `install/schema.sql`
- Soluzione: Includere `stratum` e `day` nella PRIMARY KEY.
- Test da aggiungere: `AsteroidProgressTest::testInsertDifferentStratumSameBodySucceeds`
- Rischi/effetti collaterali: Serve ALTER TABLE e script migrazione; tabella cresce più rapidamente.

## E19 — Vincoli mancanti queues/fleets
- File: `install/schema.sql`
- Soluzione: Aggiungere UNIQUE KEY su `queues` ed estendere ENUM o migrare a VARCHAR per `fleets.state`.
- Test da aggiungere: `SchemaConstraintTest::testDuplicateQueueSlotRejected`
- Rischi/effetti collaterali: ALTER tabelle popolate offline; deploy coordinato PHP/SQL per ENUM.

## E20 — Install non crea utente admin
- File: `install/install.php`
- Soluzione: Aggiungere INSERT guidato con `password_hash` e validazione input.
- Test da aggiungere: `InstallTest::testInstallerCreatesLoginableUser`
- Rischi/effetti collaterali: Installer diventa interattivo; gestire caso utente esistente.

## E21 — Logout senza verifica CSRF
- File: `core/Api/AuthController.php`
- Soluzione: Chiamare `requireCsrf()` come negli altri endpoint mutanti.
- Test da aggiungere: `AuthApiTest::testLogoutWithoutCsrfTokenReturns403`
- Rischi/effetti collaterali: Client JS chiamanti logout senza header si rompono; aggiornare frontend.

## E22 — CORS unsafe con credenziali
- File: `public/api/index.php`
- Soluzione: Allowlist origini da config, mai `*` con `Allow-Credentials: true`.
- Test da aggiungere: `CorsTest::testUnknownOriginGetsNoAllowOriginHeader`
- Rischi/effetti collaterali: Accessi domini non listati bloccati; configurare origine produzione.

## E23 — Login senza rate-limit/tronca username
- File: `core/Auth.php`
- Soluzione: Validare lunghezza username (no troncamento) e aggiungere rate-limit su tentativi falliti.
- Test da aggiungere: `AuthTest::testUsernameTooLongRejectedNotTruncated`
- Rischi/effetti collaterali: Falsi blocchi dietro proxy condivisi; tabella extra da pulire.

## E24 — Nome partita non validato
- File: `core/Games.php`
- Soluzione: Normalizzare, trim, whitelist pattern Unicode e lunghezza max 100 prima dell'INSERT.
- Test da aggiungere: `GamesTest::testCreateRejectsEmptyOversizedAndControlCharNames`
- Rischi/effetti collaterali: Nomi con emoji/simboli rifiutati; decidere ammissibilità `\p{So}`.

## E25 — Nome DB interpolato senza escaping
- File: `install/install.php`
- Soluzione: Validare rigidamente nome DB con regex whitelist alfanumerica/underscore prima dell'uso.
- Test da aggiungere: `InstallTest::testDatabaseNameWithBacktickOrQuotesRejected`
- Rischi/effetti collaterali: Nomi legittimi con `-` o punti rifiutati; documentare limitazione.

## E26 — Token CSRF solo via header
- File: `core/Api/GamesController.php`
- Soluzione: Accettare token anche da campo body `csrf_token` per compatibilità form.
- Test da aggiungere: `GamesApiTest::testCsrfAcceptedViaHeaderOrFormField`
- Rischi/effetti collaterali: Superficie leggermente maggiore ma equivalente; nessuna rottura client esistenti.

## E27 — Config::fromFile non valida schema
- File: `core/Config.php`
- Soluzione: Chiamare `ConfigSchema::validate()` dopo il decode JSON.
- Test da aggiungere: `ConfigTest::testFromFileThrowsOnMissingRequiredKeys`
- Rischi/effetti collaterali: Boot fallito per config incomplete; errore rapido voluto.

## E28 — Endpoint GET /api/config/format mancante
- File: `public/api/index.php`
- Soluzione: Aggiungere rotta pubblica che restituisce sezione `format` della config.
- Test da aggiungere: `ConfigFormatApiTest::testGetConfigFormatReturnsScaleAndSeparators`
- Rischi/effetti collaterali: Espone dati non sensibili; frontend gestisce latenza/errore fetch iniziale.

## E29 — Games::create ignora config partita
- File: `core/Games.php`
- Soluzione: Propagare config deserializzata da `config_json` se presente, altrimenti globale.
- Test da aggiungere: `GamesTest::testOpenUsesStoredConfigNotGlobal`
- Rischi/effetti collaterali: Partite vecchie con config incompleto necessitano merge default globali.

## E30 — processed_until inizializzato a 0
- File: `core/Games.php`, `install/schema.sql`
- Soluzione: Impostare `processed_until` al timestamp di creazione (ms) nell'INSERT.
- Test da aggiungere: `GamesTest::testNewGameProcessedUntilEqualsCreationTime`
- Rischi/effetti collaterali: Script correzione necessario per partite esistenti con backlog fittizio.

## E31 — mt_srand($seed) inquina PRNG globale
- File: `core/Galaxy.php`
- Soluzione: Eliminare `mt_srand` globale, usare RNG locale (vedi E9).
- Test da aggiungere: `GalaxyTest::testGenerationDoesNotReseedGlobalGenerator`
- Rischi/effetti collaterali: Codice dipendente involontariamente dal reseed globale si comporta diversamente.

## E32 — FormatTest privo di casi limite
- File: `tests/php/FormatTest.php`
- Soluzione: Integrare suite con casi bordo arrotondamento, separatori misti, scientific notation.
- Test da aggiungere: `testShortBeyondScaleUsesScientific`, `testParseSeparatorMatrix`
- Rischi/effetti collaterali: Test falliranno finché bug E11-E15 non risolti; marcare come expected-fail temporanei.

## E33 — Test JS asserisce comportamenti diversi da PHP
- File: `tests/js/format.test.mjs`
- Soluzione: Usare golden table condivisa (JSON) generata da script comune per entrambi i runner.
- Test da aggiungere: `format.test.mjs::matchesGoldenTable`
- Rischi/effetti collaterali: Serve runner npm in CI; rigenerare fixture a ogni cambio formato deliberato.

## E34 — DbTest non esercita wrapper withGameLock
- File: `tests/php/DbTest.php`
- Soluzione: Aggiungere test end-to-end concorrenza con processi/thread simulati.
- Test da aggiungere: `DbTest::testWithGameLockSerializesConcurrentCallers`
- Rischi/effetti collaterali: Possibile flakiness su CI lente; usare timeout larghi e marcare integration test.

## E35 — Nessun test HTTP endpoint API
- File: assenti
- Soluzione: Introdurre harness `ApiTestCase` per simulare superglobals e testare routing/CSRF/status code.
- Test da aggiungere: `AuthApiTest`, `GamesApiTest`, `RouterTest`
- Rischi/effetti collaterali: Harness duplica comportamento web server; mantenere test thin-controller.

## E36 — Test densità non verifica distribuzione spaziale
- File: `tests/php/GalaxyTest.php`
- Soluzione: Estendere assert per verificare spread pianeti CPU tra sistemi e massimi per sistema.
- Test da aggiungere: `GalaxyTest::testCpuSlotsSpreadAcrossSystemsWithinMaxPerSystem`
- Rischi/effetti collaterali: Test fallirà se generazione attuale ammette clustering estremo; utile guida per E8/E9.

## E37 — progress.md dichiara elementi inesistenti
- File: `docs/progress.md`
- Soluzione: Aggiornare voci spuntando solo feature completate e aggiungendo note commit SHA.
- Test da aggiungere: n/a (doc); smoke CI opzionale su route menzionate.
- Rischi/effetti collaterali: Nessuno tecnico; migliora affidabilità documentazione avanzamento.
