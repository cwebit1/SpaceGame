# Modifiche di design decise (da riportare nella specifica)

> **Per l'agente:** queste modifiche NON sono ancora nella specifica. Non applicarle finché non vengono riportate in `docs/superpowers/specs/2026-11-02-spacegame-design.md` e nel piano.

Stato: decise con Enrico il 2026-11-04. Da trasformare in sezioni di specifica prima delle milestone che le toccano (C per le navi, E per la battaglia, economia per B già fatta: ritaratura di `game_config.json`).

## 0. Principio generale: ogni investimento ha un peso
- **Tutto quello che si fa ha un valore** proporzionale alle risorse spese e un effetto reale proporzionale: navi, difese, ricerche, **potenziamenti delle navi**, strutture, luna, Ammiraglio. Niente che sposti risorse senza riscontro.
- Il valore di ogni investimento entra nella Potenza, nella classifica, nel simulatore, nella PF e nelle valutazioni delle CPU.
- **Proporzionalità**: a parità di risorse spese, potenziare, ricercare o costruire devono rendere in modo confrontabile (nessuna scelta sempre inutile o sempre ottimale). I costi che crescono esponenzialmente devono avere effetti tarati di conseguenza.
- **Ricerche (motori compresi)**: oggi il costo cresce ×1,6 per livello ma il bonus è lineare (es. Combustione +10%/livello: livello 10 = ×2, livello 40 = ×5, con un costo milioni di volte più alto). Regola nuova: **il miglioramento segue il costo**. Moltiplicatore = crescita_costo^(e × L), con **e = 0,25** (in `game_config.json`, regolabile per ricerca). Significa: **ogni raddoppio della spesa dà sempre +19%**, a qualunque livello e per qualunque cosa. Esempio Combustione (costo ×1,6/livello): L10 ×3,2 · L20 ×10,5 · L30 ×34 · L40 ×110 (velocità; consumo ridotto in proporzione). Vale per tutte le ricerche con bonus (armi, scudi, corazza, motori, stiva…).
- **Potenziamenti nave**: valore = risorse spese nei livelli; stessa regola (moltiplicatore = 1,25^(e × L), ogni raddoppio della spesa +19%: L10 ×1,75 · L40 ×9,3), confrontabile con il costruire più navi dello stesso tipo. Il valore effettivo di una flotta include i potenziamenti.

## 1. Scala del gioco
- Obiettivo: **battaglie con milioni di navi**.
- **Velocità player**: da x1–x100 a **x1–x10** (tempo di gioco).
- **Nuovo moltiplicatore risorse player: x1–x3000** (impostazione della partita), per arrivare alle flotte da milioni.
- **CPU: 50% del moltiplicatore del player**, sia per le risorse sia per la ricerca (sostituisce i moltiplicatori CPU 0–velocità player).
- Il ritmo di crescita lo decide il player con il moltiplicatore x1–x3000: niente altra ritaratura del ritmo.

## 2. Navi: valore e profilo
- **Valore** della nave = costo pesato (M×1, C×1,5, D×2, Lu×5).
- **Struttura proporzionale al valore.**
- **Attacco = valore × moltiplicatore di classe**: economia di scala forte solo per la classe pesante (proposta: leggera ×1, media ~×1,3, pesante ×5–7).
- **Profilo fuoco/difesa** ricavato dalle tecnologie richieste (fuoco: Laser, Ioni, Plasma, Armi; difesa: Corazza, Scudi; mobilità: propulsioni). Ogni nave da combattimento deve richiedere almeno una tecnologia di fuoco e una di difesa.
- **Modello base** della nave = costo + tecnologie richieste ai livelli richiesti: definiscono profilo e statistiche base.
- **Ricerca oltre i requisiti**: ogni livello sopra quello richiesto dà un bonus percentuale e ha un valore (vedi 2b). I livelli richiesti non danno bonus aggiuntivi (già nel modello base), così nulla viene contato due volte.

## 2b. Valore delle ricerche
- Le ricerche contano tantissimo in battaglia (nel report reale: bonus +350–435%), quindi **hanno un valore esplicito**.
- **Valore di una ricerca** = risorse pesate spese per tutti i suoi livelli (stessi pesi del valore navi).
- **Valore effettivo di una flotta** = Σ valore navi × moltiplicatori delle ricerche **oltre i requisiti** di ogni nave (attacco, scudi, corazza, motori) del proprietario. È quello che usano Potenza, classifica, simulatore, PF relativa e le CPU per decidere se attaccare.
- I bonus per livello delle ricerche di combattimento devono essere rilevanti e crescere senza tetto basso, così chi investe in ricerca batte flotte più grandi.
- Il report di battaglia mostra per ogni lato i bonus di attacco/scudo/corazza (come nel report reale).

## 3. Classi
- Tre classi: **leggera, media, pesante**. La pesante è un salto di scala netto (super-navi).
- Assegnazione delle navi alle classi: da definire con la tabella completa.

## 4. Fuoco rapido
- Meccanica classica: con FR N contro il tipo colpito, probabilità (N−1)/N di sparare di nuovo.
- **FR calcolato**: k × attacco / (scudo + struttura del bersaglio), con tetto; k dipende dalle tecnologie (Plasma contro corazze e difese, Laser contro navi agili, Ioni contro scudi).
- Sostituisce la matrice forte/debole (++/−−).
- Tabella "fuoco rapido contro / subisce da" generata per ogni nave e riportata nella specifica.

## 5. Contromisure
- Navi leggere con FR 2–3 contro le pesanti ("zanzare").
- Una difesa dedicata contro la classe pesante.
- Le pesanti si pagano con tecnologie altissime, lentezza, consumo enorme.
- **Nessun limite di numero** per nessuna nave: togliere "1 per impero" della Mothership.

## 6. Battaglia
- **Motore probabilistico per tipo di nave** (valori attesi, stile OPBE): costo indipendente dal numero di navi, regge milioni di unità.
- Da rivedere nella specifica: bersaglio casuale per unità, esplosione sotto il 30%, colpi sotto l'1% dello scudo, abilità superate (Raffica, Fuoco ad area, Corazza pesante, Perforante, Carico pesante), formula della Potenza (§12).

## 7. Conveyor (produzione di massa per classe)
- Tre strutture: **Light Conveyor, Medium Conveyor, Heavy Conveyor**, una per classe.
- Ogni conveyor produce in continuo **navi e difese della sua classe** (navi civili comprese, es. Light Conveyor: cargo leggeri e pesanti, caccia, satelliti, difese leggere).
- **Light Conveyor: +10 unità al secondo per livello.**
- Medium e Heavy: stesso principio, con un ritmo in unità/s proporzionale al valore delle navi della classe (stesso "valore prodotto al secondo" a parità di livello), così una nave pesante richiede molto più tempo di una leggera.
- Il Cantiere resta il requisito per sbloccarli; il tempo di produzione delle unità della classe è dato dal suo conveyor.
- Valori iniziali e costi dei livelli in `game_config.json`.

## 8. Lezioni da un report di battaglia reale (genere, scala milioni)
Attaccante: 62 milioni di una sola nave media + poche altre. Difensore: ~114 milioni di unità miste, comprese migliaia di super-navi e cannoni anti-pesanti. Risultato: difensore annientato **nel primo round**; potenza di fuoco 1.000 miliardi contro 39 miliardi; gli scudi hanno assorbito lo 0,25% del danno; bonus tecnologici +350–435%.
Da evitare nel nostro design:
- **Battaglie decise in un round anche a parità**: a parità di valore effettivo lo scontro deve durare più round; ma con un divario reale (ricerche, potenziamenti, valore) la flotta più debole **deve poter essere vaporizzata** in un round. Il divario si guadagna investendo.
- **Scudi irrilevanti ad alta scala**: lo scudo deve scalare con la nave (es. riduzione percentuale o riserva proporzionale al valore), non essere un numero fisso.
- **Vince il monotipo**: il fuoco rapido e le tecnologie devono rendere sempre conveniente una flotta mista; ogni nave deve avere almeno un contro efficace.
- **Super-navi poche e inutili contro decine di milioni di medie**: la classe pesante deve restare decisiva anche contro grandi numeri (coerente con FR calcolato e attacco con economia di scala).
Da tenere: numeri fino a migliaia di miliardi (già gestiti con DOUBLE e scala lunga), detriti e saccheggio enormi, difese che in parte sopravvivono e si riparano.

## 9. Altri punti da rivedere
- Abilità come mattoni configurabili (navi nuove senza codice).
- Cambi del simulatore di battaglia già in specifica (pulsante Simula, 5 simulazioni).
- Fluidità dell'interfaccia senza ricaricare la pagina (consigli di Qwen da recuperare), prima della milestone I.
