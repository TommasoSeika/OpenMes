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
| 4 | Una linea richiede 22 persone (Linea 1); le altre 16, 14 e 10. Per alcuni formati ne bastano meno: cassette 20, 14 e 9, vassoi sulla Linea 4 12. | 22 è il numero citato nell'incontro; gli altri sono inventati. | `seed/linee.csv` (`persone_formato`) |
| 5 | Capacità delle linee in pedane/ora per formato: da 2 a 6. | Inventate. Il dato reale di partenza è il tempo di una pedana di Abate. | `seed/linee.csv` |
| 6 | Un lotto sta in una sola cella. Capacità cella 280–360 bins, un bin circa 300 kg netti, tara 33 kg. | Inventate. | `seed/celle.csv`, `seed/conferimenti_pesa.csv` |

## Piano linee

| # | Ipotesi | Come si cambia |
|---|---|---|
| 7 | Le linee partono alle **07:30**. | `Support/Demo.php` (`DAY_START`) |
| 8 | Durata di un blocco = pedane ÷ capacità + **avvio** se è il primo blocco della giornata, oppure **cambio** rispetto al blocco prima. Avvio e cambio non si sommano. | `Domain/LinePlanner.php` |
| 9 | Tre durate di cambio: varietà 25 minuti (pulizia), formato 15, imballo 8. Se ne servono più insieme **conta il più pesante**, non la somma. Cambiare solo calibro non ferma la linea. | `seed/regole_cambio.csv` (anche regole per coppia di articoli) |
| 10 | Un blocco non può iniziare prima che il suo ordine sia arrivato. | `Domain/LinePlanner.php` |
| 11 | "Proponi sequenza": ordini presi per ora del camion e poi per priorità dell'ufficio; ciascuno va dove costa meno (prima nessun camion perso, poi finire presto, poi meno cambi). Su ogni linea si resta in ordine di camion. La priorità pesa sul finire presto (alta conta tre volte, bassa una), ma non fa aspettare una linea per un ordine che deve ancora arrivare. | `Domain/LinePlanner.php` |
| 12 | Blocchi consecutivi dello stesso articolo sulla stessa linea sono **un solo lancio**, quindi un solo foglio. Un ordine lanciato non si sposta più. | `Services/PlanService.php` |
| 13 | Pause e fermi macchina non sono considerati. | — |

## Persone e costo

| # | Ipotesi | Come si cambia |
|---|---|---|
| 14 | Il costo orario è una **media per qualifica**, non per persona: espone meno dati di busta paga. | `seed/costi_orari.csv` |
| 15 | Le persone si assegnano **alla singola produzione** (gli ordini dello stesso articolo, uno dopo l'altro su una linea: un foglio). Una persona può fare più produzioni nella giornata, anche su linee diverse, purché gli orari non si sovrappongano. Il costo di chi cambia linea si divide così da solo tra i fogli. | `Domain/CrewPlanner.php` |
| 16 | Le ore di una persona su un foglio sono la durata del foglio, ridotta se è uscita prima. | `Domain/WorkSheetCosting.php` |
| 17 | Chi è assente non si può assegnare. Chi **esce prima** si può assegnare alle produzioni che cominciano prima della sua uscita; se esce durante la produzione è segnalato, e "Proponi squadre" non lo sceglie. Chi è presente tutto il turno resta finché le linee finiscono. | `Domain/CrewPlanner.php` |
| 17b | Le persone richieste dipendono da **linea e formato**. La squadra è fatta di teste, non di postazioni: non si dice chi sta alla cernita e chi al confezionamento. | `seed/linee.csv`; postazioni da fare se servono |
| 17c | "Proponi squadre" riempie le produzioni dalla prima all'ultima: prima un capolinea, poi chi era già sulla produzione precedente della linea, poi chi ha già lavorato su quella linea oggi, poi chi è abilitato su meno linee. Chi è già assegnato a mano resta dov'è. | `Domain/CrewPlanner.php` |
| 17d | La squadra segue gli ordini: se il piano cambia, ogni ordine porta con sé le sue persone. Dopo il lancio la squadra si può ancora cambiare e il foglio aperto si aggiorna; a foglio chiuso non più. Le giornate passate mostrano la squadra scritta sui fogli. | `Services/CrewService.php` |
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

## Tracciabilità

| # | Ipotesi | Come si cambia |
|---|---|---|
| 27a | Il prodotto finito si identifica con il **lotto di confezionamento**, uno per foglio di lavorazione: `L` + anno + giorno dell'anno + numero del foglio (foglio 1702 del 24 novembre 2026 = `L26328-1702`). È quello che andrebbe stampato sull'etichetta della pedana. Non ci sono codici per singola pedana (SSCC). | `Domain/Traceability.php` |
| 27b | Un foglio confeziona un articolo per uno o più ordini in un'unica passata, e la linea non registra da quale bins viene ogni collo. I kg di ogni lotto sono quindi **ripartiti tra gli ordini del foglio in proporzione alle pedane**: l'elenco dei lotti dietro un ordine è esatto, i kg per lotto sono una stima che torna al totale. | `Domain/Traceability.php` |
| 27c | La catena non ha tabelle proprie: è ordine → foglio → lotti del foglio → lotto → produttore, cioè quello che i fogli già registrano. Un ordine è tracciabile da quando viene lanciato; i lotti compaiono quando si salvano sul foglio. | `Services/TraceService.php` |
| 27d | La tracciabilità si ferma al conferimento pesato all'ingresso: non c'è l'appezzamento, la data di raccolta o i trattamenti. A valle si ferma al camion dell'ordine: non c'è il documento di trasporto. | Da fare con i dati veri |

## Liste di lavoro e scansioni

| # | Ipotesi | Come si cambia |
|---|---|---|
| 27e | Una missione è: i bins di **un lotto**, da **una cella** a **una linea**, per **un ordine**, entro 20 minuti prima che il blocco cominci. Il lotto è il più vecchio della varietà giusta che ha ancora frutta in cella. | `Services/MissionService.php` |
| 27f | Le missioni si fanno in ordine di orario; a pari orario viene prima l'ordine a cui l'ufficio ha dato la priorità più alta. Si dividono tra i carrellisti presenti dando ogni missione a chi ha meno bins da portare. Il capoturno può passarne una a un altro. | `Domain/MissionPlanner.php` |
| 27g | Le liste **si generano a comando** dal piano linee. Se il piano cambia la schermata lo segnala e si rigenerano: le missioni già prelevate o consegnate restano, quelle da fare vengono rifatte. | `Services/MissionService.php` |
| 27h | I codici dicono cosa sono: `LOT:` l'etichetta sul bins (una per lotto, stampata alla pesa), `LINE:` il codice all'ingresso della linea, `CELL:` la porta della cella. Si legge il bins al prelievo e la linea alla consegna; la cella non si legge. Non c'è un codice per singolo bins: si conferma il lotto, non il numero di bins. | `Domain/MissionPlanner.php` |
| 27i | La **fotocamera è simulata**: il terminale disegna le etichette che il carrellista avrebbe davanti, con i loro QR veri, e toccarne una equivale a leggerla. Un lettore a pistola funziona già, perché scrive il codice come una tastiera. La lettura con la fotocamera del telefono è da fare. | `Pages/ortofrutta/Terminal.jsx` |
| 27j | Un bins letto alla cella esce dalla giacenza della cella. Una missione consegnata scrive il lotto, con i suoi kg, sul foglio di lavorazione di quella produzione: subito se è già lanciata, al lancio se non lo è. I kg sono quelli della missione, non una pesata. | `Services/WorkSheetService.php` |
| 27k | Sul terminale la persona si sceglie da un elenco: sul campo entrerebbe con il badge. La lista di un operatore di linea sono le produzioni su cui è in squadra; per carrellisti e operatori non ci sono ancora liste per imballi, pedane finite o pulizie. | Da fare se servono |

## Demo

| # | Ipotesi | Come si cambia |
|---|---|---|
| 28 | La giornata demo è l'ultima con ordini (27 novembre 2026) e l'orologio è simulato: parte dalle 10:00 e avanza quando si simulano gli arrivi. Non segue l'ora reale. | `Support/Demo.php` |
| 29 | "Simula arrivo ordini" fa entrare gli ordini A per A a ondate: tutti quelli arrivati entro 70 minuti dal primo in attesa. Tre ondate nella giornata demo. | `OrderController` |
| 30 | Per sapere quanta frutta serve a un ordine si usa la resa storica della varietà. | `Services/MissionService.php` |
| 31 | Il nome dell'azienda, nel menu e nell'intestazione, è un'impostazione nel database (`attiva-demo.sh "Nome"`), non è scritto nel codice. Le schermate stanno all'indirizzo `/admin/stabilimento`; il vecchio `/admin/ortofrutta` porta lì. | Il repository è pubblico |
| 32 | Il brief di progetto **non è nel repository**: contiene informazioni dell'incontro con il cliente e il repository è pubblico. | Si aggiunge se il repository diventa privato |

## Punti aperti del brief, come sono stati trattati

- Rapporto tra l'azienda e la cooperativa dei soci: non modellato; costi e
  margini sono intestati a un'unica azienda.
- Numero e nomi delle linee: ipotesi 1.
- Capacità reale e durata dei cambi: ipotesi 5 e 9.
- Persona che cambia linea a metà turno: risolto con le squadre per produzione, ipotesi 15.
- Costo orario per persona o per qualifica: ipotesi 14.
- Ricavo dello scarto: ipotesi 20.
- App turni, distinte imballi, timbrature: simulate dai CSV con una forma
  plausibile; i nomi delle colonne vanno allineati alle esportazioni vere.
