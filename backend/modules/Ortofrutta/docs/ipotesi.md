# Ipotesi

Dove mancava un'informazione è stata fatta un'ipotesi ragionevole. Sono elencate
qui perché vanno confermate o corrette con il cliente prima di passare ai dati
veri. Nessuna blocca la demo.

## Stabilimento

| # | Ipotesi | Da dove viene | Come si cambia |
|---|---|---|---|
| 1 | Le linee sono **4**, tutte nell'Edificio 2, chiamate Linea 1, 2, 4 e 6. | La planimetria ne disegna 4; nell'incontro sono state citate la 4 e la 6. Il brief ne ipotizzava 6. | `seed/linee.csv` |
| 2 | Le celle frigo sono **24**: 16 nell'Edificio 2 (due blocchi da 8) e 8 nell'Edificio 1. | Planimetria. | `seed/celle.csv` |
| 3 | La zona verde dell'Edificio 1 è confezionamento pedane e carico camion. | Planimetria ("packaging"). | — |
| 4 | Una linea richiede 22 persone (Linea 1); le altre 18, 14 e 12. | 22 è il numero citato nell'incontro; gli altri sono inventati. | `seed/linee.csv` |
| 5 | Capacità delle linee in pedane/ora per formato: da 2 a 6. | Inventate. Il dato reale di partenza è il tempo di una pedana di Abate. | `seed/linee.csv` |
| 6 | Un lotto sta in una sola cella. Capacità cella 280–360 bins, un bin circa 300 kg netti, tara 33 kg. | Inventate. | `seed/celle.csv`, `seed/conferimenti_pesa.csv` |

## Piano linee

| # | Ipotesi | Come si cambia |
|---|---|---|
| 7 | Le linee partono alle **07:30**. | `Support/Demo.php` (`DAY_START`) |
| 8 | Durata di un blocco = pedane ÷ capacità + **avvio** se è il primo blocco della giornata, oppure **cambio** rispetto al blocco prima. Avvio e cambio non si sommano. | `Domain/LinePlanner.php` |
| 9 | Tre durate di cambio: varietà 25 minuti (pulizia), formato 15, imballo 8. Se ne servono più insieme **conta il più pesante**, non la somma. Cambiare solo calibro non ferma la linea. | `seed/regole_cambio.csv` (anche regole per coppia di articoli) |
| 10 | Un blocco non può iniziare prima che il suo ordine sia arrivato. | `Domain/LinePlanner.php` |
| 11 | "Proponi sequenza": ordini presi per ora del camion e poi per priorità dell'ufficio; ciascuno va dove costa meno (prima nessun camion perso, poi finire presto, poi meno cambi). Su ogni linea si resta in ordine di camion. | `Domain/LinePlanner.php` |
| 12 | Blocchi consecutivi dello stesso articolo sulla stessa linea sono **un solo lancio**, quindi un solo foglio. Un ordine lanciato non si sposta più. | `Services/PlanService.php` |
| 13 | Pause e fermi macchina non sono considerati. | — |

## Persone e costo

| # | Ipotesi | Come si cambia |
|---|---|---|
| 14 | Il costo orario è una **media per qualifica**, non per persona: espone meno dati di busta paga. | `seed/costi_orari.csv` |
| 15 | Una persona lavora su **una linea al giorno**. Il costo di chi cambia linea a metà turno non è diviso tra i fogli. | Punto aperto del brief |
| 16 | Le ore di una persona su un foglio sono la durata del foglio, ridotta se è uscita prima. | `Domain/WorkSheetCosting.php` |
| 17 | Chi è assente **o esce prima** non si può assegnare a una linea. | `Services/CrewService.php` |
| 18 | Carrellisti, controllo qualità e addetti alle pedane non entrano nel costo dei fogli. | — |
| 19 | 80 persone, circa 77 presenti; turno unico 07:00–19:30 che copre anche i camion della sera. | `seed/presenze_app_turni.csv` |

## Fogli e margini

| # | Ipotesi | Come si cambia |
|---|---|---|
| 20 | Lo scarto (sidro, industria) **non ha un ricavo**: non riduce il costo. | Punto aperto del brief |
| 21 | Kg entrati, kg confezionati e scarto si registrano **per lotto**, così la resa è del singolo produttore. I kg confezionati si propongono come entrati − scarto e si possono correggere (calo peso). | — |
| 22 | Le unità prodotte (colli, pedane) derivano dai kg confezionati: non si digitano. | `Domain/WorkSheetCosting.php` |
| 23 | Tutti i lotti di un foglio hanno lo stesso costo di lavorazione €/kg, quello del foglio. | — |
| 24 | Il prezzo di vendita di un foglio è la media dei prezzi dei suoi ordini (listino del cliente per la settimana, al netto dello sconto), pesata sulle pedane. | `Services/MarginService.php` |
| 25 | Il margine si calcola su tutta la merce della demo: non c'è distinzione tra merce propria e lavorazione per l'azienda partner. | Da fare con i dati veri |
| 26 | Prezzi di acquisto, listini, sconti e distinte imballi sono inventati. Pere Abate è l'unica varietà reale; clienti, produttori e persone hanno nomi di fantasia. | `seed/` |
| 27 | Due produttori (P07, P12) hanno una resa volutamente bassa, attorno al 70%. | `seed/genera_dati.py` |

## Demo

| # | Ipotesi | Come si cambia |
|---|---|---|
| 28 | La giornata demo è l'ultima con ordini (27 novembre 2026) e l'orologio è simulato: parte dalle 10:00 e avanza quando si simulano gli arrivi. Non segue l'ora reale. | `Support/Demo.php` |
| 29 | "Simula arrivo ordini" fa entrare gli ordini A per A a ondate: tutti quelli arrivati entro 70 minuti dal primo in attesa. Tre ondate nella giornata demo. | `OrderController` |
| 30 | Le missioni bins prendono il lotto più vecchio della varietà giusta e chiedono il bin alla linea 20 minuti prima dell'inizio del blocco. La resa attesa è quella storica della varietà. | `Services/PlantService.php` |
| 31 | Il nome dell'azienda nell'intestazione è un'impostazione nel database (`attiva-demo.sh "Nome"`), non è scritto nel codice. | Il repository è pubblico |
| 32 | Il brief di progetto **non è nel repository**: contiene informazioni dell'incontro con il cliente e il repository è pubblico. | Si aggiunge se il repository diventa privato |

## Punti aperti del brief, come sono stati trattati

- Rapporto tra l'azienda e la cooperativa dei soci: non modellato; costi e
  margini sono intestati a un'unica azienda.
- Numero e nomi delle linee: ipotesi 1.
- Capacità reale e durata dei cambi: ipotesi 5 e 9.
- Persona che cambia linea a metà turno: ipotesi 15.
- Costo orario per persona o per qualifica: ipotesi 14.
- Ricavo dello scarto: ipotesi 20.
- App turni, distinte imballi, timbrature: simulate dai CSV con una forma
  plausibile; i nomi delle colonne vanno allineati alle esportazioni vere.
