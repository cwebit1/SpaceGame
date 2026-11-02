# Avvio sviluppo con Free Claude Code

1. Avvia il proxy FCC (admin: http://127.0.0.1:8082/admin) e assicurati che un modello sia attivo.
2. Apri un terminale in `D:\CweAgent\projects\SpaceGame` e avvia Claude Code puntato al proxy, come indicato da FCC (in genere variabile `ANTHROPIC_BASE_URL=http://127.0.0.1:8082`).
3. Verifica che il plugin **superpowers** sia attivo in quella sessione.
4. Incolla il prompt qui sotto.

---

```
Sei lo sviluppatore di SpaceGame. Leggi per intero:
- docs/superpowers/specs/2026-11-02-spacegame-design.md (specifica: fonte di verità di regole e numeri)
- docs/superpowers/plans/2026-11-02-spacegame.md (piano)

Esegui il piano task per task con la skill superpowers:subagent-driven-development
(un implementatore e un revisore per ogni task). Regole:
- Rispetta "Global Constraints" e "Review Focus" del piano.
- TDD: prima il test che fallisce, poi il codice, poi test verdi, poi commit.
- Nessun numero di gioco nel codice: tutto in game_config.json, copiato dalla specifica.
- Se un valore manca o è ambiguo nella specifica, FERMATI e chiedi: non inventare.
- Non inserire mai credenziali: config.local.php lo compila l'utente.
- Alla fine di ogni Milestone (A, B, C, ...) fermati, riassumi cosa funziona e aspetta conferma.
Inizia dal Task 1.
```
