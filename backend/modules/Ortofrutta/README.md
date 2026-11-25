# POC per un magazzino di lavorazione frutta

Modulo OpenMES con due scopi:

1. **Tracciabilità.** Da un prodotto finito consegnato a un cliente si risale a
   quando è stato lavorato, su quale linea, da chi, con quali lotti, di quali
   produttori e quanti kg; e da un lotto conferito si va avanti fino a tutti i
   clienti che lo hanno ricevuto.
2. **Squadre.** Il capoturno organizza le persone produzione per produzione:
   chi lavora su cosa, dalle 07:30 all'ultimo camion.

Intorno ci sono il piano linee, i fogli di lavorazione con il costo in €/kg e i
margini. Gira solo su **dati simulati**, senza collegamenti a gestionale,
macchine o timbrature.

Nel menu e nelle schermate il modulo porta il **nome dell'azienda**, che si
imposta quando si caricano i dati (vedi sotto) e sta nel database. Nel codice
la cartella si chiama `Ortofrutta`: è un nome interno che l'utente non vede.

| Schermata | Chi la usa | Cosa fa |
|---|---|---|
| Stabilimento | Capoturno | Planimetria dei due edifici: celle frigo, linee, zona confezionamento e carico |
| Conferimenti | Pesa | Peso lordo, tara dei bins, netto, cella di destinazione: nasce il lotto |
| 1 · Ordini del giorno | Ufficio | Ordini con tipo, arrivo, camion, priorità di uscita. Simulazione degli arrivi |
| 2 · Piano linee | Responsabile | Una colonna per linea, ordini trascinabili, cambi, avviso sul camion, "Proponi sequenza" |
| 3 · Squadre | Capoturno | Per ogni produzione: persone richieste, assegnate, mancanti; chi è libero in quell'orario; "Proponi squadre" |
| 4 · Fogli di lavorazione | Responsabile | Lotti in ingresso, kg confezionati, scarto: il costo in €/kg appare al salvataggio |
| 5 · Tracciabilità | Qualità, ufficio | Dal cliente al produttore (ordine → lotto di confezionamento → lotti → produttori) e dal produttore ai clienti (lotto → fogli → ordini) |
| 6 · Margini | Direzione | Costo e margine per foglio, lotto, produttore, cliente. Simulatore di prezzo minimo |
| Missioni bins | Carrellisti | Quale lotto prelevare, da quale cella, per quale linea, entro che ora (sola lettura) |

## Avvio in tre comandi

```bash
git clone https://github.com/TommasoSeika/OpenMes.git && cd OpenMes
./install.sh
docker compose exec backend sh modules/Ortofrutta/bin/attiva-demo.sh "Nome Azienda"
```

Il primo scarica il codice, il secondo costruisce e avvia OpenMES (stampa
indirizzo, utente e password), il terzo attiva il modulo e carica i dati demo.
Poi si apre `/admin/stabilimento`, oppure la voce con il nome dell'azienda nel
menu.

Il nome dell'azienda è quello che compare nel menu e nell'intestazione di ogni
schermata. Sta nel database, non nel codice; senza, il modulo si chiama
"Azienda Demo". Per cambiarlo si rilancia il terzo comando con un altro nome.

In GitHub Codespaces basta il terzo comando: i primi due li fa il codespace
(vedi `.devcontainer/README.md`).

## Ricaricare i dati demo

- Dalle schermate: pulsante **Ripristina dati demo**, in alto a destra.
- Da riga di comando: `docker compose exec backend php artisan ortofrutta:demo`.

Riporta tutto alla situazione delle 10:00 della giornata demo: due settimane di
dati, con gli ordini A per B della sera prima già in sequenza.

## Copione della demo (15 minuti)

1. **Ore 10:00.** In *2 · Piano linee* ci sono solo gli ordini A per B arrivati la
   sera prima, già in sequenza.
2. **Arrivano gli ordini A per A.** In *1 · Ordini del giorno*, "Simula arrivo
   ordini" ne fa entrare una decina; quelli per il camion delle 15:00 sono
   marcati "carico veloce".
3. **Si pianifica.** In *2 · Piano linee*, "Proponi sequenza" ordina per camion e
   mette vicini gli articoli uguali. Trascinando un ordine delle 15:00 in fondo
   alla linea, dopo un cambio, il blocco diventa rosso: non chiude prima del
   camion.
4. **Si organizzano le persone.** In *3 · Squadre* ogni linea ha le sue
   produzioni in ordine di orario. "Proponi squadre" le riempie tenendo le stesse
   persone sulla linea da una produzione alla successiva. Le produzioni di
   cestini della Linea 4 restano scoperte (servono 14 specialisti, tre sono
   assenti e uno esce alle 12:30), quelle di vassoi no (ne bastano 12). Si
   sceglie una produzione: a destra chi è in squadra, chi è libero in
   quell'orario e, per chi non lo è, il motivo. In fondo, "Chi fa cosa oggi".
5. **Si chiude un foglio.** Nel piano, "Lancia" sul primo gruppo di una linea crea
   il foglio, con la squadra di quella produzione. In *4 · Fogli di lavorazione*:
   "Usa i lotti suggeriti", si inserisce lo scarto, "Salva e calcola il costo":
   appare il costo in €/kg.
6. **Si traccia.** Dal foglio, "Tracciabilità": cliente, lotto di confezionamento,
   lotti entrati, produttori, kg. Cliccando un lotto si passa all'altra vista:
   tutti i fogli in cui è entrato e tutti i clienti a cui è arrivato. In *5 ·
   Tracciabilità* si cerca per ordine, cliente, lotto stampato sulla pedana
   (es. `L26328-1702`) o produttore, sulle due settimane di storico.
7. **Si guarda il margine.** In *6 · Margini*, classifica dei produttori: i due a
   resa bassa sono in fondo. Nel simulatore si prova il prezzo di una settimana
   in scontistica.

## Regole di calcolo

Stanno in `Domain/` e non dipendono dal database:

- `CostCalculator` — manodopera, imballi, costo di lavorazione €/kg, resa, costo
  materia, margine, prezzo minimo.
- `LinePlanner` — durata dei blocchi, cambi, ora di fine, avviso sul camion,
  proposta di sequenza.
- `WorkSheetCosting` — il costo di un foglio. È l'unico punto in cui un foglio
  viene valorizzato: fogli e margini leggono lo stesso numero.
- `CrewPlanner` — chi può lavorare su una produzione (abilitato sulla linea,
  presente in quell'orario, non impegnato altrove) e la proposta delle squadre.
- `Traceability` — il lotto di confezionamento di un foglio e la ripartizione dei
  kg di ogni lotto tra gli ordini del foglio.

Verifica:

```bash
# nell'applicazione in esecuzione, senza PHPUnit
docker compose exec backend php artisan ortofrutta:verifica

# test automatici (PHPUnit), in un contenitore isolato che si cancella da solo
docker run --rm --entrypoint sh ghcr.io/mes-open/openmes:latest -c \
  "composer install --no-interaction --no-scripts -q && vendor/bin/phpunit tests/Unit/Ortofrutta"

# tutte le schermate e le azioni della demo, sull'applicazione in esecuzione
bash backend/modules/Ortofrutta/bin/prova-http.sh
```

Il comando dei test usa l'immagine già costruita e nessun volume: non va
sostituito con `docker compose run backend ... composer install`, che scrive
nella cache condivisa con l'applicazione in esecuzione e la lascia con pacchetti
di sviluppo che non ha.

Il caso dell'incontro è coperto: acquisto 1,00 € + lavorazione 0,20 € a resa
piena = prezzo minimo 1,20 €.

## Dati

I dati entrano dai CSV di `seed/`, uno per fonte reale, con la forma che avrebbe
un'esportazione di quella fonte: passare ai dati veri vuol dire sostituire i file.

| File | Fonte reale |
|---|---|
| `ordini_gestionale.csv`, `articoli_imballi.csv`, `listino_vendita.csv`, `clienti.csv`, `produttori.csv` | Gestionale |
| `presenze_app_turni.csv` | App turni |
| `timbrature.csv`, `costi_orari.csv` | Timbrature e paghe (costo medio per qualifica) |
| `calibratrice_L1.csv` … `calibratrice_L6.csv`, `fogli_storico.csv`, `squadre_storico.csv` | Calibratrici e fogli di lavorazione |
| `conferimenti_pesa.csv` | Pesa all'ingresso |
| `linee.csv`, `celle.csv`, `regole_cambio.csv` | Anagrafiche di stabilimento (in `linee.csv` anche le persone richieste per formato) |

`seed/genera_dati.py` li rigenera (`python3 seed/genera_dati.py`). È
deterministico: stesso seme, stessi file. **Tutto è inventato**: nomi, prezzi,
costi, rese, capacità.

## Documenti

- [`docs/ricognizione.md`](docs/ricognizione.md) — cosa si riusa di OpenMES, cosa
  si estende, cosa è costruito da zero, e le modifiche al core.
- [`docs/ipotesi.md`](docs/ipotesi.md) — le ipotesi fatte dove mancava
  un'informazione, da confermare con il cliente.
