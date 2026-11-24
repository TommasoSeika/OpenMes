#!/usr/bin/env python3
"""Genera i dati simulati del modulo Ortofrutta.

Tutto quello che esce da questo script e' inventato: nomi di persone,
produttori e clienti, prezzi, costi, rese, capacita' delle linee. Nessun dato
reale. I file hanno la forma che avrebbero le esportazioni delle fonti vere
(gestionale, app turni, timbrature, calibratrici), cosi' passare ai dati veri
vuol dire sostituire i file.

Uso:  python3 genera_dati.py        (riscrive i CSV accanto allo script)

Il risultato e' deterministico: stesso seme, stessi file.
"""

import csv
import math
import random
from collections import defaultdict
from datetime import date, datetime, time, timedelta
from pathlib import Path

OUT = Path(__file__).resolve().parent
random.seed(20261127)

# ── Calendario ───────────────────────────────────────────────────────────────
# Dieci giorni lavorativi. L'ultimo e' la "giornata demo": ha gli ordini ma non
# ancora i fogli di lavorazione, che si creano dal piano durante la demo.
GIORNI = [date(2026, 11, 16) + timedelta(days=d) for d in (0, 1, 2, 3, 4, 7, 8, 9, 10, 11)]
DEMO = GIORNI[-1]
INIZIO_LINEE = time(7, 30)


def dt(giorno, ora):
    return datetime.combine(giorno, ora)


def fmt_dt(x):
    return x.strftime("%Y-%m-%d %H:%M") if x else ""


def scrivi(nome, intestazione, righe):
    with open(OUT / nome, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f, delimiter=";")
        w.writerow(intestazione)
        w.writerows(righe)
    print(f"  {nome:32s} {len(righe):5d} righe")


# ── Linee (planimetria: 4 linee nell'Edificio 2) ─────────────────────────────
# codice, nome, edificio, persone richieste, minuti di avvio, {formato: pedane/ora}
LINEE = [
    ("L1", "Linea 1", "Calibratrice grande", "Edificio 2", 22, 15,
     {"Cartone 40x60": 6.0, "Cassetta 30x40": 5.0}),
    ("L2", "Linea 2", "Calibratrice media", "Edificio 2", 16, 15,
     {"Cartone 40x60": 5.5, "Cassetta 30x40": 4.5, "Vassoio 6 frutti": 3.0}),
    ("L4", "Linea 4", "Cestini e vassoi", "Edificio 2", 14, 20,
     {"Cestino 1 kg": 2.5, "Vassoio 6 frutti": 3.5}),
    ("L6", "Linea 6", "Polivalente", "Edificio 2", 10, 10,
     {"Cartone 40x60": 4.0, "Cassetta 30x40": 3.5, "Cestino 1 kg": 2.0}),
]
CAP = {l[0]: l[6] for l in LINEE}
AVVIO = {l[0]: l[5] for l in LINEE}
RICHIESTE = {l[0]: l[4] for l in LINEE}

# ── Celle frigo (planimetria: 16 nell'Edificio 2, 8 nell'Edificio 1) ─────────
CELLE = []
n = 0
for blocco, righe in (("Nord", (1, 2)), ("Sud", (3, 4))):
    for r in righe:
        for c in range(1, 5):
            n += 1
            CELLE.append((f"C{n:02d}", "Edificio 2", blocco, r, c, random.choice((320, 340, 360)), 0.5))
for r in (1, 2):
    for c in range(1, 5):
        n += 1
        CELLE.append((f"C{n:02d}", "Edificio 1", "Unico", r, c, random.choice((280, 300)), 1.0))

# ── Clienti (nomi di fantasia) ───────────────────────────────────────────────
CLIENTI = [
    ("C01", "Supermercati Aurora", "GDO"),
    ("C02", "Mercati Riuniti del Delta", "Mercato"),
    ("C03", "Spesa Chiara", "GDO"),
    ("C04", "Gruppo Ortolando", "GDO"),
    ("C05", "Frutta Nord Export", "Export"),
    ("C06", "Dettaglio Belvedere", "Dettaglio"),
]

# ── Formati e imballi ────────────────────────────────────────────────────────
# formato: (kg per collo, colli per pedana)
FORMATI = {
    "Cartone 40x60": (13.0, 48),
    "Cassetta 30x40": (6.0, 96),
    "Cestino 1 kg": (8.0, 80),
    "Vassoio 6 frutti": (9.0, 60),
}
# imballo: [(componente, quantita', riferimento, costo unitario)]
PEDANA = [("Pedana legno", 1, "pedana", 7.50), ("Angolari e reggia", 1, "pedana", 1.10)]
DISTINTE = {
    "Cartone marchio cliente": [("Cartone 40x60 stampato", 1, "collo", 0.62), ("Alveolo", 2, "collo", 0.06),
                                ("Etichetta", 1, "collo", 0.012)] + PEDANA,
    "Cartone neutro": [("Cartone 40x60 neutro", 1, "collo", 0.48), ("Alveolo", 2, "collo", 0.06),
                       ("Etichetta", 1, "collo", 0.012)] + PEDANA,
    "Cartone export": [("Cartone 40x60 rinforzato", 1, "collo", 0.70), ("Alveolo", 2, "collo", 0.06),
                       ("Sacchetto", 1, "collo", 0.035), ("Etichetta", 1, "collo", 0.012)] + PEDANA,
    "Cassetta legno": [("Cassetta legno 30x40", 1, "collo", 0.42), ("Carta velina", 1, "collo", 0.03),
                       ("Etichetta", 1, "collo", 0.012)] + PEDANA,
    "Cassetta plastica a rendere": [("Nolo cassetta plastica", 1, "collo", 0.18),
                                    ("Etichetta", 1, "collo", 0.012)] + PEDANA,
    "Cestino flowpack": [("Cestino", 8, "collo", 0.045), ("Film flowpack", 8, "collo", 0.02),
                         ("Cartone 30x40", 1, "collo", 0.38), ("Etichetta", 8, "collo", 0.012)] + PEDANA,
    "Cestino rete": [("Cestino", 8, "collo", 0.045), ("Rete", 8, "collo", 0.03),
                     ("Cartone 30x40", 1, "collo", 0.38), ("Etichetta", 8, "collo", 0.012)] + PEDANA,
    "Vassoio filmato": [("Vassoio", 10, "collo", 0.05), ("Film", 10, "collo", 0.018),
                        ("Cartone 30x40", 1, "collo", 0.40), ("Etichetta", 10, "collo", 0.012)] + PEDANA,
}

# ── Articoli ─────────────────────────────────────────────────────────────────
# Abate Fetel e' la varieta' principale; le altre hanno nomi di fantasia.
# codice, varieta', calibro, formato, imballo, cliente (vuoto = generico)
ARTICOLI = [
    ("ART001", "Abate Fetel", "65-70", "Cartone 40x60", "Cartone marchio cliente", "C01"),
    ("ART002", "Abate Fetel", "70-75", "Cartone 40x60", "Cartone marchio cliente", "C01"),
    ("ART003", "Abate Fetel", "65-70", "Cartone 40x60", "Cartone neutro", ""),
    ("ART004", "Abate Fetel", "70-75", "Cartone 40x60", "Cartone neutro", ""),
    ("ART005", "Abate Fetel", "75+", "Cartone 40x60", "Cartone export", "C05"),
    ("ART006", "Abate Fetel", "60-65", "Cassetta 30x40", "Cassetta legno", ""),
    ("ART007", "Abate Fetel", "65-70", "Cassetta 30x40", "Cassetta plastica a rendere", "C02"),
    ("ART008", "Abate Fetel", "60-65", "Cestino 1 kg", "Cestino flowpack", "C01"),
    ("ART009", "Abate Fetel", "65-70", "Vassoio 6 frutti", "Vassoio filmato", "C03"),
    ("ART010", "Pera Dorata", "65-70", "Cartone 40x60", "Cartone neutro", ""),
    ("ART011", "Pera Dorata", "60-65", "Cestino 1 kg", "Cestino flowpack", "C03"),
    ("ART012", "Pera Brunella", "65-70", "Cassetta 30x40", "Cassetta legno", ""),
    ("ART013", "Pera Brunella", "70-75", "Cartone 40x60", "Cartone neutro", ""),
    ("ART014", "Mela Rubina", "75-80", "Cartone 40x60", "Cartone marchio cliente", "C01"),
    ("ART015", "Mela Rubina", "70-75", "Cassetta 30x40", "Cassetta plastica a rendere", "C02"),
    ("ART016", "Mela Rubina", "70-75", "Vassoio 6 frutti", "Vassoio filmato", "C04"),
    ("ART017", "Mela Verdea", "75-80", "Cartone 40x60", "Cartone neutro", ""),
    ("ART018", "Mela Verdea", "70-75", "Cestino 1 kg", "Cestino rete", "C04"),
    ("ART019", "Abate Fetel", "70-75", "Cassetta 30x40", "Cassetta legno", ""),
    ("ART020", "Mela Rubina", "80+", "Cartone 40x60", "Cartone export", "C05"),
]
ART = {a[0]: {"varieta": a[1], "calibro": a[2], "formato": a[3], "imballo": a[4], "cliente": a[5]} for a in ARTICOLI}
for cod, a in ART.items():
    kg_collo, colli = FORMATI[a["formato"]]
    a["kg_pedana"] = kg_collo * colli
VARIETA = sorted({a["varieta"] for a in ART.values()})

# Clienti che possono ordinare gli articoli generici.
CLIENTI_GENERICI = {
    "ART003": ["C02", "C06", "C04"], "ART004": ["C02", "C06", "C03"], "ART006": ["C02", "C06"],
    "ART010": ["C02", "C03", "C06"], "ART012": ["C02", "C06"], "ART013": ["C02", "C04"],
    "ART017": ["C02", "C06", "C04"], "ART019": ["C02", "C06"],
}

# ── Regole di cambio: una durata per tipo ────────────────────────────────────
CAMBIO = {"varieta": 25, "formato": 15, "imballo": 8}


def minuti_cambio(da, a):
    """Vale il cambio piu' pesante fra quelli che servono."""
    if da is None or da == a:
        return 0
    x, y = ART[da], ART[a]
    if x["varieta"] != y["varieta"]:
        return CAMBIO["varieta"]
    if x["formato"] != y["formato"]:
        return CAMBIO["formato"]
    if x["imballo"] != y["imballo"]:
        return CAMBIO["imballo"]
    return 0


# ── Produttori (soci conferenti, nomi di fantasia) ───────────────────────────
PRODUTTORI = [
    ("P01", "Az. Agr. Fontanile", "Borgo Fontanile"), ("P02", "Podere Le Robinie", "Le Robinie"),
    ("P03", "Soc. Agr. Campolungo", "Campolungo"), ("P04", "Az. Agr. Tre Pioppi", "Tre Pioppi"),
    ("P05", "Frutteto del Borgo", "Borgo Nuovo"), ("P06", "Az. Agr. Vallescura", "Vallescura"),
    ("P07", "Podere Argine Vecchio", "Argine Vecchio"), ("P08", "Soc. Agr. La Chiusa", "La Chiusa"),
    ("P09", "Az. Agr. Quattro Venti", "Quattro Venti"), ("P10", "Frutteti Riva Alta", "Riva Alta"),
    ("P11", "Az. Agr. Casa Rossa", "Casa Rossa"), ("P12", "Podere Il Gelso", "Il Gelso"),
    ("P13", "Soc. Agr. Terre Basse", "Terre Basse"), ("P14", "Az. Agr. Ponte Stretto", "Ponte Stretto"),
    ("P15", "Frutteto San Lazzaro di Sotto", "San Lazzaro di Sotto"),
]
# Due produttori con resa volutamente bassa: devono finire in fondo alla
# classifica dei margini.
RESA_BASSA = {"P07", "P12"}
RESA_PROD = {p[0]: (random.uniform(0.66, 0.72) if p[0] in RESA_BASSA else random.uniform(0.87, 0.93))
             for p in PRODUTTORI}
PREZZO_ACQ = {"Abate Fetel": (0.95, 1.05), "Pera Dorata": (0.62, 0.70), "Pera Brunella": (0.55, 0.62),
              "Mela Rubina": (0.48, 0.55), "Mela Verdea": (0.42, 0.48)}
# Chi coltiva cosa.
COLTIVA = {
    "Abate Fetel": ["P01", "P02", "P03", "P04", "P05", "P06", "P07", "P08", "P09", "P10", "P12"],
    "Pera Dorata": ["P02", "P11", "P13"], "Pera Brunella": ["P05", "P14"],
    "Mela Rubina": ["P09", "P11", "P12", "P13", "P15"], "Mela Verdea": ["P14", "P15"],
}

# ── Listino di vendita per cliente e settimana ───────────────────────────────
PREZZO_BASE = {
    ("Abate Fetel", "Cartone 40x60"): 1.55, ("Abate Fetel", "Cassetta 30x40"): 1.48,
    ("Abate Fetel", "Cestino 1 kg"): 1.98, ("Abate Fetel", "Vassoio 6 frutti"): 1.88,
    ("Pera Dorata", "Cartone 40x60"): 1.18, ("Pera Dorata", "Cestino 1 kg"): 1.62,
    ("Pera Brunella", "Cassetta 30x40"): 1.08, ("Pera Brunella", "Cartone 40x60"): 1.10,
    ("Mela Rubina", "Cartone 40x60"): 0.98, ("Mela Rubina", "Cassetta 30x40"): 0.93,
    ("Mela Rubina", "Vassoio 6 frutti"): 1.28, ("Mela Verdea", "Cartone 40x60"): 0.90,
    ("Mela Verdea", "Cestino 1 kg"): 1.32,
}
CORREZIONE_CLIENTE = {"C01": 0.00, "C02": -0.06, "C03": 0.02, "C04": 0.01, "C05": 0.08, "C06": 0.04}
SETTIMANE = [f"2026-W{w}" for w in range(45, 53)]
# Settimane in scontistica: (cliente, settimana) -> sconto %
SCONTI = {("C01", "2026-W48"): 10, ("C01", "2026-W50"): 12, ("C03", "2026-W47"): 8,
          ("C03", "2026-W49"): 10, ("C04", "2026-W48"): 6, ("C04", "2026-W51"): 9}


def clienti_di(articolo):
    a = ART[articolo]
    return [a["cliente"]] if a["cliente"] else CLIENTI_GENERICI[articolo]


# ── Persone (80, nomi fittizi) ───────────────────────────────────────────────
NOMI = ["Ada", "Aldo", "Anna", "Bruna", "Carla", "Dario", "Elena", "Elio", "Fabio", "Franca", "Gina", "Gino",
        "Ilaria", "Ivo", "Lara", "Lino", "Luca", "Marta", "Mirco", "Nadia", "Nino", "Olga", "Paola", "Piero",
        "Rita", "Rocco", "Sara", "Silvio", "Tina", "Ugo", "Vera", "Walter", "Zeno", "Irina", "Karim", "Amina",
        "Dragan", "Elira", "Bogdan", "Fatima"]
COGNOMI = ["Albanti", "Bergoli", "Caminati", "Dellarocca", "Ervani", "Fontechiara", "Galbiero", "Imbriali",
           "Lodesani", "Marzocca", "Nardelli", "Orlandini", "Pratesi", "Quarenghi", "Rossetti", "Salvioli",
           "Tommasini", "Ubaldini", "Venturoli", "Zanardelli", "Kovaci", "Dumitrescu", "El Amrani", "Petrovic",
           "Hoxhaj", "Benali", "Montefiori", "Castelvetri", "Bonaccorsi", "Ferraguti"]
COSTO_ORARIO = {"Addetto cernita": 17.80, "Addetto confezionamento": 18.40, "Capolinea": 22.50,
                "Carrellista": 20.60, "Controllo qualita'": 21.20, "Addetto pedane": 18.90}

PERSONE = []
usati = set()


def nome_nuovo():
    while True:
        x = f"{random.choice(NOMI)} {random.choice(COGNOMI)}"
        if x not in usati:
            usati.add(x)
            return x


def aggiungi(qualifica, gruppo, linee):
    PERSONE.append({"matricola": f"M{len(PERSONE) + 1:03d}", "nome": nome_nuovo(), "gruppo": gruppo,
                    "qualifica": qualifica, "linee": linee})


# Capolinea: uno per linea, piu' un jolly.
for cod in ("L1", "L2", "L4", "L6"):
    aggiungi("Capolinea", f"Squadra {cod}", [cod])
aggiungi("Capolinea", "Jolly", ["L1", "L2", "L6"])
# Addetti di linea. La Linea 4 (cestini e vassoi) ha un gruppo chiuso di
# specialisti: nella giornata demo ne mancano alcuni e la linea resta sotto
# organico, qualunque sia l'assegnazione.
for _ in range(15):
    aggiungi(random.choice(["Addetto confezionamento", "Addetto cernita"]), "Squadra L4", ["L4"])
for _ in range(24):
    aggiungi(random.choice(["Addetto cernita", "Addetto confezionamento"]), "Squadra L1", ["L1", "L2"])
for _ in range(14):
    aggiungi(random.choice(["Addetto cernita", "Addetto confezionamento"]), "Squadra L2", ["L1", "L2", "L6"])
for _ in range(10):
    aggiungi(random.choice(["Addetto cernita", "Addetto confezionamento"]), "Squadra L6", ["L2", "L6"])
# Fuori linea: carrellisti, controllo qualita', zona confezionamento pedane.
for _ in range(6):
    aggiungi("Carrellista", "Carrellisti", [])
for _ in range(3):
    aggiungi("Controllo qualita'", "Qualita'", [])
for _ in range(3):
    aggiungi("Addetto pedane", "Confezionamento", [])
assert len(PERSONE) == 80, len(PERSONE)

# Presenze: circa 77 in turno. Nella giornata demo mancano 4 specialisti della
# Linea 4 (3 assenti, 1 esce prima).
SPECIALISTI_L4 = [p["matricola"] for p in PERSONE if p["linee"] == ["L4"]]
PRESENZE = {}  # (matricola, giorno) -> (stato, entrata, uscita)
MOTIVI = ["Ferie", "Malattia", "Permesso"]
for g in GIORNI:
    assenti = set(random.sample([p["matricola"] for p in PERSONE], 3))
    anticipate = set()
    if g == DEMO:
        linea4 = [m for m in SPECIALISTI_L4 if PERSONE[int(m[1:]) - 1]["qualifica"] != "Capolinea"]
        assenti = set(linea4[:3]) | {PERSONE[30]["matricola"]}
        anticipate = {linea4[3], PERSONE[45]["matricola"]}
    else:
        anticipate = set(random.sample([p["matricola"] for p in PERSONE if p["matricola"] not in assenti], 2))
    for p in PERSONE:
        m = p["matricola"]
        if m in assenti:
            PRESENZE[(m, g)] = (random.choice(MOTIVI), None, None)
        elif m in anticipate:
            PRESENZE[(m, g)] = ("Uscita anticipata", time(7, 0), time(12, 30))
        else:
            PRESENZE[(m, g)] = ("Presente", time(7, 0), time(19, 30))


def presente_fino(m, g):
    stato, entrata, uscita = PRESENZE[(m, g)]
    return uscita


# ── Ordini ───────────────────────────────────────────────────────────────────
ORDINI = []
numero_ordine = 48200


def nuovo_ordine(giorno, articolo, pedane, tipo, arrivo, camion, priorita=2, cliente=None, note=""):
    global numero_ordine
    numero_ordine += 1
    cliente = cliente or random.choice(clienti_di(articolo))
    o = {"numero": f"OV{numero_ordine}", "giorno": giorno, "cliente": cliente, "articolo": articolo,
         "pedane": pedane, "tipo": tipo, "arrivo": arrivo, "camion": camion, "priorita": priorita, "note": note}
    ORDINI.append(o)
    return o


ARTICOLI_PER_LINEA = defaultdict(list)
for cod, a in ART.items():
    for l in CAP:
        if a["formato"] in CAP[l]:
            ARTICOLI_PER_LINEA[l].append(cod)
PESI_ART = {"ART001": 9, "ART002": 7, "ART003": 6, "ART004": 5, "ART005": 4, "ART006": 4, "ART007": 4,
            "ART008": 5, "ART009": 4, "ART010": 3, "ART011": 3, "ART012": 2, "ART013": 2, "ART014": 4,
            "ART015": 3, "ART016": 3, "ART017": 2, "ART018": 3, "ART019": 2, "ART020": 2}


def pedane_casuali():
    return random.choices([1, 2, 3, 4, 5, 6, 8, 10, 12], weights=[2, 5, 6, 6, 4, 4, 3, 2, 1])[0]


def ordini_storici(giorno, precedente):
    scelta = random.choices(list(PESI_ART), weights=list(PESI_ART.values()), k=40)
    # A per B: arrivano la sera prima, partono il giorno dopo.
    for _ in range(random.randint(5, 6)):
        art = scelta.pop()
        arrivo = dt(precedente, time(18, 0)) + timedelta(minutes=random.randrange(0, 90, 5))
        camion = dt(giorno, random.choice([time(12, 0), time(15, 0)]))
        nuovo_ordine(giorno, art, pedane_casuali(), "A per B", arrivo, camion, random.choice([1, 2]))
    # A per A: arrivano tra le 10:00 e le 13:30 e partono in giornata.
    for _ in range(random.randint(16, 18)):
        art = scelta.pop()
        arrivo = dt(giorno, time(10, 0)) + timedelta(minutes=random.randrange(0, 210, 5))
        veloce = arrivo.time() < time(12, 15) and random.random() < 0.6
        camion = dt(giorno, time(15, 0) if veloce else time(19, 30))
        nuovo_ordine(giorno, art, pedane_casuali(), "A per A", arrivo, camion, 1 if veloce else 2)
    # Scorta: righe aggiunte dal responsabile, senza camion.
    for _ in range(random.randint(1, 2)):
        art = random.choice(["ART001", "ART003", "ART014"])
        nuovo_ordine(giorno, art, random.choice([4, 6, 10]), "Scorta", dt(giorno, time(8, 0)), None, 3,
                     cliente=ART[art]["cliente"] or "C02", note="Produzione a scorta")


for i, g in enumerate(GIORNI[:-1]):
    ordini_storici(g, GIORNI[i - 1] if i else g - timedelta(days=3))

# Giornata demo: scritta a mano, perche' il copione della demo deve reggere.
G, IERI = DEMO, GIORNI[-2]
sera = lambda h, m: dt(IERI, time(h, m))
ora = lambda h, m: dt(G, time(h, m))
# 1. A per B arrivati la sera prima: alle 10:00 sono gia' sul piano.
nuovo_ordine(G, "ART001", 10, "A per B", sera(18, 10), ora(12, 0), 1, "C01")
nuovo_ordine(G, "ART002", 6, "A per B", sera(18, 25), ora(12, 0), 1, "C01")
nuovo_ordine(G, "ART014", 8, "A per B", sera(18, 40), ora(12, 0), 1, "C01")
nuovo_ordine(G, "ART008", 4, "A per B", sera(19, 0), ora(15, 0), 2, "C01")
nuovo_ordine(G, "ART006", 6, "A per B", sera(19, 15), ora(15, 0), 2, "C02")
nuovo_ordine(G, "ART012", 4, "A per B", sera(19, 20), ora(19, 30), 2, "C06")
# 2. Prima ondata A per A (il pulsante "Simula arrivo ordini" la fa entrare).
nuovo_ordine(G, "ART003", 5, "A per A", ora(10, 5), ora(15, 0), 1, "C02")
nuovo_ordine(G, "ART001", 8, "A per A", ora(10, 10), ora(15, 0), 1, "C01")
nuovo_ordine(G, "ART009", 4, "A per A", ora(10, 20), ora(15, 0), 1, "C03")
nuovo_ordine(G, "ART004", 4, "A per A", ora(10, 35), ora(15, 0), 1, "C06")
nuovo_ordine(G, "ART011", 3, "A per A", ora(10, 40), ora(15, 0), 1, "C03")
nuovo_ordine(G, "ART015", 5, "A per A", ora(10, 50), ora(15, 0), 1, "C02")
nuovo_ordine(G, "ART007", 6, "A per A", ora(11, 0), ora(15, 0), 1, "C02")
nuovo_ordine(G, "ART016", 4, "A per A", ora(11, 5), ora(19, 30), 2, "C04")
nuovo_ordine(G, "ART002", 5, "A per A", ora(11, 10), ora(15, 0), 1, "C01")
nuovo_ordine(G, "ART010", 3, "A per A", ora(11, 15), ora(19, 30), 2, "C03")
# 3. Seconda ondata.
nuovo_ordine(G, "ART005", 6, "A per A", ora(11, 40), ora(19, 30), 2, "C05")
nuovo_ordine(G, "ART018", 3, "A per A", ora(11, 50), ora(19, 30), 2, "C04")
nuovo_ordine(G, "ART003", 4, "A per A", ora(12, 5), ora(15, 0), 1, "C06")
nuovo_ordine(G, "ART013", 3, "A per A", ora(12, 20), ora(19, 30), 2, "C04")
nuovo_ordine(G, "ART017", 4, "A per A", ora(12, 30), ora(19, 30), 2, "C02")
nuovo_ordine(G, "ART019", 3, "A per A", ora(12, 45), ora(19, 30), 2, "C06")
nuovo_ordine(G, "ART020", 4, "A per A", ora(13, 0), ora(19, 30), 2, "C05")
nuovo_ordine(G, "ART008", 3, "A per A", ora(13, 10), ora(19, 30), 2, "C01")
nuovo_ordine(G, "ART014", 4, "A per A", ora(13, 25), ora(19, 30), 2, "C01")


# ── Piano storico: come sono andate davvero le giornate passate ──────────────
def durata_blocco(linea, articolo, pedane, precedente):
    ore = pedane / CAP[linea][ART[articolo]["formato"]]
    setup = AVVIO[linea] if precedente is None else minuti_cambio(precedente, articolo)
    return setup, timedelta(minutes=round(ore * 60) + setup)


def pianifica(ordini, giorno):
    stato = {l: {"libera": dt(giorno, INIZIO_LINEE), "ultimo": None, "blocchi": []} for l in CAP}
    coda = sorted(ordini, key=lambda o: (o["camion"] or dt(giorno, time(23, 59)), o["arrivo"]))
    for o in coda:
        formato = ART[o["articolo"]]["formato"]
        migliore = None
        for l, s in stato.items():
            if formato not in CAP[l]:
                continue
            inizio = max(s["libera"], o["arrivo"] if o["arrivo"].date() == giorno else dt(giorno, INIZIO_LINEE))
            setup, durata = durata_blocco(l, o["articolo"], o["pedane"], s["ultimo"])
            fine = inizio + durata
            # A parita' di fine, meglio la linea che non cambia articolo.
            chiave = (fine + timedelta(minutes=setup * 2), l)
            if migliore is None or chiave < migliore[0]:
                migliore = (chiave, l, inizio, fine, setup)
        _, l, inizio, fine, setup = migliore
        stato[l]["blocchi"].append({"ordine": o, "inizio": inizio, "fine": fine, "setup": setup})
        stato[l]["libera"], stato[l]["ultimo"] = fine, o["articolo"]
    return stato


# ── Lotti: conferimenti pesati all'ingresso e messi in cella ─────────────────
fabbisogno = defaultdict(float)
for o in ORDINI:
    fabbisogno[ART[o["articolo"]]["varieta"]] += o["pedane"] * ART[o["articolo"]]["kg_pedana"]

LOTTI = []
TARA_BINS = 33
celle_usate = defaultdict(list)
# Le celle vuote si riempiono in ordine sparso, cosi' i lotti finiscono in tutti
# e due gli edifici.
ORDINE_CELLE = random.sample([c[0] for c in CELLE], len(CELLE))


def scegli_cella(varieta, bins):
    # Prima una cella che ha gia' quella varieta' e ha posto, poi una vuota.
    capienza = {c[0]: c[5] for c in CELLE}
    candidate = [c for c, lotti in celle_usate.items()
                 if lotti and lotti[0]["varieta"] == varieta and sum(x["bins"] for x in lotti) + bins <= capienza[c]]
    if candidate:
        return candidate[0]
    for c in ORDINE_CELLE:
        if c not in celle_usate:
            return c
    return min(capienza, key=lambda c: sum(x["bins"] for x in celle_usate[c]))


quota_lotti = {"Abate Fetel": 22, "Pera Dorata": 4, "Pera Brunella": 3, "Mela Rubina": 7, "Mela Verdea": 4}
n_lotto = 0
for varieta, quanti in quota_lotti.items():
    serve = fabbisogno[varieta] / 0.84 * 1.30  # resa media e un 30% di giacenza residua
    produttori = COLTIVA[varieta]
    for i in range(quanti):
        n_lotto += 1
        prod = produttori[i % len(produttori)]
        netto = round(serve / quanti * random.uniform(0.8, 1.2) / 10) * 10
        bins = max(1, round(netto / 300))
        if varieta.startswith("Mela"):
            giorno = date(2026, 10, 5) + timedelta(days=random.randrange(0, 30))
        else:
            giorno = date(2026, 9, 1) + timedelta(days=random.randrange(0, 40))
        lotto = {"codice": f"LT26-{n_lotto:03d}", "giorno": giorno,
                 "ora": time(random.randrange(7, 17), random.choice((0, 10, 20, 30, 40, 50))),
                 "produttore": prod, "varieta": varieta, "netto": netto, "bins": bins,
                 "prezzo": round(random.uniform(*PREZZO_ACQ[varieta]), 2),
                 "resa": max(0.6, min(0.95, RESA_PROD[prod] + random.uniform(-0.015, 0.015))), "residuo": netto}
        lotto["cella"] = scegli_cella(varieta, bins)
        celle_usate[lotto["cella"]].append(lotto)
        LOTTI.append(lotto)
LOTTI.sort(key=lambda x: (x["giorno"], x["ora"]))
for i, l in enumerate(LOTTI, 1):
    l["codice"] = f"LT26-{i:03d}"


# ── Fogli di lavorazione storici ─────────────────────────────────────────────
FOGLI, RIGHE_CALIBRATRICE, SQUADRE = [], [], []
numero_foglio = 1600


def squadra_del_giorno(giorno):
    """Assegna i presenti abilitati fino al fabbisogno, una linea alla volta."""
    liberi = [p for p in PERSONE if PRESENZE[(p["matricola"], giorno)][0] != "Ferie"
              and PRESENZE[(p["matricola"], giorno)][1] is not None]
    assegnati = {}
    presi = set()
    for l in ("L4", "L1", "L2", "L6"):
        candidati = [p for p in liberi if l in p["linee"] and p["matricola"] not in presi]
        candidati.sort(key=lambda p: (p["qualifica"] != "Capolinea", len(p["linee"]), p["matricola"]))
        scelti = candidati[:RICHIESTE[l]]
        presi |= {p["matricola"] for p in scelti}
        assegnati[l] = scelti
    return assegnati


def consuma_lotti(varieta, kg_confezionati, giorno):
    righe = []
    resto = kg_confezionati
    for lotto in LOTTI:
        if resto <= 0.5:
            break
        if lotto["varieta"] != varieta or lotto["giorno"] > giorno or lotto["residuo"] < 300:
            continue
        possibile = lotto["residuo"] * lotto["resa"]
        conf = min(resto, possibile)
        entrati = round(conf / lotto["resa"])
        calo = round(entrati * random.uniform(0.003, 0.008))
        scarto = max(0, entrati - round(conf) - calo)
        sidro = round(scarto * random.uniform(0.55, 0.70))
        righe.append({"lotto": lotto["codice"], "entrati": entrati, "confezionati": round(conf),
                      "sidro": sidro, "industria": scarto - sidro})
        lotto["residuo"] -= entrati
        resto -= conf
    assert resto <= 0.5, f"giacenza insufficiente di {varieta}: mancano {resto:.0f} kg"
    return righe


for g in GIORNI[:-1]:
    del_giorno = [o for o in ORDINI if o["giorno"] == g]
    piano = pianifica(del_giorno, g)
    squadre = squadra_del_giorno(g)
    for l, persone in squadre.items():
        for p in persone:
            SQUADRE.append((g.isoformat(), l, p["matricola"]))
    lanci = []
    for l, s in piano.items():
        corrente = None
        for b in s["blocchi"]:
            art = b["ordine"]["articolo"]
            if corrente and corrente["articolo"] == art:
                corrente["fine"] = b["fine"]
                corrente["ordini"].append(b["ordine"])
            else:
                corrente = {"linea": l, "articolo": art, "inizio": b["inizio"], "fine": b["fine"],
                            "ordini": [b["ordine"]]}
                lanci.append(corrente)
    for lancio in sorted(lanci, key=lambda x: (x["inizio"], x["linea"])):
        numero_foglio += 1
        pedane = sum(o["pedane"] for o in lancio["ordini"])
        kg = pedane * ART[lancio["articolo"]]["kg_pedana"]
        FOGLI.append((numero_foglio, g.isoformat(), lancio["linea"], lancio["articolo"],
                      lancio["inizio"].strftime("%H:%M"), lancio["fine"].strftime("%H:%M"), pedane,
                      "|".join(o["numero"] for o in lancio["ordini"])))
        for r in consuma_lotti(ART[lancio["articolo"]]["varieta"], kg, g):
            RIGHE_CALIBRATRICE.append((g.isoformat(), lancio["linea"], numero_foglio, r["lotto"],
                                       lancio["inizio"].strftime("%H:%M"), lancio["fine"].strftime("%H:%M"),
                                       r["entrati"], r["confezionati"], r["sidro"], r["industria"]))

# ── Scrittura dei file ───────────────────────────────────────────────────────
print("File scritti in", OUT)
scrivi("linee.csv", ["codice", "nome", "descrizione", "edificio", "persone_richieste", "minuti_avvio",
                     "formato", "pedane_ora"],
       [(l[0], l[1], l[2], l[3], l[4], l[5], f, c) for l in LINEE for f, c in l[6].items()])
scrivi("celle.csv", ["codice", "edificio", "blocco", "riga", "colonna", "capacita_bins", "temperatura_c"], CELLE)
scrivi("clienti.csv", ["codice", "nome", "canale"], CLIENTI)
scrivi("produttori.csv", ["codice", "ragione_sociale", "localita", "socio"],
       [(p[0], p[1], p[2], "si") for p in PRODUTTORI])
scrivi("regole_cambio.csv", ["tipo", "da_articolo", "a_articolo", "minuti"],
       [(t, "", "", m) for t, m in CAMBIO.items()])
scrivi("costi_orari.csv", ["qualifica", "costo_orario"], list(COSTO_ORARIO.items()))

righe = []
for cod, varieta, calibro, formato, imballo, cliente in ARTICOLI:
    kg_collo, colli = FORMATI[formato]
    descrizione = f"{varieta} {calibro} - {formato} - {imballo}"
    for componente, quantita, riferimento, costo in DISTINTE[imballo]:
        righe.append((cod, descrizione, varieta, calibro, formato, imballo, cliente, kg_collo, colli,
                      componente, quantita, riferimento, costo))
scrivi("articoli_imballi.csv", ["articolo", "descrizione", "varieta", "calibro", "formato", "imballo", "cliente",
                                "kg_per_collo", "colli_per_pedana", "componente", "quantita", "riferimento",
                                "costo_unitario"], righe)

righe = []
for cod in ART:
    a = ART[cod]
    for cli in clienti_di(cod):
        for sett in SETTIMANE:
            prezzo = round(PREZZO_BASE[(a["varieta"], a["formato"])] + CORREZIONE_CLIENTE[cli], 2)
            righe.append((cli, cod, sett, f"{prezzo:.2f}", SCONTI.get((cli, sett), 0)))
scrivi("listino_vendita.csv", ["cliente", "articolo", "settimana", "prezzo_kg", "sconto_pct"], righe)

scrivi("conferimenti_pesa.csv", ["lotto", "data", "ora", "produttore", "varieta", "peso_lordo_kg", "tara_kg",
                                 "peso_netto_kg", "bins", "cella", "prezzo_acquisto_kg"],
       [(l["codice"], l["giorno"].isoformat(), l["ora"].strftime("%H:%M"), l["produttore"], l["varieta"],
         l["netto"] + l["bins"] * TARA_BINS, l["bins"] * TARA_BINS, l["netto"], l["bins"], l["cella"],
         f"{l['prezzo']:.2f}") for l in LOTTI])

scrivi("ordini_gestionale.csv", ["numero", "data", "cliente", "articolo", "pedane", "tipo", "arrivo", "camion",
                                 "priorita", "note"],
       [(o["numero"], o["giorno"].isoformat(), o["cliente"], o["articolo"], o["pedane"], o["tipo"],
         fmt_dt(o["arrivo"]), fmt_dt(o["camion"]), o["priorita"], o["note"]) for o in ORDINI])

righe, timbrature = [], []
for g in GIORNI:
    for p in PERSONE:
        stato, entrata, uscita = PRESENZE[(p["matricola"], g)]
        righe.append((p["matricola"], p["nome"], p["gruppo"], p["qualifica"], "|".join(p["linee"]), g.isoformat(),
                      stato, entrata.strftime("%H:%M") if entrata else "", uscita.strftime("%H:%M") if uscita else ""))
        if entrata:
            e = dt(g, entrata) + timedelta(minutes=random.randint(-9, 3))
            if g == DEMO:
                timbrature.append((p["matricola"], g.isoformat(), e.strftime("%H:%M"), ""))
            else:
                u = dt(g, uscita) + timedelta(minutes=random.randint(-2, 12))
                timbrature.append((p["matricola"], g.isoformat(), e.strftime("%H:%M"), u.strftime("%H:%M")))
scrivi("presenze_app_turni.csv", ["matricola", "nome", "gruppo", "qualifica", "linee_abilitate", "giorno",
                                  "stato", "entrata", "uscita"], righe)
scrivi("timbrature.csv", ["matricola", "giorno", "entrata", "uscita"], timbrature)
scrivi("fogli_storico.csv", ["numero", "data", "linea", "articolo", "ora_inizio", "ora_fine", "pedane", "ordini"],
       FOGLI)
scrivi("squadre_storico.csv", ["data", "linea", "matricola"], SQUADRE)
for l in CAP:
    scrivi(f"calibratrice_{l}.csv", ["data", "linea", "foglio", "lotto", "ora_inizio", "ora_fine", "kg_entrati",
                                     "kg_confezionati", "kg_scarto_sidro", "kg_scarto_industria"],
           [r for r in RIGHE_CALIBRATRICE if r[1] == l])

# ── Controlli a colpo d'occhio ───────────────────────────────────────────────
print("\nControlli")
print(f"  ordini {len(ORDINI)} ({len(ORDINI) / len(GIORNI):.1f} al giorno), fogli {len(FOGLI)} "
      f"({FOGLI[0][0]}-{FOGLI[-1][0]}), lotti {len(LOTTI)}, celle usate {len(celle_usate)}")
tardi = 0
for g in GIORNI[:-1]:
    piano = pianifica([o for o in ORDINI if o["giorno"] == g], g)
    for s in piano.values():
        tardi += sum(1 for b in s["blocchi"] if b["ordine"]["camion"] and b["fine"] > b["ordine"]["camion"])
    fine = max(s["libera"] for s in piano.values())
    print(f"  {g} fine ultima linea {fine:%H:%M}")
print(f"  blocchi storici oltre il camion: {tardi}")
piano = pianifica([o for o in ORDINI if o["giorno"] == DEMO], DEMO)
for l, s in piano.items():
    oltre = [b["ordine"]["numero"] for b in s["blocchi"] if b["ordine"]["camion"] and b["fine"] > b["ordine"]["camion"]]
    print(f"  demo {l}: {len(s['blocchi'])} blocchi, fine {s['libera']:%H:%M}, oltre il camion {oltre}")
presenti_l4 = [m for m in SPECIALISTI_L4 if PRESENZE[(m, DEMO)][0] == "Presente"]
print(f"  demo Linea 4: richieste {RICHIESTE['L4']}, specialisti presenti tutto il giorno {len(presenti_l4)}")
per_prod = defaultdict(lambda: [0, 0])
lotto_prod = {l["codice"]: l["produttore"] for l in LOTTI}
for r in RIGHE_CALIBRATRICE:
    per_prod[lotto_prod[r[3]]][0] += r[6]
    per_prod[lotto_prod[r[3]]][1] += r[7]
print("  resa per produttore:", ", ".join(f"{p} {c / e:.0%}" for p, (e, c) in sorted(per_prod.items())))
residuo = defaultdict(float)
for l in LOTTI:
    residuo[l["varieta"]] += l["residuo"]
print("  giacenza residua kg:", ", ".join(f"{v} {k:,.0f}" for v, k in residuo.items()))
