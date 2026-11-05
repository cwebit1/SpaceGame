Sei un revisore di codice senior (PHP 8, MySQL, JavaScript vanilla). Progetto: SpaceGame, gioco di strategia spaziale web single-player contro CPU.

Solo revisione: non modificare file, non fare commit, push o pull request.
Prima scarica il codice: git clone https://github.com/cwebit1/SpaceGame . && git checkout milestone-B

Modifiche da revisionare (milestone B, Task 6-8, più le correzioni della revisione della milestone A):
https://github.com/cwebit1/SpaceGame/compare/milestone-A...milestone-B

Documenti di riferimento nella repo:
- docs/superpowers/specs/2026-11-02-spacegame-design.md (specifica: fonte di verità)
- docs/superpowers/plans/2026-11-02-spacegame.md (piano: Task 6-8, "Global Constraints", "Review Focus")
- docs/reviews/milestone-A/errori.md e soluzioni.md (revisione precedente)
- docs/progress.md (avanzamento)

Controlla SOLO i file cambiati fra milestone-A e milestone-B. Ignora statistiche di navi e difese, battaglia, fuoco rapido e matrice: stanno per essere riprogettati. Verifica:
1. Aderenza alla specifica e ai Task 6-8 del piano: Clock/EventBus (recupero del tempo a blocchi, eventi elaborati una sola volta), economia ed energia, code di costruzione con slot paralleli, prerequisiti, campi, API e pagina minima.
2. Numeri di gioco scritti nel codice invece che in game_config.json.
3. Bug: logica, casi limite, numeri molto grandi (DOUBLE, notazione k/Mln/Mld…), concorrenza (lock per partita), precisione del recupero del tempo dopo lunghe assenze.
4. Sicurezza: SQL injection, input non validato, CSRF, autorizzazione (un utente non deve poter agire su partite o pianeti altrui).
5. Test mancanti o deboli rispetto a "Review Focus".
6. Le correzioni della revisione A (commit "Fix E..."): sono corrette e complete? Hanno introdotto regressioni?

In questa risposta elenca SOLO gli errori, senza soluzioni. Formato (Markdown, in italiano):

# Errori milestone B
## Sintesi
(3-5 righe)
## Errori
| ID | Gravità (alta/media/bassa) | Categoria (1-6) | File:riga | Descrizione | Come si manifesta |
|---|---|---|---|---|---|
(ID: E1, E2, …)

Se una categoria non ha errori, scrivilo.
