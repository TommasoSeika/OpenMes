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

set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../../../.."

env_get() { grep -E "^$1=" .env 2>/dev/null | head -n1 | cut -d= -f2-; }
BASE="http://localhost:$(env_get HTTP_PORT || true)"
[ "$BASE" = "http://localhost:" ] && BASE="http://localhost"
ORTO="$BASE/admin/ortofrutta"
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
page /margini Margins
page /missioni Missions
page "/ordini?giorno=2026-11-20" Orders
SHEET="$(docker compose exec -T postgres psql -U "$(env_get POSTGRES_USER)" -d "$(env_get POSTGRES_DB)" -Atc 'select id from orto_work_sheets order by number desc limit 1' 2>/dev/null | tr -d '[:space:]')"
page "/fogli/$SHEET" WorkSheet

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
[ "$STATUS" = 302 ] && ok "proponi squadre" || fail "squadre -> HTTP $STATUS  $(why)"
get /piano
printf '%s' "$BODY" | grep -qE '"understaffed":true' && ok "una linea sotto organico è evidenziata nel piano" || fail "nessuna linea risulta sotto organico"

send POST /piano/lancia "{\"line_id\":$LINE}"
NEW="$(field sheet_id)"
[ "$STATUS" = 200 ] && [ -n "$NEW" ] && ok "lancia: creato un foglio di lavorazione" || fail "lancia -> HTTP $STATUS  $(printf '%s' "$BODY" | cut -c1-200) $(why)"
[ -n "$NEW" ] && page "/fogli/$NEW" WorkSheet

send POST /margini/simula '{"purchase_price":1,"yield_pct":100,"processing":0.2,"discount_pct":0}'
[ "$(field minimum_net)" = "1.2" ] && ok "simulatore: 1,00 € + 0,20 € = prezzo minimo 1,20 €" || fail "simulatore -> $(printf '%s' "$BODY" | cut -c1-200)"

get /margini
printf '%s' "$BODY" | grep -qE '"producers":\[\{' && ok "margini: classifica produttori presente" || fail "margini senza produttori"

send POST /demo/ripristina
[ "$STATUS" = 302 ] && ok "dati demo rimessi a posto" || fail "ripristina finale -> HTTP $STATUS"

echo
if [ "$FAILED" = 0 ]; then echo "Tutto a posto."; else echo "Ci sono controlli falliti."; fi
exit "$FAILED"
