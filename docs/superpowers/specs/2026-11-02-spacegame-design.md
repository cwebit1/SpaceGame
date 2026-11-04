# SpaceGame — Specifica di design

Gioco di strategia spaziale per browser, **single player contro CPU**, persistente in tempo reale. Contenuti originali (nomi generici di genere, numeri/formule/albero propri).

Legenda: **L** = livello · **M/C/D/Lu** = Metallo/Cristallo/Deuterio/Lunarite · "ora di gioco" = ora reale × velocità del player · "reale" = non accelerato · ogni numero di questo documento è un **valore iniziale in `game_config.json`**.

---

## 1. Architettura

| Elemento | Scelta |
|---|---|
| Backend | PHP 8 senza framework, PDO MySQL, API JSON |
| DB | MySQL (Aruba hosting Linux in produzione, MySQL/MariaDB locale in sviluppo) |
| Frontend | HTML/CSS/JS vanilla, PWA installabile, mobile-first Android, nessuna build |
| Numeri | `DOUBLE` in DB, float in PHP (livelli praticamente infiniti) |
| Tempo | tabella `events` ordinata per orario; a ogni richiesta `Clock` elabora gli eventi fino ad "adesso" a blocchi (max ~2 s per richiesta, riprende alla successiva); `cron.php` opzionale con chiave |
| Config | `game_config.json` unico, validato all'avvio (errore indica il percorso del campo). **Nessun numero di gioco nel codice.** Ogni partita salva una copia; pulsante "aggiorna parametri" per partita |
| Accesso | un solo utente (login, password hash, sessione, CSRF, query preparate); credenziali DB in `config.local.php` fuori dalla root pubblica se possibile |
| Partite | multiple; schermata "Le mie partite": crea / apri / elimina (conferma) |
| Grafica | segnaposto CSS/SVG; asset definitivi generati più avanti con Qwen tramite manifest JSON (formato da definire), caricati da `assets/` |

**Struttura file**
```
public/            index.html, css/, js/, assets/, manifest.webmanifest, sw.js
public/api/        index.php (router)
core/              Config, Db, Clock, Economy, Energy, Queue, Fleet, Combat, Espionage,
                   Missiles, Moon, Pirates, Gauntlet, Admiral, Mining, Market, Diplomacy,
                   Events, Power, Galaxy, Cpu/Brain, Cpu/Simple, Format
install/           install.php (crea tabelle) — da cancellare dopo l'uso
cron.php           opzionale
game_config.json
config.local.php   (non versionato)
tests/
```

**Principio CPU = player**: tutte le azioni (costruire, ricercare, inviare flotte, commerciare) passano dalle **stesse funzioni** con gli stessi controlli. Nessun bonus nascosto.

**Tabelle principali**: `user`, `games`, `empires`, `planets` (include lune, `is_moon`), `buildings`(corpo, tipo, livello, completato_il), `research`, `units`(corpo, tipo, quantità, danno), `upgrades`(impero, unità, ramo, livello), `queues`, `fleets`, `events`, `reports`, `log`, `debris`, `asteroid_progress`, `market`, `offers`, `relations`, `pacts`, `pirates`, `admiral`, `scores`.

---

## 2. Impostazioni partita

| Impostazione | Range | Modificabile in partita |
|---|---|---|
| Velocità player | x1–x100 | no |
| Moltiplicatore risorse CPU | 0–velocità player | **sì** (globale o per CPU) |
| Moltiplicatore ricerca CPU | 0–velocità player | **sì** |
| Galassie × sistemi | 3–9 × 100–499 | no |
| Densità CPU (% posizioni occupate) | 0–100% (def. 40%) | no |
| Quota CPU vive | def. 70% dei pianeti CPU | no |
| Tetto imperi vivi | def. 150 | no |
| Dimensione pianeti/lune CPU | 10–100% della base | no |
| Livello di sfida CPU | facile/normale/difficile/esperto | sì |
| Coalizioni anti-player | on/off | sì |

---

## 3. Galassia e pianeti

- Coordinate `galassia:sistema:posizione`. Posizioni: **1–15 pianeti**, **16 Warp pirata**, **17 asteroide Metallo, 18 Cristallo, 19 Deuterio, 20 Lunarite**.
- **Spazi**: pianeta **2000** base, +200 per livello Terraformer. Ogni livello di struttura occupa 1 spazio (Terraformer e Nanoforge no).
- **Posizione** (temperatura): 1–3 solare +30% deut −30% · 4–6 +15%/−15% · 7–9 0 · 10–12 −15%/+15% · 13–15 −30%/+30%. Geotermica: 1–3 +50%, 4–6 +25%, 7–9 0, 10–12 −25%, 13–15 −50%.
- **Tipo** (casuale): Roccioso M +20% · Cristallino C +20% · Oceanico D +20% · Desertico solare +20% · Standard.
- **Colonie**: max = floor((Astrofisica+1)/2) oltre al pianeta madre.
- **Spazi da battaglie** (attacchi a pianeta o luna): difensore vince **+50** · attaccante vince **−50** · pareggio 0 · bombardamento vinto **−100** · bombardamento fallito **+50**. Mai sotto la base (pianeta 2000+Terraformer, luna base Nanoforge). Se spazi occupati > disponibili: non si costruisce finché non si rientra.

---

## 4. Risorse, produzione, energia

- Risorse: **Metallo, Cristallo, Deuterio, Lunarite** + Energia (non accumulabile senza Energy Storage).
- Produzione base gratuita per pianeta: 20 M/h, 10 C/h.
- Produzione calcolata **in modo esatto** tra eventi: `prod × fattore_energia × bonus × velocità`, ferma a capacità magazzino.
- **Fattore energia** = min(1, energia disponibile / richiesta). Ordine priorità di default in carenza: difese/scudo → radar/phalanx → miniere/strutture (personalizzabile). Prima della carenza si scarica l'Energy Storage.

### 4.1 Estrazione (pianeta)
| Struttura | Produzione/h | Energia | Costo base M/C/D | Crescita |
|---|---|---|---|---|
| Miniera di metallo | 40·L·1,08^L | 8·L·1,07^L | 80/20/0 | ×1,55 |
| Miniera di cristallo | 25·L·1,08^L | 9·L·1,07^L | 60/35/0 | ×1,60 |
| Sintetizzatore di deuterio | 12·L·1,08^L | 12·L·1,07^L | 220/70/0 | ×1,55 |

### 4.2 Energia
| Fonte | Energia | Consumo | Costo base | Crescita | Prerequisiti |
|---|---|---|---|---|---|
| Power Plant | 12·L·1,06^L | — | 50/20/0 | ×1,5 | — |
| Centrale solare | 22·L·1,08^L × posizione × tipo | — | 70/30/0 | ×1,55 | — |
| Geothermal Plant | 30·L·1,08^L × fattore calore | — | 300/150/0 | ×1,6 | Geologia 1 |
| Nuclear Plant | 28·L·1,15^L | — | 600/300/0 | ×1,75 | Fisica nucleare 1, Robot 3 |
| Centrale a fusione | 35·L·1,09^L | D 6·L·1,08^L /h | 900/360/180 | ×1,70 | Energetica 3, Sintetizzatore 5 |
| Antimatter Reactor (pianeta o luna) | 500·L·1,12^L | Lu 2·L·1,1^L /h | 200k/150k/50k + 10k Lu | ×1,8 | Fisica antimateria 1, luna con NF 3 |
| Solar Satellite (unità) | 25 cad. × bonus solare | — | 0/2k/500 cad. | — | Tecnologia orbitale 1, C 1 |
| Energy Storage | accumula 5000·1,6^L | — | 2k/3k/0 | ×1,7 | Tecnologia orbitale 2 |
| Lunar Solar Array (luna) | 25·L·1,08^L | — | 2k/1k/0 | ×1,55 | NF 1 |

Solar Satellite: non occupa spazi, non spara, scudo 1, struttura 2k, lascia detriti, non potenziabile. Tecnologia Energetica: +2% energia per livello.

### 4.3 Magazzini
Magazzino M (1000/0/0), C (1000/500/0), Serbatoio D (1000/1000/0): capacità **10.000·1,6^L**, crescita ×1,7, **proteggono il 10%** dal saccheggio. Lunarite Vault (luna, 5k/5k/0, ×1,7): 2.000·1,6^L. Bottini/trasporti/rimborsi possono superare la capacità fino a +100%.

---

## 5. Strutture

Tempo edifici: **ore = (M+C+D)/3000 ÷ (1+0,15·Robot) × 0,25^Naniti ÷ velocità**. Code illimitate; risorse scalate all'avvio di ogni elemento; se mancano la coda si ferma e riparte da sola; riordino/annullo elementi in attesa.

### 5.1 Infrastruttura pianeta
| Struttura | Effetto per livello | Prerequisiti | Costo base | Crescita | Energia |
|---|---|---|---|---|---|
| Fabbrica di robot | tempi edifici ÷(1+0,15·L) | — | 400/120/200 | ×1,7 | — |
| Fabbrica di naniti | **tutti** i tempi (edifici, navi, difese, missili, ricerca, potenziamenti) ×0,25^L | Robot 10, Computer 10 | 1M/500k/100k | ×1,8 | — |
| Cantiere spaziale | tempi navi/difese ÷(1+0,2·L), potenziamenti ÷(1+0,1·L) | Robot 2 | 400/200/100 | ×1,7 | — |
| Laboratorio di ricerca | tempi ricerca ÷(1+0,25·L) | — | 200/400/200 | ×1,7 | — |
| Terraformer | +200 spazi, non occupa spazi | Terraformazione 1, Naniti 1 | 0/50k/100k | ×1,7 | 50·L |
| Silo missilistico | 6 slot al L1, +3 per livello (6 + 3·(L−1)); ogni missile (IPM o ABM) occupa 1 slot; i missili si producono qui (coda propria) | Balistica 1, C 1 | 20k/20k/1k | ×1,7 | — |
| Sensor Phalanx (anche luna) | scansiona flotte in volo da/verso un corpo entro **2^(L−1)** sistemi; 5000 D/scansione, attesa 10 min di gioco | Sensori avanzati 3, Iperspazio 4 | 20k/40k/20k | ×1,7 | 30·L |
| Teleporter (anche luna) | sposta istantaneamente navi tra due propri corpi con Teleporter; capacità 1M peso×L; 1 D/10 peso; ricarica 2 h di gioco ÷ L (entrambi i lati) | Teletrasporto 5, Naniti 1, Iperspaziale 6 | 2M/4M/2M | ×1,8 | 100·L |
| Deep Core Drill | +3% produzione miniere | Geologia 4, Miniera M 10, Robot 5 | 20k/10k/5k | ×1,7 | 40·L |
| Planetary Bunker | +5% quota protetta | magazzini 3 | 10k/10k/0 | ×1,6 | 15·L |
| Repair Bay | ripara 2%·L (max 25%) delle navi perse che rientrano | Scienza militare 1, C 4, Corazza 4 | 15k/10k/5k | ×1,6 | 30·L |
| Trade Center | tassi mercato +2%·L, più offerte CPU | Economia galattica 1, Robot 4 | 8k/8k/2k | ×1,6 | 10·L |
| Embassy | +10%·L guadagno relazioni; alleanza da L3, tributo da L5 | Diplomazia 1, Robot 3 | 5k/10k/2k | ×1,6 | 10·L |
| Intelligence Agency | +10%·L rilevazione sonde; da L5 report nemici falsati | Controspionaggio 1 | 10k/20k/5k | ×1,7 | 20·L |
| Missile Factory | tempi missili −10%·L, +1 sistema gittata ogni 2 L | Balistica 3, Silo 3 | 15k/10k/5k | ×1,6 | 25·L |
| Recycling Plant | +2%·L detriti raccolti nel sistema | Riciclaggio 1, C 4 | 12k/8k/4k | ×1,6 | 20·L |
| Fleet Command | +1 flotta ogni 3 L, −2%·L consumo D in partenza (max 40%) | Logistica 2, C 5 | 25k/25k/10k | ×1,7 | 30·L |
| Pirate Bounty Office | +5%·L bottini Warp/Rift, mostra prossimo Warp | Caccia ai pirati 1 | 10k/15k/5k | ×1,6 | 15·L |
| Construction Hub | +1 slot parallelo coda edifici ogni 5 L | Logistica 4, Robot 8 | 50k/30k/10k | ×1,8 | 50·L |
| Assembly Line | +1 linea parallela Cantiere ogni 5 L | Logistica 4, C 8 | 50k/40k/10k | ×1,8 | 50·L |
| Quantum Archive | −1%·L costo ricerche avviate qui (max 30%) | Ingegneria quantistica 1, Lab 8 | 20k/40k/10k | ×1,7 | 40·L |
| War Academy | −1%·L costo navi/difese qui (max 25%) | Scienza militare 3, C 6 | 30k/20k/10k | ×1,7 | 30·L |
| Asteroid Processing Plant | +3%·L risorse scaricate dai Miner | Geologia 3, Robot 4 | 15k/10k/5k | ×1,6 | 30·L |
| Resource Converter | converte 10k×L/h tra risorse a tasso fisso (tassi base mercato) | Economia galattica 4, Robot 6 | 20k/20k/10k | ×1,7 | 60·L |
| Colony Hub | colonie fondate da qui: edifici a L/2, +10%·L risorse iniziali | Colonizzazione avanzata 1 | 15k/15k/5k | ×1,6 | 20·L |
| Jamming Tower | 8%·L (max 60%) di bloccare Phalanx nemiche | Controspionaggio 4 | 20k/30k/10k | ×1,7 | 40·L |
| Weather Control | −5%·L probabilità eventi negativi (max 50%) | Climatologia 1 | 25k/25k/15k | ×1,7 | 50·L |
| Orbital Elevator | −5%·L D al decollo, +2%·L stiva flotte in partenza | Tecnologia orbitale 5, Robot 6 | 30k/15k/5k | ×1,7 | 30·L |

### 5.2 Luna
- Creata dal **livello 1 della Lunar Nanoforge** (sul pianeta Naniti 1 + Ingegneria lunare 1). Una per pianeta, stesse coordinate. Spazi **1000** + **200 per livello NF oltre il 1°** (+/− battaglie, §3).
- **Resistenza** max = (5M + 2M×NF) × (1 + 10%×Bulwark) × (1 + 2%×Corazza).
  - Riduce: bombardamento (attacco totale sopravvissuto di **Dreadnought, Mothership, Bomber** ×3, dopo battaglia vinta, ridotto da Corazza del difensore); eventi (meteoriti).
  - Ripristina: 2%/h di gioco + 0,5%·Orbital Dock + 0,25%·Lunar Shipyard; **riparazione d'emergenza** a pagamento (M+C+Lu proporzionale) ogni 6 h di gioco.
  - Soglie: 75–50% estrazione −25% · 50–25% estrazione −50%, Forge/Shipyard −50% · <25% strutture ferme (solo difese e riparazione) · 0% **distrutta**.
- **Distruzione**: perse strutture, risorse, navi in porto, difese; flotte dirette alla luna tornano al pianeta; 20% del costo strutture diventa detriti (incl. Lu); **attesa 48 h reali** prima di ricostruire.
- Missili **non** colpiscono lune.

| Struttura lunare | Effetto per livello | Prerequisiti | Costo base M/C/D/Lu | Crescita | Energia |
|---|---|---|---|---|---|
| Lunar Nanoforge | crea luna (L1), +200 spazi | Ingegneria lunare 1, Naniti 1 (pianeta) | 200k/400k/100k/0 | ×1,8 | — |
| Lunarite Extractor | 5·L·1,08^L Lu/h | NF 1 | 10k/10k/5k/0 | ×1,6 | 15·L·1,08^L |
| Deuterium Extractor | 15·L·1,08^L D/h (no bonus temperatura) | NF 1 | 3k/1,5k/0/0 | ×1,55 | 12·L·1,07^L |
| Lunar Solar Array | vedi §4.2 | NF 1 | — | — | — |
| Lunarite Vault | vedi §4.3 | Lunarite Extractor 1 | — | — | — |
| Lunar Bunker | +5%·L quota protetta luna | NF 2 | 10k/10k/0/0 | ×1,6 | — |
| Lunar Shipyard | costruisce navi/difese, tempi ÷(1+0,2·L) | NF 2, Ingegneria lunare 2 | 10k/5k/2k/0 | ×1,7 | — |
| Upgrade Forge | coda potenziamenti ÷(1+0,25·L) | NF 2, Computer 6 | 20k/20k/10k/0 | ×1,7 | 40·L |
| Lunar Bulwark | +10% resistenza max | NF 2, Ingegneria lunare 4, Corazza 6 | 20k/10k/0/0 | ×1,7 | — |
| Orbital Dock | ripara 2%·L (max 30%) navi perse che rientrano | NF 3, LS 2, Scienza militare 4 | 15k/10k/5k/0 | ×1,6 | — |
| Deep Space Radar | avvisa flotte ostili in arrivo entro L sistemi, composizione approssimata | NF 3, Sensori avanzati 1 | 10k/20k/5k/0 | ×1,6 | 20·L |
| Hyperspace Relay | +5%·L velocità flotte in partenza | NF 4, Teletrasporto 1 | 30k/30k/20k/0 | ×1,7 | — |
| Admiral's Academy | +5%·L Medaglie, −2%·L costo Ammiraglio | NF 3, Scienza militare 6, Computer 8 | 50k/80k/20k/2k | ×1,7 | 30·L |
| Mining Control | Miner in partenza: +5%·L estrazione, +1 strato ogni 5 L | NF 2, Tecnologia mineraria 4 | 20k/15k/5k/0 | ×1,6 | 20·L |
| Lunar Observatory | vede eventi/Rift/campi L ore prima, +2%·L velocità ricerca | NF 2, Sensori avanzati 5 | 30k/50k/10k/0 | ×1,7 | 25·L |
| Cryo Hangar | navi parcheggiate invisibili a spionaggio/Phalanx; capacità 500k peso×L | NF 4, Controspionaggio 8 | 100k/60k/40k/5k | ×1,8 | 60·L |
| Interdiction Field | flotte nemiche in arrivo −3%·L velocità (max 45%) | NF 5, Ingegneria quantistica 5 | 120k/120k/60k/8k | ×1,8 | 80·L |
| Decoy Generator | flotte fantasma: il nemico sopravvaluta le forze del 10%·L | NF 3, Controspionaggio 6 | 40k/60k/20k/1k | ×1,7 | 40·L |
| Mass Driver | lancia risorse senza cargo verso il pianeta d'origine (bidirezionale): 100k×L/h; nello stesso sistema, a qualsiasi distanza se la luna si è spostata | NF 3, Logistica 6 | 60k/30k/10k/0 | ×1,7 | 50·L |
| Warp Beacon | −5%·L tempo di volo verso Warp, Rift, Gauntlet | NF 4, Teletrasporto 3 | 50k/50k/30k/3k | ×1,7 | 50·L |
| Sensor Phalanx, Teleporter, Antimatter Reactor | come §4.2/5.1 | | | | |

### 5.3 Luna mobile (Death Match)
- Ricerca **Death Star** sblocca la missione *Sposta luna*.
- Velocità **10 sistemi/h di gioco** (+5% per livello Death Star); salto galassia = 200 sistemi. Costo D ∝ distanza × NF. Ricarica 24 h reali dopo l'arrivo.
- In viaggio mantiene tutte le funzioni ed è attaccabile nella posizione corrente.
- Può fermarsi **solo presso un pianeta senza luna** (di chiunque); finché è lì quel pianeta non può creare una luna.

---

## 6. Ricerca

Costo × **1,6** per livello (Astrofisica ×1,5). Tempo: ore = (M+C+D)/3000 ÷ (1+0,25·Lab) × 0,25^Naniti ÷ velocità; si usano Lab e Naniti del pianeta di avvio (Rete di ricerca somma i Lab).

| Ricerca | Prerequisiti | Costo base M/C/D | Effetto per livello |
|---|---|---|---|
| Energetica | Lab 1 | 0/800/400 | +2% energia |
| Laser | Energetica 2 | 200/100/0 | +1% danno laser |
| Ioni | Laser 5, Energetica 4 | 1000/300/100 | +1% danno ionico |
| Plasma | Ioni 5, Energetica 8 | 2000/4000/1000 | +1% M, +0,66% C, +0,33% D |
| Iperspazio | Energetica 5, Scudi 5 | 0/4000/2000 | +5% stiva |
| Propulsione a combustione | Energetica 1 | 400/0/600 | +10% velocità navi a combustione |
| Propulsione a impulso | Energetica 1 | 2000/4000/600 | +20% velocità impulso, gittata missili +5 sistemi |
| Propulsione iperspaziale | Iperspazio 3 | 10k/20k/6k | +30% velocità iperspaziale |
| Armi | Lab 4 | 800/200/0 | +10% attacco |
| Scudi | Energetica 3, Lab 6 | 200/600/0 | +10% scudi |
| Corazza | Lab 2 | 1000/0/0 | +10% struttura |
| Spionaggio | Lab 3 | 200/1000/200 | informazioni/furtività |
| Computer | Lab 1 | 0/400/600 | +1 flotta contemporanea, +1% iniziativa difese |
| Astrofisica | Spionaggio 4, Impulso 3 | 4000/8000/4000 | colonie, spedizioni |
| Rete di ricerca | Lab 10, Computer 8, Iperspazio 8 | 240k/400k/160k | somma Lab di più pianeti (L pianeti) |
| Terraformazione | Energetica 12, Naniti 1 | 50k/100k/50k | sblocca Terraformer |
| Geologia | Lab 3 | 1k/500/0 | +1% miniere |
| Fisica nucleare | Energetica 4 | 2k/2k/500 | +1% energia nucleare |
| Fisica dell'antimateria | Fisica nucleare 10, Plasma 10 | 200k/300k/100k | −1% consumo Lu reattore |
| Tecnologia orbitale | Energetica 2, C 1 | 500/1k/200 | +1% energia satelliti |
| Tecnologia mineraria | Geologia 2, Astrofisica 1 | 3k/2k/1k | +1% estrazione Miner |
| Logistica | Computer 4 | 5k/5k/2k | +1% velocità code |
| Economia galattica | Computer 2 | 2k/3k/0 | +1% tassi mercato |
| Diplomazia | Computer 2 | 1k/3k/0 | +2% guadagno relazioni |
| Controspionaggio | Spionaggio 4 | 3k/6k/1k | +2% rilevazione sonde |
| Sensori avanzati | Spionaggio 5, Iperspazio 2 | 5k/8k/2k | +1% gittata radar |
| Balistica | Impulso 2 | 3k/1k/2k | +2% danno missili |
| Teletrasporto | Iperspazio 6, Iperspaziale 4 | 50k/80k/40k | −2% ricarica Teleporter |
| Scienza militare | Armi 4, Corazza 4 | 5k/3k/1k | +1% riparazione navi |
| Riciclaggio | Combustione 4 | 2k/2k/500 | +1% raccolta detriti |
| Colonizzazione avanzata | Astrofisica 3 | 5k/10k/5k | +2% risorse iniziali colonie |
| Ingegneria quantistica | Computer 10, Iperspazio 6 | 40k/80k/20k | −0,5% costo ricerche |
| Ingegneria lunare | Naniti 1, Astrofisica 4 | 50k/50k/20k | +1% resistenza luna |
| Climatologia | Energetica 8 | 10k/20k/10k | −1% eventi negativi |
| Caccia ai pirati | Armi 3, Spionaggio 3 | 3k/3k/1k | +2% danni contro pirati |
| Death Star | Ingegneria lunare 8, Iperspaziale 12, Teletrasporto 6, NF 10 | 2M/4M/2M + 100k Lu | +5% velocità luna |

---

## 7. Navi

Tempo: ore/unità = (M+C+D)/3000 ÷ (1+0,2·Cantiere) × 0,25^Naniti ÷ velocità. Velocità effettiva = base × propulsione × Motori × bonus.

| Nave | Costo M/C/D | Att | Scudo | Struttura | Vel. | Stiva | Consumo | Iniz. | Peso | Propulsione | Prerequisiti | Abilità |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Light Fighter | 2,5k/800/0 | 45 | 12 | 3,3k | 13.000 | 60 | 10 | 90 | 30 | combustione | C1, Combustione 1 | *Sciame*: 25% schiva colpi di Battleship/Battlecruiser/Dreadnought |
| Heavy Fighter | 5k/3,5k/0 | 140 | 30 | 8,5k | 11.000 | 120 | 30 | 80 | 80 | impulso | C3, Corazza 2, Impulso 2 | *Perforante*: ignora 50% scudo |
| Cruiser | 18k/7k/2k | 380 | 60 | 25k | 16.000 | 700 | 120 | 65 | 250 | impulso | C5, Impulso 4, Ioni 2 | *Raffica*: ogni colpo si divide su 3 bersagli piccoli (LF/HF) |
| Battleship | 42k/15k/0 | 1.100 | 220 | 57k | 10.500 | 1.400 | 250 | 40 | 600 | iperspaziale | C7, Iperspaziale 4 | *Corazza pesante*: colpi < 2% struttura −50% |
| Battlecruiser | 30k/40k/15k | 700 | 400 | 70k | 12.000 | 800 | 300 | 100 | 750 | iperspaziale | C8, Iperspazio 5, Laser 12, Iperspaziale 5 | *Primo colpo*: +50 iniziativa fissa |
| Dreadnought | 1,8M/1,2M/400k | 18.000 | 25.000 | 3M | 1.500 | 100k | 1.000 | 20 | 30.000 | iperspaziale | C12, Naniti 1, Plasma 10, Corazza 12, Iperspaziale 8 | *Fuoco ad area*: ogni colpo fa 50% anche a 2 bersagli vicini |
| Bomber | 48k/26k/15k | 950 | 520 | 74k | 4.500 | 450 | 700 | 45 | 700 | impulso (iperspaziale con Iperspaziale 8) | C8, Impulso 6, Plasma 5 | *Carico pesante*: danno ×3 contro difese e bersagli con vel. < 5000 |
| Carrier | 60k/40k/20k | 200 | 300 | 80k | 8.000 | 2.000 | 400 | 35 | 5.000 | iperspaziale | C9, Computer 8, Corazza 8, Iperspaziale 6 | hangar 100.000 (solo LF, HF, Probe); se distrutto i caccia lanciati −30% attacco |
| Mothership | 50M/40M/20M + 1M Lu | 50.000 | 100.000 | 20M | 1.000 | 10M | 5.000 | 15 | — | iperspaziale | **solo luna**: LS 8, Naniti 3, Iperspazio 12, Iperspaziale 12, Computer 12, Rete di ricerca 1 | 1 per impero; hangar 1.000.000.000 (tutte tranne Mothership); aura flotta +10% attacco, scudi, iniziativa; ripara 20% navi perse della sua flotta; se distrutta ricostruibile dopo 7 giorni reali |
| Small Cargo | 2,2k/2,2k/0 | 4 | 10 | 4,4k | 5.500 (11.000 con Impulso 5) | 5.500 | 10 | 50 | 50 | combustione→impulso | C2, Combustione 2 | — |
| Large Cargo | 6,5k/6,5k/0 | 6 | 25 | 13k | 7.800 | 26.000 | 40 | 45 | 150 | combustione | C4, Combustione 6 | — |
| Colony Ship | 9k/18k/9k | 0 | 90 | 27k | 2.600 | 7.000 | 500 | 10 | 400 | impulso | C4, Impulso 3, Astrofisica 1 | fonda colonia, carico = dotazione |
| Espionage Probe | 0/900/0 | 0 | 0 | 900 | 80M | 0 | 1 | 99 | 5 | combustione | C3, Combustione 3, Spionaggio 2 | — |
| Recycler | 9k/5,5k/2k | 1 | 10 | 14,5k | 2.200 | 18.000 | 150 | 10 | 200 | combustione | C4, Combustione 6, Scudi 2 | raccoglie detriti |
| Miner | 8k/4k/1k | 0 | 50 | 12k | 6.000 | 50.000 | 60 | 0 | 120 | combustione | C4, Tecnologia mineraria 1, Astrofisica 2 | estrae 2.000/h di gioco |

**Hangar**: navi trasportate non consumano D, viaggiano alla velocità del vettore, vengono lanciate prima del 1° round. Ramo Hangar +5%/livello.

**Matrice forte/debole** (riga attacca colonna). **++** danno ×2 · **−−** danno ×0,5 · vuoto ×1.

| | LF | HF | Cruiser | Battleship | Battlecr. | Dreadn. | Bomber | Difese |
|---|---|---|---|---|---|---|---|---|
| Light Fighter | | | −− | | | ++ | ++ | −− |
| Heavy Fighter | | | −− | ++ | −− | ++ | | |
| Cruiser | ++ | ++ | | −− | −− | −− | | |
| Battleship | | −− | ++ | | ++ | −− | ++ | |
| Battlecruiser | | ++ | ++ | −− | | −− | | |
| Dreadnought | −− | −− | ++ | ++ | ++ | | | |
| Bomber | −− | | | −− | | | | ++ |

Carrier, Mothership e navi civili: ×1 contro tutti.

---

## 8. Difese

Tempi come navi. Le difese non si spostano. Dopo battaglia con flotte: **70% delle distrutte riparato gratis**.

| Difesa | Contro | Costo M/C/D | Att | Scudo | Struttura | Iniz. | Abilità | Debole contro (l'attaccante fa ×2) | Prerequisiti |
|---|---|---|---|---|---|---|---|---|---|
| Flak Battery | LF | 2k/0/0 | 20 | 25 | 2k | 70 | colpisce 5 bersagli; ×4 vs LF | Battleship, Bomber | C1 |
| Pulse Laser | HF | 2,5k/1k/0 | 120 | 30 | 3,5k | 75 | annulla schivata; ×4 vs HF | Cruiser | C2, Energetica 1, Laser 3 |
| Ion Cannon | Cruiser | 6k/4k/0 | 180 | 400 | 10k | 50 | danno ×2 sugli scudi; ×4 vs Cruiser | Battleship, Dreadnought | C4, Ioni 4 |
| Rail Gun | Battleship | 18k/8k/1k | 900 | 150 | 26k | 40 | ignora Corazza pesante; ×3 vs Battleship | HF, Bomber | C6, Energetica 6, Armi 5, Scudi 2 |
| Sentinel Turret | Battlecruiser | 25k/20k/5k | 600 | 250 | 45k | 160 | ×4 vs Battlecruiser | Dreadnought | C7, Computer 8, Laser 10 |
| SAM Site | Bomber | 8k/6k/2k | 300 | 100 | 14k | 85 | ×5 vs Bomber, annulla Carico pesante | Cruiser, Battlecruiser | C4, Impulso 3, Computer 3 |
| Torpedo Platform | Carrier | 40k/25k/10k | 2,5k | 200 | 60k | 55 | spara **prima del lancio hangar**; Carrier distrutto = contenuto perso; ×5 vs Carrier | HF, Bomber | C8, Impulso 7, Armi 8 |
| Plasma Lance | Dreadnought | 60k/50k/30k | 4k | 500 | 100k | 25 | +1% struttura del bersaglio come danno extra | LF, Bomber | C10, Plasma 8 |
| Graviton Cannon | Mothership | 5M/4M/2M | 250k | 20k | 2M | 10 | annulla aura; ×10 vs Mothership; max 1/pianeta; energia 500 | LF, HF | C14, Naniti 2, Iperspazio 12, Plasma 12 |
| Planetary Shield | — | 20k/20k/0 | 0 | 50k (+10%/Scudi) | — | — | assorbe prima delle difese, si rigenera a ogni round; max 1; energia 100 | — | C3, Scudi 4 |
| Anti-Ballistic Missile | missili | 8k/0/2k | — | — | — | — | intercetta 1 missile; 1 slot silo | — | Silo 2 |
| Interplanetary Missile | difese | 12,5k/2,5k/10k | 1.000.000 | — | — | — | 1 slot silo; vedi §11 | — | Silo 4, Impulso 1 |

Iniziativa difese +1%/livello Computer.

---

## 9. Potenziamenti (navi e difese)

- Per **impero** e per **tipo di unità**, livelli **infiniti**, coda dedicata (Cantiere ÷(1+0,1·L), Upgrade Forge, Naniti, Logistica). Le CPU li usano uguali.
- Rami navi: **Attacco** +2% · **Difesa** +2% scudo e struttura · **Motori** +2% velocità, −1% consumo, +2% iniziativa, +0,5% schivata (schivata totale max 40%) · **Stiva** +3% · **Hangar** (Carrier/Mothership) +5% · **Estrazione** (Miner) +3% · **Profondità** (Miner) +1 strato.
- Rami difese: **Attacco** +2% · **Difesa** +2% · **Prontezza** +2% iniziativa.
- Costo livello L = costo unità × 2 × **1,25^(L−1)** (stesse proporzioni M/C/D). **Da L11** anche Lunarite = 50 × 1,25^(L−11).
- Prerequisiti: C2 (Stiva anche Iperspazio 1).

---

## 10. Flotte e missioni

- Missioni: Attacco, Trasporto, Stazionamento, Colonizzazione, Spionaggio, Riciclaggio, Bombardamento (luna o pianeta), Sposta luna, Attacco pirati, Gauntlet, Mining, Spedizione. Ritorno automatico. Richiamo in volo (ritorno con tempo già percorso).
- Flotte contemporanee = 1 + Computer + Fleet Command/3 + Comando Ammiraglio/5.
- **Distanza** (unità): stesso sistema 100 + 10·Δposizione · sistemi diversi 1000·Δsistemi · galassie diverse 200.000·Δgalassie.
- **Tempo** (s) = 3600 × distanza / velocità_flotta × (100 / velocità%) ÷ velocità di gioco. Velocità% 10–100.
- **Consumo D** = Σ consumo_nave × distanza/1000 × (velocità%/100)².
- **Sosta obbligatoria**: ogni flotta arrivata (volo o teletrasporto) attende **30 s reali** prima di ripartire.
- **Saccheggio**: fino al **100%** delle risorse oltre la quota protetta, limitato dalla stiva; riempimento bilanciato M/C/D/Lu con priorità impostabile.
- **Anti-spazzolatura**: lo stesso attaccante può attaccare lo stesso corpo max 3 volte ogni 24 h di gioco.
- **Spionaggio**: punteggio = sonde + 2×(Spionaggio att − Spionaggio dif). ≥0 risorse · ≥2 flotta · ≥3 difese · ≥5 strutture · ≥7 ricerca. Rilevazione = clamp(5% + 5%×(Spy dif − Spy att) + 2%×Controspionaggio + 10%×Intelligence Agency, 0, 95%); se rilevate le sonde combattono e vengono distrutte. Report mostra bottino saccheggiabile, PF, resistenza luna, e il pulsante **Simula**: apre il simulatore di battaglia con i difensori compilati dai dati del report e come attaccante la flotta del pianeta attivo, modificabile. I dati non rilevati restano vuoti, segnati "non rilevato", e il risultato è indicato come stima parziale. Il simulatore usa i dati del report così come sono, anche se falsati (Intelligence Agency, Decoy Generator).

---

## 11. Battaglia

1. Pre-battaglia: Torpedo Platform sparano sui Carrier → lancio hangar → applicazione bonus (tecnologie, potenziamenti, Ammiraglio, aura).
2. Massimo **6 round**. Ogni round: scudi al 100% → unità di entrambi i lati ordinate per **iniziativa** = base × (1+2%×Motori/Prontezza) × (1+1%×livello propulsione) × aura → si spara a **gruppi di 10 punti** (es. 90–99), i due lati in contemporanea dentro il gruppo; i danni si applicano a fine gruppo e le unità distrutte non sparano nei gruppi successivi.
3. Ogni unità sceglie un bersaglio casuale tra i nemici vivi. Danno = attacco × matrice × abilità. Scudo assorbe prima; colpi < 1% dello scudo annullati. Struttura sotto 30%: probabilità di esplodere = 1 − struttura/(0,3 × struttura max).
4. Fine: un lato senza unità = vittoria; altrimenti **pareggio** (attaccante torna senza bottino).
5. Report: flotte per round, perdite, bottino, detriti, spazi ±, PF prima/dopo.
6. **Detriti**: 30% di M+C delle navi distrutte, 10% delle difese; si sommano; decadono 5%/giorno di gioco. Nessun detrito da pirati/Rift/Gauntlet.

**Missili** (solo contro pianeti, prodotti nella coda del Silo): all'arrivo gli ABM del bersaglio partono **in automatico**, uno per missile; ogni ABM ha **10% di probabilità di fallire**. Missile intercettato = distrutto, **vince il difensore** (ABM consumato). Missile che passa = **vince l'attaccante**: il danno colpisce a caso tutte le unità presenti sul pianeta (navi a terra e difese, non le strutture). Danno 1.000.000 × (1+10%×Armi) × (1+2%×Balistica), **ignora scudi**, velocità 80M, gittata = 5×Impulso + floor(Missile Factory/2) sistemi. Difese danneggiate: si riparano 10%/h di gioco. Distrutte: 50% perse, 50% relitti riparabili pagando 50% del costo (50% del tempo).

---

## 12. Potenza

- **PU** = √(Offesa × Resistenza). Offesa = attacco effettivo × colpi medi/round × (1 + Iniziativa/200). Resistenza = (struttura + scudo×6) ÷ (1 − schivata).
- **PF** flotta = Σ PU (hangar inclusi) × aura Mothership. **PF relativa** contro un bersaglio applica la matrice (++ ×1,5, −− ×0,67).
- **Power** impero = PF flotte + PU difese + strutture + ricerca + Ammiraglio. Strutture e ricerca = risorse investite/1000 con pesi M×1, C×1,5, D×2, Lu×5 (luna × % resistenza). Ammiraglio = Medaglie spese × 10. Potenziamenti già dentro PU. Missile in silo = 350.
- Usi: classifica (totale + flotte/difese/strutture/ricerca/Gauntlet), coalizioni (Power player > 3× media CPU vive), soglia attacco CPU (PF relativa ≥ 1,3 × bersaglio, variata da personalità e sfida), Warp/Rift, simulatore di battaglia.

---

## 13. CPU

- **Vive**: imperi completi con personalità **Aggressiva / Difensiva / Economica / Tecnologica / Opportunista**; partono con 1–3 pianeti; decisioni ogni 30–60 min di gioco: stato → difesa (salvare flotta, accodare difese) → economia → spionaggio/valutazione → attacco/espansione (attacchi, Warp, Rift, Gauntlet, Miner, colonie, luna) → diplomazia/commercio. Memoria di rancore e di bersagli redditizi.
- Priorità per personalità:

| | Aggressiva | Difensiva | Economica | Tecnologica | Opportunista |
|---|---|---|---|---|---|
| Luna | base d'attacco | Bulwark, difese, Cryo Hangar, Interdiction | Lu e D | Upgrade Forge | Phalanx, Decoy |
| Potenziamenti | Attacco | Difesa | Stiva | equilibrati | Motori |
| Missili | contro difese prima di attaccare | molti ABM | pochi | medi | frequenti |
| Mercato | vende per flotta | poco | molte offerte | compra Lu | compra ai minimi |
| Pirati/Gauntlet | Warp spesso | raro | Rift | Warp, Gauntlet | Rift |
| Distruzione lune | sì | no | no | raro | lune danneggiate |

- **Semplificate**: crescita a formula: livello miniere = min(40, floor(k × √ore_di_gioco)), k = 0,5 inattivo / 1 debole / 1,5 medio; risorse = produzione × ore dall'ultimo saccheggio (cap magazzino); difese e navi proporzionali al livello. Non attaccano. Le CPU vive possono colonizzarle o razziarle.
- **Sfida**: precisione valutazione bersagli, frequenza decisioni, qualità composizione flotte. Mai risorse extra.
- Moltiplicatori risorse/ricerca applicati a produzione e velocità ricerca delle CPU (≤ velocità player).

---

## 14. Pirati, Gauntlet, Ammiraglio, Miner

**Warp pirata** (posizione 16 di ogni sistema): livello unico per impero, uguale in tutti i Warp. PF pirata = 50.000 × 1,18^livello, composizione variabile per livello. Vittoria: **catturi tutte** le navi pirata, livello +1, bottino crescente (a volte Lu). Sconfitta: navi perse, livello invariato. Nessun limite agli attacchi.

**Pirate Rift**: 2 al giorno reale, posizione casuale, durata 6 h reali, flotta debole (PF 15–30% della PF media delle tue flotte), attaccabile anche da flotte piccole, vittoria = cattura navi, non cambia livello; chi arriva prima vince.

**Admiral's Gauntlet** (parte da una luna, una corsa per volta): ondate continue, PF nemica = 30.000 × 1,12^ondata, composizione sempre diversa; scudi rigenerati tra le ondate, navi perse restano perse. Ogni ondata vinta dà subito **Medaglie** = 1,1^ondata. Fine quando perdi o scatta il ritiro automatico (ondata max / % perdite / PF relativa sfavorevole). Record e classifica.

**Ammiraglio** (ogni impero, CPU incluse; bonus su **tutte** le flotte): costo livello = 10 × 1,15^L Medaglie.
Tattica +1% attacco · Difesa +1% scudo/struttura · Iniziativa +1% · Logistica +1% velocità −1% consumo · Saccheggio +1% stiva · Comando +1 flotta ogni 5 L · Cacciatore di pirati +2% danni vs pirati/Rift/Gauntlet · Ingegnere −1% tempi potenziamenti.

**Asteroidi** (pos. 17–20 di ogni sistema): strati infiniti, riserva = 100k × 1,2^strato (Lunarite ×0,1). Strati accessibili = 1 + Profondità (+ Mining Control). Avanzamento **per impero**, reset a **mezzanotte ora italiana**. Ogni giorno un tipo casuale è **Morbido**: +3 strati, +50% velocità. Estrazione = 2.000/h × Miner × (1+3%×Estrazione) × (1+1%×Tecnologia mineraria) × Mining Control. Flotte presenti contendibili.

**Asteroid Field** (evento): 3 al giorno reale, durata 12 h, tipi Ferroso/Cristallino/Ghiacciato/Raro (Lu), riserve grandi, conteso. Contesa: battaglia solo con ostilità e scorta armata; Miner senza scorta attaccati perdono il carico (all'attaccante) e il 50% delle navi; senza ostilità la riserva si divide.

---

## 15. Mercato, diplomazia, eventi

**Mercato galattico**: tassi base 1 C = 2 M, 1 D = 3 M, 1 Lu = 10 M; variano con domanda/offerta (ogni scambio sposta il tasso in proporzione al volume rispetto alla liquidità) e tornano verso la base del 2%/h di gioco; **nessuna commissione**; consegna immediata.
**Offerte CPU** (soprattutto Economiche): con scadenza; consegna via cargo, pagamento all'arrivo; intercettabili.

**Diplomazia** (CPU vive, punteggio −100…+100): Guerra ≤−50 · Ostile −49…−10 · Neutrale −9…+9 · Amichevole +10…+49 · Alleato ≥+50 (difende e attacca con te). Sale: scambi, regali, attaccare i suoi nemici, rispettare patti. Scende: attacchi, spionaggio scoperto, attaccare alleati, rompere patti, Power troppo alto. Patti: non aggressione (durata fissa), alleanza, tributo. Coalizioni anti-player (§12).

**Eventi casuali** (frequenze in config, disattivabili, ridotti da Weather Control/Climatologia): Pirati in attacco, Meteoriti (danno resistenza luna o risorse; Planetary Shield riduce), Giacimento ricco (+50% una risorsa), Relitto alla deriva (detriti, a volte Lu), Tempesta solare (solare −50% in un sistema), Scoperta scientifica (ricerca in corso −20%), Mercenari (navi a pagamento), Ribellione (produzione colonia ridotta finché arriva una flotta), Anomalia (esplorabile: premio o perdita flotta). **Spedizione** (Astrofisica): esito casuale in base alla dimensione della flotta.

---

## 16. Registro e notifiche

- Registro filtrabile (tipo/pianeta/periodo) di code, flotte, battaglie, spionaggi, mercato, diplomazia, pirati, eventi, luna, spazi.
- Riepilogo "mentre eri via" al rientro. Notifiche browser a gioco aperto (attacco in arrivo, Rift, coda ferma). Email/Telegram: fase successiva (richiede cron).
- Conservazione: eventi 30 giorni reali, report battaglia 90.

---

## 17. Numeri

DOUBLE. Visualizzazione scala lunga italiana, 2 decimali, virgola: k · Mln · Mld · Bln · Bld · Tln · Tld · Qln · Qld · Qnl · Qnd · Sln · Sld · … fino a 10⁶³, oltre notazione scientifica. Nome esteso con tocco lungo. Input accetta `5k`, `2,5Mln`.

---

## 18. Interfaccia (mobile-first Android)

- PWA a schermo intero, tema scuro, target tocco ≥ 48 px, niente hover (dettagli con tocco lungo).
- **Barra alta**: corpo attivo (swipe per cambiare), risorse + produzione/h, energia, Power.
- **Barra bassa**: Dash · Costruisci · Flotte · Mappa · Altro.
- **Dash**: card flotte in volo in cima (colore per missione, countdown grande al secondo, orari arrivo/ritorno, barra di avanzamento andata/sosta/ritorno, anello sosta 30 s, **ostili in arrivo sempre primi in rosso + vibrazione**, tocco = dettagli e Richiama, filtri Tutte/Mie/Ostili/Miner/Pirati); poi code attive con stato "in attesa"; poi eventi del giorno (asteroide Morbido, Rift con countdown, campi) e ultimi report.
- **Costruisci**: schede Strutture · Ricerca · Cantiere · Difese · Potenziamenti · Ammiraglio; card con livello, costo (rosso se mancante), tempo, "+ Coda"; riordino trascinando.
- **Cantiere** (anche difese e missili):
  - slot paralleli (Assembly Line); card per unità con **slider 0–100%** dove 100% = massimo acquistabile con le risorse **ancora libere** (ricalcolo dinamico tra card), campo numerico, −1/+1/Max, anteprima costo/tempo/PU;
  - barra risorse impegnate/libere con risorsa limitante evidenziata;
  - **tocco** sulla card: 1° assegna al primo slot libero, successivi spostano allo slot seguente, dopo l'ultimo **disattiva**; badge S1/S2/…;
  - pulsanti **Unità uguali / Risorse uguali / Tempi uguali**; anteprima barre per slot con trascinamento lotti;
  - **Stop** per slot in qualsiasi momento senza costi: si tengono le unità completate, le risorse delle non completate tornano intere;
  - preset salvabili.
- **Flotte**: invio in 3 passi (navi con slider → bersaglio → missione/velocità) con consumo, tempo, PF relativa; simulatore di battaglia; regole di ritiro Gauntlet. Il simulatore esegue `combat.simulator_runs` (5) battaglie e mostra una stima: percentuali di vittoria/pareggio/sconfitta; barra totale della flotta inviata divisa tra sopravvissuta e persa, con % persa; una barra per tipo di nave con quantità inviata, perdite medie e intervallo min–max.
- **Mappa**: vista galassia (icone CPU, Warp, Rift, asteroidi, detriti, lune) e vista sistema (posizioni 1–20 verticali); pinch-zoom, swipe; tocco su corpo = spia/attacca/trasporta.
- **Altro**: impero, classifica, diplomazia, mercato, registro, impostazioni partita, cambio partita, logout.
- Timer calcolati in locale sull'orario del server.

---

## 19. Sviluppo, test, pubblicazione

- Sviluppo **in locale** (PHP 8 + MySQL/MariaDB, es. XAMPP, server PHP integrato). Aruba solo alla pubblicazione.
- Test: PHPUnit solo in sviluppo (non caricato su Aruba). Test obbligatori su formule (produzione, tempi, costi), recupero tempo (stesso risultato elaborando in 1 blocco o in N blocchi), battaglia (deterministica con seed), saccheggio/quota protetta, code ferme/ripartenza, CPU che rispetta i vincoli del player.
- Pubblicazione: upload FTP, creazione DB dal pannello Aruba, `config.local.php` compilato dall'utente, esecuzione `install.php` una volta e cancellazione.
- Ordine di sviluppo e dettaglio dei passi: nel piano di implementazione.
