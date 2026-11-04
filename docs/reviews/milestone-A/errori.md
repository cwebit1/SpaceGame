# Errori milestone A

## Sintesi
La milestone A presenta criticità significative su aderenza alla specifica, sicurezza e coerenza dei formattatori. I numeri di gioco sono spesso hard-coded ignorando game_config.json. Bug nei parser/formattatori PHP/JS causano divergenze di output e errori di parsing (es. "1.5" interpretato come 1500). Mancano validazioni di input e protezioni CSRF/SQL injection in install e API. La configurazione DB e le impostazioni di default sono incoerenti con la spec. I test non coprono i casi limite richiesti dal Review Focus.

## Errori
| ID | Gravità | Categoria | File:riga | Descrizione | Come si manifesta |
|---|---|---|---|---|---|
| E1 | media | 2 | core/Galaxy.php:290,293 | Valori hard-coded (2000 campi) invece di leggere da config | Modifiche a game_config.json non hanno effetto sulla generazione |
| E2 | media | 2 | core/Formula.php:65 | Divisore 3000.0 hard-coded, chiave assente in config | Impossibile bilanciare tempi costruzione via config |
| E3 | bassa | 2 | core/Formula.php:79-82 | Default silenziosi se chiavi upgrades mancano | Comportamento implicito con valori inventati al posto di errori |
| E4 | bassa | 2 | core/Galaxy.php:196-197,213,242 | Costanti gameplay (pianeti, personalità) hard-coded | Configurazione CPU non rispettata dalla spec |
| E5 | alta | 1 | core/Games.php:35-38 vs :110-116 | Incoerenza default tra validateSettings e create (min vs valori specifici) | Partite create con densità CPU 0 o dimensioni divergenti |
| E6 | alta | 1/3 | core/Games.php:39 + game_config.json:1262 | live_share_pct 0.7 trattato come percentuale intera (~0 imperi vivi) | Quota CPU vive errata in produzione; test passano solo sovrascrivendo settings |
| E7 | media | 1 | core/Galaxy.php:205-210 | Formula live-share derivata per imperi non pianeti; remainingPlanets negativo | Slot CPU vuoti con densità alta e tetto basso |
| E8 | media | 3 | core/Galaxy.php:341-360 | Pianeta madre scelto primo slot libero, no posizione preferita; query N+1 | Player nasce in posizione estrema; generazione lenta su galassie grandi |
| E9 | media | 3 | core/Galaxy.php:33-35, 220, 249 | Casualità globale non seminata correttamente; pesi tipi pianeta ignorati | Generazioni non deterministiche con stesso seed; config pesi ignorata |
| E10 | media | 3 | core/Galaxy.php:419-427 | randomPlanetType ignora parametri config, restituisce lista fissa | Aggiunta/rimozione tipi in config senza effetto |
| E11 | media | 3 | core/Format.php:187-190 | short() non sale di scala quando arrotondato >=1000 (PHP vs JS diverge) | Output "1000,00 Mld" invece di "1,00 Bln" |
| E12 | media | 3 | public/js/format.js:66 vs core/Format.php:73 | Arrotondamento diverso prima della formattazione tra JS e PHP | Stesso numero renderizzato diversamente client/server |
| E13 | media | 3 | core/Format.php:100,173 | Notazione scientifica con virgola decimale rifiutata in PHP ma accettata in JS | parse('1,5e15') fallisce in PHP, funziona in JS |
| E14 | media | 3 | core/Format.php:135 | Controllo negativo limitato a '-', nessun limite superiore esplicito in alcuni rami | Comportamenti ai bordi non uniformi tra rami parse |
| E15 | bassa | 3 | core/Format.php:140-154 / public/js/format.js:89-103 | Ambiguità separatore migliaia/decimale ("1.5" diventa 15 in PHP) | Errore numerico grave nel parsing input utente |
| E16 | alta | 3 | core/Db.php:68-88 | PDO singleton statico ignora flag $test nelle chiamate successive | Test e produzione possono colpire il DB sbagliato nello stesso processo |
| E17 | media | 3 | core/Db.php:134-155 | Lock GET_LOCK ricorsivo rilascia protezione prematuramente; timeout 10s vs piano 5s | Recupero a blocchi sblocca sezione critica in anticipo |
| E18 | media | 1 | install/schema.sql:261-269 | PK asteroid_progress esclude stratum/day presenti nella tabella | Conflict INSERT multi-strato/giorno |
| E19 | bassa | 1 | install/schema.sql:146-156 | Vincoli mancanti su position code; ENUM fleets.state incompleto | Rischio posizioni duplicate; stati missioni futuri non supportati |
| E20 | media | 1/5 | install/install.php (intero file) | Manca creazione utente con password_hash (richiesto Task 5) | Installazione completata ma login impossibile |
| E21 | alta | 4 | core/Api/AuthController.php:47-55 | POST /api/logout manca verifica CSRF | Attacco cross-site disconnette utenti |
| E22 | alta | 4 | public/api/index.php:26-29 | CORS Allow-Origin * con Allow-Credentials true | Esposizione API a qualsiasi origine in sviluppo; blocco browser in prod |
| E23 | media | 4 | core/Auth.php:26-36 + tests | Login senza rate-limit; username >50 char troncato silenziosamente | Brute-force possibile; collisioni username troncati |
| E24 | media | 4 | core/Games.php:61 | Nome partita non validato né sanitizzato (VARCHAR(100)) | Input non validato; nomi degeneri dall'API pubblica |
| E25 | media | 4 | install/install.php:50 | Interpolazione nome DB in SQL senza escaping (backtick injection) | SQL injection confinata alla config locale |
| E26 | bassa | 4 | core/Api/GamesController.php:206 | Token CSRF solo header X-CSRF-Token, no fallback form field | Basso rischio ma difformità pratiche; nessun test API-level CSRF |
| E27 | media | 1 | core/Config.php + ConfigSchema | Config::fromFile() non invoca mai ConfigSchema::validate() | game_config.json malformato passa in produzione causando runtime errors |
| E28 | media | 1/5 | piano Task 3 vs public/api/index.php | Endpoint GET /api/config/format mancante | Frontend non sincronizza sigle format; init JS hardcoded |
| E29 | bassa | 1 | core/Games.php:24-28, core/Api/GamesController.php:153 | Games::create() carica sempre game_config.json globale, ignora copia partita | Parametri partita schiacciati da config corrente durante generazione |
| E30 | media | 3 | core/Games.php:56-70 | processed_until non inizializzato all'istante di creazione (default 0) | Prima catch-up elabora decenni simulati dopo attivazione Clock |
| E31 | bassa | 3 | core/Galaxy.php:31-35 | mt_srand($seed) modifica PRNG globale del processo | Determinismo non garantito in contesto multi-partita concorrente |
| E32 | media | 5 | tests/php/FormatTest.php | Assenti test casi limite 1e70, confini arrotondamento, parse virgola/punto | Bug E11/E13/E15 invisibili alla suite PHP |
| E33 | media | 5 | tests/js/format.test.mjs:66 | Test JS asserisce comportamento corretto che PHP non produce | Parità PHP↔JS non verificata; suite danno risposte opposte |
| E34 | media | 5 | tests/php/DbTest.php:97-160 | Test lock usa GET_LOCK diretto, non esercita Db::withGameLock() wrapper | Wrapper lock usato da Clock non coperto end-to-end |
| E35 | media | 5 | tests/php/* (assente) | Nessun test HTTP API (router, CSRF, status codes, property checks) | Regression sicure e routing non rilevate |
| E36 | bassa | 5 | tests/php/GalaxyTest.php:99-123 | Test densità non verifica casualità distribuzione slot tra sistemi | Densità corretta ma clustering distorto non catturato |
| E37 | bassa | 1 | docs/progress.md:30 vs repo | progress.md dichiara endpoint /api/config/format esistente e test verdi | Documentazione avanzamento non conforme stato reale codice |
