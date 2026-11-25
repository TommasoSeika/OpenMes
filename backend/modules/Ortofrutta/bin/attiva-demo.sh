#!/bin/sh
# Enable the Ortofrutta module and load its simulated data.
#
# Run inside the backend container, from anywhere:
#
#   docker compose exec backend sh modules/Ortofrutta/bin/attiva-demo.sh "Nome Azienda"
#
# The company name is optional: it is what the menu and the header of every
# screen show, and it is kept in the database, not in the repository. Safe to run again: it
# reloads the simulated data from the CSV files in seed/.
set -e

cd "$(dirname "$0")/../../.."
COMPANY="${1:-}"

echo "[Ortofrutta] Attivo il modulo..."
php artisan tinker --execute='app(\App\Services\ModuleManager::class)->enable("Ortofrutta");'
php artisan config:clear > /dev/null
php artisan route:clear > /dev/null

echo "[Ortofrutta] Creo le tabelle..."
php artisan migrate --force --path=modules/Ortofrutta/database/migrations

echo "[Ortofrutta] Carico i dati simulati..."
if [ -n "$COMPANY" ]; then
    php artisan ortofrutta:demo --azienda="$COMPANY"
else
    php artisan ortofrutta:demo
fi
php artisan tinker --execute='try { \Spatie\Permission\Models\Permission::firstOrCreate(["name" => "tab:ortofrutta", "guard_name" => "web"]); } catch (\Throwable $e) { echo $e->getMessage(); }'

echo "[Ortofrutta] Aggiorno le cache e ricarico l'applicazione..."
php artisan config:cache > /dev/null
php artisan route:cache > /dev/null
php artisan octane:reload > /dev/null 2>&1 || true

echo "[Ortofrutta] Fatto. Apri /admin/stabilimento."
