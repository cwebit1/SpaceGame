# Revisioni esterne

Una cartella per milestone, con le due risposte di Qwen (prompt in `docs/REVIEW-QWEN.md`):

```
docs/reviews/
  milestone-A/
    errori.md     elenco errori (ID E1, E2…, gravità, file:riga)
    soluzioni.md  soluzione proposta per ogni ID + sezione "## Esito" scritta dall'agente
  milestone-B/
    …
```

L'agente, all'inizio della sessione successiva, carica la cartella su GitHub, corregge gli errori alti/medi e compila `## Esito`.
