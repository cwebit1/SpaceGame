# Lavoro in coda (agente)

Regole:
- Fai **il primo punto non spuntato**. Quando è finito e salvato con commit, cambia `- [ ]` in `- [x]` aggiungendo l'hash del commit, fai commit di questo file e passa al punto successivo.
- Scrivi i file a pezzi: **massimo 150 righe per scrittura** (le risposte lunghe vanno in timeout).
- Se un punto fallisce 3 volte, scrivi il problema nella sezione "Blocchi" in fondo, spunta il punto con `- [!]` e passa al successivo.
- **NON iniziare la milestone C** e non toccare codice di navi, difese, battaglia o fuoco rapido: cambieranno con la nuova specifica.
- Nessuna domanda all'utente: è fuori casa. Decidi seguendo `docs/design-modifiche.md`.

## Coda

- [x] **1. Correggi la specifica** `docs/superpowers/specs/2026-11-04-navi-battaglia-valore-design.md` seguendo ALLA LETTERA `docs/design-modifiche.md` (bd3ee6b):
  1. §1: velocità player x1–x10; nuovo moltiplicatore risorse player x1–x3000; CPU al 50% del player per risorse e ricerca.
  2. §2.1, §2b, note §4 e §5: elimina ogni bonus lineare (+x%/livello, 1+tech/100, 1+upgrade/50). Tutti i bonus di ricerche, motori e potenziamenti = crescita_costo^(0,25 × livelli oltre il requisito). Togli il tetto 40% alla schivata.
  3. Elimina la matrice ++/−− (§2.3), la colonna "Debole vs", i bonus ×2/×4/×5 delle difese e le regole per singola unità (rimbalzo <1%, esplosione <30%): la battaglia è per stack.
  4. §4 e §5: CALCOLA le statistiche con valore = M + 1,5·C + 2·D + 5·Lu (es. Light Fighter = 3.700). Struttura = valore × coefficiente; attacco = valore × moltiplicatore di classe (leggera 1, media 1,3, pesante 6). Scrivi i coefficienti scelti. Togli le abilità superate (Raffica, Fuoco ad area, Corazza pesante, Perforante, Carico pesante).
  5. Togli "1/impero" della Mothership e "max 1/pianeta" del Graviton Cannon.
  6. §6: niente tetto basso al fuoco rapido; FR = k × attacco / (scudo + struttura del bersaglio), k dalle tecnologie (Plasma vs corazze/difese, Laser vs navi agili, Ioni vs scudi). Ricalcola TUTTA la tabella con la formula e i valori del §4. Le navi leggere hanno FR 2–3 contro le pesanti.
  7. Scrivi §7 contromisure, §8 battaglia (motore probabilistico per stack; a parità di valore più round, con divario forte la flotta debole viene vaporizzata), §9 conveyor (Light +10 unità/s per livello; Medium e Heavy a parità di valore prodotto al secondo).
- [x] **2. Autocontrollo della specifica**: per ogni punto di `docs/design-modifiche.md` (sezioni 0–8) verifica che la specifica lo contenga e non lo contraddica; correggi. Cerca e rimuovi residui: "matrice", "+2%", "/100", "1/impero", "ritorni decrescenti". Commit con `docs/design-modifiche.md` incluso. (Verificato: tutte le sezioni 0-8 presenti e coerenti; residui puliti - "matrice" solo come "Nessuna matrice", "/100" solo in costi M/C/D, "1/impero" rimosso, "ritorni decrescenti" assenti, "+2%/L" solo come "Nessun bonus lineare") (9edc1c8)
- [x] **3. Revisione Qwen milestone A, errori rimasti**: verifica E6 (gravità alta) e correggilo se serve; poi i medi ancora aperti E1, E2, E7–E15, E32–E36, uno per commit, con test verdi. Salta quelli su statistiche navi, battaglia o fuoco rapido (scrivi "rinviato: nuovo design"). Aggiorna "## Esito" in `docs/reviews/milestone-A/soluzioni.md` e `docs/progress.md`. Commit e `git push origin master`. (E6, E1, E2, E7, E11–E15 risolti; E32–E36 rinviati: nuova spec) (966a4fe)
- [ ] **4. Piano di implementazione** della nuova specifica in `docs/superpowers/plans/2026-11-04-navi-battaglia-valore.md`, nello stesso formato del piano esistente (task, file, interfacce, test), indicando cosa cambia nel codice già fatto (milestone A/B, `game_config.json`) e i nuovi task per la milestone C ed E. Senza toccare il codice. Commit e `git push origin master`.
- [ ] **5. Fine**: aggiungi in fondo a questo file una riga che INIZIA con `CODA-FINITA` seguita da data e ora. Non fare altro.

## Blocchi

(vuoto)
