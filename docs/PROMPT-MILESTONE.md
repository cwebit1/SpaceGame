# Istruzioni per ogni sessione (una sessione = una milestone)

Sei lo sviluppatore di SpaceGame.

1. Leggi per intero:
   - `docs/superpowers/specs/2026-11-02-spacegame-design.md` (specifica: fonte di verità di regole e numeri)
   - `docs/superpowers/plans/2026-11-02-spacegame.md` (piano)
   - `docs/progress.md` (avanzamento; se non esiste, crealo)
   - le cartelle in `docs/reviews/` (revisioni esterne di Qwen: `milestone-X/errori.md` e `milestone-X/soluzioni.md`)
2. **Revisioni prima di tutto.** Se in `docs/reviews/` c'è una cartella `milestone-X` non ancora segnata come "gestita" in `docs/progress.md`:
   - fai subito commit e push dei due file così come sono (`git add docs/reviews/milestone-X` → `git commit -m "docs: review Qwen milestone X"` → `git push origin master`);
   - per ogni errore di gravità **alta** e **media** in `errori.md` applica la soluzione proposta in `soluzioni.md` (o una migliore, spiegando perché), con TDD, test verdi, commit `fix(review): …`; se un errore ti sembra infondato spiega il motivo;
   - in fondo a `soluzioni.md` aggiungi la sezione `## Esito` con, per ogni errore: corretto (commit) / non corretto (motivo);
   - segna la revisione come gestita in `docs/progress.md`, commit e push.
3. Trova la **prima milestone non completata** (A, B, C… J) da `docs/progress.md`, `git log --oneline` e `git tag`. Lavora **solo su quella milestone** in questa sessione. Se trovi file non salvati da una sessione interrotta (`git status`), riprendili e completali.
4. Esegui i suoi task in ordine **senza chiedere conferma tra un task e l'altro**, con la skill `superpowers:subagent-driven-development`:
   - TDD: test che fallisce → codice → test verdi → commit;
   - rispetta "Global Constraints" e "Review Focus" del piano;
   - nessun numero di gioco nel codice: tutto in `game_config.json`, copiato dalla specifica;
   - non inserire mai credenziali (`config.local.php` lo compila l'utente; in locale DB `root` senza password su `127.0.0.1`);
   - spunta le caselle `- [x]` del piano man mano;
   - nei comandi Bash usa percorsi relativi alla cartella del progetto o con `/`, mai con `\` (altrimenti il controllo dei permessi non li riconosce e chiede conferma);
   - lancia test e comandi **in primo piano** e leggi direttamente il loro output: mai `sleep`, mai comandi in background, mai `cat` di file di output in cartelle temporanee; usa solo percorsi dentro il progetto;
   - mai scrivere più di circa 150 righe in una sola scrittura: i file grandi (es. `game_config.json`, schema SQL, PWA) vanno creati a sezioni, prima lo scheletro e poi una sezione per volta con Edit, verificando dopo ogni sezione (le risposte troppo lunghe vanno in timeout sul provider).
5. Fermati prima della fine **solo** se: un valore della specifica manca o è ambiguo, oppure i test non passano dopo 3 tentativi. In quel caso spiega il problema e aspetta.
6. A fine milestone:
   - esegui tutti i test (`vendor/bin/phpunit`, e `node` sui test JS se presenti) e riporta il risultato;
   - aggiorna `docs/progress.md` (milestone completata, commit, cosa funziona, note per la prossima);
   - fai il commit;
   - crea il tag `milestone-X` (es. `git tag milestone-A`) e fai `git push origin master --tags`; mai `--force`;
   - scrivi all'utente: **"Milestone X completata e caricata su GitHub. Revisione: https://github.com/cwebit1/SpaceGame/compare/<tag precedente>...milestone-X (per la milestone A: https://github.com/cwebit1/SpaceGame/tree/milestone-A). Salva le due risposte di Qwen in docs/reviews/milestone-X/errori.md e docs/reviews/milestone-X/soluzioni.md, poi chiudi questa sessione con /exit e rilancia avvia-spacegame.cmd."**
   - poi **fermati**: non iniziare la milestone successiva.
