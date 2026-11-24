# Ricognizione: OpenMES e il modulo Ortofrutta

Letto sul repository alla versione 0.29.3. Dice cosa il modulo riusa di OpenMES,
cosa estende e cosa costruisce da zero.

## Come è fatto OpenMES

- **Backend** Laravel 12 su PostgreSQL 17, servito da Octane/RoadRunner in Docker.
- **Frontend** React 19 + Inertia, compilato da Vite. Il brief parlava di
  Livewire e Alpine: il progetto li sta abbandonando e chiede che le pagine nuove
  siano React (`CLAUDE.md`, "Frontend conventions"). Le schermate del modulo sono
  quindi React.
- **Moduli**: cartelle in `backend/modules/<Nome>/` con un `module.json` e un
  service provider. Sono spenti finché non vengono attivati (Admin → Modules,
  oppure `bin/attiva-demo.sh`). Un modulo spento non registra nulla.

## Cosa si riusa

| Di OpenMES | Come lo usa il modulo |
|---|---|
| Sistema dei moduli (`ModuleManager`, `module.json`, `Installer`) | Attivazione, migrazioni proprie, caricamento dati alla prima attivazione |
| Autenticazione, sessioni, ruoli | Le schermate stanno sotto `/admin` e passano dal middleware `tab.access` |
| Layout dell'applicazione (`AppLayout`), messaggi flash, titolo di pagina | Ogni schermata è una pagina Inertia dentro il layout di OpenMES |
| Build Vite del core | Compila anche le pagine del modulo (`modules/*/resources/js/Pages`) e i suoi stili Tailwind |
| `apiCall` (`resources/js/lib/http.js`) | Chiamate del piano linee, con il token CSRF di OpenMES |
| Linee di produzione del core (`lines`) | Le 4 linee vengono anche create come linee core (codice `ORTO-L1`…), così compaiono nel resto di OpenMES |

## Cosa si estende (solo punti di estensione, nessuna modifica)

| Punto di estensione | Uso |
|---|---|
| `MenuRegistry::addGroup` / `addGroupItem` | Gruppo "Ortofrutta" nel menu, con le schermate in ordine |
| Filtro `tabs.registry` | Scheda "ortofrutta": il permesso `tab:ortofrutta` si può dare a un ruolo |
| `loadMigrationsFrom`, `loadRoutesFrom`, comandi artisan | Tabelle `orto_*`, rotte `/admin/ortofrutta/*`, `ortofrutta:demo`, `ortofrutta:verifica` |

## Cosa si costruisce da zero

Tutto il dominio ortofrutticolo, che in OpenMES non c'è:

- **Pesa e celle frigo.** Il magazzino del core ha giacenze per magazzino e
  lotto, ma non celle, bins, tara o peso alla pesa.
- **Ordini con pedane, tipo (A per A, A per B, scorta), ora di arrivo e ora del
  camion.** L'ordine di produzione del core ha una sola data, che fa sia da
  giorno pianificato sia da scadenza.
- **Piano linee con tempi di cambio e avviso sul camion** (vedi sotto).
- **Squadre dai presenti del giorno.** Nel core squadre, qualifiche e assenze
  sono previste come modulo esterno, non incluso.
- **Fogli di lavorazione con costo in €/kg, resa e scarto**, e **margini per
  lotto, produttore e cliente**. Il core ha un report costi per ordine, non un
  costo al kg né un margine.

## Piano linee: perché dentro il modulo (il "piano B" del brief)

Il pianificatore di OpenMES non regge i due requisiti centrali:

- **Tempi di cambio.** Il pianificatore manuale (`/admin/schedule`) non li
  conosce. Esistono solo nello schedulatore automatico, che li conta ma
  dichiara di non riordinare per ridurli.
- **Ora del camion.** Non c'è un campo di spedizione: l'ordine è "in ritardo"
  solo se la sua data è passata. Non si può dire "finisce alle 15:20, il camion
  parte alle 15:00".

La schermata 2 è quindi costruita nel modulo. Gli ordini restano nelle tabelle
del modulo: creare ordini di produzione del core richiederebbe tipi prodotto,
processi e lotti di produzione che al POC non servono. Collegarli è un passo
successivo naturale (un ordine lanciato → un ordine di produzione del core).

## Modifiche al core

Due, entrambe liste di cartelle ammesse, nessuna di comportamento:

1. `backend/.gitignore` — aggiunta `!/modules/Ortofrutta` (i moduli sono
   ignorati da git tranne gli esempi; senza questa riga il modulo non si può
   versionare) e l'esclusione di `config/octane.php`, punto di montaggio creato
   dal codespace.
2. `.githooks/pre-commit` — aggiunta `Ortofrutta/` all'elenco dei moduli che il
   controllo pre-commit lascia passare.

Il resto vive in `backend/modules/Ortofrutta`, più i test in
`backend/tests/Unit/Ortofrutta` (è dove il progetto tiene i test dei moduli).

## Struttura del modulo

```
Domain/        regole di calcolo, senza database
Models/        tabelle orto_*
Services/      piano, squadre, fogli, margini, stabilimento, caricamento dati
Http/          controller e validazione delle richieste
Console/       ortofrutta:demo, ortofrutta:verifica
resources/js/  le nove schermate (React)
seed/          CSV dei dati simulati e lo script che li genera
bin/           attiva-demo.sh
docs/          questo file e le ipotesi
```

## Fuori perimetro, non toccato

Tenant multipli, cancellazioni con cestino, traduzioni del core (non esiste
l'italiano: le schermate del modulo sono scritte in italiano, il resto di
OpenMES resta in inglese), permessi fini, messa in produzione.
