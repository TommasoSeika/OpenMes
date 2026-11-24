#!/usr/bin/env bash
# Start OpenMES inside a GitHub Codespace (also works in a local dev container).
#
# Runs on every start of the codespace (devcontainer.json → postStartCommand)
# and is safe to run by hand at any time:
#
#   bash .devcontainer/start.sh
#
# First run:  writes .env with generated passwords, builds the image from this
#             checkout and starts the stack.
# Later runs: keep the passwords and the data, rebuild only what changed, and
#             print the address and the login again.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

HTTP_PORT=8080
HTTPS_PORT=8443
ENV_FILE=.env

say()  { printf '\n\033[0;36m→ %s\033[0m\n' "$*"; }
ok()   { printf '\033[0;32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
fail() { printf '\033[0;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# ── .env helpers ──────────────────────────────────────────────────────────────

env_get() { # env_get KEY → value, or nothing
    [ -f "$ENV_FILE" ] || return 0
    awk -F= -v key="$1" '$1 == key { sub(/^[^=]*=/, ""); print; exit }' "$ENV_FILE"
}

env_set() { # env_set KEY VALUE → replace the line, or add it
    local key="$1" value="$2" tmp
    touch "$ENV_FILE"
    if grep -q "^${key}=" "$ENV_FILE"; then
        tmp="$(mktemp)"
        # Values travel through the environment, not `awk -v`, which would
        # interpret backslash escapes in them.
        ENV_KEY="$key" ENV_VALUE="$value" awk -F= '
            $1 == ENVIRON["ENV_KEY"] { print ENVIRON["ENV_KEY"] "=" ENVIRON["ENV_VALUE"]; next }
            { print }
        ' "$ENV_FILE" > "$tmp"
        cat "$tmp" > "$ENV_FILE"
        rm -f "$tmp"
    else
        # Keep the new line on a line of its own if the file lacks a final newline.
        if [ -s "$ENV_FILE" ] && [ -n "$(tail -c1 "$ENV_FILE")" ]; then
            echo >> "$ENV_FILE"
        fi
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
}

env_default() { # env_default KEY VALUE → set only when missing or empty
    if [ -z "$(env_get "$1")" ]; then
        env_set "$1" "$2"
    fi
}

random_alnum() { # 24 letters and digits. No symbols: the value is written
    # unquoted to .env and passes through compose and the entrypoint's sed.
    local s
    s="$(head -c 64 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
    printf '%s' "${s:0:24}"
}

random_app_key() { # Laravel key format, as install.sh generates it
    printf 'base64:%s' "$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
}

# ── Where the browser reaches the application ─────────────────────────────────
# In a codespace that is GitHub's forwarded address for port 8080, served over
# HTTPS. The Codespaces Caddyfile tells the application about it.

# Development mode adds docker-compose.dev.yml: the source is bind-mounted into
# the containers and a watcher rebuilds the frontend on every save, so changes
# show without rebuilding the image. Turn it on once with
#   OPENMES_DEV=1 bash .devcontainer/start.sh
# (and off with OPENMES_DEV=0); the choice is remembered in .env.
if [ -n "${OPENMES_DEV:-}" ]; then
    env_set OPENMES_DEV "$OPENMES_DEV"
fi
DEV_MODE="$(env_get OPENMES_DEV)"
COMPOSE_FILES="docker-compose.yml"
if [ "$DEV_MODE" = "1" ]; then
    COMPOSE_FILES="${COMPOSE_FILES}:docker-compose.dev.yml"
fi

if [ -n "${CODESPACE_NAME:-}" ]; then
    PUBLIC_HOST="${CODESPACE_NAME}-${HTTP_PORT}.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
    APP_URL="https://${PUBLIC_HOST}"
    COMPOSE_FILES="${COMPOSE_FILES}:.devcontainer/docker-compose.codespaces.yml"
    STATEFUL_DOMAINS="${PUBLIC_HOST},localhost:${HTTP_PORT},127.0.0.1:${HTTP_PORT},localhost"
else
    PUBLIC_HOST="localhost"
    APP_URL="http://localhost:${HTTP_PORT}"
    STATEFUL_DOMAINS="localhost,localhost:${HTTP_PORT},localhost:${HTTPS_PORT},127.0.0.1:${HTTP_PORT}"
fi

# ── .env ──────────────────────────────────────────────────────────────────────
# Address settings are rewritten on every run. Everything else is written once:
# a new database password would no longer match the existing postgres volume,
# and a new APP_KEY would make everything encrypted so far unreadable.

FIRST_RUN=0
[ -f "$ENV_FILE" ] || FIRST_RUN=1

env_set DOMAIN "$PUBLIC_HOST"
env_set APP_URL "$APP_URL"
env_set HTTP_PORT "$HTTP_PORT"
env_set HTTPS_PORT "$HTTPS_PORT"
env_set COMPOSE_FILE "$COMPOSE_FILES"
env_set SANCTUM_STATEFUL_DOMAINS "$STATEFUL_DOMAINS"

env_default APP_ENV production
env_default APP_DEBUG false
env_default APP_TIMEZONE Europe/Rome
# Anonymous usage reports to the OpenMES project: off here. Switch them on in
# Settings → System → Usage reporting if you want to send them.
env_default OPENMES_TELEMETRY false
env_default POSTGRES_DB openmmes
env_default POSTGRES_USER openmmes_user
env_default ADMIN_USERNAME admin
env_default ADMIN_EMAIL admin@example.com
env_default APP_KEY "$(random_app_key)"
env_default POSTGRES_PASSWORD "$(random_alnum)"
env_default ADMIN_PASSWORD "$(random_alnum)"
chmod 600 "$ENV_FILE"

if [ "$FIRST_RUN" = "1" ]; then
    ok ".env created with generated passwords"
else
    ok ".env kept (passwords and data preserved)"
fi

# ── Docker ────────────────────────────────────────────────────────────────────

say "Waiting for Docker..."
tries=0
until docker info > /dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 60 ]; then
        fail "Docker did not start. Rebuild the codespace (Command Palette → Codespaces: Rebuild Container) and try again."
    fi
    sleep 2
done
ok "Docker is running"

# ── Build and start ───────────────────────────────────────────────────────────

if [ "$FIRST_RUN" = "1" ]; then
    say "Building and starting OpenMES. The first build takes roughly 10 minutes..."
else
    say "Starting OpenMES (rebuilding only what changed)..."
fi
docker compose up -d --build

# ── Wait until it answers ─────────────────────────────────────────────────────
# A 200 from the login page means the entrypoint finished migrating and seeding
# and Caddy is proxying. Up to 5 minutes for a cold first start.

say "Waiting for the application (the first start creates the database)..."
if [ "$DEV_MODE" = "1" ]; then
    warn "Development mode: the frontend watcher installs its packages and builds first. Allow about 5 minutes."
fi
ready=0
code=""
for _ in $(seq 1 "$([ "$DEV_MODE" = "1" ] && echo 300 || echo 150)"); do
    code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${HTTP_PORT}/login" 2> /dev/null || true)"
    if [ "$code" = "200" ]; then
        ready=1
        break
    fi
    sleep 2
done

echo ""
if [ "$ready" = "1" ]; then
    ok "OpenMES is ready"
else
    warn "OpenMES is not answering yet (last status: ${code:-none}). It may still be starting."
    warn "Follow it with:  docker compose logs -f backend"
fi

echo ""
echo "  Address:   ${APP_URL}"
echo "  Login:     $(env_get ADMIN_USERNAME)"
echo "  Password:  $(env_get ADMIN_PASSWORD)   (initial password, saved in .env)"
echo ""
if [ -n "${CODESPACE_NAME:-}" ]; then
    echo "  Open the address in a normal browser tab, or use the Ports tab → OpenMES → globe icon."
    echo "  Use this address, not localhost: the application builds its links for it."
    echo ""
fi
echo "  After changing code:  bash .devcontainer/start.sh"
echo "  Logs:                 docker compose logs -f backend"
echo "  Stop:                 docker compose down"
echo ""
