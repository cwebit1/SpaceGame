# SpaceGame — Design navi, battaglia, valore

## 0. Principio

**Ogni investimento ha un peso**. Tutto quello che si fa ha un valore proporzionale alle risorse spese e un effetto reale proporzionale: navi, difese, ricerche, **potenziamenti delle navi**, strutture, luna, Ammiraglio. Niente che sposti risorse senza riscontro.

- Il valore di ogni investimento entra nella Potenza, nella classifica, nel simulatore, nella PF e nelle valutazioni delle CPU.
- **Proporzionalità**: a parità di risorse spese, potenziare, ricercare o costruire devono rendere in modo confrontabile (nessuna scelta sempre inutile o sempre ottimale). I costi che crescono esponenzialmente devono avere effetti tarati di conseguenza.
- **Ricerche (motori compresi)**: il costo cresce ×1,6 per livello (×1,5 Astrofisica) ma il bonus segue il costo. Moltiplicatore = crescita_costo^(e × L), con **e = 0,25** (in `game_config.json`, regolabile per ricerca). Significa: **ogni raddoppio della spesa dà sempre +19%**, a qualunque livello e per qualunque cosa. Esempio Combustione (costo ×1,6/livello): L10 ×3,2 · L20 ×10,5 · L30 ×34 · L40 ×110 (velocità; consumo ridotto in proporzione). Vale per tutte le ricerche con bonus (armi, scudi, corazza, motori, stiva…).
- **Potenziamenti nave**: valore = risorse spese nei livelli; stessa regola (moltiplicatore = crescita_upgrade^(0,25 × L), con crescita_upgrade = 1,25; ogni raddoppio della spesa +19%: L10 ×1,75 · L40 ×9,3), confrontabile con il costruire più navi dello stesso tipo. Il valore effettivo di una flotta include i potenziamenti.
- **Simmetria CPU = Player**: le stesse funzioni `Combat::simulate`, `Stats::effective`, `Power::unitPower` valgono per entrambi. Nessun bonus nascosto per la CPU.
- **Numeri in `game_config.json`**: ogni costo, stat, formula, coefficiente, soglia, tempo è configurabile. Il codice contiene solo logica, zero numeri di gioco.
- **Determinismo con seed**: `Combat::simulate` accetta `int $seed`; stesso input + stesso seed = stesso output. Usato per simulatore UI (5 run con seed diversi) e per riproducibilità test.
- **Stack, non istanze**: unità identiche vengono raggruppate in *stack* (quantità, danno aggregato) per performance: 1M unità = 1 stack, non 1M oggetti.
- **Smantellamento strutture**: smontare un livello di una struttura riporta valore ed effetto a quelli del livello inferiore e libera lo spazio; rimborso del **50% delle risorse di quel livello** (valore in `game_config.json`).

## 1. Scala

Tutte le grandezze di gioco usano **scala lunga italiana** (migliaia, milioni, miliardi, bilioni, biliardi, trilioni, triliardi, quadrillioni, quadriliardi, quintillioni, quintiliardi, sestillioni, sestiliardi, … fino a 10⁶³; oltre notazione scientifica).

| Suffisso | Valore | Nome italiano |
|---|---|---|
| k | 10³ | mille |
| Mln | 10⁶ | milione |
| Mld | 10⁹ | miliardo |
| Bln | 10¹² | bilione |
| Bld | 10¹⁵ | biliardo |
| Tln | 10¹⁸ | trilione |
| Tld | 10²¹ | triliardo |
| Qln | 10²⁴ | quadrillione |
| Qld | 10²⁷ | quadriliardo |
| Qnl | 10³⁰ | quintillione |
| Qnd | 10³³ | quintiliardo |
| Sln | 10³⁶ | sestillione |
| Sld | 10³⁹ | sestiliardo |

**Input utente**: accetta `5k`, `2,5Mln`, `1,2Bld`, `100` (senza suffisso = unità). Virgola decimale italiana. `Format::parse()` normalizza in `float`; `Format::short()` e `Format::long()` formattano per UI.

**Range pratico**: livelli edifici/ricerca praticamente infiniti (costo `DOUBLE`, float PHP). Livello 100 → costi ~10³⁰–10⁴⁰, ancora in `DOUBLE`. Livello 1000 → 10³⁰⁰, serve notazione scientifica.

**Unità di misura tempo**:
- Secondo reale (wall-clock)
- Secondi di gioco = secondi reali × `games.speed` (**x1–x10**)
- Ora di gioco = 3600 secondi di gioco
- Giorno di gioco = 24 ore di gioco
- **Reali** (non accelerati): attesa luna 48 h reali, ricarica sposta-luna 24 h reali, Rift 2/giorno reale durata 6 h reali, campi asteroidi 3/giorno reale durata 12 h reali, sosta flotta 30 s reali, reset asteroidi mezzanotte `Europe/Rome`, ricostruzione Mothership 7 giorni reali, conservazione log 30/90 giorni reali.

**Impostazioni partita**:
- **Velocità player**: x1–x10 (tempo di gioco).
- **Moltiplicatore risorse player**: x1–x3000 (impostazione partita, determina la scala delle flotte).
- **CPU**: 50% del moltiplicatore del player, sia per le risorse sia per la ricerca (sostituisce i vecchi moltiplicatori CPU 0–velocità player).
- Il ritmo di crescita lo decide il player con il moltiplicatore x1–x3000: niente altra ritaratura del ritmo.

## 2. Valore e formule

### 2.1 Power Unit (PU) — unità atomica di valore

**PU** = √(Offesa × Resistenza)

- **Offesa** = attacco_effettivo × colpi_medi_per_round × (1 + Iniziativa / 200)
  - `attacco_effettivo` = attacco_base × moltiplicatore_ricerca_attacco × moltiplicatore_upgrade_attacco × moltiplicatore_ammiraglio_attacco × aura_mothership
  - `colpi_medi_per_round` = 1 per navi/difese
- **Resistenza** = (struttura + scudo × 6) ÷ (1 − schivata)
  - `struttura` = struttura_base × moltiplicatore_ricerca_corazza × moltiplicatore_upgrade_difesa × moltiplicatore_ammiraglio_difesa × aura_mothership
  - `scudo` = scudo_base × moltiplicatore_ricerca_scudi × moltiplicatore_upgrade_difesa × moltiplicatore_ammiraglio_difesa × aura_mothership (ricarica 100% ogni round)
  - `schivata` = base_dodge × moltiplicatore_upgrade_motori × moltiplicatore_tech_propulsione × moltiplicatore_ammiraglio_iniziativa (senza tetto: la proporzionalità richiede che possa crescere illimitatamente)

Dove:
- `moltiplicatore_ricerca_attacco` = crescita_costo_armi^(0,25 × livelli_extra_armi) × crescita_costo_laser^(0,25 × livelli_extra_laser) × crescita_costo_ioni^(0,25 × livelli_extra_ioni) × crescita_costo_plasma^(0,25 × livelli_extra_plasma)
- `moltiplicatore_ricerca_scudi` = crescita_costo_scudi^(0,25 × livelli_extra_scudi)
- `moltiplicatore_ricerca_corazza` = crescita_costo_corazza^(0,25 × livelli_extra_corazza)
- `moltiplicatore_upgrade_attacco` = crescita_upgrade^(0,25 × livello_upgrade_attacco)  (crescita_upgrade = 1,25)
- `moltiplicatore_upgrade_difesa` = crescita_upgrade^(0,25 × livello_upgrade_difesa)
- `moltiplicatore_upgrade_motori` = crescita_upgrade^(0,25 × livello_upgrade_motori)
- `moltiplicatore_tech_propulsione` = crescita_costo_propulsione^(0,25 × livelli_extra_propulsione)
- `moltiplicatore_ammiraglio_attacco` = crescita_ammiraglio^(0,25 × livello_tattica)
- `moltiplicatore_ammiraglio_difesa` = crescita_ammiraglio^(0,25 × livello_difesa)
- `moltiplicatore_ammiraglio_iniziativa` = crescita_ammiraglio^(0,25 × livello_iniziativa)
- `livelli_extra` = livello_ricerca − livello_richiesto_minimo (0 se sotto il requisito; i livelli richiesti sono già nel modello base)
- `crescita_ammiraglio` = 1,25 (stessa crescita dei potenziamenti, da config)

**PF flotta** = Σ PU (incluse navi in hangar) × (1 + aura_mothership)

**PF relativa** contro bersaglio specifico = PF_flotta × fattore_fuoco_rapido_medio (vedi §6)

**Power impero** = PF_flotte + PU_difese + (risorse_investite_strutture / 1000 × pesi) + (risorse_investite_ricerca / 1000 × pesi) + Medaglie_spese × 10
- Pesi risorse: M ×1, C ×1,5, D ×2, Lu ×5; strutture luna × % resistenza
- Potenziamenti già inclusi dentro PU delle unità

**Missile in silo** = 350 PU ciascuno (costo ~15k M/C/D → coefficiente calibrato)

### 2.2 Formule costi e tempi (da `game_config.json`)

Tutte le formule usano chiavi `snake_case` dal config. Nessun numero hardcoded nel codice PHP/JS.

| Elemento | Formula | Chiavi config |
|---|---|---|
| Curva livello (produzione, energia, costo) | `a * L * b^L` | `production.curve.a`, `production.curve.b` |
| Costo livello L (L≥1) | `base * growth^(L-1)` | `building.<code>.cost`, `building.<code>.growth` |
| Tempo edifici (ore) | `(m+c+d)/3000 ÷ (1+0.15*robot) * 0.25^nanite ÷ speed` | `formula.build_divisor` (3000), `formula.robot_factor` (0.15), `formula.nanite_factor` (0.25) |
| Tempo navi/difese (ore/unità) | `(m+c+d)/3000 ÷ (1+0.2*shipyard) * 0.25^nanite ÷ speed` | `formula.shipyard_factor` (0.2) |
| Tempo ricerca (ore) | `(m+c+d)/3000 ÷ (1+0.25*lab) * 0.25^nanite ÷ speed` | `formula.lab_factor` (0.25) |
| Tempo potenziamenti (ore) | `(m+c+d)/3000 ÷ (1+0.1*shipyard) * 0.25^nanite ÷ (1+0.01*logistica) ÷ speed` | `formula.upgrade_shipyard_factor` (0.1), `formula.logistica_factor` (0.01) |
| Costo upgrade livello L | `unit_cost * 2 * 1.25^(L-1)` (stesse proporzioni M/C/D) | `formula.upgrade_base_multiplier` (2), `formula.upgrade_growth` (1.25) |
| Costo upgrade L≥11 | + `lunarite = 50 * 1.25^(L-11)` | `formula.upgrade_lunarite_base` (50) |

### 2.3 Soglie (da config `combat.thresholds`)

- **Nessun tetto alla schivata**: la proporzionalità richiede che possa crescere illimitatemente.
- **Nessun rimbalzo scudo, nessuna esplosione per singolo stack, nessuna regola per singola unità**: la battaglia è per stack, non per istanze. Soglie per unità (rimbalzo <1%, esplosione <30%) eliminate.
- Round max: 6 (`combat.max_rounds`)
- Iniziativa gruppo: 10 punti (`combat.initiative_group`)
- Riparazione difese post-battaglia: 70% (`combat.defense_repair_rate`)
- Detriti navi: 30% M+C (`combat.debris_ship_rate`); difese: 10% (`combat.debris_defense_rate`)
- Decadimento detriti: 5%/giorno di gioco (`combat.debris_decay_daily`)
- Fuoco rapido max per classe: `combat.rapid_fire.max_by_class` (leggera 5, media 3, pesante 2, difese 3)

## 2b. Ricerche

Tutte le ricerche costano **M/C/D** (niente Lunarite), crescita ×**1,6** per livello (eccetto Astrofisica ×1,5). Tempo: `formula.research_time` = `(m+c+d)/3000 ÷ (1+0,25*lab) * 0.25^nanite ÷ speed`. Usano Lab e Naniti del pianeta di avvio (Rete di ricerca somma Lab di più pianeti).

**Regola dei bonus di ricerca (proporzionalità)**: il bonus non è più lineare (+x%/livello). Il moltiplicatore segue il costo: `moltiplicatore = crescita_costo^(0,25 × livelli_extra)`, dove `livelli_extra = max(0, livello_ricerca − livello_richiesto_minimo)`. Con crescita 1,6: L10 ×3,2 · L20 ×10,5 · L30 ×34 · L40 ×110. Ogni raddoppio della spesa dà sempre +19% (e = 0,25 in `game_config.json`). Vale per Armi, Scudi, Corazza, Laser, Ioni, Plasma, Propulsioni, Iperspazio, e tutte le ricerche con bonus percentuale.

| Ricerca | Prerequisiti | Costo base M/C/D | Liv. minimo per effetto | Note |
|---|---|---|---|---|
| Energetica | Lab 1 | 0/800/400 | 1 | +energia (tutte fonti) — bonus ∝ 1,6^(0,25×L) |
| Laser | Energetica 2 | 200/100/0 | 1 | danno laser (LF, Pulse Laser, Sentinel) — bonus ∝ 1,6^(0,25×L) |
| Ioni | Laser 5, Energetica 4 | 1000/300/100 | 1 | danno ionico (HF, Ion Cannon, Cruiser, Plasma Lance) — bonus ∝ 1,6^(0,25×L) |
| Plasma | Ioni 5, Energetica 8 | 2000/4000/1000 | 1 | produzione M/C/D (Dreadnought, Bomber, Plasma Lance, Graviton) — bonus ∝ 1,6^(0,25×L) |
| Iperspazio | Energetica 5, Scudi 5 | 0/4000/2000 | 1 | stiva (tutte navi) — bonus ∝ 1,6^(0,25×L); sblocca navi iperspaziali |
| Propulsione a combustione | Energetica 1 | 400/0/600 | 1 | velocità navi combustione (LF, Cargo, Colony, Probe, Recycler, Miner) — bonus ∝ 1,6^(0,25×L); consumo D ridotto in proporzione |
| Propulsione a impulso | Energetica 1 | 2000/4000/600 | 1 | velocità navi impulso (HF, Cruiser, Bomber, Battleship con Ipersp. 8) — bonus ∝ 1,6^(0,25×L); gittata missili +5 sistemi/L |
| Propulsione iperspaziale | Iperspazio 3 | 10k/20k/6k | 1 | velocità navi iperspaziali (Battleship, Battlecr, Dread, Mothership, Carrier) — bonus ∝ 1,6^(0,25×L) |
| Armi | Lab 4 | 800/200/0 | 1 | attacco (tutte navi/difese) — bonus ∝ 1,6^(0,25×L); base per moltiplicatori danno |
| Scudi | Energetica 3, Lab 6 | 200/600/0 | 1 | scudi (tutte navi/difese, incl. planetaria) — bonus ∝ 1,6^(0,25×L) |
| Corazza | Lab 2 | 1000/0/0 | 1 | struttura (tutte navi/difese) — bonus ∝ 1,6^(0,25×L) |
| Spionaggio | Lab 3 | 200/1000/200 | 1 | Info/furtività sonde — Score = sonde + 2×(Spy_att − Spy_dif) |
| Computer | Lab 1 | 0/400/600 | 1 | +1 flotta/L; iniziativa difese — bonus ∝ 1,6^(0,25×L) |
| Astrofisica | Spionaggio 4, Impulso 3 | 4000/8000/4000 | 1 | Colonie max = floor((L+1)/2), spedizioni — crescita costo ×1,5 |
| Rete di ricerca | Lab 10, Computer 8, Iperspazio 8 | 240k/400k/160k | 1 | Somma Lab di L pianeti |
| Terraformazione | Energetica 12, Naniti 1 | 50k/100k/50k | 1 | Sblocca Terraformer |
| Geologia | Lab 3 | 1k/500/0 | 1 | produzione miniere — bonus ∝ 1,6^(0,25×L); Deep Core Drill bonus ∝ 1,6^(0,25×L) |
| Fisica nucleare | Energetica 4 | 2k/2k/500 | 1 | energia nucleare — bonus ∝ 1,6^(0,25×L) |
| Fisica antimateria | Fisica nucleare 10, Plasma 10 | 200k/300k/100k | 1 | consumo Lu reattore — bonus ∝ 1,6^(0,25×L) |
| Tecnologia orbitale | Energetica 2, C 1 | 500/1k/200 | 1 | energia satelliti — bonus ∝ 1,6^(0,25×L); sblocca Energy Storage, Solar Satellite |
| Tecnologia mineraria | Geologia 2, Astrofisica 1 | 3k/2k/1k | 1 | estrazione Miner — bonus ∝ 1,6^(0,25×L); Mining Control bonus ∝ 1,6^(0,25×L) |
| Logistica | Computer 4 | 5k/5k/2k | 1 | velocità code (tutte) — bonus ∝ 1,6^(0,25×L); consumo D partenza — bonus ∝ 1,6^(0,25×L) |
| Economia galattica | Computer 2 | 2k/3k/0 | 1 | tassi mercato — bonus ∝ 1,6^(0,25×L); Trade Center bonus ∝ 1,6^(0,25×L) |
| Diplomazia | Computer 2 | 1k/3k/0 | 1 | guadagno relazioni — bonus ∝ 1,6^(0,25×L); Embassy bonus ∝ 1,6^(0,25×L) |
| Controspionaggio | Spionaggio 4 | 3k/6k/1k | 1 | rilevazione sonde — bonus ∝ 1,6^(0,25×L); Int. Agency bonus ∝ 1,6^(0,25×L), Decoy |
| Sensori avanzati | Spionaggio 5, Iperspazio 2 | 5k/8k/2k | 1 | gittata radar — bonus ∝ 1,6^(0,25×L); Phalanx, Deep Space Radar |
| Balistica | Impulso 2 | 3k/1k/2k | 1 | danno missili — bonus ∝ 1,6^(0,25×L); Missile Factory gittata, IPM danno |
| Teletrasporto | Iperspazio 6, Iperspaziale 4 | 50k/80k/40k | 1 | ricarica Teleporter — bonus ∝ 1,6^(0,25×L); Hyperspace Relay velocità bonus ∝ 1,6^(0,25×L) |
| Scienza militare | Armi 4, Corazza 4 | 5k/3k/1k | 1 | riparazione navi — bonus ∝ 1,6^(0,25×L); Repair Bay bonus ∝ 1,6^(0,25×L), Orbital Dock bonus ∝ 1,6^(0,25×L), Mothership bonus ∝ 1,6^(0,25×L) |
| Riciclaggio | Combustione 4 | 2k/2k/500 | 1 | raccolta detriti — bonus ∝ 1,6^(0,25×L); Recycling Plant bonus ∝ 1,6^(0,25×L) |
| Colonizzazione avanzata | Astrofisica 3 | 5k/10k/5k | 1 | risorse iniziali colonie — bonus ∝ 1,6^(0,25×L); Colony Hub bonus ∝ 1,6^(0,25×L) |
| Ingegneria quantistica | Computer 10, Iperspazio 6 | 40k/80k/20k | 1 | costo ricerche — bonus ∝ 1,6^(0,25×L); Quantum Archive bonus ∝ 1,6^(0,25×L), Interdiction Field |
| Ingegneria lunare | Naniti 1, Astrofisica 4 | 50k/50k/20k | 1 | resistenza luna — bonus ∝ 1,6^(0,25×L); Lunar Bulwark bonus ∝ 1,6^(0,25×L), Death Star |
| Climatologia | Energetica 8 | 10k/20k/10k | 1 | prob. eventi negativi — bonus ∝ 1,6^(0,25×L); Weather Control bonus ∝ 1,6^(0,25×L) |
| Caccia ai pirati | Armi 3, Spionaggio 3 | 3k/3k/1k | 1 | danni vs pirati — bonus ∝ 1,6^(0,25×L); Pirate Bounty bonus ∝ 1,6^(0,25×L), Admiral cacciatore |
| Death Star | Ingegneria lunare 8, Iperspaziale 12, Teletrasporto 6, NF 10 | 2M/4M/2M + 100k Lu | 1 | velocità luna mobile — bonus ∝ 1,6^(0,25×L); sblocca missione Sposta luna |

## 3. Classi

Tre classi di navi da combattimento: **leggera, media, pesante**. La pesante è un salto di scala netto (super-navi).

| Classe | Moltiplicatore attacco base | Profilo tipico | Tecnologie richieste minime |
|---|---|---|---|
| Leggera | ×1,0 | Veloci, economiche, FR alto vs pesanti | 1 tecnologia fuoco + 1 tecnologia difesa |
| Media | ~×1,3 | Bilanciate, versatili | 2 tecnologie fuoco + 1 tecnologia difesa |
| Pesante | ×5–7 | Lente, costose, consumo enorme, attacco con economia di scala | 3+ tecnologie fuoco + 2+ tecnologie difesa (livelli altissimi) |

**Regole di assegnazione**:
- Ogni nave da combattimento deve richiedere **almeno una tecnologia di fuoco** (Laser, Ioni, Plasma, Armi) e **almeno una tecnologia di difesa** (Corazza, Scudi).
- Navi civili (cargo, colonizzatore, sonda, riciclatore, miner) non hanno classe; sono prodotte dal Light Conveyor.
- Il **modello base** della nave = costo + tecnologie richieste ai livelli richiesti: definiscono profilo e statistiche base.
- **Ricerca oltre i requisiti**: ogni livello sopra quello richiesto dà un bonus percentuale e ha un valore (vedi §2b). I livelli richiesti non danno bonus aggiuntivi (già nel modello base), così nulla viene contato due volte.

**Assegnazione navi alle classi** (da confermare con tabella completa §4):

| Nave | Classe |
|---|---|
| Light Fighter | Leggera |
| Heavy Fighter | Leggera |
| Cruiser | Media |
| Battleship | Media |
| Battlecruiser | Pesante |
| Dreadnought | Pesante |
| Bomber | Media |
| Carrier | Pesante (nave appoggio) |
| Mothership | Pesante (super-nave unica) |

## 4. Statistiche navi (tabella)

**Valore nave** = costo pesato: **M×1 + C×1,5 + D×2 + Lu×5** (risorse base per 1 unità, senza crescita).
**Struttura** = valore × coefficiente_struttura (da config, per classe).
**Attacco base** = valore × moltiplicatore_classe (leggera ×1,0 · media ×1,3 · pesante ×6,0).
**Scudo base** = valore × coefficiente_scudo (da config, per classe).
**Velocità, stiva, consumo, iniziativa, peso, propulsione** = da tabella originale §7 spec 2026-11-02, copiati in `game_config.json`.
**Abilità** = mattoni configurabili (nessun codice hardcoded); ogni nave dichiara le sue abilità in config.

| Nave | Costo M/C/D/Lu | Valore | Classe | Struttura | Attacco | Scudo | Vel. | Stiva | Cons. | Iniz. | Peso | Prop. | Requisiti min | Abilità |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Light Fighter | 2,5k/800/0/0 | 3,7k | Leggera | 3,7k | 3.700 | 1.1k | 13k | 60 | 10 | 90 | 30 | Comb. | C1, Comb. 1 | Sciame (FR +2 vs pesanti) |
| Heavy Fighter | 5k/3,5k/0/0 | 10,25k | Leggera | 10,25k | 10.250 | 3k | 11k | 120 | 30 | 80 | 80 | Imp. | C3, Corazza 2, Imp. 2 | — |
| Cruiser | 18k/7k/2k/0 | 41,5k | Media | 41,5k | 53.950 | 12k | 16k | 700 | 120 | 65 | 250 | Imp. | C5, Imp. 4, Ioni 2 | — |
| Battleship | 42k/15k/0/0 | 79,5k | Media | 79,5k | 103.350 | 24k | 10,5k | 1.400 | 250 | 40 | 600 | Ipersp. | C7, Ipersp. 4 | — |
| Battlecruiser | 30k/40k/15k/0 | 137,5k | Pesante | 137,5k | 825.000 | 41k | 12k | 800 | 300 | 100 | 750 | Ipersp. | C8, Ipersp. 5, Laser 12 | Primo colpo (+50 iniziativa) |
| Dreadnought | 1,8M/1,2M/400k/0 | 5,5M | Pesante | 5,5M | 33.000.000 | 1,65M | 1,5k | 100k | 1.000 | 20 | 30k | Ipersp. | C12, Naniti 1, Plasma 10, Corazza 12, Ipersp. 8 | — |
| Bomber | 48k/26k/15k/0 | 128,5k | Media | 128,5k | 167.050 | 39k | 4,5k | 450 | 700 | 45 | 700 | Imp. | C8, Imp. 6, Plasma 5 | — |
| Carrier | 60k/40k/20k/0 | 175k | Pesante | 175k | 1.050.000 | 52,5k | 8k | 2.000 | 400 | 35 | 5k | Ipersp. | C9, Computer 8, Corazza 8, Ipersp. 6 | Hangar 100k (LF, HF, Probe) |
| Mothership | 50M/40M/20M/1M Lu | 170M | Pesante | 170M | 1.020.000.000 | 51M | 1k | 10M | 5.000 | 15 | — | Ipersp. | LS 8, Naniti 3, Ipersp. 12, Iperspaz. 12, Comp. 12, Rete 1 | Hangar 1M; aura +10% att/scudo/iniz; ripara 20% flotta |
| Small Cargo | 2,2k/2,2k/0/0 | 5,5k | — | 4,4k | 4 | 10 | 5,5k | 5,500 | 10 | 50 | 50 | Comb. | C2, Comb. 2 | — |
| Large Cargo | 6,5k/6,5k/0/0 | 16,25k | — | 13k | 6 | 25 | 7,8k | 26.000 | 40 | 45 | 150 | Comb. | C4, Comb. 6 | — |
| Colony Ship | 9k/18k/9k/0 | 54k | — | 27k | 0 | 90 | 2,6k | 7.000 | 500 | 10 | 400 | Imp. | C4, Imp. 3, Astrof. 1 | Fonda colonia |
| Espionage Probe | 0/900/0/0 | 1,35k | — | 900 | 0 | 0 | 80M | 0 | 1 | 99 | 5 | Comb. | C3, Comb. 3, Spio. 2 | — |
| Recycler | 9k/5,5k/2k/0 | 25,25k | — | 14,5k | 1 | 10 | 2,2k | 18.000 | 150 | 10 | 200 | Comb. | C4, Comb. 6, Scudi 2 | Raccolta detriti |
| Miner | 8k/4k/1k/0 | 18,5k | — | 12k | 0 | 50 | 6k | 50.000 | 60 | 0 | 120 | Comb. | C4, Tec. min. 1, Astrof. 2 | Estrazione 2.000/h |

**Note**:
- Valori calcolati con pesi M=1, C=1,5, D=2, Lu=5. Lunarite solo Mothership (1M Lu = 5M valore).
- **Struttura, Attacco, Scudo base sono modello base** (costo + tech richieste a livello minimo).
- **Bonus ricerca oltre requisiti**: ogni livello di Armi/Scudi/Corazza/Propulsioni **sopra il livello richiesto** moltiplica attacco/scudo/struttura/velocità per `crescita_costo^(0,25 × livelli_extra)`.
- **Bonus potenziamenti**: stesso schema delle ricerche — `crescita_upgrade^(0,25 × livello_upgrade)` (crescita_upgrade = 1,25). Ogni raddoppio della spesa = +19%. Nessun bonus lineare (+x%/L), nessun tetto alla schivata.
- **Ammiraglio**: crescita_ammiraglio = 1,25, e = 0,25 — stesso schema: `crescita_ammiraglio^(0,25 × livello_skill)`.
- **Nessun limite numero navi**: tolto "1 per impero" per Mothership (costo e slot hangar la limitano naturalmente).

## 5. Difese (tabella)

**Valore difesa** = costo pesato (M×1 + C×1,5 + D×2 + Lu×5) per unità.
**Struttura** = valore × coefficiente_struttura_difesa (da config, per classe: leggera 1,0 · media 1,0 · pesante 1,2).
**Attacco base** = valore × moltiplicatore_classe_difesa (leggera ×1,0 · media ×1,3 · pesante ×6,0).
**Scudo base** = valore × coefficiente_scudo_difesa (da config, per classe: leggera 0,3 · media 0,3 · pesante 0,25).
**Iniziativa base** = da tabella originale §8 spec 2026-11-02.
**Fuoco rapido calcolato** = k × attacco / (scudo + struttura bersaglio), con tetto max_fr (da config per classe).

| Difesa | Costo M/C/D/Lu | Valore | Classe | Struttura | Attacco | Scudo | Iniz. | FR vs | Requisiti | Abilità |
|---|---|---|---|---|---|---|---|---|---|---|
| Flak Battery | 2k/0/0/0 | 2k | Leggera | 2k | 2.000 | 600 | 70 | LF (FR 2-3) | C1 | Colpisce 5 bersagli |
| Pulse Laser | 2,5k/1k/0/0 | 4k | Leggera | 4k | 4.000 | 1.2k | 75 | HF (FR 2-3) | C2, Energetica 1, Laser 3 | Annulla schivata |
| Ion Cannon | 6k/4k/0/0 | 12k | Media | 12k | 15.600 | 3.6k | 50 | Cruiser (FR 2-3) | C4, Ioni 4 | Danno ×2 scudi |
| Rail Gun | 18k/8k/1k/0 | 35,5k | Media | 35,5k | 46.150 | 10,65k | 40 | Battleship (FR 2-3) | C6, Energetica 6, Armi 5, Scudi 2 | — |
| Sentinel Turret | 25k/20k/5k/0 | 72,5k | Pesante | 87k | 435.000 | 18k | 160 | Battlecr (FR 2-3) | C7, Computer 8, Laser 10 | — |
| SAM Site | 8k/6k/2k/0 | 21k | Media | 21k | 27.300 | 6.3k | 85 | Bomber (FR 2-3) | C4, Impulso 3, Computer 3 | Annulla bonus attacco vs difese |
| Torpedo Platform | 40k/25k/10k/0 | 92,5k | Pesante | 111k | 555.000 | 23k | 55 | Carrier (FR 2-3) | C8, Impulso 7, Armi 8 | Spara prima lancio hangar |
| Plasma Lance | 60k/50k/30k/0 | 175k | Pesante | 210k | 1.050.000 | 44k | 25 | Dreadnought (FR 2-3) | C10, Plasma 8 | +1% struttura bersaglio come danno extra |
| Graviton Cannon | 5M/4M/2M/0 | 13,5M | Pesante | 16,2M | 81.000.000 | 3,375M | 10 | Mothership (FR 2-3) | C14, Naniti 2, Ipersp. 12, Plasma 12 | Annulla aura; energia 500 |
| Planetary Shield | 20k/20k/0/0 | 50k | — | — | 0 | 50k | — | — | C3, Scudi 4 | Assorbe prima difese, rigenera ogni round; max 1; energia 100 |
| Anti-Ballistic Missile | 8k/0/2k/0 | 12k | — | — | — | — | — | Missili | Silo 2 | Intercetta 1 missile (10% fallimento); 1 slot silo |
| Interplanetary Missile | 12,5k/2,5k/10k/0 | 37,5k | — | — | 1M | — | — | Difese | Silo 4, Impulso 1 | 1 slot silo; vedi §11 |

**Note**:
- Valori calcolati con pesi M=1, C=1,5, D=2, Lu=5.
- **Coefficienti scelti**: struttura: leggera 1,0 / media 1,0 / pesante 1,2; scudo: leggera 0,3 / media 0,3 / pesante 0,25.
- **Nessuna matrice forte/debole**, **nessuna colonna "Debole vs"**, **nessun bonus ×2/×4/×5 vs tipi specifici**: sostituiti da **fuoco rapido calcolato** (vedi §6).
- **FR calcolato** = k × attacco / (scudo + struttura bersaglio), tetto `combat.max_rapid_fire` (config).
  - `k` dipende da tecnologie: Plasma (vs corazze/difese), Laser (vs navi agili), Ioni (vs scudi).
- **Riparazione post-battaglia**: 70% delle difese distrutte riparate gratis (da config `combat.defense_repair_rate`).
- **Potenziamenti difesa**: stesso schema ricerche — `crescita_upgrade^(0,25 × livello_upgrade)` (crescita_upgrade = 1,25). Ogni raddoppio della spesa = +19%. Nessun bonus lineare (+x%/L).
- **ABM/IPM**: non usano FR; meccanica missili dedicata (§11 spec originale).

## 6. Fuoco rapido (tabella)

**Meccanica classica**: con FR N contro il tipo colpito, probabilità (N−1)/N di sparare di nuovo immediatamente (stesso round, stesso gruppo iniziativa).
**FR calcolato**: `FR = min(max_fr, floor(k * attack / (target_shield + target_hull)))`.
- **Nessun tetto basso al fuoco rapido**: il FR emerge naturalmente dalla formula; `max_fr` serve solo come sicurezza assoluta.
- `k` = coefficiente tecnologia: **Plasma** → `k_plasma` (vs corazze/difese), **Laser** → `k_laser` (vs navi agili/leggere), **Ioni** → `k_ions` (vs scudi alti). Da `game_config.json` (`combat.rapid_fire.k_plasma`, etc.).
- `max_fr` = tetto per classe (leggera 5, media 3, pesante 2, difese 3). Da `game_config.json` (`combat.rapid_fire.max_by_class`).
- Il FR si applica **solo se l'attaccante ha la tecnologia corrispondente a livello > 0**.
- **Navi leggere hanno FR 2–3 contro le pesanti** (emerge dalla formula: attacco alto / (scudo+struttura bassa dei bersagli pesanti)).
- Tabella generata per ogni nave/difesa con i valori del §4 e riportata sotto.

### 6.1 FR navi (calcolato con stats base + tech livelli richiesti del §4)

| Attaccante | FR vs Light Fighter | FR vs Heavy Fighter | FR vs Cruiser | FR vs Battleship | FR vs Battlecr | FR vs Dreadnought | FR vs Bomber | FR vs Carrier | FR vs Difese |
|---|---|---|---|---|---|---|---|---|---|
| Light Fighter | 1 | 1 | 2 | 3 | 3 | 3 | 2 | 2 | 2 |
| Heavy Fighter | 1 | 1 | 2 | 3 | 3 | 3 | 2 | 2 | 2 |
| Cruiser | 2 | 2 | 1 | 2 | 2 | 2 | 2 | 2 | 2 |
| Battleship | 1 | 1 | 2 | 1 | 2 | 2 | 2 | 2 | 2 |
| Battlecruiser | 1 | 1 | 2 | 2 | 1 | 2 | 1 | 1 | 2 |
| Dreadnought | 1 | 1 | 1 | 2 | 2 | 1 | 1 | 1 | 1 |
| Bomber | 2 | 2 | 2 | 2 | 2 | 1 | 1 | 1 | 3 |
| Carrier | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 |
| Mothership | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 |

### 6.2 FR difese (calcolato con stats base + tech livelli richiesti del §5)

| Difesa | FR vs LF | FR vs HF | FR vs Cruiser | FR vs Battleship | FR vs Battlecr | FR vs Dreadnought | FR vs Bomber | FR vs Carrier | FR vs Altre difese |
|---|---|---|---|---|---|---|---|---|---|
| Flak Battery | 3 | 3 | 2 | 2 | 1 | 1 | 2 | 1 | 2 |
| Pulse Laser | 1 | 3 | 2 | 2 | 2 | 1 | 2 | 1 | 2 |
| Ion Cannon | 1 | 1 | 3 | 2 | 2 | 2 | 3 | 2 | 2 |
| Rail Gun | 1 | 1 | 2 | 3 | 2 | 1 | 2 | 2 | 2 |
| Sentinel Turret | 1 | 1 | 2 | 2 | 3 | 2 | 1 | 2 | 2 |
| SAM Site | 1 | 2 | 2 | 2 | 2 | 2 | 3 | 2 | 2 |
| Torpedo Platform | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 3 | 1 |
| Plasma Lance | 1 | 1 | 1 | 2 | 2 | 3 | 1 | 1 | 2 |
| Graviton Cannon | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 |

**Note**:
- FR = 1 significa nessun fuoco rapido (sparo singolo).
- Valori sopra sono **FR base** (con tech ai livelli minimi richiesti). **Ricerca oltre i requisiti** aumenta `attack` → aumenta FR.
- **Potenziamenti Attacco** (schema `crescita_upgrade^(0,25 × livello)`) aumentano FR proporzionalmente.
- **Nessuna matrice ++/−−**: il fuoco rapido sostituisce completamente la matrice forte/debole.

## 7. Contromisure

Le navi pesanti (classe pesante) sono potenti ma hanno contromisure deliberate:

1. **Navi leggere "zanzare"**: Light Fighter e Heavy Fighter hanno FR 2–3 contro le navi pesanti (Battlecruiser, Dreadnought, Mothership). Emerge dalla formula FR = k × attacco / (scudo + struttura bersaglio): le navi leggere hanno attacco proporzionale al valore (×1,0) ma le navi pesanti hanno scudo+struttura enormi, quindi il FR delle leggere vs pesanti è >1. Con ricerca Armi/Laser/Ioni alta, Light Fighter raggiunge FR 3 vs Dreadnought.

2. **Difesa dedicata anti-pesanti**: **Graviton Cannon** (difesa pesante) ha FR alto vs Mothership e annulla l'aura Mothership. **Plasma Lance** ha FR 3 vs Dreadnought. **Sentinel Turret** ha FR 3 vs Battlecruiser.

3. **Costo tecnologico altissimo**: navi pesanti richiedono livelli di ricerca molto alti (Plasma 10, Corazza 12, Iperspaziale 8, Naniti 1 per Dreadnought; Iperspaziale 12, Computer 12, Rete 1 per Mothership). Chi non investe in ricerca non può costruirle.

4. **Lentezza e consumo enorme**: velocità 1.500–8.000 (vs 11.000–16.000 delle medie/leggere); consumo deuterio 300–5.000/ora. Spostarle costa molto e le rende vulnerabili a intercettazioni.

5. **Nessun limite numerico**: tolto "1 per impero" della Mothership e "max 1/pianeta" del Graviton Cannon. Il costo in risorse, slot hangar (Mothership), tecnologia e tempo di produzione le limita naturalmente.

## 8. Battaglia

### 8.1 Motore probabilistico per stack (stile OPBE)

La battaglia simula **stack** (gruppi di unità identiche), non singole unità. Costo computazionale O(numero_tipi), non O(numero_unità): regge milioni di navi.

**Algoritmo per round**:
1. **Ordinamento per iniziativa**: tutti gli stack (attaccante + difensore) ordinati per iniziativa decrescente. Gruppo iniziativa = 10 punti (config `combat.initiative_group`).
2. **Selezione bersaglio**: ogni stack che spara sceglie un bersaglio tra gli stack nemici **vivi** con probabilità proporzionale al loro "peso bersaglio" = quantità × (struttura + scudo). Niente "bersaglio casuale per unità" — è per stack, pesato per consistenza.
3. **Calcolo colpo**: danno = attacco_effettivo × moltiplicatori_tecnologia × moltiplicatori_upgrade × aura_mothership.
4. **Applicazione danno**: 
   - Prima allo scudo (ricarica 100% ogni round).
   - Eccedenza alla struttura.
   - **Nessun rimbalzo <1% scudo** (eliminato).
   - **Nessuna esplosione <30% struttura** per singolo colpo (eliminata — la distruzione è deterministica per stack: se struttura ≤ 0, lo stack muore).
5. **Fuoco rapido**: se FR > 1 contro quel tipo, probabilità (FR−1)/FR di sparare di nuovo subito (stesso round, stesso gruppo iniziativa). Il FR è calcolato come da §6.
6. **Fine round**: scudi ricaricati al 100%. Stack con struttura ≤ 0 rimossi.
7. **Round successivo**: fino a `combat.max_rounds` (6) o finche un lato non ha più unità.

**Risultati attesi (valori attesi, niente RNG per simulazione)**:
- **A parità di valore effettivo** (flotta × bonus ricerche × upgrade × ammiraglio): lo scontro dura **più round** (3–6), consuma deuterio, genera detriti, nessuna parte viene "vaporizzata" al primo round.
- **Con divario forte** (es. bonus ricerca +350% vs +0%, o flotta 10× valore): la flotta debole **viene vaporizzata in 1–2 round**. Il divario si guadagna investendo in ricerca, potenziamenti, navi migliori.

### 8.2 Elementi rimossi dalla vecchia specifica

- Matrice ++/−− (sostituita da FR calcolato §6)
- Colonna "Debole vs" e bonus ×2/×4/×5 (sostituiti da FR calcolato)
- Regole per singola unità: rimbalzo scudo <1%, esplosione <30% struttura
- Abilità superate: **Raffica** (burst), **Fuoco ad area** (area damage), **Corazza pesante** (heavy armor), **Perforante** (piercing), **Carico pesante** (heavy payload) — sostituite da FR calcolato e statistiche base
- Formula Potenza (§12 vecchia spec) — sostituita da PU (§2.1)

## 9. Conveyor (produzione di massa per classe)

Tre strutture, una per classe di navi da combattimento:

| Conveyor | Classe prodotta | Navi incluse | Difese incluse |
|---|---|---|---|
| **Light Conveyor** | Leggera | Light Fighter, Heavy Fighter, Small Cargo, Large Cargo, Colony Ship, Espionage Probe, Recycler, Miner, Solar Satellite | Flak Battery, Pulse Laser |
| **Medium Conveyor** | Media | Cruiser, Battleship, Bomber | Ion Cannon, Rail Gun, SAM Site |
| **Heavy Conveyor** | Pesante | Battlecruiser, Dreadnought, Carrier, Mothership | Sentinel Turret, Torpedo Platform, Plasma Lance, Graviton Cannon |

**Meccanica**:
- Il **Cantiere** (Shipyard) resta il prerequisito per sbloccare i Conveyor (livello Shipyard minimo per classe).
- Ogni Conveyor produce in **continuo** navi e difese della sua classe.
- **Light Conveyor: +10 unità al secondo per livello** (livello 1 = 10 u/s, livello 10 = 100 u/s, ecc.).
- **Medium e Heavy Conveyor**: stesso principio, con ritmo in unità/s proporzionale al **valore medio delle navi della classe**. Obiettivo: **stesso "valore prodotto al secondo" a parità di livello**.
  - Esempio: se Light Conveyor L1 produce 10 u/s di navi da ~3.700 valore = 37.000 valore/s, Medium Conveyor L1 produce ~37.000 / 41.500 ≈ 0,9 u/s (1 Cruiser ogni ~1,1 s), Heavy Conveyor L1 produce ~37.000 / 137.500 ≈ 0,27 u/s (1 Battlecruiser ogni ~3,7 s).
- Il tempo di produzione delle unità della classe è **dato dal suo Conveyor**, non dal Cantiere.
- Valori iniziali, crescita costi, e produzione base per livello in `game_config.json` (`conveyor.*`).