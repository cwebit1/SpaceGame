Sei un revisore di codice senior (PHP 8, MySQL, JavaScript vanilla). Progetto: SpaceGame, gioco di strategia spaziale web single-player contro CPU.

Repository: https://github.com/cwebit1/SpaceGame
Modifiche da revisionare (milestone A): https://github.com/cwebit1/SpaceGame/compare/e8df24f...80869e0

Documenti di riferimento nella repo:
- docs/superpowers/specs/2026-11-02-spacegame-design.md  (specifica: fonte di verità)
- docs/superpowers/plans/2026-11-02-spacegame.md        (piano: task della milestone, "Global Constraints", "Review Focus")
- docs/progress.md                                       (avanzamento)

Controlla SOLO i file modificati in questa milestone, verificando:
1. Aderenza alla specifica e ai task della milestone nel piano (requisiti mancanti o diversi).
2. Numeri di gioco scritti nel codice invece che in game_config.json.
3. Bug: logica, casi limite, numeri molto grandi (DOUBLE, notazione k/Mln/Mld…), concorrenza (lock per partita), recupero del tempo a blocchi.
4. Sicurezza: SQL injection, input non validato, credenziali nel codice.
5. Test mancanti o deboli rispetto a "Review Focus".

In questa risposta elenca SOLO gli errori, senza soluzioni. Formato (Markdown, in italiano):

# Errori milestone A
## Sintesi
(3-5 righe)
## Errori
| ID | Gravità (alta/media/bassa) | Categoria (1-5) | File:riga | Descrizione | Come si manifesta |
|---|---|---|---|---|---|---|
(ID: E1, E2, …)

Se una categoria non ha errori, scrivilo.