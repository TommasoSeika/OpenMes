# Ortofrutta — POC per un magazzino di lavorazione frutta

Modulo OpenMES che segue un ordine dall'arrivo in ufficio fino al costo di
lavorazione in €/kg e al margine per produttore. Gira solo su **dati simulati**,
senza collegamenti a gestionale, macchine o timbrature.

| Schermata | Chi la usa | Cosa fa |
|---|---|---|
| Stabilimento | Capoturno | Planimetria dei due edifici: celle frigo, linee, zona confezionamento e carico |
| Conferimenti | Pesa | Peso lordo, tara dei bins, netto, cella di destinazione: nasce il lotto |
| 1 · Ordini del giorno | Ufficio | Ordini con tipo, arrivo, camion, priorità di uscita. Simulazione degli arrivi |
| 2 · Piano linee | Responsabile | Una colonna per linea, ordini trascinabili, cambi, avviso sul camion, "Proponi sequenza" |
| 3 · Squadre | Responsabile | Richiesti, assegnati, mancanti per linea, dai presenti del giorno |
| 4 · Fogli di lavorazione | Responsabile | Lotti in ingresso, kg confezionati, scarto: il costo in €/kg appare al salvataggio |
| 5 · Margini | Direzione | Costo e margine per foglio, lotto, produttore, cliente. Simulatore di prezzo minimo |
| Missioni bins | Carrellisti | Quale lotto prelevare, da quale cella, per quale linea, entro che ora (sola lettura) |

## Avvio in tre comandi

```bash
git clone https://github.com/TommasoSeika/OpenMes.git && cd OpenMes
./install.sh
docker compose exec backend sh modules/Ortofrutta/bin/attiva-demo.sh "Nome Azienda"
```

Il primo scarica il codice, il secondo costruisce e avvia OpenMES (stampa
indirizzo, utente e password), il terzo attiva il modulo e carica i dati demo.
Poi si apre `/admin/ortofrutta`, oppure la voce **Ortofrutta** nel menu.

Il nome dell'azienda è facoltativo: è quello che compare nell'intestazione di
ogni schermata. Sta nel database, non nel codice.

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
4. **Si assegnano le persone.** In *3 · Squadre*, "Proponi squadre": la Linea 4
   resta sotto organico (mancano specialisti: tre assenti, uno esce prima) e il
   sistema mostra chi è presente e abilitato. Nel piano la linea è evidenziata.
5. **Si chiude un foglio.** Nel piano, "Lancia" sul primo gruppo di una linea crea
   il foglio. In *4 · Fogli di lavorazione*: "Usa i lotti suggeriti", si inserisce
   lo scarto, "Salva e calcola il costo": appare il costo in €/kg.
6. **Si guarda il margine.** In *5 · Margini*, classifica dei produttori: i due a
   resa bassa sono in fondo. Nel simulatore si prova il prezzo di una settimana
   in scontistica.

## Regole di calcolo

Stanno in `Domain/` e non dipendono dal database:

- `CostCalculator` — manodopera, imballi, costo di lavorazione €/kg, resa, costo
  materia, margine, prezzo minimo.
- `LinePlanner` — durata dei blocchi, cambi, ora di fine, avviso sul camion,
  proposta di sequenza.
- `WorkSheetCosting` — il costo di un foglio. È l'unico punto in cui un foglio
  viene valorizzato: schermata 4 e schermata 5 leggono lo stesso numero.

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
| `linee.csv`, `celle.csv`, `regole_cambio.csv` | Anagrafiche di stabilimento |

`seed/genera_dati.py` li rigenera (`python3 seed/genera_dati.py`). È
deterministico: stesso seme, stessi file. **Tutto è inventato**: nomi, prezzi,
costi, rese, capacità.

## Documenti

- [`docs/ricognizione.md`](docs/ricognizione.md) — cosa si riusa di OpenMES, cosa
  si estende, cosa è costruito da zero, e le modifiche al core.
- [`docs/ipotesi.md`](docs/ipotesi.md) — le ipotesi fatte dove mancava
  un'informazione, da confermare con il cliente.
