#!/usr/bin/env bash
# Smoke test of the Ortofrutta module against a running stack.
#
# Run from the machine that runs Docker (in a codespace: its terminal):
#
#   bash backend/modules/Ortofrutta/bin/prova-http.sh
#
# Signs in as the administrator from .env, opens every screen, runs the main
# actions of the demo (arrivals, proposed sequence, crews, launch, simulator)
# and puts the demo data back. Prints one line per check; exits 1 if any fails.
#
# ORTO_URL overrides the address (default: http://localhost:<HTTP_PORT from .env>).

set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../../../.."

env_get() { grep -E "^$1=" .env 2>/dev/null | head -n1 | cut -d= -f2-; }
BASE="${ORTO_URL:-http://localhost:$(env_get HTTP_PORT || true)}"
[ "$BASE" = "http://localhost:" ] && BASE="http://localhost"
ORTO="$BASE/admin/stabilimento"
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT
FAILED=0

ok()   { printf '  OK    %s\n' "$*"; }
fail() { printf '  FAIL  %s\n' "$*"; FAILED=1; }
decode() { local v; read -r v || true; printf '%b' "${v//%/\\x}"; }
xsrf() { awk '$6 == "XSRF-TOKEN" { print $7 }' "$JAR" | decode; }
why() { docker compose exec -T backend sh -c 'grep -E "^\[[0-9-]+ [0-9:]+\] [a-z]+\.ERROR" storage/logs/laravel.log | tail -n 1 | cut -c1-400' 2>/dev/null; }

# status and body of a request: sets $STATUS and $BODY
request() {
    local out
    out="$(curl -s -c "$JAR" -b "$JAR" -w '\n%{http_code}' "$@")"
    STATUS="${out##*$'\n'}"
    BODY="${out%$'\n'*}"
}
get() { request "$ORTO$1"; }
send() { # send METHOD PATH [json]
    request -X "$1" -H "X-XSRF-TOKEN: $(xsrf)" -H 'X-Requested-With: XMLHttpRequest' -H 'Accept: application/json' \
        -H 'Content-Type: application/json' -d "${3:-{\}}" "$ORTO$2"
}
field() { printf '%s' "$BODY" | grep -oE "\"$1\":[^,}]+" | head -n1 | cut -d: -f2-; }
sql() { docker compose exec -T postgres psql -U "$(env_get POSTGRES_USER)" -d "$(env_get POSTGRES_DB)" -Atc "$1" 2>/dev/null | tr -d '[:space:]'; }

page() { # page PATH COMPONENT
    get "$1"
    if [ "$STATUS" = 200 ] && printf '%s' "$BODY" | grep -qE "ortofrutta\\\\?/$2\""; then
        ok "schermata $2 (${1:-/})"
    else
        fail "schermata $2 (${1:-/}) -> HTTP $STATUS  $(why)"
    fi
}

echo "Regole di calcolo"
if docker compose exec -T backend php artisan ortofrutta:verifica > /tmp/orto-verifica.log 2>&1; then
    ok "$(grep -c ' OK ' /tmp/orto-verifica.log) regole rispettate"
else
    fail "ortofrutta:verifica"; grep -E 'NO|rror' /tmp/orto-verifica.log | head -n 8
fi

echo "Accesso"
request "$BASE/login"
request -H "X-XSRF-TOKEN: $(xsrf)" -H 'X-Requested-With: XMLHttpRequest' \
    --data-urlencode "username=$(env_get ADMIN_USERNAME)" --data-urlencode "password=$(env_get ADMIN_PASSWORD)" "$BASE/login"
[ "$STATUS" = 302 ] && ok "amministratore" || fail "accesso -> HTTP $STATUS"

echo "Dati demo"
send POST /demo/ripristina
[ "$STATUS" = 302 ] && ok "ripristina dati demo" || fail "ripristina -> HTTP $STATUS  $(why)"

echo "Schermate"
page "" Plant
page /conferimenti Deliveries
page /ordini Orders
page /piano Plan
page /squadre Crews
page /fogli WorkSheets
page /tracciabilita Trace
page "/tracciabilita?vista=lotto" Trace
page /margini Margins
page /missioni Missions
page "/ordini?giorno=2026-11-20" Orders
SHEET="$(sql 'select id from orto_work_sheets order by number desc limit 1')"
page "/fogli/$SHEET" WorkSheet

request "$BASE/admin/ortofrutta/piano?giorno=2026-11-20"
[ "$STATUS" = 302 ] && ok "il vecchio indirizzo /admin/ortofrutta porta al nuovo" || fail "vecchio indirizzo -> HTTP $STATUS"

echo "Tracciabilità"
# An order of a past day: it has a closed sheet, raw lots and producers behind it.
TRACED="$(sql "select o.id from orto_orders o join orto_work_sheets s on s.id = o.work_sheet_id where s.status = 'closed' order by o.day desc, o.id limit 1")"
get "/tracciabilita?ordine=$TRACED"
if [ "$STATUS" = 200 ] && printf '%s' "$BODY" | grep -qE '"packing_lot":"L[0-9]{5}-[0-9]+"' && printf '%s' "$BODY" | grep -qE '"producers":\[\{"name"'; then
    ok "dal cliente al produttore: ordine -> lotto di confezionamento -> lotti -> produttori"
else
    fail "tracciabilità di un ordine -> HTTP $STATUS  $(why)"
fi
# Every sheet has orders behind all its pallets, so sharing its kilograms among them loses nothing.
BALANCE="$(sql 'select count(*) from orto_work_sheets s where s.pallets <> (select coalesce(sum(o.pallets), 0) from orto_orders o where o.work_sheet_id = s.id)')"
[ "$BALANCE" = 0 ] && ok "ogni foglio ha ordini per tutte le sue pedane: i kg ripartiti tornano al totale" || fail "$BALANCE fogli con pedane diverse da quelle dei loro ordini"
USED="$(sql 'select lot_id from orto_work_sheet_lots group by lot_id order by count(*) desc limit 1')"
get "/tracciabilita?vista=lotto&lotto=$USED"
if [ "$STATUS" = 200 ] && printf '%s' "$BODY" | grep -qE '"sheets":\[\{"id"' && printf '%s' "$BODY" | grep -qE '"deliveries":\[\{"order_id"'; then
    ok "dal produttore ai clienti: lotto -> fogli -> ordini -> clienti"
else
    fail "tracciabilità di un lotto -> HTTP $STATUS  $(why)"
fi
get "/tracciabilita?q=L99999-1"
[ "$STATUS" = 200 ] && printf '%s' "$BODY" | grep -qE '"results":\{"rows":\[\],"total":0\}' && ok "una ricerca senza risultati risponde con un elenco vuoto" || fail "ricerca senza risultati -> HTTP $STATUS  $(why)"

echo "Copione della demo"
get /piano
printf '%s' "$BODY" | grep -qE '"backlog":\[\]' && ok "ore 10:00: gli A per B sono già in sequenza" || fail "alle 10:00 ci sono ordini da pianificare"

send POST /ordini/simula
[ "$STATUS" = 302 ] && ok "simula arrivo ordini (prima ondata)" || fail "simula -> HTTP $STATUS  $(why)"

send POST /piano/proponi
if [ "$STATUS" = 200 ]; then
    ok "proponi sequenza: blocchi oltre il camion = $(field late_count), in attesa di arrivo = $(field waiting)"
else
    fail "proponi -> HTTP $STATUS  $(why)"
fi

LINE="$(printf '%s' "$BODY" | grep -oE '"lines":\[\{"id":[0-9]+' | grep -oE '[0-9]+$')"
ORDER="$(printf '%s' "$BODY" | grep -oE '"blocks":\[\{"id":[0-9]+' | head -n1 | grep -oE '[0-9]+$')"
send POST /piano/sposta "{\"order_id\":$ORDER,\"line_id\":$LINE,\"position\":500}"
[ "$STATUS" = 200 ] && ok "sposta un ordine in fondo alla linea: blocchi oltre il camion = $(field late_count)" || fail "sposta -> HTTP $STATUS  $(printf '%s' "$BODY" | cut -c1-200) $(why)"

send POST /squadre/proponi
CREW="$(sql 'select count(*) from orto_production_crew')"
[ "$STATUS" = 302 ] && [ "${CREW:-0}" -gt 0 ] && ok "proponi squadre: ogni produzione ha la sua squadra ($CREW assegnazioni)" || fail "squadre -> HTTP $STATUS  $(why)"
get /squadre
printf '%s' "$BODY" | grep -qE '"issue":"busy"' && fail "una persona è su due produzioni nello stesso orario" || ok "nessuno è su due produzioni nello stesso orario"
get /piano
printf '%s' "$BODY" | grep -qE '"understaffed":true' && ok "le produzioni senza squadra completa sono evidenziate nel piano" || fail "nessuna produzione risulta scoperta"

send POST /piano/lancia "{\"line_id\":$LINE}"
NEW="$(field sheet_id)"
[ "$STATUS" = 200 ] && [ -n "$NEW" ] && ok "lancia: creato un foglio di lavorazione" || fail "lancia -> HTTP $STATUS  $(printf '%s' "$BODY" | cut -c1-200) $(why)"
[ -n "$NEW" ] && page "/fogli/$NEW" WorkSheet

# The crew of the production follows onto its sheet, and a change on the crews screen changes the sheet.
if [ -n "$NEW" ]; then
    PROD="$(sql "select id from orto_orders where work_sheet_id = $NEW order by position limit 1")"
    ON_SHEET="$(sql "select count(*) from orto_work_sheet_people where work_sheet_id = $NEW")"
    [ "${ON_SHEET:-0}" -gt 0 ] && ok "il foglio nasce con la squadra della sua produzione ($ON_SHEET persone)" || fail "il foglio $NEW non ha persone"
    WHO="$(sql "select person_id from orto_production_crew where order_id = $PROD order by person_id desc limit 1")"
    send POST /squadre/rimuovi "{\"production\":$PROD,\"person_id\":$WHO}"
    [ "$STATUS" = 302 ] && [ "$(sql "select count(*) from orto_work_sheet_people where work_sheet_id = $NEW")" = "$((ON_SHEET - 1))" ] && ok "togliere una persona dalla produzione la toglie dal foglio aperto" || fail "rimuovi -> HTTP $STATUS  $(why)"
    send POST /squadre/assegna "{\"production\":$PROD,\"person_id\":$WHO}"
    [ "$STATUS" = 302 ] && [ "$(sql "select count(*) from orto_work_sheet_people where work_sheet_id = $NEW")" = "$ON_SHEET" ] && ok "riassegnarla la rimette sul foglio" || fail "assegna -> HTTP $STATUS  $(why)"
    # The production after it on the same line: empty its crew, then bring over the one before.
    NEXT="$(sql "select o.id from orto_orders o join orto_orders f on f.id = $PROD where o.day = f.day and o.line_id = f.line_id and o.work_sheet_id is null order by o.position limit 1")"
    if [ -n "$NEXT" ]; then
        send POST /squadre/svuota "{\"production\":$NEXT}"
        [ "$STATUS" = 302 ] && [ "$(sql "select count(*) from orto_production_crew where order_id = $NEXT")" = 0 ] && ok "svuota la squadra di una produzione" || fail "svuota -> HTTP $STATUS  $(why)"
        send POST /squadre/copia "{\"production\":$NEXT}"
        COPIED="$(sql "select count(*) from orto_production_crew where order_id = $NEXT")"
        [ "$STATUS" = 302 ] && [ "${COPIED:-0}" -gt 0 ] && ok "stessa squadra della produzione precedente ($COPIED persone)" || fail "copia -> HTTP $STATUS  $(why)"
    fi
    page "/squadre?produzione=$PROD" Crews
fi

# Fill in the new sheet: one lot of the right variety, 3,000 kg in, 280 kg of waste.
if [ -n "$NEW" ]; then
    LOT="$(sql "select l.id from orto_lots l join orto_items i on i.variety = l.variety join orto_work_sheets s on s.item_id = i.id where s.id = $NEW order by l.received_at desc limit 1")"
    send PUT "/fogli/$NEW" "{\"lots\":[{\"lot_id\":$LOT,\"kg_in\":3000,\"kg_packed\":2700,\"kg_waste_cider\":180,\"kg_waste_industry\":100}]}"
    COST="$(sql "select cost_per_kg from orto_work_sheets where id = $NEW")"
    [ "$STATUS" = 302 ] && [ -n "$COST" ] && ok "foglio salvato senza digitare costi: lavorazione $COST €/kg" || fail "salva foglio -> HTTP $STATUS  $(printf '%s' "$BODY" | cut -c1-200) $(why)"
    send POST "/fogli/$NEW/chiudi"
    [ "$STATUS" = 302 ] && [ "$(sql "select status from orto_work_sheets where id = $NEW")" = closed ] && ok "foglio chiuso" || fail "chiudi foglio -> HTTP $STATUS  $(why)"
    get "/tracciabilita?foglio=$NEW"
    [ "$STATUS" = 200 ] && printf '%s' "$BODY" | grep -qE "\"lot_id\":$LOT," && ok "il foglio appena chiuso è tracciabile: cliente -> lotto $(sql "select code from orto_lots where id = $LOT")" || fail "il foglio $NEW non compare nella tracciabilità  $(why)"
    send POST /squadre/rimuovi "{\"production\":$PROD,\"person_id\":$WHO}"
    [ "$(sql "select count(*) from orto_work_sheet_people where work_sheet_id = $NEW")" = "$ON_SHEET" ] && ok "la squadra di una produzione chiusa non si cambia più" || fail "una produzione chiusa ha perso una persona"
    NUMBER="$(sql "select number from orto_work_sheets where id = $NEW")"
    get /margini
    SAME="$(printf '%s' "$BODY" | grep -oE "\"number\":$NUMBER,[^}]*\"processing\":[0-9.]+" | grep -oE '[0-9.]+$')"
    [ -n "$SAME" ] && [ "$(printf '%.4f' "$SAME")" = "$(printf '%.4f' "$COST")" ] && ok "stesso €/kg nel foglio e nei margini ($SAME)" || fail "il foglio $NUMBER nei margini ha lavorazione '$SAME', nel foglio '$COST'"
fi

echo "Altre azioni"
send PATCH "/ordini/$ORDER/priorita" '{"priority":1}'
[ "$STATUS" = 302 ] && ok "priorità di uscita modificata dall'ufficio" || fail "priorità -> HTTP $STATUS  $(why)"
send POST /ordini/scorta "{\"item_id\":$(sql 'select id from orto_items order by code limit 1'),\"pallets\":4}"
[ "$STATUS" = 302 ] && [ "$(sql "select count(*) from orto_orders where type = 'stock' and number like 'SC%'")" = 1 ] && ok "riga di scorta aggiunta" || fail "scorta -> HTTP $STATUS  $(why)"
LOTS="$(sql 'select count(*) from orto_lots')"
CELL="$(sql 'select c.id from orto_cells c where not exists (select 1 from orto_lots l where l.cell_id = c.id) order by c.code limit 1')"
send POST /conferimenti "{\"producer_id\":$(sql 'select id from orto_producers order by code limit 1'),\"variety\":\"Abate Fetel\",\"gross_kg\":9990,\"bins\":30,\"bin_tare_kg\":33,\"cell_id\":$CELL,\"purchase_price_kg\":1.02}"
[ "$STATUS" = 302 ] && [ "$(sql 'select count(*) from orto_lots')" = "$((LOTS + 1))" ] && ok "conferimento pesato e messo in cella: netto $(sql 'select net_kg from orto_lots order by id desc limit 1') kg" || fail "conferimento -> HTTP $STATUS  $(printf '%s' "$BODY" | cut -c1-200) $(why)"

send POST /margini/simula '{"purchase_price":1,"yield_pct":100,"processing":0.2,"discount_pct":0}'
[ "$(field minimum_net)" = "1.2" ] && ok "simulatore: 1,00 € + 0,20 € = prezzo minimo 1,20 €" || fail "simulatore -> $(printf '%s' "$BODY" | cut -c1-200)"

get /margini
printf '%s' "$BODY" | grep -qE '"producers":\[\{' && ok "margini: classifica produttori presente" || fail "margini senza produttori"

send POST /demo/ripristina
[ "$STATUS" = 302 ] && ok "dati demo rimessi a posto" || fail "ripristina finale -> HTTP $STATUS"

echo
if [ "$FAILED" = 0 ]; then echo "Tutto a posto."; else echo "Ci sono controlli falliti."; fi
exit "$FAILED"
